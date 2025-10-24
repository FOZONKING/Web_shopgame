<?php
// seller_dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php'; // Includes PDO, helpers h(), q(), q1(), getStatusInfo()

// ------ Auth: Seller only ------
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'seller') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ขายเท่านั้น'];
    // Maybe redirect to index.php or login.php depending on if they are logged in
    header('Location: ' . ($user ? 'index.php' : 'login.php'));
    exit;
}
$seller_id = (int)$user['id'];

// ------ Fetch Dashboard Data for Seller ------

// 1. Orders requiring action (Awaiting Shipment)
$awaitingShipmentCount = q1(
    "SELECT COUNT(DISTINCT o.id) as c
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE oi.seller_id = ? AND o.status = 'escrow_held'",
    [$seller_id]
)['c'] ?? 0;

// 2. Products Low on Stock (Example: <= 3 remaining)
// Considers NULL quantity as unlimited
$lowStockCount = q1(
    "SELECT COUNT(*) as c
     FROM products
     WHERE seller_id = ? AND quantity IS NOT NULL AND (quantity - reserved) <= 3 AND (quantity - reserved) > 0",
    [$seller_id]
)['c'] ?? 0;

// 3. Total Active Products
$activeProductCount = q1(
    "SELECT COUNT(*) as c
     FROM products
     WHERE seller_id = ? AND (quantity IS NULL OR quantity > reserved)", // Consider active if unlimited or stock > reserved
    [$seller_id]
)['c'] ?? 0;

// 4. Recently Completed Orders (Example: Last 5)
$recentCompletedOrders = q(
    "SELECT DISTINCT o.id, o.order_number, o.total_amount, o.status, o.updated_at
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE oi.seller_id = ? AND o.status = 'completed'
     ORDER BY o.updated_at DESC
     LIMIT 5",
    [$seller_id]
)->fetchAll();

// --- [โค้ด NAVBAR Data] ---
$userNav = $user;
$uidNav = $seller_id;
$roleNav = $user['role'];
$cartCountNav = 0; // Seller might buy, calc if needed
if (($roleNav === 'buyer' || $roleNav === 'seller') && $uidNav) {
    $userCartNav = @$_SESSION['carts'][$uidNav] ?? [];
    $cartCountNav = is_array($userCartNav) ? array_sum($userCartNav) : 0;
}
// --- [จบโค้ด NAVBAR Data] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Dashboard - Web Shop Game</title>
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
            font-size: 2.5rem;
            color: var(--brand-light);
        }

        /* Adjusted icon style */
        .list-group-item.action-link {
            transition: background-color 0.2s ease;
            font-weight: 600;
        }

        .list-group-item.action-link:hover {
            background-color: #f0fdf4;
            color: var(--brand-dark);
        }

        .list-group-item.action-link i {
            color: var(--brand);
        }

        /* Icon color */
        .table {
            --bs-table-border-color: var(--border);
        }

        .table thead {
            background-color: #f8f9fa;
            color: #495057;
        }

        .table th {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .table tbody tr:hover {
            background-color: #f0fdf4;
        }

        /* Badge status styles */
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"> <span class="navbar-toggler-icon"></span> </button>
            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
                    <li class="nav-item"><a href="shop.php" class="nav-link fw-semibold">ร้านค้า</a></li>
                    <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): // Allow seller to have cart icon 
                    ?>
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
                                    <li><a class="dropdown-item" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php elseif ($roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item active" href="seller_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Seller Dashboard</a></li>
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
                                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                                <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                                <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item" href="cart.php"><i class="bi bi-cart3 me-2"></i>ไปตะกร้า</a></li>
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
        <h2 class="mb-4 fw-bold"><i class="bi bi-speedometer2 me-2"></i>Seller Dashboard</h2>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card dashboard-stat-card border-start border-5 border-warning">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title text-warning"><?= number_format($awaitingShipmentCount) ?></h5>
                            <p class="card-text">ออเดอร์รอจัดส่ง</p>
                        </div>
                        <i class="bi bi-hourglass-split stat-icon text-warning opacity-50"></i>
                    </div>
                    <a href="seller_orders.php?tab=awaiting" class="stretched-link"></a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card dashboard-stat-card border-start border-5 border-info">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title text-info"><?= number_format($lowStockCount) ?></h5>
                            <p class="card-text">สินค้าใกล้หมด</p>
                        </div>
                        <i class="bi bi-exclamation-triangle-fill stat-icon text-info opacity-50"></i>
                    </div>
                    <a href="manage_products.php?filter=low_stock" class="stretched-link"></a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card dashboard-stat-card border-start border-5 border-success">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title text-success"><?= number_format($activeProductCount) ?></h5>
                            <p class="card-text">สินค้าที่วางขาย</p>
                        </div>
                        <i class="bi bi-box-seam stat-icon text-success opacity-50"></i>
                    </div>
                    <a href="manage_products.php" class="stretched-link"></a>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i> ทางลัดจัดการร้านค้า</div>
                    <div class="list-group list-group-flush">
                        <a href="manage_products.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-box-seam me-2"></i> จัดการสินค้าทั้งหมด</a>
                        <a href="manage_products.php#addForm" class="list-group-item list-group-item-action action-link"><i class="bi bi-plus-circle me-2"></i> เพิ่มสินค้าใหม่</a>
                        <a href="seller_orders.php?tab=awaiting" class="list-group-item list-group-item-action action-link"><i class="bi bi-receipt-cutoff me-2"></i> ดูออเดอร์ที่รอจัดส่ง</a>
                        <a href="seller_orders.php" class="list-group-item list-group-item-action action-link"><i class="bi bi-list-ul me-2"></i> ดูออเดอร์ทั้งหมด</a>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-header fw-semibold"><i class="bi bi-check2-circle me-1"></i> 5 ออเดอร์ล่าสุดที่สำเร็จ</div>
                    <?php if (!$recentCompletedOrders): ?>
                        <div class="card-body text-center text-secondary">
                            <i class="bi bi-emoji-frown fs-1 mb-2"></i><br>
                            ยังไม่มีออเดอร์ที่ดำเนินการสำเร็จ
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>เลขที่ออเดอร์</th>
                                        <th class="text-end">ยอดรวม(ร้านคุณ)</th>
                                        <th>วันที่สำเร็จ</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentCompletedOrders as $order):
                                        // Recalculate seller's total for this specific completed order
                                        $seller_comp_total = q1("SELECT SUM(subtotal) as seller_total FROM order_items WHERE order_id=? AND seller_id=?", [$order['id'], $seller_id])['seller_total'] ?? 0.0;
                                    ?>
                                        <tr>
                                            <td><a href="order_detail.php?order_number=<?= h($order['order_number']) ?>"><?= h($order['order_number']) ?></a></td>
                                            <td class="text-end text-success fw-semibold">฿<?= number_format((float)$seller_comp_total, 2) ?></td>
                                            <td><?= date('d/m/y H:i', strtotime($order['updated_at'])) ?></td>
                                            <td class="text-end"><a href="order_detail.php?order_number=<?= h($order['order_number']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> ดู</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>