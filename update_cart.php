<?php
// update_cart.php — อัปเดตตะกร้าแบบ AJAX (ต่อ user) + group ตามผู้ขาย
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
function json_out($ok, $payload = [])
{
    echo json_encode(array_merge(['success' => $ok], $payload));
    exit;
}

if (empty($_SESSION['user'])) json_out(false, ['msg' => 'unauthorized']);

/* helper */
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

/* รับค่าจาก JS */
$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$qtyIn  = (int)($_POST['qty'] ?? 1);

$cart = get_user_cart();
if ($id <= 0 || !in_array($action, ['increase', 'decrease', 'set', 'remove'], true)) {
    json_out(false, ['msg' => 'bad params']);
}

/* อัปเดตจำนวน */
if ($action === 'remove') {
    unset($cart[$id]);
} else {
    $current = (int)($cart[$id] ?? 0);
    if ($action === 'increase') $current++;
    if ($action === 'decrease') $current = max(0, $current - 1);
    if ($action === 'set')      $current = max(0, $qtyIn);
    if ($current <= 0) unset($cart[$id]);
    else $cart[$id] = $current;
}

/* คำนวณผลรวมจากฐานข้อมูล + แยกต่อร้าน */
$total = 0.0;
$outCart = [];           // pid => {id,name,price,qty,subtotal}
$sellerTotals = [];      // seller_id => subtotal

if ($cart) {
    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($v) => $v > 0));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT p.id, p.name, p.price, p.seller_id
            FROM products p
            WHERE p.id IN ($ph)";
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $valid = [];
        foreach ($rows as $p) {
            $pid  = (int)$p['id'];
            $qty  = (int)($cart[$pid] ?? 0);
            if ($qty <= 0) continue;

            $price = (float)$p['price'];
            $sub   = $qty * $price;
            $total += $sub;

            $outCart[$pid] = [
                'id'       => $pid,
                'name'     => $p['name'],
                'price'    => $price,
                'qty'      => $qty,
                'subtotal' => $sub,
            ];

            $sellerId = (int)($p['seller_id'] ?? 0);
            if (!isset($sellerTotals[$sellerId])) $sellerTotals[$sellerId] = 0.0;
            $sellerTotals[$sellerId] += $sub;

            $valid[] = $pid;
        }

        // ล้าง id ผี
        foreach ($cart as $pid => $q) {
            if (!in_array((int)$pid, $valid, true)) unset($cart[$pid]);
        }
    }
}

/* บันทึกกลับ session ตาม user */
set_user_cart($cart);

json_out(true, [
    'cart'          => $outCart,
    'total'         => $total,
    'seller_totals' => $sellerTotals,
]);
