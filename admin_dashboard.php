<?php
// admin_dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php'; // Includes PDO, helpers h(), q(), q1(), getStatusInfo()

// ------ auth: admin only ------
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ดูแลระบบเท่านั้น'];
    header('Location: index.php');
    exit;
}
$admin_id = (int)$user['id'];

// ------ Fetch Dashboard Data ------

// 1. Pending Actions Counts
$pendingPayments = q1("SELECT COUNT(*) as c FROM payments WHERE status='pending'")['c'] ?? 0;
$pendingSellers = q1("SELECT COUNT(*) as c FROM kycs WHERE status='pending'")['c'] ?? 0;
// เพิ่มเติม: อาจจะนับออเดอร์ที่มีปัญหา หรือรอการจัดการอื่นๆ

// 2. Overview Stats
$totalUsers = q1("SELECT COUNT(*) as c FROM users")['c'] ?? 0;
$totalSellers = q1("SELECT COUNT(*) as c FROM users WHERE role='seller'")['c'] ?? 0; // สมมติว่า role ถูกอัปเดตเมื่อ approve KYC
// หรือนับจาก KYC ที่ approved: $totalSellers = q1("SELECT COUNT(*) as c FROM kycs WHERE status='approved'")['c'] ?? 0;
$totalProducts = q1("SELECT COUNT(*) as c FROM products")['c'] ?? 0;
$totalOrders = q1("SELECT COUNT(*) as c FROM orders")['c'] ?? 0;
// เพิ่มเติม: อาจจะดึงยอดขายรวม (ซับซ้อนกว่า)

// 3. Recent Activity (Optional Example)
$recentOrders = q("SELECT id, order_number, total_amount, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5")->fetchAll();


// --- [โค้ด NAVBAR Data] ---
$userNav = $user;
$uidNav = $admin_id;
$roleNav = $user['role'];
$cartCountNav = 0;
// --- [จบโค้ด NAVBAR Data] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Web Shop Game</title>
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
            --muted-color: #6c757d;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Sora', sans-serif;
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

        .dashboard-stat-card .card-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--brand-dark);
        }

        .dashboard-stat-card .card-text {
            color: var(--muted-color);
            font-weight: 600;
        }

        .dashboard-stat-card .stat-icon {
            font-size: 3rem;
            color: var(--border);
            opacity: 0.8;
        }

        .list-group-item.action-link {
            transition: background-color 0.2s ease;
        }

        .list-group-item.action-link:hover {
            background-color: #f0fdf4;
            color: var(--brand-dark);
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
                    <?php if ($roleNav === 'buyer'): ?>
                        <li class="nav-item"> <a href="cart.php" class="btn btn-outline-secondary btn-sm position-relative"> <i class="bi bi-cart3 fs-5"></i> <?php if ($cartCountNav > 0): ?> <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCountNav ?></span> <?php endif; ?> </a> </li>
                    <?php endif; ?>
                    <?php if ($uidNav): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                                <div class="avatar d-flex align-items-center justify-content-center" style="width:36px; height:36px; background-color:var(--brand); color:#fff; border-radius:50%; font-weight:700;"> <?= strtoupper(substr($userNav['name'] ?? $userNav['email'], 0, 1)) ?> </div>
                                <span class="d-none d-sm-inline fw-semibold"><?= h($userNav['name'] ?? $userNav['email']) ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 1rem;">
                                <?php if ($roleNav === 'admin'): ?>
                                    <li><a class="dropdown-item active" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                                    <li><a class="dropdown-item" href="admin_payments_review.php"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป</a></li>
                                    <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-person-check me-2"></i>ตรวจสอบคำขอผู้ขาย</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_users.php"><i class="bi bi-people me-2"></i>จัดการผู้ใช้</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php elseif ($roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                                    <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-2"></i>ออเดอร์ร้านฉัน</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php elseif ($roleNav === 'buyer'): ?>
                                    <li><a class="dropdown-item" href="register_seller.php"><i class="bi bi-patch-check me-2"></i>สมัครเป็นผู้ขาย</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php endif; ?>
                                <?php if ($roleNav !== 'admin'): ?>
                                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                                <?php endif; ?>
                                <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                                <?php endif; ?>
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
        <h2 class="mb-4 fw-bold"><i class="bi bi-speedometer2 me-2"></i>Admin Dashboard</h2>

        <h4 class="mb-3">ภาพรวมระบบ</h4>
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-stat-card">
                    <div class="card-body">
                        <i class="bi bi-people-fill stat-icon float-end"></i>
                        <h5 class="card-title"><?= number_format($totalUsers) ?></h5>
                        <p class="card-text">ผู้ใช้ทั้งหมด</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-stat-card">
                    <div class="card-body">
                        <i class="bi bi-person-check-fill stat-icon float-end"></i>
                        <h5 class="card-title"><?= number_format($totalSellers) ?></h5>
                        <p class="card-text">ผู้ขายที่อนุมัติ</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-stat-card">
                    <div class="card-body">
                        <i class="bi bi-box-seam stat-icon float-end"></i>
                        <h5 class="card-title"><?= number_format($totalProducts) ?></h5>
                        <p class="card-text">สินค้าในระบบ</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-stat-card">
                    <div class="card-body">
                        <i class="bi bi-receipt stat-icon float-end"></i>
                        <h5 class="card-title"><?= number_format($totalOrders) ?></h5>
                        <p class="card-text">คำสั่งซื้อทั้งหมด</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> รายการที่ต้องดำเนินการ</div>
                    <div class="list-group list-group-flush">
                        <a href="admin_payments_review.php?tab=pending" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center action-link">
                            <div><i class="bi bi-credit-card-2-front me-2"></i> ตรวจสอบสลิปโอนเงิน</div>
                            <?php if ($pendingPayments > 0): ?>
                                <span class="badge bg-warning rounded-pill"><?= $pendingPayments ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="admin_seller_requests.php?tab=pending" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center action-link">
                            <div><i class="bi bi-person-check me-2"></i> ตรวจสอบคำขอเป็นผู้ขาย</div>
                            <?php if ($pendingSellers > 0): ?>
                                <span class="badge bg-warning rounded-pill"><?= $pendingSellers ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i> ทางลัดจัดการระบบ</div>
                    <div class="list-group list-group-flush">
                        <a href="admin_manage_users.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-people me-2"></i> จัดการข้อมูลผู้ใช้</a>
                        <a href="admin_manage_products.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-box-seam me-2"></i> จัดการสินค้าทั้งหมด</a>
                        <a href="admin_manage_categories.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-tags me-2"></i> จัดการหมวดหมู่สินค้า</a>
                        <a href="admin_manage_orders.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-receipt me-2"></i> จัดการคำสั่งซื้อทั้งหมด</a>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mt-4 mb-3">5 คำสั่งซื้อล่าสุด</h4>
        <div class="card overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>เลขที่ออเดอร์</th>
                            <th class="text-end">ยอดรวม</th>
                            <th class="text-center">สถานะ</th>
                            <th>วันที่สร้าง</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentOrders): ?>
                            <tr>
                                <td colspan="5" class="text-center text-secondary p-3">ยังไม่มีคำสั่งซื้อ</td>
                            </tr>
                            <?php else: foreach ($recentOrders as $order):
                                list($status_text, $status_color, $status_classes) = getStatusInfo($order['status']);
                            ?>
                                <tr>
                                    <td><a href="order_detail.php?order_number=<?= h($order['order_number']) ?>"><?= h($order['order_number']) ?></a></td>
                                    <td class="text-end">฿<?= number_format((float)$order['total_amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge badge-status <?= $status_classes ?>"><?= h($status_text) ?></span></td>
                                    <td><?= date('d/m/y H:i', strtotime($order['created_at'])) ?></td>
                                    <td class="text-end"><a href="order_detail.php?order_number=<?= h($order['order_number']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> ดู</a></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>