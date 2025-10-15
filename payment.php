<?php
// payment.php  — Buyer uploads slip -> awaiting_verification (admin review)
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
if (!isset($_SESSION['user'])) {
  header("Location: login.php");
  exit;
}
$uid = (int)($_SESSION['user']['id'] ?? 0);

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* รับเลขออเดอร์ */
$code = trim($_GET['order_number'] ?? '');
if ($code === '') {
  http_response_code(400);
  exit('missing order_number');
}

/* ดึงคำสั่งซื้อ (เฉพาะของผู้ใช้) */
$order = q1("SELECT * FROM orders WHERE order_number=? AND user_id=?", [$code, $uid]);
if (!$order) {
  http_response_code(404);
  exit('Order not found');
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
    http_response_code(400);
    exit('Bad CSRF');
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
  if ($size <= 0 || $size > 5 * 1024 * 1024) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไฟล์สลิปต้องไม่เกิน 5MB'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
  $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  if (!isset($map[$mime])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'รองรับเฉพาะ JPG/PNG/WebP/GIF'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }

  $dir = __DIR__ . "/uploads/slips";
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
  $dest = "$dir/$name";
  if (!move_uploaded_file($tmp, $dest)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'อัปโหลดสลิปไม่สำเร็จ'];
    header("Location: payment.php?order_number=" . urlencode($code));
    exit;
  }
  $slipPath = "uploads/slips/$name";

  try {
    $pdo->beginTransaction();

    // บันทึกการชำระ (pending)
    q("INSERT INTO payments(order_id, method, slip_path, paid_at, status)
       VALUES(?, ?, ?, NOW(), 'pending')", [$order['id'], 'bank_transfer', $slipPath]);

    // เปลี่ยนสถานะออเดอร์ -> awaiting_verification (รอแอดมินตรวจสลิป)
    q("UPDATE orders SET status='awaiting_verification', updated_at=NOW()
       WHERE id=? AND status='awaiting_payment'", [$order['id']]);

    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อัปโหลดสลิปแล้ว ✅ กำลังรอแอดมินตรวจสอบ'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'บันทึกการชำระเงินไม่สำเร็จ: ' . $e->getMessage()];
  }

  header("Location: payment.php?order_number=" . urlencode($code));
  exit;
}

/* badge สถานะสวย ๆ */
function statusBadge($status)
{
  $map = [
    'awaiting_payment'      => ['รอชำระเงิน', 'warning'],
    'awaiting_verification' => ['รอตรวจสลิป (แอดมิน)', 'secondary'],
    'escrow_held'           => ['พักเงินกับ Escrow', 'info'],
    'shipped'               => ['ผู้ขายจัดส่งแล้ว', 'primary'],
    'completed'             => ['สำเร็จ', 'success'],
    'cancelled'             => ['ยกเลิก', 'dark'],
  ];
  [$text, $color] = $map[$status] ?? [$status, 'light'];
  return '<span class="badge bg-' . $color . '">' . $text . '</span>';
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ชำระเงิน (Escrow) - <?= h($order['order_number']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --bg: #0b0f14;
      --card: #0f1620;
      --muted: #94a3b8;
      --border: rgba(148, 163, 184, .18);
      --grad: linear-gradient(90deg, #16a34a, #22c55e);
    }

    body {
      background: var(--bg);
      color: #e5e7eb;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
    }

    .page-head {
      background: radial-gradient(1200px 300px at 20% -10%, rgba(34, 197, 94, .25), transparent),
        radial-gradient(800px 200px at 90% -20%, rgba(34, 197, 94, .15), transparent);
      border-bottom: 1px solid var(--border);
    }

    .order-chip {
      font-weight: 700;
      color: #22c55e;
    }

    .btn-pay {
      background: var(--grad);
      border: none;
      font-weight: 700;
    }

    .qr-box {
      width: 180px;
      height: 180px;
      border: 1px dashed var(--border);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--muted);
    }

    .slip-thumb {
      max-width: 100%;
      border-radius: 12px;
      border: 1px solid var(--border);
    }

    .table td,
    .table th {
      vertical-align: middle;
    }
  </style>
</head>

<body>
  <div class="page-head">
    <div class="container py-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h3 class="mb-1">ชำระเงินผ่าน Escrow</h3>
        <div class="text-secondary">เลขคำสั่งซื้อ: <span class="order-chip"><?= h($order['order_number']) ?></span></div>
      </div>
      <div class="text-end">
        <div class="mb-1">สถานะ: <?= statusBadge($order['status']) ?></div>
        <div>ยอดชำระ: <strong class="text-success">฿<?= number_format($order['total_amount'], 2) ?></strong></div>
      </div>
    </div>
  </div>

  <div class="container py-4">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header">รายการสินค้า</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
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
                    <td><?= h($it['name']) ?></td>
                    <td class="text-end">฿<?= number_format($it['price'], 2) ?></td>
                    <td class="text-end"><?= (int)$it['quantity'] ?></td>
                    <td class="text-end">฿<?= number_format($it['subtotal'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="table-dark">
                  <th colspan="3" class="text-end">ยอดรวม</th>
                  <th class="text-end">฿<?= number_format($order['total_amount'], 2) ?></th>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <?php if ($order['status'] === 'awaiting_payment'): ?>
          <form class="card mt-3" method="post" enctype="multipart/form-data">
            <div class="card-header">อัปโหลดสลิปโอนเงิน</div>
            <div class="card-body vstack gap-2">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="file" class="form-control" name="slip" accept="image/*" required>
              <small class="text-secondary">รองรับ JPG/PNG/WebP/GIF ขนาดไม่เกิน 5MB</small>
              <button class="btn btn-pay mt-2"><i class="bi bi-shield-check me-1"></i> ส่งสลิป (รอแอดมินตรวจ)</button>
            </div>
          </form>
        <?php elseif ($order['status'] === 'awaiting_verification'): ?>
          <div class="card mt-3">
            <div class="card-body">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-hourglass-split"></i>
                <div>ส่งสลิปแล้ว — <strong>กำลังรอแอดมินตรวจสอบ</strong></div>
              </div>
              <?php if ($payment && !empty($payment['slip_path'])): ?>
                <img class="slip-thumb" src="<?= h($payment['slip_path']) ?>" alt="payment slip">
                <div class="mt-2 text-secondary small">เวลาอัปโหลด: <?= h($payment['paid_at'] ?? '') ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($order['status'] === 'escrow_held'): ?>
          <div class="card mt-3">
            <div class="card-body">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-check2-circle text-success"></i>
                <div>ตรวจสอบแล้ว ✅ ระบบพักเงินไว้กับ Escrow</div>
              </div>
              <?php if ($payment && !empty($payment['slip_path'])): ?>
                <img class="slip-thumb" src="<?= h($payment['slip_path']) ?>" alt="payment slip">
                <div class="mt-2 text-secondary small">วิธี: <?= h($payment['method'] ?? 'bank_transfer') ?> • เวลา: <?= h($payment['paid_at'] ?? '') ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-5">
        <div class="card">
          <div class="card-header">ช่องทางการชำระเงิน</div>
          <div class="card-body">
            <div class="d-flex align-items-center gap-3">
              <div class="qr-box p-1 bg-white">
                <img src="uploads/qr/myqr.png" alt="QR PromptPay" style="max-width:100%; max-height:100%;">
              </div>
              <div>
                <div>บัญชี: <strong>ธรณธันย์ ศิริพรรณา</strong></div>
                <div>ธนาคาร: <strong>กรุงไทย</strong></div>
                <div>เลขบัญชี: <strong>123-4-56789-0</strong></div>
                <small class="text-secondary">ชำระแล้วอัปโหลดสลิปในฟอร์มด้านซ้าย</small>
              </div>
            </div>
            <hr>
            <small class="text-secondary">หมายเหตุ: เงินจะถูก “พักไว้กับระบบ Escrow” หลังแอดมินตรวจสอบและอนุมัติสลิปแล้ว</small>
          </div>
        </div>

        <div class="d-grid mt-3">
          <a class="btn btn-outline-light" href="order_detail.php?order_number=<?= urlencode($order['order_number']) ?>">ดูรายละเอียดคำสั่งซื้อ</a>
          <a class="btn btn-outline-light mt-2" href="shop.php">เลือกซื้อสินค้าต่อ</a>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>