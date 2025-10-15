<?php
// add_to_cart.php — รองรับ GET/POST + AJAX และใช้ตะกร้าต่อผู้ใช้
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function is_ajax()
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function json_out($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ต้องล็อกอิน
$user = $_SESSION['user'] ?? null;
if (!$user) {
    if (is_ajax()) json_out(['success' => false, 'msg' => 'unauthorized']);
    header("Location: login.php");
    exit;
}
$uid = (int)$user['id'];

// cart helpers
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

$id  = (int)($_GET['id']  ?? $_POST['id']  ?? 0);
$qty = (int)($_GET['qty'] ?? $_POST['qty'] ?? 1);
if ($id <= 0) {
    if (is_ajax()) json_out(['success' => false, 'msg' => 'missing product id']);
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบสินค้า'];
    header("Location: shop.php");
    exit;
}
if ($qty <= 0) $qty = 1;

// ตรวจสอบสินค้า
$st = $pdo->prepare("SELECT id, name, price, quantity, reserved FROM products WHERE id=?");
$st->execute([$id]);
$p = $st->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    if (is_ajax()) json_out(['success' => false, 'msg' => 'สินค้าไม่พบ']);
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'สินค้าไม่พบ'];
    header("Location: shop.php");
    exit;
}

// เช็คสต็อก (ถ้ากำหนด)
$limit = null;
if ($p['quantity'] !== null) {
    $limit = max(0, (int)$p['quantity'] - (int)$p['reserved']);
}

// เพิ่มลงตะกร้า (ต่อ user)
$cart   = get_user_cart();
$newQty = ($cart[$id] ?? 0) + $qty;
if ($limit !== null) $newQty = min($newQty, $limit);
if ($newQty <= 0 && isset($cart[$id])) unset($cart[$id]);
else $cart[$id] = $newQty;
set_user_cart($cart);

if (is_ajax()) {
    $cartCount = array_sum($cart);
    json_out(['success' => true, 'cart_count' => $cartCount]);
}

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'เพิ่ม ' . h($p['name']) . ' ในตะกร้าแล้ว'];
$back = $_SERVER['HTTP_REFERER'] ?? 'cart.php';
header("Location: $back");
