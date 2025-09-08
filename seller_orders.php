<?php
// seller_orders.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header("Location: login.php");
    exit;
}
if (($user['role'] ?? '') !== 'seller' && ($user['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ขายเท่านั้น'];
    header("Location: index.php");
    exit;
}
$seller_id = (int)$user['id'];

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// ตัวกรองสถานะที่หน้าแสดง (ค่าปริยาย: รอจัดส่ง)
$tab = $_GET['tab'] ?? 'awaiting';
$validTabs = ['awaiting', 'shipped', 'others'];
if (!in_array($tab, $validTabs, true)) $tab = 'awaiting';

// อัปโหลดหลักฐานจัดส่ง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_shipping') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF ไม่ถูกต้อง'];
        header("Location: seller_orders.php?tab=" . urlencode($tab));
        exit;
    }
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $tracking   = trim($_POST['tracking_no'] ?? '');
    if ($order_id <= 0 || $tracking === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'กรุณากรอกเลขพัสดุให้ครบถ้วน'];
        header("Location: seller_orders.php?tab=" . urlencode($tab));
        exit;
    }

    // ตรวจสิทธิ์ว่าออเดอร์นี้มีสินค้าของ seller จริง
    $own = $pdo->prepare("SELECT 1 
                        FROM order_items 
                        WHERE order_id=? AND seller_id=? LIMIT 1");
    $own->execute([$order_id, $seller_id]);
    if (!$own->fetchColumn()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์ในคำสั่งซื้อนี้'];
        header("Location: seller_orders.php?tab=" . urlencode($tab));
        exit;
    }

    // รับไฟล์
    $filePath = null;
    if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['proof']['tmp_name'];
        $size = (int)$_FILES['proof']['size'];
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไฟล์ต้องไม่เกิน 5MB'];
            header("Location: seller_orders.php?tab=" . urlencode($tab));
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        $map   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!isset($map[$mime])) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'รองรับ JPG/PNG/WebP/PDF เท่านั้น'];
            header("Location: seller_orders.php?tab=" . urlencode($tab));
            exit;
        }
        $dir = __DIR__ . "/uploads/shipping";
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'ship_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
        $dest = "$dir/$name";
        if (!move_uploaded_file($tmp, $dest)) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'อัปโหลดไฟล์ไม่สำเร็จ'];
            header("Location: seller_orders.php?tab=" . urlencode($tab));
            exit;
        }
        $filePath = "uploads/shipping/$name";
    }

    // บันทึก shipments + อัปเดตสถานะออเดอร์ (ฝั่งผู้ขายจัดส่งแล้ว)
    try {
        $pdo->beginTransaction();

        // บันทึก/เพิ่มแถวใน shipments
        // ถ้ายังไม่มีตาราง ให้สร้างตามนี้:
        // CREATE TABLE IF NOT EXISTS shipments(
        //   id INT AUTO_INCREMENT PRIMARY KEY,
        //   order_id INT NOT NULL,
        //   seller_id INT NOT NULL,
        //   tracking_no VARCHAR(100),
        //   slip_path VARCHAR(255),
        //   shipped_at DATETIME,
        //   created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        // );
        $pdo->prepare("INSERT INTO shipments(order_id,seller_id,tracking_no,slip_path,shipped_at)
                   VALUES(?,?,?,?,NOW())")
            ->execute([$order_id, $seller_id, $tracking, $filePath]);

        // อัปเดตสถานะออเดอร์เป็น shipped (ถ้าตอนนี้เป็น escrow_held)
        $pdo->prepare("UPDATE orders 
                   SET status = CASE WHEN status='escrow_held' THEN 'shipped' ELSE status END,
                       updated_at=NOW()
                   WHERE id=?")
            ->execute([$order_id]);

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อัปโหลดหลักฐานจัดส่งแล้ว ✅'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()];
    }
    header("Location: seller_orders.php?tab=shipped");
    exit;
}

// ดึงรายการออเดอร์ตามแท็บ
// ฐาน: ออเดอร์ที่มีสินค้า seller_id = ของเราคนเดียว
$baseSQL = "
  SELECT DISTINCT o.id, o.order_number, o.status, o.total_amount, o.created_at, u.name AS buyer_name, u.email AS buyer_email
  FROM orders o
  JOIN order_items oi ON oi.order_id=o.id
  JOIN users u ON u.id=o.user_id
  WHERE oi.seller_id = :sid
";

if ($tab === 'awaiting') {
    // “รอจัดส่ง” กรณี escrow ถือเงินแล้ว
    $sql = $baseSQL . " AND o.status IN ('escrow_held') ORDER BY o.created_at DESC";
} elseif ($tab === 'shipped') {
    $sql = $baseSQL . " AND o.status IN ('shipped') ORDER BY o.created_at DESC";
} else {
    // อื่น ๆ (completed / cancelled / awaiting_payment / awaiting_verification / paid …)
    $sql = $baseSQL . " AND o.status NOT IN ('escrow_held','shipped') ORDER BY o.created_at DESC";
}
$st = $pdo->prepare($sql);
$st->execute(['sid' => $seller_id]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

// นับ badge เล็กน้อย
$countAwaiting = $pdo->prepare($baseSQL . " AND o.status='escrow_held'");
$countAwaiting->execute(['sid' => $seller_id]);
$awaitingN = $countAwaiting->rowCount();

$countShipped = $pdo->prepare($baseSQL . " AND o.status='shipped'");
$countShipped->execute(['sid' => $seller_id]);
$shippedN = $countShipped->rowCount();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <title>ออเดอร์ของฉัน (ผู้ขาย)</title>
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

        .badge-status {
            font-size: .85rem
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22c55e, #0ea5e9);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0"><i class="bi bi-box-seam me-2"></i>ออเดอร์ของฉัน (ผู้ขาย)</h3>
            <a href="index.php" class="btn btn-outline-light"><i class="bi bi-house"></i> หน้าหลัก</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <ul class="nav nav-pills gap-2 mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'awaiting' ? 'active' : '' ?>" href="?tab=awaiting">
                    รอจัดส่ง <?= $awaitingN ? '<span class="badge bg-success ms-1">' . $awaitingN . '</span>' : '' ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'shipped' ? 'active' : '' ?>" href="?tab=shipped">
                    จัดส่งแล้ว <?= $shippedN ? '<span class="badge bg-info ms-1">' . $shippedN . '</span>' : '' ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'others' ? 'active' : '' ?>" href="?tab=others">อื่น ๆ</a>
            </li>
        </ul>

        <?php if (!$orders): ?>
            <div class="card p-4 text-secondary">ยังไม่มีออเดอร์ในหมวดนี้</div>
        <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-dark align-middle table-hover mb-0">
                        <thead class="table-success">
                            <tr>
                                <th>#</th>
                                <th>เลขคำสั่งซื้อ</th>
                                <th>ผู้ซื้อ</th>
                                <th class="text-end">ยอดรวม</th>
                                <th>สถานะ</th>
                                <th class="text-end">ดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $i => $o):
                                $s = $o['status'];
                                $color = match ($s) {
                                    'awaiting_payment'      => 'warning',
                                    'awaiting_verification' => 'secondary',
                                    'escrow_held'           => 'info',
                                    'shipped'               => 'primary',
                                    'completed'             => 'success',
                                    'cancelled'             => 'danger',
                                    default                 => 'light'
                                };
                            ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="fw-bold"><?= h($o['order_number']) ?></td>
                                    <td>
                                        <div class="small text-secondary"><?= h($o['buyer_name'] ?: $o['buyer_email']) ?></div>
                                    </td>
                                    <td class="text-end">฿<?= number_format((float)$o['total_amount'], 2) ?></td>
                                    <td><span class="badge bg-<?= $color ?> badge-status"><?= h($s) ?></span></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-light" href="order_detail.php?order_number=<?= urlencode($o['order_number']) ?>">
                                            <i class="bi bi-eye"></i> ดูรายละเอียด
                                        </a>
                                        <?php if ($s === 'escrow_held'): ?>
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#mdlShip<?= (int)$o['id'] ?>">
                                                <i class="bi bi-truck"></i> อัปโหลดหลักฐานจัดส่ง
                                            </button>
                                        <?php endif; ?>

                                        <!-- Modal อัปโหลดหลักฐานจัดส่ง -->
                                        <div class="modal fade" id="mdlShip<?= (int)$o['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form class="modal-content" method="post" enctype="multipart/form-data">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">อัปโหลดหลักฐานจัดส่ง</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body vstack gap-2">
                                                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                        <input type="hidden" name="action" value="upload_shipping">
                                                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                        <label class="form-label">เลขพัสดุ / Tracking No.</label>
                                                        <input type="text" name="tracking_no" class="form-control" required placeholder="เช่น TH1234567890">
                                                        <label class="form-label mt-2">ไฟล์หลักฐาน (รูป/ PDF สูงสุด 5MB)</label>
                                                        <input type="file" name="proof" class="form-control" accept="image/*,.pdf">
                                                        <small class="text-secondary">เมื่อบันทึกแล้วสถานะคำสั่งซื้อจะเปลี่ยนเป็น <span class="text-info">shipped</span></small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button class="btn btn-success"><i class="bi bi-upload"></i> บันทึก</button>
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                        <!-- /Modal -->
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-3">
            <a href="index.php" class="btn btn-outline-light"><i class="bi bi-house"></i> กลับหน้าหลัก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>