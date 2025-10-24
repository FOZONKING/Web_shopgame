<?php
// admin_payments_review.php — แอดมินตรวจสลิปและยืนยันเงินเข้า + แสดงสินค้า/ผู้ขายต่อออเดอร์
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

// ------ csrf ------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// ------ filter + search ------
$tab = $_GET['tab'] ?? 'pending'; // pending|approved|rejected|all
$q   = trim($_GET['q'] ?? '');
$validTabs = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($tab, $validTabs, true)) $tab = 'pending';

// ------ action: approve / reject ------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_url = "admin_payments_review.php?tab=" . urlencode($tab) . ($q ? '&q=' . urlencode($q) : '');

    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    } else {
        $pid    = (int)($_POST['payment_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $note   = trim($_POST['note'] ?? '');

        $pay = q1("SELECT p.*, o.id as order_id, o.user_id, o.order_number, o.status AS order_status
                   FROM payments p JOIN orders o ON o.id=p.order_id
                   WHERE p.id=? LIMIT 1", [$pid]);

        if (!$pay) {
            $flash = ['type' => 'danger', 'msg' => 'ไม่พบรายการชำระเงิน ID: ' . $pid];
        } elseif ($pay['status'] === 'pending' || $pay['order_status'] === 'awaiting_verification') {
            try {
                $pdo->beginTransaction();
                if ($action === 'approve') {
                    q(
                        "UPDATE payments SET status='approved', reviewed_by=?, reviewed_at=NOW(), reject_reason=NULL WHERE id=?",
                        [$admin_id, $pid]
                    );
                    q(
                        "UPDATE orders SET status='escrow_held', updated_at=NOW() WHERE id=? AND status IN ('awaiting_verification','awaiting_payment','pending')",
                        [$pay['order_id']]
                    );
                    $pdo->commit();
                    $flash = ['type' => 'success', 'msg' => 'อนุมัติสลิปออเดอร์ #' . h($pay['order_number']) . ' แล้ว → สถานะเปลี่ยนเป็น "พักเงินกลาง"'];
                } elseif ($action === 'reject') {
                    if (empty($note)) {
                        $flash = ['type' => 'warning', 'msg' => 'กรุณาระบุเหตุผลในการปฏิเสธสลิป'];
                        $pdo->rollBack();
                    } else {
                        q(
                            "UPDATE payments SET status='rejected', reviewed_by=?, reviewed_at=NOW(), reject_reason=? WHERE id=?",
                            [$admin_id, $note, $pid]
                        );
                        q(
                            "UPDATE orders SET status='awaiting_payment', updated_at=NOW() WHERE id=? AND status='awaiting_verification'",
                            [$pay['order_id']]
                        );
                        $pdo->commit();
                        $flash = ['type' => 'warning', 'msg' => 'ปฏิเสธสลิปออเดอร์ #' . h($pay['order_number']) . ' (เหตุผล: ' . h($note) . ') → สถานะกลับไปเป็น "รอชำระเงิน"'];
                    }
                } else {
                    $flash = ['type' => 'danger', 'msg' => 'การดำเนินการไม่ถูกต้อง'];
                    $pdo->rollBack();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล'];
            }
        } else {
            $flash = ['type' => 'info', 'msg' => 'รายการชำระเงิน #' . $pid . ' ได้รับการจัดการไปแล้ว'];
        }
    }
    $_SESSION['flash'] = $flash;
    header("Location: " . $redirect_url);
    exit;
}

// ------ Build WHERE clause for fetching list ------
$where_conditions = [];
$params = [];
if (in_array($tab, ['pending', 'approved', 'rejected'], true)) {
    $where_conditions[] = "p.status = ?";
    $params[] = $tab;
}
if ($q !== '') {
    $where_conditions[] = "(o.order_number LIKE :q OR u.email LIKE :q OR u.name LIKE :q)";
    $params['q'] = "%$q%";
}
$wsql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';


// ------ Fetch Payment List ------
// *** [แก้ไข] เพิ่ม p.method เข้าไปใน SELECT ***
$sql_list = "
    SELECT
        p.id as payment_id, p.order_id, p.slip_path, p.method,
        p.status as payment_status, p.paid_at, p.reviewed_at, p.reject_reason, p.reviewed_by,
        o.order_number, o.total_amount, o.status AS order_status,
        u.name AS buyer_name, u.email AS buyer_email
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    JOIN users u ON u.id = o.user_id
    $wsql
    ORDER BY
        CASE p.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
        p.id DESC
    LIMIT 200
";
$list = q($sql_list, $params)->fetchAll(PDO::FETCH_ASSOC);

// ------ Fetch Order Items in Batch ------
$itemsByOrder = [];
if ($list) {
    $orderIds = array_values(array_unique(array_column($list, 'order_id')));
    if ($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sqlItems = "
            SELECT
                oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                p.image1,
                s.id AS seller_id, s.name AS seller_name, s.email AS seller_email
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN users s ON s.id = oi.seller_id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.order_id, oi.id
        ";
        $itemRows = q($sqlItems, $orderIds)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itemRows as $it) {
            $itemsByOrder[(int)$it['order_id']][] = $it;
        }
    }
}

// ------ Get counts for tabs ------
$countSQLBase = "SELECT COUNT(*) as count FROM payments p ";
$cnt_pending = q1($countSQLBase . " WHERE p.status='pending'", [])['count'] ?? 0;
$cnt_approved = q1($countSQLBase . " WHERE p.status='approved'", [])['count'] ?? 0;
$cnt_rejected = q1($countSQLBase . " WHERE p.status='rejected'", [])['count'] ?? 0;

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
    <title>ตรวจสลิป/ชำระเงิน (Admin) - Web Shop Game</title>
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

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
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

        .form-control,
        .form-select {
            border-radius: 12px;
            padding: .8rem;
            border-color: var(--border);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
        }

        .segmented-control {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            background-color: #f8f9fa;
        }

        .segmented-control .btn {
            color: var(--muted-color);
            border: none;
            font-weight: 600;
            border-radius: 9px !important;
            padding: 0.5rem 1rem;
        }

        .segmented-control .btn.active {
            background-color: var(--brand);
            color: #fff;
            box-shadow: 0 4px 8px rgba(34, 197, 94, 0.2);
        }

        .segmented-control .btn:not(.active):hover {
            background-color: rgba(0, 0, 0, 0.03);
        }

        .segmented-control .badge {
            background-color: #e9ecef;
            color: var(--muted-color);
            font-size: 0.75rem;
        }

        .segmented-control .btn.active .badge {
            background-color: rgba(255, 255, 255, 0.2);
            color: #fff;
        }

        .slip-thumb {
            width: 100px;
            height: 140px;
            object-fit: cover;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .slip-thumb:hover {
            transform: scale(1.05);
        }

        .items-cell {
            min-width: 300px;
            max-width: 450px;
        }

        .item-row {
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            padding: .4rem 0;
            border-bottom: 1px dashed var(--border);
        }

        .item-row:last-child {
            border-bottom: 0;
        }

        .thumb {
            width: 48px;
            height: 36px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .seller {
            color: var(--muted-color);
            font-size: .8rem;
        }

        .price {
            white-space: nowrap;
            font-size: .9rem;
        }

        .item-details {
            flex-grow: 1;
        }

        .item-name {
            font-weight: 600;
            font-size: .9rem;
            margin-bottom: 0;
        }

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

        .modal-content {
            background-color: var(--card);
            border-color: var(--border);
            color: var(--text);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border);
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"> <span class="navbar-toggler-icon"></span> </button>
            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
                    <li class="nav-item"><a href="shop.php" class="nav-link fw-semibold">ร้านค้า</a></li>
                    <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): ?>
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
                                    <li><a class="dropdown-item active" href="admin_payments_review.php"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป</a></li>
                                    <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-person-check me-2"></i>ตรวจสอบคำขอผู้ขาย</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_users.php"><i class="bi bi-people me-2"></i>จัดการผู้ใช้</a></li>
                                    <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_categories.php"><i class="bi bi-tags me-2"></i>จัดการหมวดหมู่</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php elseif ($roleNav === 'seller'): ?>
                                    <li><a class="dropdown-item" href="seller_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Seller Dashboard</a></li>
                                    <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                                    <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-2"></i>ออเดอร์ร้านฉัน</a></li>
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
        <h3 class="mb-3 fw-bold"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป / การชำระเงิน</h3>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= h($flash['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form class="d-flex flex-wrap gap-2 mb-4" method="get">
            <div class="btn-group segmented-control flex-grow-1 flex-md-grow-0" role="group">
                <a href="?tab=pending<?= $q ? '&q=' . urlencode($q) : '' ?>" class="btn <?= $tab === 'pending' ? 'active' : '' ?>">
                    <i class="bi bi-hourglass-split me-1"></i> รอตรวจ <?= $cnt_pending > 0 ? '<span class="badge rounded-pill ms-1">' . $cnt_pending . '</span>' : '' ?>
                </a>
                <a href="?tab=approved<?= $q ? '&q=' . urlencode($q) : '' ?>" class="btn <?= $tab === 'approved' ? 'active' : '' ?>">
                    <i class="bi bi-check-circle me-1"></i> อนุมัติแล้ว <?= $cnt_approved > 0 ? '<span class="badge rounded-pill ms-1">' . $cnt_approved . '</span>' : '' ?>
                </a>
                <a href="?tab=rejected<?= $q ? '&q=' . urlencode($q) : '' ?>" class="btn <?= $tab === 'rejected' ? 'active' : '' ?>">
                    <i class="bi bi-x-circle me-1"></i> ปฏิเสธแล้ว <?= $cnt_rejected > 0 ? '<span class="badge rounded-pill ms-1">' . $cnt_rejected . '</span>' : '' ?>
                </a>
                <a href="?tab=all<?= $q ? '&q=' . urlencode($q) : '' ?>" class="btn <?= $tab === 'all' ? 'active' : '' ?>">
                    <i class="bi bi-list-ul me-1"></i> ทั้งหมด
                </a>
            </div>
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <div class="input-group flex-grow-1">
                <input class="form-control" name="q" placeholder="ค้นหา: เลขออเดอร์ / อีเมล / ชื่อผู้ซื้อ" value="<?= h($q) ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($q): ?>
                    <a href="?tab=<?= h($tab) ?>" class="btn btn-outline-danger" title="ล้างการค้นหา"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>

        <div class="card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>คำสั่งซื้อ</th>
                            <th>ผู้ซื้อ</th>
                            <th class="items-cell">สินค้า / ผู้ขาย</th>
                            <th class="text-end">ยอดรวม</th>
                            <th class="text-center">สถานะออเดอร์</th>
                            <th>สลิป</th>
                            <th class="text-end">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$list): ?>
                            <tr>
                                <td colspan="8" class="text-center text-secondary p-4"><i class="bi bi-info-circle me-2"></i>ไม่มีรายการชำระเงินที่ตรงเงื่อนไข</td>
                            </tr>
                            <?php else: foreach ($list as $i => $r):
                                list($order_status_text, $order_status_color, $order_status_classes) = getStatusInfo($r['order_status']);
                                list($pay_status_text, $pay_status_color, $pay_status_classes) = getStatusInfo($r['payment_status']);
                            ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <div class="fw-bold"><a href="order_detail.php?order_number=<?= h($r['order_number']) ?>" target="_blank" title="ดูรายละเอียดออเดอร์"><?= h($r['order_number']) ?></a></div>
                                        <div class="small text-secondary"><?= h($r['method'] ?? 'N/A') ?> · <?= date('d/m/y H:i', strtotime($r['paid_at'] ?? 'now')) ?></div>
                                        <span class="badge badge-status <?= $pay_status_classes ?> mt-1"><?= h($pay_status_text) ?></span>
                                        <?php if ($r['payment_status'] === 'rejected' && !empty($r['reject_reason'])): ?>
                                            <div class="small text-danger mt-1" title="เหตุผลที่ปฏิเสธ"><i class="bi bi-exclamation-triangle"></i> <?= h($r['reject_reason']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['reviewed_at'])): ?>
                                            <div class="small text-secondary mt-1" title="ตรวจเมื่อ">โดย ID <?= h($r['reviewed_by'] ?? '?') ?> @ <?= date('d/m/y H:i', strtotime($r['reviewed_at'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= h($r['buyer_name'] ?: '-') ?></div>
                                        <small class="text-secondary"><?= h($r['buyer_email'] ?: '-') ?></small>
                                    </td>
                                    <td class="items-cell">
                                        <?php $items = $itemsByOrder[(int)$r['order_id']] ?? []; ?>
                                        <?php if (!$items): ?>
                                            <span class="text-secondary">— ไม่มีรายการสินค้า —</span>
                                            <?php else: foreach ($items as $it): ?>
                                                <div class="item-row">
                                                    <img class="thumb" src="<?= h($it['image1'] ?: 'https://via.placeholder.com/48x36?text=N/A') ?>" alt="">
                                                    <div class="item-details">
                                                        <div class="d-flex justify-content-between">
                                                            <p class="item-name text-truncate" title="<?= h($it['name']) ?>"><?= h($it['name']) ?> × <?= (int)$it['quantity'] ?></p>
                                                            <div class="price">฿<?= number_format((float)$it['price'], 2) ?></div>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <div class="seller">ผู้ขาย: <?= h($it['seller_name'] ?: ('ID ' . $it['seller_id'])) ?></div>
                                                            <div class="seller">รวม: ฿<?= number_format((float)$it['subtotal'], 2) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                        <?php endforeach;
                                        endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">฿<?= number_format((float)$r['total_amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge badge-status <?= $order_status_classes ?>"><?= h($order_status_text) ?></span></td>
                                    <td>
                                        <?php if (!empty($r['slip_path'])): ?>
                                            <a href="<?= h($r['slip_path']) ?>" target="_blank" title="คลิกเพื่อดูสลิปขนาดเต็ม">
                                                <img class="slip-thumb" src="<?= h($r['slip_path']) ?>" alt="Slip Preview">
                                            </a>
                                        <?php else: ?>
                                            <span class="text-secondary small">(ไม่มีสลิป)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($r['payment_status'] === 'pending'): // Show buttons only for pending 
                                        ?>
                                            <div class="d-flex justify-content-end gap-2">
                                                <button class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#approve<?= $r['payment_id'] ?>" title="อนุมัติการชำระเงิน">
                                                    <i class="bi bi-check-lg"></i> อนุมัติ
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject<?= $r['payment_id'] ?>" title="ปฏิเสธการชำระเงิน">
                                                    <i class="bi bi-x-lg"></i> ปฏิเสธ
                                                </button>
                                            </div>
                                        <?php elseif ($r['payment_status'] === 'approved'): ?>
                                            <span class="badge badge-status bg-success-subtle text-success-emphasis"><i class="bi bi-check-circle me-1"></i>อนุมัติแล้ว</span>
                                        <?php elseif ($r['payment_status'] === 'rejected'): ?>
                                            <span class="badge badge-status bg-danger-subtle text-danger-emphasis"><i class="bi bi-x-circle me-1"></i>ปฏิเสธแล้ว</span>
                                        <?php else: ?>
                                            <span class="text-secondary small">—</span>
                                        <?php endif; ?>

                                        <div class="modal fade" id="approve<?= $r['payment_id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <form class="modal-content" method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><i class="bi bi-check-circle-fill text-success me-2"></i>ยืนยันอนุมัติสลิป</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$r['payment_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <p>คุณตรวจสอบสลิปของออเดอร์ <strong><?= h($r['order_number']) ?></strong> เรียบร้อยแล้วใช่หรือไม่?</p>
                                                        <p class="mb-0">เมื่ออนุมัติ สถานะออเดอร์จะเปลี่ยนเป็น <span class="badge bg-primary-subtle text-primary-emphasis">พักเงินกลาง</span></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                        <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> ยืนยันอนุมัติ</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <div class="modal fade" id="reject<?= $r['payment_id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <form class="modal-content" method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><i class="bi bi-x-circle-fill text-danger me-2"></i>ปฏิเสธสลิป</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$r['payment_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <div class="mb-3">
                                                            <label for="note<?= $r['payment_id'] ?>" class="form-label">เหตุผลการปฏิเสธ <span class="text-danger">*</span></label>
                                                            <textarea class="form-control" name="note" id="note<?= $r['payment_id'] ?>" rows="3" required placeholder="เช่น ยอดไม่ตรง, สลิปปลอม, รูปไม่ชัดเจน"></textarea>
                                                        </div>
                                                        <small class="text-secondary d-block">ออเดอร์ <strong><?= h($r['order_number']) ?></strong> จะถูกส่งกลับไปสถานะ <span class="badge bg-warning-subtle text-warning-emphasis">รอชำระเงิน</span></small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-lg"></i> ยืนยันปฏิเสธ</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับ Dashboard</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>