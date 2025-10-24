<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q($sql, $p = [])
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function q1($sql, $p = [])
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/* ต้องล็อกอิน */
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header("Location: login.php");
    exit;
}
$uid  = (int)$user['id'];
$role = $user['role'] ?? 'buyer';

/* --- ลบออเดอร์ --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    $allow = false;
    // ... (โค้ดส่วนตรวจสอบสิทธิ์และลบข้อมูล เหมือนเดิม) ...
    if ($role === 'buyer') {
        $check = q1("SELECT id FROM orders WHERE id=? AND user_id=?", [$del_id, $uid]);
        if ($check) $allow = true;
    } elseif ($role === 'seller') {
        $check = q1("SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.id=? AND oi.seller_id=?", [$del_id, $uid]);
        if ($check) $allow = true;
    } elseif ($role === 'admin') {
        $allow = true;
    }

    if ($allow) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM payments WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM shipments WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM escrow_ledger WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$del_id]);
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'ลบคำสั่งซื้อเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์ลบคำสั่งซื้อนี้'];
    }
    header("Location: order_list.php"); // Redirect กลับมาหน้าเดิม
    exit;
}

/* --- ดึงออเดอร์ตามบทบาท --- */
if ($role === 'buyer') {
    $orders = q("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC", [$uid]);
} elseif ($role === 'seller') {
    $orders = q("SELECT DISTINCT o.* FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.seller_id=? ORDER BY o.id DESC", [$uid]);
} else { /* admin */
    $orders = q("SELECT * FROM orders ORDER BY id DESC");
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- [โค้ด NAVBAR] ---
// ดึงข้อมูลสำหรับ Navbar (แยกตัวแปร)
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
    <title>รายการคำสั่งซื้อ - Web Shop Game</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
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

        .table {
            border-color: var(--border);
        }

        .table thead {
            background-color: #f8f9fa;
        }

        .table th {
            font-weight: 600;
            color: #495057;
        }

        .table tbody tr:hover {
            background-color: #f0fdf4;
        }

        .badge-status {
            font-size: .8rem;
            font-weight: 600;
            padding: .4em .7em;
        }

        .badge.bg-secondary {
            background-color: #e2e8f0 !important;
            color: #475569 !important;
        }

        .badge.bg-warning {
            background-color: #fef9c3 !important;
            color: #a16207 !important;
        }

        .badge.bg-info {
            background-color: #cffafe !important;
            color: #0891b2 !important;
        }

        .badge.bg-primary {
            background-color: #dbeafe !important;
            color: #1d4ed8 !important;
        }

        .badge.bg-success {
            background-color: #dcfce7 !important;
            color: var(--brand-dark) !important;
        }

        .badge.bg-danger {
            background-color: #fee2e2 !important;
            color: #b91c1c !important;
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

    <div class="container py-4">
        <h2 class="mb-4 fw-bold"><i class="bi bi-receipt me-2"></i>คำสั่งซื้อของฉัน</h2>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= h($flash['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$orders): ?>
            <div class="card p-5 text-center">
                <div class="mb-3"><i class="bi bi-journal-x" style="font-size: 4rem; color: var(--muted);"></i></div>
                <h4 class="fw-bold">ยังไม่มีคำสั่งซื้อ</h4>
                <p class="text-secondary">เมื่อคุณสั่งซื้อ สินค้าจะมาแสดงที่นี่</p>
                <div class="mt-3">
                    <a href="shop.php" class="btn btn-brand btn-lg"><i class="bi bi-shop"></i> เริ่มเลือกซื้อสินค้า</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>เลขที่คำสั่งซื้อ</th>
                                <th class="text-end">ยอดรวม</th>
                                <th class="text-center">สถานะ</th>
                                <th>วันที่สร้าง</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><?= (int)$o['id'] ?></td>
                                    <td><?= h($o['order_number'] ?? ('#' . $o['id'])) ?></td>
                                    <td class="text-end">฿<?= number_format((float)$o['total_amount'], 2) ?></td>
                                    <td class="text-center">
                                        <?php
                                        $status = $o['status'] ?? '';
                                        $color = match ($status) {
                                            'pending'               => 'secondary',
                                            'awaiting_payment'      => 'warning',
                                            'awaiting_verification' => 'info',
                                            'escrow_held'           => 'primary',
                                            'shipped'               => 'primary',
                                            'completed'             => 'success',
                                            'cancelled'             => 'danger',
                                            default                 => 'secondary'
                                        };
                                        $status_th = match ($status) {
                                            'pending'               => 'รอดำเนินการ',
                                            'awaiting_payment'      => 'รอชำระเงิน',
                                            'awaiting_verification' => 'รอตรวจสอบ',
                                            'escrow_held'           => 'พักเงินกลาง',
                                            'shipped'               => 'จัดส่งแล้ว',
                                            'completed'             => 'สำเร็จ',
                                            'cancelled'             => 'ยกเลิก',
                                            default                 => 'ไม่ทราบสถานะ'
                                        };
                                        ?>
                                        <span class="badge bg-<?= $color ?> badge-status"><?= h($status_th) ?></span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($o['created_at'] ?? 'now')) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <?php
                                            // *** [โค้ดแก้ไข] *** กำหนด URL ปลายทางตามสถานะ
                                            $detail_url = 'order_detail.php?order_number=' . urlencode($o['order_number']);
                                            $is_awaiting_payment = in_array($o['status'], ['awaiting_payment', 'pending'], true);
                                            if ($is_awaiting_payment) {
                                                $detail_url = 'payment.php?order_number=' . urlencode($o['order_number']);
                                            }
                                            ?>
                                            <a href="<?= $detail_url // ใช้ URL ที่กำหนดไว้ 
                                                        ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-eye"></i> ดู <?= $is_awaiting_payment ? '/ ชำระเงิน' : '' // เพิ่มข้อความถ้าไปหน้า payment 
                                                                                ?>
                                            </a>
                                            <?php if (in_array($role, ['buyer', 'seller', 'admin'])): ?>
                                                <form method="post" onsubmit="return confirm('แน่ใจว่าต้องการลบออเดอร์ #<?= h($o['order_number']) ?>? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                                                    <input type="hidden" name="delete_id" value="<?= $o['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-house"></i> กลับหน้าหลัก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>