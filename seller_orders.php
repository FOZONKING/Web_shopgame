<?php
// seller_orders.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php'; // Includes PDO connection and helper functions like h(), q(), q1(), getStatusInfo()

/* ---------- Authentication & Authorization ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header("Location: login.php");
    exit;
}
// Allow only 'seller' or 'admin' roles
if (!in_array($user['role'] ?? '', ['seller', 'admin'], true)) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ขายหรือแอดมินเท่านั้น'];
    header("Location: index.php");
    exit;
}
$seller_id = (int)$user['id']; // Assume seller ID is user ID for sellers

/* ---------- CSRF Protection ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* ---------- Handle POST Request: Upload Shipping Proof ---------- */
$flash = $_SESSION['flash'] ?? null; // Get flash message early
unset($_SESSION['flash']); // Clear flash message immediately

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_shipping') {
    $tab_redirect = $_GET['tab'] ?? 'awaiting'; // For redirecting back to the correct tab

    // 1. Validate CSRF Token
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    } else {
        // 2. Get and Validate Inputs
        $order_id  = (int)($_POST['order_id'] ?? 0);
        $tracking  = trim($_POST['tracking_no'] ?? '');
        $file      = $_FILES['proof'] ?? null;

        if ($order_id <= 0 || $tracking === '') {
            $flash = ['type' => 'danger', 'msg' => 'กรุณากรอกหมายเลขออเดอร์และเลขพัสดุให้ครบถ้วน'];
        } else {
            // 3. Check Permissions (Seller owns an item in this order and order status is correct)
            $order_to_ship = q1("SELECT status FROM orders WHERE id = ?", [$order_id]);
            $can_ship_status = $order_to_ship && $order_to_ship['status'] === 'escrow_held'; // Only ship if escrow held
            $own = q1("SELECT 1 FROM order_items WHERE order_id=? AND seller_id=? LIMIT 1", [$order_id, $seller_id]);

            if (!$own) {
                $flash = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์จัดการคำสั่งซื้อนี้'];
            } elseif (!$can_ship_status) {
                $flash = ['type' => 'warning', 'msg' => 'ไม่สามารถแจ้งจัดส่งสำหรับคำสั่งซื้อในสถานะนี้ได้'];
            } else {
                // 4. Handle File Upload (if provided)
                $filePath = null;
                $uploadError = false;
                if ($file && $file['error'] === UPLOAD_ERR_OK) {
                    $tmp  = $file['tmp_name'];
                    $size = (int)$file['size'];
                    $mime = mime_content_type($tmp); // Use modern function
                    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

                    if ($size <= 0 || $size > 5 * 1024 * 1024) { // 5MB Limit
                        $flash = ['type' => 'danger', 'msg' => 'ไฟล์หลักฐานต้องมีขนาดไม่เกิน 5MB'];
                        $uploadError = true;
                    } elseif (!isset($map[$mime])) {
                        $flash = ['type' => 'danger', 'msg' => 'รองรับเฉพาะไฟล์ JPG, PNG, WebP หรือ PDF เท่านั้น'];
                        $uploadError = true;
                    } else {
                        $dir = __DIR__ . "/uploads/shipping";
                        if (!is_dir($dir)) @mkdir($dir, 0775, true);
                        $name = 'ship_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
                        $dest = "$dir/$name";
                        if (!move_uploaded_file($tmp, $dest)) {
                            $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์'];
                            $uploadError = true;
                        } else {
                            $filePath = "uploads/shipping/$name"; // Relative path for storage
                        }
                    }
                } // End file handling

                // 5. Update Database if upload was successful (or no file was needed/uploaded)
                if (!$uploadError) {
                    try {
                        $pdo->beginTransaction();
                        // Insert shipping record
                        q(
                            "INSERT INTO shipments(order_id, seller_id, tracking_no, slip_path, shipped_at) VALUES(?, ?, ?, ?, NOW())",
                            [$order_id, $seller_id, $tracking, $filePath]
                        );

                        // Update order status to 'shipped' (was already checked to be 'escrow_held')
                        q(
                            "UPDATE orders SET status = 'shipped', updated_at = NOW() WHERE id = ? AND status = 'escrow_held'",
                            [$order_id]
                        );

                        $pdo->commit();
                        $flash = ['type' => 'success', 'msg' => 'บันทึกข้อมูลการจัดส่งเรียบร้อย ✅'];
                        $tab_redirect = 'shipped'; // Redirect to shipped tab after success
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        // Log error $e->getMessage() for debugging
                        $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล'];
                    }
                } // End DB Update
            } // End Permission & Status Check
        } // End Input Validation
    } // End CSRF Check

    // Redirect after processing POST request
    $_SESSION['flash'] = $flash; // Store flash message back into session before redirect
    header("Location: seller_orders.php?tab=" . urlencode($tab_redirect));
    exit;
} // End POST handling

/* ---------- Fetch Data for Display ---------- */

// Filter tab validation
$tab = $_GET['tab'] ?? 'awaiting';
$validTabs = ['awaiting', 'shipped', 'others'];
if (!in_array($tab, $validTabs, true)) $tab = 'awaiting';

// Base SQL Query for orders containing items from this seller
$baseSQL = "
    SELECT DISTINCT o.id, o.order_number, o.status, o.total_amount, o.created_at, u.name AS buyer_name, u.email AS buyer_email
    FROM orders o
    JOIN order_items oi ON oi.order_id=o.id
    JOIN users u ON u.id=o.user_id
    WHERE oi.seller_id = :sid
";
// Optimized Count SQL Query
$countSQL = "SELECT COUNT(DISTINCT o.id) as count FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.seller_id = :sid";


// Determine status filter based on the selected tab
$params = ['sid' => $seller_id];
if ($tab === 'awaiting') {
    $sql = $baseSQL . " AND o.status = 'escrow_held'";
} elseif ($tab === 'shipped') {
    $sql = $baseSQL . " AND o.status = 'shipped'";
} else {
    $sql = $baseSQL . " AND o.status NOT IN ('escrow_held', 'shipped')";
}
$sql .= " ORDER BY o.created_at DESC"; // Add ORDER BY at the end

// Fetch orders for the current tab
$orders = q($sql, $params)->fetchAll();

// Count for badges (Optimized)
$awaitingN = q1($countSQL . " AND o.status='escrow_held'", ['sid' => $seller_id])['count'] ?? 0;
$shippedN = q1($countSQL . " AND o.status='shipped'", ['sid' => $seller_id])['count'] ?? 0;
// *** นับจำนวน Others ด้วย ***
$othersN = q1($countSQL . " AND o.status NOT IN ('escrow_held', 'shipped')", ['sid' => $seller_id])['count'] ?? 0;

// --- [โค้ด NAVBAR Data] ---
$userNav = $user;
$uidNav = $seller_id;
$roleNav = $user['role'];
$cartCountNav = 0;
// --- [จบโค้ด NAVBAR Data] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ออเดอร์ของฉัน (ผู้ขาย) - Web Shop Game</title>
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

        .badge-status {
            font-size: .8rem;
            font-weight: 600;
            padding: .4em .7em;
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

        /* --- [ดีไซน์ใหม่] Segmented Control Tabs (Light Theme) --- */
        .segmented-control {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            background-color: #f8f9fa;
            /* Light gray background */
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

        /* --- [จบ ดีไซน์ใหม่] --- */

        .table {
            --bs-table-border-color: var(--border);
        }

        /* Use theme border color */
        .table thead {
            background-color: #f8f9fa;
            color: #495057;
        }

        /* Light header */
        .table th {
            font-weight: 600;
        }

        .table tbody tr:hover {
            background-color: #f0fdf4;
        }

        /* Light green hover */
        .modal-content {
            background-color: var(--card);
            border-color: var(--border);
            color: var(--text);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border);
        }

        .form-control {
            background-color: #fff;
            border-color: var(--border);
            color: var(--text);
        }

        .form-control:focus {
            background-color: #fff;
            border-color: var(--brand);
            box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
            color: var(--text);
        }

        .form-label {
            font-weight: 600;
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
                        <li class="nav-item">
                            <a href="cart.php" class="btn btn-outline-secondary btn-sm position-relative">
                                <i class="bi bi-cart3 fs-5"></i>
                                <?php if ($cartCountNav > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCountNav ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endif; ?>
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
                                    <li><a class="dropdown-item" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                                    <li><a class="dropdown-item" href="admin_payments_review.php"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป</a></li>
                                    <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-person-check me-2"></i>ตรวจสอบคำขอผู้ขาย</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_users.php"><i class="bi bi-people me-2"></i>จัดการผู้ใช้</a></li>
                                    <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php endif; ?>
                                <?php if ($roleNav === 'seller'): ?>
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
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h3 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i>ออเดอร์ของร้านค้า</h3>
            <a href="manage_products.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> จัดการสินค้า</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= h($flash['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-center mb-4">
            <div class="btn-group segmented-control" role="group" aria-label="Order Status Tabs">
                <a href="?tab=awaiting" class="btn <?= $tab === 'awaiting' ? 'active' : '' ?>">
                    <i class="bi bi-hourglass-split me-1"></i> รอจัดส่ง <?= $awaitingN > 0 ? '<span class="badge rounded-pill ms-1">' . $awaitingN . '</span>' : '' ?>
                </a>
                <a href="?tab=shipped" class="btn <?= $tab === 'shipped' ? 'active' : '' ?>">
                    <i class="bi bi-truck me-1"></i> จัดส่งแล้ว <?= $shippedN > 0 ? '<span class="badge rounded-pill ms-1">' . $shippedN . '</span>' : '' ?>
                </a>
                <a href="?tab=others" class="btn <?= $tab === 'others' ? 'active' : '' ?>">
                    <i class="bi bi-list-ul me-1"></i> อื่น ๆ <?= $othersN > 0 ? '<span class="badge rounded-pill ms-1">' . $othersN . '</span>' : '' ?>
                </a>
            </div>
        </div>
        <?php if (!$orders): ?>
            <div class="card p-4 text-center text-secondary">
                <i class="bi bi-inbox fs-1 mb-2"></i>
                ยังไม่มีออเดอร์ในหมวดนี้
            </div>
        <?php else: ?>
            <div class="card overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>เลขคำสั่งซื้อ</th>
                                <th>ผู้ซื้อ</th>
                                <th class="text-end">ยอดรวม(ร้านคุณ)</th>
                                <th class="text-center">สถานะ</th>
                                <th>วันที่</th>
                                <th class="text-end">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $i => $o):
                                list($status_text, $status_color, $status_classes) = getStatusInfo($o['status']);
                                $seller_order_total = q1("SELECT SUM(subtotal) as seller_total FROM order_items WHERE order_id=? AND seller_id=?", [$o['id'], $seller_id])['seller_total'] ?? 0.0;
                            ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="fw-bold"><?= h($o['order_number']) ?></td>
                                    <td>
                                        <div><?= h($o['buyer_name'] ?: $o['buyer_email']) ?></div>
                                        <small class="text-secondary"><?= h($o['buyer_email']) ?></small>
                                    </td>
                                    <td class="text-end text-success fw-semibold">฿<?= number_format((float)$seller_order_total, 2) ?></td>
                                    <td class="text-center"><span class="badge badge-status <?= $status_classes /* ใช้คลาสสำหรับธีมสว่าง */ ?>"><?= h($status_text) ?></span></td>
                                    <td><?= date('d/m/y H:i', strtotime($o['created_at'])) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary me-1" href="order_detail.php?order_number=<?= urlencode($o['order_number']) ?>" title="ดูรายละเอียด">
                                            <i class="bi bi-eye"></i> <span class="d-none d-md-inline">รายละเอียด</span>
                                        </a>
                                        <?php if ($o['status'] === 'escrow_held'): ?>
                                            <button class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#mdlShip<?= (int)$o['id'] ?>" title="แจ้งจัดส่ง"> <i class="bi bi-truck"></i> <span class="d-none d-md-inline">แจ้งจัดส่ง</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if ($o['status'] === 'escrow_held'): ?>
                                    <div class="modal fade" id="mdlShip<?= (int)$o['id'] ?>" tabindex="-1" aria-labelledby="mdlShipLabel<?= (int)$o['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <form class="modal-content" method="post" enctype="multipart/form-data">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="mdlShipLabel<?= (int)$o['id'] ?>"><i class="bi bi-truck me-2"></i>แจ้งจัดส่ง - ออเดอร์ #<?= h($o['order_number']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body vstack gap-3">
                                                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                    <input type="hidden" name="action" value="upload_shipping">
                                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                    <div>
                                                        <label for="tracking_no_<?= (int)$o['id'] ?>" class="form-label">เลขพัสดุ / Tracking No. / ข้อความยืนยัน <span class="text-danger">*</span></label>
                                                        <input type="text" name="tracking_no" id="tracking_no_<?= (int)$o['id'] ?>" class="form-control" required placeholder="เช่น TH1234567890 หรือ 'ส่งในเกมแล้ว'">
                                                    </div>
                                                    <div>
                                                        <label for="proof_<?= (int)$o['id'] ?>" class="form-label">ไฟล์หลักฐาน (ถ้ามี - รูป/PDF สูงสุด 5MB)</label>
                                                        <input type="file" name="proof" id="proof_<?= (int)$o['id'] ?>" class="form-control" accept="image/*,.pdf">
                                                        <small class="text-secondary d-block mt-1">อัปโหลดรูปภาพพัสดุ, ใบเสร็จ, ภาพหน้าจอในเกม หรือหลักฐานอื่นๆ (ถ้ามี)</small>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                    <button type="submit" class="btn btn-brand"><i class="bi bi-send-check-fill me-1"></i> ยืนยันการจัดส่ง</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>