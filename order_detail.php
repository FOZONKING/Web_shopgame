<?php
// order_detail.php — แสดงรายละเอียดออเดอร์ + อัปโหลดสลิป/ใบจัดส่ง + ยืนยันรับสินค้า
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

/* ---------- helpers ---------- */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q1($sql, $p = [])
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function qall($sql, $p = [])
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- auth ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header("Location: login.php");
    exit;
}
$uid = (int)$user['id'];
$role = (string)($user['role'] ?? 'buyer');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ---------- param ---------- */
$order_number = trim($_GET['order_number'] ?? '');
if ($order_number === '') {
    http_response_code(400);
    exit('ไม่พบเลขคำสั่งซื้อ');
}

/* ---------- fetch order by role ---------- */
if ($role === 'buyer') {
    $order = q1("SELECT o.*,u.name buyer_name,u.email buyer_email 
             FROM orders o JOIN users u ON u.id=o.user_id
             WHERE o.order_number=? AND o.user_id=?", [$order_number, $uid]);
} elseif ($role === 'seller') {
    $order = q1("SELECT DISTINCT o.*,u.name buyer_name,u.email buyer_email
             FROM orders o JOIN users u ON u.id=o.user_id
             JOIN order_items oi ON oi.order_id=o.id
             WHERE o.order_number=? AND oi.seller_id=?", [$order_number, $uid]);
} else { // admin
    $order = q1("SELECT o.*,u.name buyer_name,u.email buyer_email 
             FROM orders o JOIN users u ON u.id=o.user_id
             WHERE o.order_number=?", [$order_number]);
}
if (!$order) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูออเดอร์นี้');
}

/* ---------- fetch items ---------- */
if ($role === 'seller') {
    $items = qall("SELECT oi.*,p.image1 FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id
               WHERE oi.order_id=? AND oi.seller_id=?", [$order['id'], $uid]);
} else {
    $items = qall("SELECT oi.*,p.image1 FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id
               WHERE oi.order_id=?", [$order['id']]);
}
$visible_total = 0;
foreach ($items as $it) {
    $visible_total += (float)$it['subtotal'];
}

/* ---------- flash ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- upload proofs ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_proof'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Bad CSRF');
    }

    $type = $_POST['type'] ?? '';
    if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'กรุณาเลือกไฟล์หลักฐาน'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $size = (int)$_FILES['proof']['size'];
    $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if ($size <= 0 || $size > 5 * 1024 * 1024 || !in_array($ext, $allowed, true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไฟล์ไม่ถูกต้อง (ต้อง JPG/PNG/WebP/PDF และไม่เกิน 5MB)'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $status = (string)$order['status'];
    $canUpload = false;
    if ($type === 'payment' && $role === 'buyer' && $uid === (int)$order['user_id']) {
        $canUpload = in_array($status, ['awaiting_payment', 'pending'], true);
    } elseif ($type === 'shipping' && $role === 'seller') {
        $hasMine = q1("SELECT 1 FROM order_items WHERE order_id=? AND seller_id=? LIMIT 1", [$order['id'], $uid]);
        $canUpload = $hasMine && in_array($status, ['escrow_held', 'approved', 'payment_pending'], true);
    }
    if (!$canUpload) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่สามารถอัปโหลดในสถานะปัจจุบัน'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $dir = __DIR__ . "/uploads/proofs";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = $type . '_' . $order['order_number'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (!move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'อัปโหลดไม่สำเร็จ'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }
    $path = "uploads/proofs/$fname";

    if ($type === 'payment') {
        $pdo->prepare("UPDATE orders SET payment_proof=?,status='awaiting_verification',updated_at=NOW()
                   WHERE id=? AND (status='awaiting_payment' OR status='pending')")
            ->execute([$path, $order['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'ส่งสลิปแล้ว รอแอดมินตรวจสอบ'];
    } else {
        $pdo->prepare("UPDATE orders SET shipping_proof=?,status='shipped',updated_at=NOW()
                   WHERE id=? AND (status IN ('escrow_held','approved','payment_pending'))")
            ->execute([$path, $order['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อัปโหลดหลักฐานจัดส่งแล้ว'];
    }
    header("Location: order_detail.php?order_number=" . urlencode($order_number));
    exit;
}

/* ---------- confirm received ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_received'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Bad CSRF');
    }
    if ($role === 'buyer' && $uid === (int)$order['user_id'] && $order['status'] === 'shipped') {
        $pdo->prepare("UPDATE orders SET status='completed',updated_at=NOW()
                   WHERE id=? AND status='shipped'")
            ->execute([$order['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ ยืนยันรับสินค้าแล้ว'];
    }
    header("Location: order_detail.php?order_number=" . urlencode($order_number));
    exit;
}

/* ---------- UI helper ---------- */
function statusBadge($s)
{
    $map = [
        'awaiting_payment' => ['รอชำระเงิน', 'warning'],
        'pending' => ['รอดำเนินการ', 'secondary'],
        'awaiting_verification' => ['รอตรวจสลิป', 'secondary'],
        'approved' => ['ชำระแล้ว (รอส่งของ)', 'info'],
        'payment_pending' => ['ตรวจสอบการชำระ', 'secondary'],
        'escrow_held' => ['พักเงิน Escrow', 'info'],
        'shipped' => ['จัดส่งแล้ว', 'primary'],
        'completed' => ['สำเร็จ', 'success'],
        'cancelled' => ['ยกเลิก', 'danger'],
    ];
    [$t, $c] = $map[$s] ?? [$s, 'light'];
    return '<span class="badge bg-' . $c . '">' . $t . '</span>';
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <title>รายละเอียดคำสั่งซื้อ <?= h($order_number) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            background: #0b0f14;
            color: #e5e7eb
        }

        .card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, .2);
            border-radius: 16px
        }

        .header {
            background: radial-gradient(1200px 300px at 20% -10%, rgba(34, 197, 94, .18), transparent),
                radial-gradient(800px 200px at 90% -20%, rgba(14, 165, 233, .12), transparent);
        }

        .thumb {
            width: 84px;
            height: 64px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, .2);
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container py-4 d-flex flex-wrap justify-content-between">
            <div>
                <h3>รายละเอียดคำสั่งซื้อ</h3>
                <div class="text-secondary">เลขที่: <span class="fw-bold text-success"><?= h($order_number) ?></span></div>
                <div class="text-secondary small">ผู้ซื้อ: <?= h($order['buyer_name']) ?> (<?= h($order['buyer_email']) ?>)</div>
            </div>
            <div class="text-end">
                <div>สถานะ: <?= statusBadge($order['status']) ?></div>
                <div>
                    <?php if ($role === 'seller'): ?>
                        <small class="text-secondary">ยอดรวมของคุณ</small>
                        <div class="text-success fw-bold">฿<?= number_format($visible_total, 2) ?></div>
                    <?php else: ?>
                        <small class="text-secondary">ยอดรวมทั้งหมด</small>
                        <div class="text-success fw-bold">฿<?= number_format($order['total_amount'], 2) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
                <?= $flash['msg'] ?><button class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header">สินค้า</div>
            <div class="card-body p-0">
                <table class="table table-dark align-middle mb-0">
                    <thead>
                        <tr>
                            <th>สินค้า</th>
                            <th class="text-end">ราคา</th>
                            <th class="text-end">จำนวน</th>
                            <th class="text-end">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><img class="thumb me-2" src="<?= h($it['image1'] ?: 'https://via.placeholder.com/84x64') ?>">
                                    <?= h($it['name']) ?> <?php if ($role !== 'buyer'): ?><div class="small text-secondary">Seller ID:<?= $it['seller_id'] ?></div><?php endif; ?>
                                </td>
                                <td class="text-end">฿<?= number_format($it['price'], 2) ?></td>
                                <td class="text-end"><?= (int)$it['quantity'] ?></td>
                                <td class="text-end">฿<?= number_format($it['subtotal'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th colspan="3" class="text-end"><?= ($role === 'seller' ? 'รวม (เฉพาะคุณ)' : 'รวมทั้งหมด') ?></th>
                            <th class="text-end">฿<?= number_format($role === 'seller' ? $visible_total : $order['total_amount'], 2) ?></th>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- actions -->
        <div class="row g-3">
            <div class="col-lg-7">
                <?php
                $s = $order['status'];
                $canPay = ($role === 'buyer' && $uid === $order['user_id'] && in_array($s, ['awaiting_payment', 'pending'], true));
                $waiting = ($s === 'awaiting_verification');
                $escrow = in_array($s, ['escrow_held', 'approved', 'payment_pending'], true);
                $canShip = ($role === 'seller' && $escrow);
                $canDone = ($role === 'buyer' && $uid === $order['user_id'] && $s === 'shipped');
                ?>
                <?php if ($canPay): ?>
                    <form class="card" method="post" enctype="multipart/form-data">
                        <div class="card-header">อัปโหลดสลิปโอนเงิน</div>
                        <div class="card-body vstack gap-2">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="type" value="payment">
                            <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            <button class="btn btn-success" name="upload_proof"><i class="bi bi-upload"></i> ส่งสลิป</button>
                        </div>
                    </form>
                <?php elseif ($waiting): ?>
                    <div class="card">
                        <div class="card-body"><i class="bi bi-hourglass"></i> ส่งสลิปแล้ว — รอแอดมินตรวจสอบ</div>
                    </div>
                <?php elseif ($canShip): ?>
                    <form class="card" method="post" enctype="multipart/form-data">
                        <div class="card-header">อัปโหลดหลักฐานจัดส่ง</div>
                        <div class="card-body vstack gap-2">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="type" value="shipping">
                            <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            <button class="btn btn-primary" name="upload_proof"><i class="bi bi-truck"></i> ยืนยันการจัดส่ง</button>
                        </div>
                    </form>
                <?php elseif ($canDone): ?>
                    <form method="post" class="card">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div><i class="bi bi-box-seam"></i> ได้รับสินค้าแล้ว?</div>
                            <div><input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                <button class="btn btn-success" name="confirm_received"><i class="bi bi-check2-circle"></i> ยืนยันรับ</button>
                            </div>
                        </div>
                    </form>
                <?php elseif ($s === 'completed'): ?>
                    <div class="card">
                        <div class="card-body"><i class="bi bi-check2-circle text-success"></i> คำสั่งซื้อสำเร็จ</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">ช่องทางชำระเงิน</div>
                    <div class="card-body">
                        <div>บัญชี: SafeTrade Co., Ltd.</div>
                        <div>ธนาคาร: XYZ</div>
                        <div>เลขที่บัญชี: 123-4-56789-0</div>
                        <small class="text-secondary">โอนแล้วแนบสลิปด้านซ้าย</small>
                    </div>
                </div>
                <div class="d-grid mt-3">
                    <a href="order_list.php" class="btn btn-outline-light"><i class="bi bi-card-list"></i> กลับหน้ารายการ</a>
                    <a href="shop.php" class="btn btn-outline-light mt-2"><i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ</a>
                </div>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>