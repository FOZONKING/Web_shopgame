<?php
// create_order.php — สร้างคำสั่งซื้อจากตะกร้า (เลขรัน A000001)
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
$uid  = (int)$user['id'];

/* CSRF */
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['csrf'])
    || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])
) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF ไม่ถูกต้อง'];
    header("Location: cart.php");
    exit;
}

/* helpers: cart ต่อผู้ใช้ */
function get_user_cart(): array
{
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    return $_SESSION['carts'][$uid] ?? [];
}
function set_user_cart(array $cart): void
{
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    $_SESSION['carts'][$uid] = $cart;
}

/* โหลดสินค้าในตะกร้า */
$cart = get_user_cart();
if (empty($cart)) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ตะกร้าว่าง'];
    header("Location: cart.php");
    exit;
}

/* ดึงข้อมูลสินค้าจริง */
$ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($v) => $v > 0));
if (!$ids) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่มีสินค้าในตะกร้า'];
    header("Location: cart.php");
    exit;
}

$ph  = implode(',', array_fill(0, count($ids), '?'));
$st  = $pdo->prepare("SELECT id,name,price,seller_id,quantity FROM products WHERE id IN ($ph)");
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'สินค้าหมดหรือถูกลบ'];
    header("Location: cart.php");
    exit;
}

/* คำนวณยอดรวม + ตรวจสต็อกง่ายๆ */
$total = 0.0;
$lines = [];
foreach ($rows as $p) {
    $pid = (int)$p['id'];
    $qty = (int)($cart[$pid] ?? 0);
    if ($qty <= 0) continue;

    $stock = is_null($p['quantity']) ? null : (int)$p['quantity'];
    if (!is_null($stock) && $qty > $stock) $qty = $stock;
    if ($qty <= 0) continue;

    $price = (float)$p['price'];
    $sub   = $qty * $price;
    $total += $sub;

    $lines[] = [
        'product_id' => $pid,
        'name' => $p['name'],
        'price' => $price,
        'quantity' => $qty,
        'subtotal' => $sub,
        'seller_id' => (int)($p['seller_id'] ?? 0),
    ];
}
if (!$lines) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่มีรายการที่สั่งซื้อได้'];
    header("Location: cart.php");
    exit;
}

/* ฟังก์ชันออกเลขออเดอร์แบบ A000001 ภายในทรานแซกชัน */
function next_order_number(PDO $pdo): string
{
    // ดึงรายการล่าสุดและล็อกแถวไว้กันชน (ต้องอยู่ใน TRANSACTION)
    $sql = "SELECT order_number FROM orders ORDER BY id DESC LIMIT 1 FOR UPDATE";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    $start = 'A000001';
    if (!$row || empty($row['order_number'])) return $start;

    $last = strtoupper(trim($row['order_number']));
    if (!preg_match('/^([A-Z])(\d{6})$/', $last, $m)) {
        // ถ้ารูปแบบไม่ตรง ให้รีสตาร์ตที่ A000001
        return $start;
    }
    $letter = $m[1];
    $num    = (int)$m[2];

    $num++;
    if ($num > 999999) {
        // ขยับตัวอักษรถ้าตัวเลขเต็ม
        $ascii = ord($letter) + 1;
        if ($ascii > ord('Z')) $ascii = ord('A'); // วนกลับ A (หรือจะ throw error ก็ได้)
        $letter = chr($ascii);
        $num = 1;
    }
    return $letter . sprintf('%06d', $num);
}

/* สร้างออเดอร์ */
try {
    $pdo->beginTransaction();

    // ออกเลขออเดอร์ถัดไปแบบรันนิ่ง
    $order_number = next_order_number($pdo);

    $stm = $pdo->prepare(
        "INSERT INTO orders (user_id, order_number, total_amount, status, created_at, updated_at)
     VALUES (?, ?, ?, 'awaiting_payment', NOW(), NOW())"
    );
    $stm->execute([$uid, $order_number, $total]);
    $order_id = (int)$pdo->lastInsertId();

    $stm2 = $pdo->prepare(
        "INSERT INTO order_items(order_id, product_id, name, price, quantity, subtotal, seller_id)
     VALUES(?,?,?,?,?,?,?)"
    );
    foreach ($lines as $ln) {
        $stm2->execute([$order_id, $ln['product_id'], $ln['name'], $ln['price'], $ln['quantity'], $ln['subtotal'], $ln['seller_id']]);
        // ตัดสต็อก (ถ้ามี)
        $pdo->prepare("UPDATE products
                     SET quantity = CASE WHEN quantity IS NULL THEN NULL ELSE GREATEST(quantity-?,0) END
                   WHERE id=?")->execute([$ln['quantity'], $ln['product_id']]);
    }

    $pdo->commit();

    // เคลียร์ตะกร้าของ user
    set_user_cart([]);

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'สร้างคำสั่งซื้อแล้ว! เลขที่: ' . $order_number];
    header("Location: payment.php?order_number=" . urlencode($order_number));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'สร้างออเดอร์ไม่สำเร็จ: ' . $e->getMessage()];
    header("Location: cart.php");
    exit;
}
