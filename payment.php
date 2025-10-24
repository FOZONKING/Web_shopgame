<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

/* Helpers */
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q($sql, $p = [])
{
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st;
}
function q1($sql, $p = [])
{
  return q($sql, $p)->fetch(PDO::FETCH_ASSOC);
}

/* ต้องล็อกอิน */
$user = $_SESSION['user'] ?? null;
if (!$user) {
  header("Location: login.php");
  exit;
}
$uid = (int)$user['id'];

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* รับเลขออเดอร์ */
$code = trim($_GET['order_number'] ?? '');
if ($code === '') {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบหมายเลขออเดอร์'];
  header("Location: order_list.php"); // ส่งกลับไปหน้า list ดีกว่า
  exit;
}

/* ดึงคำสั่งซื้อ (เฉพาะของผู้ใช้) */
$order = q1("SELECT * FROM orders WHERE order_number=? AND user_id=?", [$code, $uid]);
if (!$order) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบคำสั่งซื้อ หรือคุณไม่มีสิทธิ์เข้าถึง'];
  header("Location: order_list.php");
  exit;
}

/* รายการสินค้า */
$items = q("SELECT name, price, quantity, subtotal FROM order_items WHERE order_id=?", [$order['id']])->fetchAll(PDO::FETCH_ASSOC);

/* สลิปล่าสุด (ถ้ามี) */
$payment = q1("SELECT * FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1", [$order['id']]);

/* Flash */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* Upload slip -> awaiting_verification */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }

  if ($order['status'] !== 'awaiting_payment') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ออเดอร์นี้ไม่อยู่ในสถานะที่ชำระเงินได้'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }

  if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'กรุณาอัปโหลดสลิป'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }

  $tmp  = $_FILES['slip']['tmp_name'];
  $size = (int)$_FILES['slip']['size'];
  if ($size <= 0 || $size > 5 * 1024 * 1024) { // 5MB Limit
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไฟล์สลิปต้องมีขนาดไม่เกิน 5MB'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }
  $mime = mime_content_type($tmp); // ใช้ function ที่ modern กว่า
  $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  if (!isset($map[$mime])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'รองรับเฉพาะไฟล์รูปภาพ JPG, PNG, WebP, GIF เท่านั้น'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }

  $dir = __DIR__ . "/uploads/slips";
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
  $dest = "$dir/$name";
  if (!move_uploaded_file($tmp, $dest)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์สลิป'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }
  $slipPath = "uploads/slips/$name"; // เก็บ path แบบ relative

  try {
    $pdo->beginTransaction();
    q("INSERT INTO payments(order_id, method, slip_path, paid_at, status) VALUES(?, ?, ?, NOW(), 'pending')", [$order['id'], 'bank_transfer', $slipPath]);
    q("UPDATE orders SET status='awaiting_verification', updated_at=NOW() WHERE id=? AND status='awaiting_payment'", [$order['id']]);
    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อัปโหลดสลิปเรียบร้อย ✅ กรุณารอแอดมินตรวจสอบ'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()];
  }

  header("Location: payment.php?order_number=" . urlencode($code));
  exit;
}

/* [อัปเดต] badge สถานะสวย ๆ + แปลไทย */
function statusBadge($status)
{
  $map = [
    'pending'               => ['รอดำเนินการ', 'secondary'],
    'awaiting_payment'      => ['รอชำระเงิน', 'warning'],
    'awaiting_verification' => ['รอตรวจสลิป', 'info'], // เปลี่ยนสีเป็น info ให้ต่าง
    'escrow_held'           => ['พักเงินกลาง', 'primary'],
    'shipped'               => ['จัดส่งแล้ว', 'primary'],
    'completed'             => ['สำเร็จ', 'success'],
    'cancelled'             => ['ยกเลิก', 'danger'],
  ];
  [$text, $color] = $map[$status] ?? [$status, 'secondary']; // default ใช้ secondary
  // ใช้คลาส badge-status เดิม เพิ่มเติมคือสีข้อความและพื้นหลังสำหรับธีมสว่าง
  $extra_classes = match ($color) {
    'secondary' => 'bg-light text-dark',
    'warning'   => 'bg-warning-subtle text-warning-emphasis',
    'info'      => 'bg-info-subtle text-info-emphasis',
    'primary'   => 'bg-primary-subtle text-primary-emphasis',
    'success'   => 'bg-success-subtle text-success-emphasis',
    'danger'    => 'bg-danger-subtle text-danger-emphasis',
    default     => 'bg-light text-dark'
  };
  return '<span class="badge badge-status ' . $extra_classes . '">' . h($text) . '</span>';
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
  <title>ชำระเงิน - <?= h($order['order_number']) ?></title>
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
      padding: .8rem 1.5rem;
    }

    .btn-brand:hover {
      background: var(--brand-dark);
      border-color: var(--brand-dark);
    }

    .page-head {
      background-color: #e8f9f0;
      /* เปลี่ยนพื้นหลังส่วนหัว */
      border-bottom: 1px solid var(--border);
    }

    .order-chip {
      font-weight: 700;
      color: var(--brand-dark);
    }

    .badge-status {
      font-size: .85rem;
      font-weight: 600;
      padding: .4em .7em;
    }

    .qr-box {
      width: 180px;
      height: 180px;
      border: 1px solid var(--border);
      /* ใช้เส้นขอบสีอ่อน */
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #fff;
      /* พื้นหลัง QR ขาว */
    }

    .slip-thumb {
      max-width: 100%;
      max-height: 250px;
      /* จำกัดความสูง */
      object-fit: contain;
      border-radius: 12px;
      border: 1px solid var(--border);
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

    .table .table-light th {
      background-color: #f8f9fa;
    }

    /* สีแถว Total */
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

  <div class="page-head">
    <div class="container py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h3 class="mb-1 fw-bold">ชำระเงิน Escrow</h3>
        <div class="text-secondary">เลขคำสั่งซื้อ: <span class="order-chip"><?= h($order['order_number']) ?></span></div>
      </div>
      <div class="text-end">
        <div class="mb-1">สถานะ: <?= statusBadge($order['status']) ?></div>
        <div>ยอดชำระ: <strong class="text-success fs-5">฿<?= number_format($order['total_amount'], 2) ?></strong></div>
      </div>
    </div>
  </div>

  <div class="container py-4">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= h($flash['msg']) ?>
        <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header fw-semibold">รายการสินค้าในออเดอร์</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th>สินค้า</th>
                    <th class="text-end">ราคา</th>
                    <th class="text-center">จำนวน</th>
                    <th class="text-end">รวม</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $it): ?>
                    <tr>
                      <td><?= h($it['name']) ?></td>
                      <td class="text-end">฿<?= number_format($it['price'], 2) ?></td>
                      <td class="text-center"><?= (int)$it['quantity'] ?></td>
                      <td class="text-end">฿<?= number_format($it['subtotal'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="table-light">
                    <th colspan="3" class="text-end">ยอดรวมสุทธิ</th>
                    <th class="text-end fs-5">฿<?= number_format($order['total_amount'], 2) ?></th>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <?php if ($order['status'] === 'awaiting_payment'): ?>
          <form class="card" method="post" enctype="multipart/form-data">
            <div class="card-header fw-semibold">อัปโหลดสลิปโอนเงิน</div>
            <div class="card-body vstack gap-2">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="file" class="form-control" name="slip" accept="image/*" required>
              <small class="text-secondary">รองรับ JPG, PNG, WebP, GIF ขนาดไม่เกิน 5MB</small>
              <button type="submit" class="btn btn-brand mt-2"><i class="bi bi-cloud-arrow-up-fill me-1"></i> ยืนยันการชำระเงิน</button>
            </div>
          </form>
        <?php elseif ($order['status'] === 'awaiting_verification'): ?>
          <div class="card">
            <div class="card-header fw-semibold">สถานะสลิป</div>
            <div class="card-body text-center">
              <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
                <i class="bi bi-hourglass-split fs-4 text-info"></i>
                <div class="fs-5">ส่งสลิปแล้ว - <strong>กำลังรอตรวจสอบ</strong></div>
              </div>
              <?php if ($payment && !empty($payment['slip_path'])): ?>
                <img class="slip-thumb mb-2" src="<?= h($payment['slip_path']) ?>" alt="Payment Slip">
                <div class="text-secondary small">เวลาอัปโหลด: <?= date('d/m/Y H:i', strtotime($payment['paid_at'] ?? 'now')) ?></div>
              <?php else: ?>
                <p class="text-danger">ไม่พบข้อมูลสลิป</p>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif (in_array($order['status'], ['escrow_held', 'shipped', 'completed'])): ?>
          <div class="card">
            <div class="card-header fw-semibold">สถานะสลิป</div>
            <div class="card-body text-center">
              <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
                <i class="bi bi-check2-circle fs-4 text-success"></i>
                <div class="fs-5">ชำระเงินเรียบร้อยแล้ว</div>
              </div>
              <?php if ($payment && !empty($payment['slip_path'])): ?>
                <img class="slip-thumb mb-2" src="<?= h($payment['slip_path']) ?>" alt="Payment Slip">
                <div class="text-secondary small">วิธี: <?= h($payment['method'] == 'bank_transfer' ? 'โอนเงิน' : $payment['method']) ?> • เวลา: <?= date('d/m/Y H:i', strtotime($payment['paid_at'] ?? 'now')) ?></div>
              <?php else: ?>
                <p class="text-secondary">ชำระเงินแล้ว (ไม่พบข้อมูลสลิป)</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-header fw-semibold">ช่องทางการชำระเงิน</div>
          <div class="card-body">
            <div class="d-flex align-items-center gap-3">
              <div class="qr-box p-1">
                <img src="uploads/qr/myqr.png" alt="QR PromptPay" style="max-width:100%; max-height:100%;">
              </div>
              <div>
                <div>บัญชี: <strong>ธรณธันย์ ศิริพรรณา</strong></div>
                <div>ธนาคาร: <strong>กรุงไทย</strong></div>
                <div>เลขบัญชี: <strong>123-4-56789-0</strong></div>
                <small class="text-secondary d-block mt-1">สแกน QR หรือโอนเข้าบัญชี แล้วอัปโหลดสลิป</small>
              </div>
            </div>
            <hr>
            <small class="text-secondary">หมายเหตุ: เงินจะถูกพักไว้กับระบบ Escrow หลังจากแอดมินตรวจสอบสลิปเรียบร้อยแล้ว</small>
          </div>
        </div>

        <div class="d-grid gap-2">
          <a class="btn btn-outline-secondary" href="order_detail.php?order_number=<?= urlencode($order['order_number']) ?>"><i class="bi bi-receipt"></i> ดูรายละเอียดคำสั่งซื้อ</a>
          <a class="btn btn-outline-secondary" href="order_list.php"><i class="bi bi-list-ul"></i> ดูคำสั่งซื้อทั้งหมด</a>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>