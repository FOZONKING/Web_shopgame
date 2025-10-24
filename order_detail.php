<?php
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
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบหมายเลขคำสั่งซื้อ'];
    header("Location: order_list.php");
    exit;
}

/* ---------- fetch order by role ---------- */
if ($role === 'buyer') {
    $order = q1("SELECT o.*,u.name buyer_name,u.email buyer_email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.order_number=? AND o.user_id=?", [$order_number, $uid]);
} elseif ($role === 'seller') {
    $order = q1("SELECT DISTINCT o.*,u.name buyer_name,u.email buyer_email FROM orders o JOIN users u ON u.id=o.user_id JOIN order_items oi ON oi.order_id=o.id WHERE o.order_number=? AND oi.seller_id=?", [$order_number, $uid]);
} else { // admin
    $order = q1("SELECT o.*,u.name buyer_name,u.email buyer_email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.order_number=?", [$order_number]);
}
if (!$order) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบคำสั่งซื้อ หรือคุณไม่มีสิทธิ์เข้าถึง'];
    header("Location: order_list.php");
    exit;
}

/* ---------- fetch items ---------- */
if ($role === 'seller') {
    $items = qall("SELECT oi.*,p.image1 FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND oi.seller_id=?", [$order['id'], $uid]);
} else {
    $items = qall("SELECT oi.*,p.image1 FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?", [$order['id']]);
}
$visible_total = 0; // ยอดรวมเฉพาะสินค้าที่ Seller คนนี้เห็น
foreach ($items as $it) {
    $visible_total += (float)$it['subtotal'];
}

/* ---------- flash ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- upload proofs ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_proof'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $type = $_POST['type'] ?? ''; // 'payment' or 'shipping'
    if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'กรุณาเลือกไฟล์หลักฐาน'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $file = $_FILES['proof'];
    $size = (int)$file['size'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if ($size <= 0 || $size > 5 * 1024 * 1024 || !in_array($ext, $allowed, true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไฟล์ไม่ถูกต้อง (ต้องเป็น JPG, PNG, WebP, PDF และขนาดไม่เกิน 5MB)'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $status = (string)$order['status'];
    $canUpload = false;
    $next_status = '';
    $success_msg = '';

    if ($type === 'payment' && $role === 'buyer' && $uid === (int)$order['user_id']) {
        if (in_array($status, ['awaiting_payment', 'pending'], true)) {
            $canUpload = true;
            $next_status = 'awaiting_verification';
            $success_msg = 'ส่งสลิปแล้ว รอแอดมินตรวจสอบ';
        }
    } elseif ($type === 'shipping' && $role === 'seller') {
        $hasMine = q1("SELECT 1 FROM order_items WHERE order_id=? AND seller_id=? LIMIT 1", [$order['id'], $uid]);
        if ($hasMine && in_array($status, ['escrow_held', 'approved', 'payment_pending'], true)) {
            $canUpload = true;
            $next_status = 'shipped';
            $success_msg = 'อัปโหลดหลักฐานจัดส่งแล้ว';
        }
    }

    if (!$canUpload) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่สามารถอัปโหลดหลักฐานในสถานะปัจจุบันได้'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }

    $dir = __DIR__ . "/uploads/proofs";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = $type . '_' . $order['order_number'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }
    $path = "uploads/proofs/$fname";

    // Update database
    try {
        if ($type === 'payment') {
            $stmt = $pdo->prepare("UPDATE orders SET payment_proof=?, status=?, updated_at=NOW() WHERE id=? AND (status='awaiting_payment' OR status='pending')");
            $stmt->execute([$path, $next_status, $order['id']]);
        } else { // shipping
            $stmt = $pdo->prepare("UPDATE orders SET shipping_proof=?, status=?, updated_at=NOW() WHERE id=? AND (status IN ('escrow_held','approved','payment_pending'))");
            $stmt->execute([$path, $next_status, $order['id']]);
        }
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $success_msg];
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่สามารถอัปเดตสถานะคำสั่งซื้อได้ อาจมีการเปลี่ยนแปลงสถานะไปก่อนหน้า'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()];
    }

    header("Location: order_detail.php?order_number=" . urlencode($order_number));
    exit;
}

/* ---------- confirm received ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_received'])) {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
        header("Location: order_detail.php?order_number=" . urlencode($order_number));
        exit;
    }
    // เฉพาะ Buyer และสถานะต้องเป็น shipped เท่านั้น
    if ($role === 'buyer' && $uid === (int)$order['user_id'] && $order['status'] === 'shipped') {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status='completed', updated_at=NOW() WHERE id=? AND status='shipped'");
            $stmt->execute([$order['id']]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ ยืนยันการรับสินค้าเรียบร้อย ขอบคุณที่ใช้บริการครับ'];
                // TODO: อาจจะต้องมี Logic การโอนเงินให้ Seller ตรงนี้
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่สามารถยืนยันการรับสินค้าได้ อาจมีการเปลี่ยนแปลงสถานะไปแล้ว'];
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ไม่สามารถยืนยันการรับสินค้าในสถานะนี้ได้'];
    }
    header("Location: order_detail.php?order_number=" . urlencode($order_number));
    exit;
}

/* ---------- UI helper ---------- */
function statusBadge($s)
{
    $map = [
        'pending'               => ['รอดำเนินการ', 'secondary'],
        'awaiting_payment'      => ['รอชำระเงิน', 'warning'],
        'awaiting_verification' => ['รอตรวจสลิป', 'info'],
        'approved'              => ['ชำระแล้ว', 'primary'], // เปลี่ยนชื่อให้เข้าใจง่าย
        'payment_pending'       => ['รอตรวจสอบชำระ', 'secondary'], // อาจจะไม่ค่อยใช้?
        'escrow_held'           => ['พักเงินกลาง', 'primary'],
        'shipped'               => ['จัดส่งแล้ว', 'primary'],
        'completed'             => ['สำเร็จ', 'success'],
        'cancelled'             => ['ยกเลิก', 'danger'],
    ];
    [$t, $c] = $map[$s] ?? [$s, 'secondary'];
    $extra_classes = match ($c) {
        'secondary' => 'bg-light text-dark',
        'warning'   => 'bg-warning-subtle text-warning-emphasis',
        'info'      => 'bg-info-subtle text-info-emphasis',
        'primary'   => 'bg-primary-subtle text-primary-emphasis',
        'success'   => 'bg-success-subtle text-success-emphasis',
        'danger'    => 'bg-danger-subtle text-danger-emphasis',
        default     => 'bg-light text-dark'
    };
    return '<span class="badge badge-status ' . $extra_classes . '">' . h($t) . '</span>';
}

// --- [โค้ด NAVBAR] ---
$userNav = $_SESSION['user'] ?? null;
$uidNav  = $userNav['id'] ?? null;
$roleNav = $userNav['role'] ?? null;
$cartCountNav = 0;
if ($uidNav) {
    $userCartNav = @$_SESSION['carts'][$uidNav] ?? [];
    $cartCountNav = is_array($userCartNav) ? array_sum($userCartNav) : 0;
}
// --- [จบโค้ด NAVBAR] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายละเอียดคำสั่งซื้อ <?= h($order_number) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- [อัปเดต] ธีมเขียว-ขาว --- */
        :root {
            --bg: #f7fefa;
            --card: #ffffff;
            --border: #e6e6e6;
            --text: #1a202c;
            --brand: #22c55e;
            --brand-dark: #16a34a;
            --muted: #adb5bd;
        }

        body {
            background-color: var(--bg);
            font-family: 'Sora', sans-serif;
            color: var(--text);
        }

        .navbar {
            background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .03);
        }

        .btn-brand {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
            font-weight: 700;
            border-radius: 12px;
        }

        .btn-brand:hover {
            background: var(--brand-dark);
            border-color: var(--brand-dark);
        }

        .header {
            background-color: #e8f9f0;
            border-bottom: 1px solid var(--border);
        }

        .thumb {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .badge-status {
            font-size: .85rem;
            font-weight: 600;
            padding: .4em .7em;
        }

        .proof-link img {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-top: 5px;
        }

        .proof-link i {
            font-size: 1.5rem;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--brand);"></div>
                <strong class="fw-bolder">Web Shop Game</strong>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
                    <li class="nav-item"><a href="shop.php" class="nav-link fw-semibold">ร้านค้า</a></li>
                    <li class="nav-item">
                        <a href="cart.php" class="btn btn-outline-secondary btn-sm position-relative">
                            <i class="bi bi-cart3 fs-5"></i>
                            <?php if ($cartCountNav > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCountNav ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php if ($uidNav): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                                <div class="avatar d-flex align-items-center justify-content-center" style="width:36px; height:36px; background-color:var(--brand); color:#fff; border-radius:50%; font-weight:700;">
                                    <?= strtoupper(substr($userNav['name'] ?? $userNav['email'], 0, 1)) ?>
                                </div>
                                <span class="d-none d-sm-inline fw-semibold"><?= h($userNav['name'] ?? $userNav['email']) ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 1rem;">
                                <?php if ($roleNav === 'admin'): ?>
                                    <li><a class="dropdown-item" href="admin_dashboard.php"><i class="bi bi-person-gear me-2"></i>หลังบ้าน (Admin)</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php endif; ?>
                                <?php if ($roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                                    <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-2"></i>ออเดอร์ร้านฉัน</a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="register_seller.php"><i class="bi bi-patch-check me-2"></i>สมัครเป็นผู้ขาย</a></li>
                                <?php endif; ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                                <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a href="login.php" class="btn btn-brand">เข้าสู่ระบบ</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="header">
        <div class="container py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h3 class="fw-bold mb-1">รายละเอียดคำสั่งซื้อ</h3>
                <div class="text-secondary mb-1">เลขที่: <span class="fw-bold" style="color:var(--brand-dark)"><?= h($order_number) ?></span></div>
                <div class="text-secondary small">ผู้ซื้อ: <?= h($order['buyer_name']) ?> (<?= h($order['buyer_email']) ?>)</div>
            </div>
            <div class="text-end">
                <div class="mb-1">สถานะ: <?= statusBadge($order['status']) ?></div>
                <div>
                    <?php if ($role === 'seller'): ?>
                        <small class="text-secondary">ยอดรวม (เฉพาะร้านคุณ)</small>
                        <div class="text-success fw-bold fs-5">฿<?= number_format($visible_total, 2) ?></div>
                    <?php else: ?>
                        <small class="text-secondary">ยอดรวมทั้งหมด</small>
                        <div class="text-success fw-bold fs-5">฿<?= number_format((float)$order['total_amount'], 2) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">รายการสินค้า</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th colspan="2">สินค้า</th>
                                        <th class="text-end">ราคา</th>
                                        <th class="text-center">จำนวน</th>
                                        <th class="text-end">รวม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <tr>
                                            <td><img class="thumb" src="<?= h($it['image1'] ?: 'https://via.placeholder.com/84x64?text=N/A') ?>"></td>
                                            <td>
                                                <?= h($it['name']) ?>
                                                <?php if ($role !== 'buyer'): // Show seller ID for Admin/Seller 
                                                ?>
                                                    <div class="small text-secondary">Seller ID: <?= (int)$it['seller_id'] ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">฿<?= number_format((float)$it['price'], 2) ?></td>
                                            <td class="text-center"><?= (int)$it['quantity'] ?></td>
                                            <td class="text-end">฿<?= number_format((float)$it['subtotal'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="4" class="text-end"><?= ($role === 'seller' ? 'ยอดรวม (เฉพาะร้านคุณ)' : 'ยอดรวมสุทธิ') ?></td>
                                        <td class="text-end fs-5">฿<?= number_format($role === 'seller' ? $visible_total : (float)$order['total_amount'], 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <?php
                $s = $order['status'];
                //เงื่อนไขการแสดงฟอร์ม
                $canPay = ($role === 'buyer' && $uid === (int)$order['user_id'] && in_array($s, ['awaiting_payment', 'pending'], true));
                $waitingVerification = ($s === 'awaiting_verification');
                $escrowHeldOrApproved = in_array($s, ['escrow_held', 'approved', 'payment_pending'], true);
                $canShip = ($role === 'seller' && $escrowHeldOrApproved && q1("SELECT 1 FROM order_items WHERE order_id=? AND seller_id=? LIMIT 1", [$order['id'], $uid]));
                $isShipped = ($s === 'shipped');
                $canConfirmReceived = ($role === 'buyer' && $uid === (int)$order['user_id'] && $isShipped);
                $isCompleted = ($s === 'completed');
                $isCancelled = ($s === 'cancelled');
                ?>

                <?php if ($canPay): ?>
                    <form class="card" method="post" enctype="multipart/form-data">
                        <div class="card-header fw-semibold">อัปโหลดสลิปโอนเงิน</div>
                        <div class="card-body vstack gap-2">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="type" value="payment">
                            <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            <small class="text-secondary">รองรับ JPG, PNG, WebP, PDF ขนาดไม่เกิน 5MB</small>
                            <button type="submit" class="btn btn-brand" name="upload_proof"><i class="bi bi-cloud-arrow-up-fill"></i> ส่งสลิป</button>
                        </div>
                    </form>
                <?php elseif ($waitingVerification): ?>
                    <div class="card">
                        <div class="card-body d-flex align-items-center gap-2"><i class="bi bi-hourglass-split fs-4 text-info"></i> ส่งสลิปแล้ว — กำลังรอตรวจสอบ</div>
                    </div>
                <?php elseif ($canShip && !$order['shipping_proof']): // Seller can ship and hasn't uploaded proof yet 
                ?>
                    <form class="card" method="post" enctype="multipart/form-data">
                        <div class="card-header fw-semibold">อัปโหลดหลักฐานการจัดส่ง</div>
                        <div class="card-body vstack gap-2">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="type" value="shipping">
                            <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            <small class="text-secondary">อัปโหลดรูปภาพหรือ PDF หลักฐานการส่งสินค้า/บริการ</small>
                            <button type="submit" class="btn btn-primary" name="upload_proof"><i class="bi bi-truck"></i> ยืนยันการจัดส่ง</button>
                        </div>
                    </form>
                <?php elseif ($isShipped && !$canConfirmReceived): // Shipped, but not the buyer viewing 
                ?>
                    <div class="card">
                        <div class="card-body d-flex align-items-center gap-2"><i class="bi bi-truck fs-4 text-primary"></i> ผู้ขายจัดส่งสินค้าแล้ว — รอลูกค้ายืนยัน</div>
                    </div>
                <?php elseif ($canConfirmReceived): ?>
                    <form method="post" class="card">
                        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="fw-semibold"><i class="bi bi-box-seam me-1"></i> ผู้ขายจัดส่งแล้ว กรุณาตรวจสอบและกดยืนยัน</div>
                            <div>
                                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                <button type="submit" class="btn btn-success" name="confirm_received"><i class="bi bi-check2-circle"></i> ยืนยันได้รับสินค้า/บริการ</button>
                            </div>
                        </div>
                    </form>
                <?php elseif ($isCompleted): ?>
                    <div class="card">
                        <div class="card-body d-flex align-items-center gap-2"><i class="bi bi-check2-circle fs-4 text-success"></i> คำสั่งซื้อนี้เสร็จสมบูรณ์แล้ว</div>
                    </div>
                <?php elseif ($isCancelled): ?>
                    <div class="card">
                        <div class="card-body d-flex align-items-center gap-2"><i class="bi bi-x-circle fs-4 text-danger"></i> คำสั่งซื้อนี้ถูกยกเลิกแล้ว</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="vstack gap-3">
                    <?php if (!empty($order['payment_proof'])): ?>
                        <div class="card">
                            <div class="card-header fw-semibold">หลักฐานการชำระเงิน</div>
                            <div class="card-body text-center proof-link">
                                <?php $proof_ext = strtolower(pathinfo($order['payment_proof'], PATHINFO_EXTENSION)); ?>
                                <?php if (in_array($proof_ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])): ?>
                                    <a href="<?= h($order['payment_proof']) ?>" target="_blank">
                                        <img src="<?= h($order['payment_proof']) ?>" alt="Payment Proof">
                                    </a>
                                <?php elseif ($proof_ext == 'pdf'): ?>
                                    <a href="<?= h($order['payment_proof']) ?>" target="_blank" class="d-block text-center text-decoration-none">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger d-block"></i>
                                        <span class="small">เปิดไฟล์ PDF</span>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= h($order['payment_proof']) ?>" target="_blank" class="d-block text-center text-decoration-none">
                                        <i class="bi bi-file-earmark-arrow-down-fill text-secondary d-block"></i>
                                        <span class="small">ดาวน์โหลดไฟล์</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($order['shipping_proof'])): ?>
                        <div class="card">
                            <div class="card-header fw-semibold">หลักฐานการจัดส่ง</div>
                            <div class="card-body text-center proof-link">
                                <?php $proof_ext = strtolower(pathinfo($order['shipping_proof'], PATHINFO_EXTENSION)); ?>
                                <?php if (in_array($proof_ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])): ?>
                                    <a href="<?= h($order['shipping_proof']) ?>" target="_blank">
                                        <img src="<?= h($order['shipping_proof']) ?>" alt="Shipping Proof">
                                    </a>
                                <?php elseif ($proof_ext == 'pdf'): ?>
                                    <a href="<?= h($order['shipping_proof']) ?>" target="_blank" class="d-block text-center text-decoration-none">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger d-block"></i>
                                        <span class="small">เปิดไฟล์ PDF</span>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= h($order['shipping_proof']) ?>" target="_blank" class="d-block text-center text-decoration-none">
                                        <i class="bi bi-file-earmark-arrow-down-fill text-secondary d-block"></i>
                                        <span class="small">ดาวน์โหลดไฟล์</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($role === 'buyer' && $uid === (int)$order['user_id'] && in_array($order['status'], ['awaiting_payment', 'pending'], true)): ?>
                        <div class="card">
                            <div class="card-header fw-semibold">ช่องทางชำระเงิน</div>
                            <div class="card-body">
                                <div>บัญชี: SafeTrade Co., Ltd.</div>
                                <div>ธนาคาร: XYZ</div>
                                <div>เลขที่บัญชี: 123-4-56789-0</div>
                                <small class="text-secondary d-block mt-2">โอนแล้ว กรุณาแนบสลิปในฟอร์มด้านซ้าย</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <a href="order_list.php" class="btn btn-outline-secondary"><i class="bi bi-list-ul"></i> กลับหน้ารายการคำสั่งซื้อ</a>
                        <a href="shop.php" class="btn btn-outline-secondary"><i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>