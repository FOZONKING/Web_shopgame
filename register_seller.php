<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

// --- Helpers ---
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

// ต้องล็อกอิน
$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: login.php');
  exit;
}
$user_id = (int)$user['id'];

// สถานะ KYC ล่าสุดของผู้ใช้
$latest = q1("SELECT * FROM kycs WHERE user_id=? ORDER BY created_at DESC LIMIT 1", [$user_id]);

$msg = null;
$ok = false;
$old_data = []; // เก็บค่าเก่า

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ถ้ามี KYC ที่ยัง pending หรือถูกอนุมัติแล้ว ไม่ให้ยื่นซ้ำ
  if ($latest && in_array($latest['status'], ['pending', 'approved'], true)) {
    $msg = ['type' => 'warning', 'text' => 'คุณมีคำขอ KYC ที่กำลังรอการตรวจสอบหรือได้รับการอนุมัติไปแล้ว'];
  } else {
    $phone = trim($_POST['phone'] ?? '');
    $idno  = trim($_POST['id_card_number'] ?? '');
    $bank  = trim($_POST['bank_account'] ?? '');

    // เก็บค่าเก่าไว้เผื่อกรอกผิด
    $old_data = ['phone' => $phone, 'id_card_number' => $idno, 'bank_account' => $bank];

    // ตรวจข้อมูลเบื้องต้น
    if ($phone === '' || $idno === '' || $bank === '') {
      $msg = ['type' => 'danger', 'text' => 'กรุณากรอกข้อมูลในช่องข้อความให้ครบถ้วน'];
    } elseif (!isset($_FILES['selfie_with_id']) || $_FILES['selfie_with_id']['error'] !== UPLOAD_ERR_OK || !isset($_FILES['id_card_image']) || $_FILES['id_card_image']['error'] !== UPLOAD_ERR_OK) {
      $msg = ['type' => 'danger', 'text' => 'กรุณาอัปโหลดรูปภาพทั้ง 2 รูป'];
    } else {
      // เตรียมโฟลเดอร์
      $baseDir = __DIR__ . '/uploads/kyc/' . $user_id . '/';
      if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
      }

      // ฟังก์ชันอัปโหลดรูป (จำกัดเฉพาะภาพ และขนาด)
      $uploadImg = function ($field, $prefix) use ($baseDir, $user_id, &$msg) {
        if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;

        $file = $_FILES[$field];
        $size = (int)$file['size'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($ext, $allowedExt, true)) {
          $msg = ['type' => 'danger', 'text' => 'ไฟล์รูปภาพต้องเป็น JPG, PNG หรือ WebP เท่านั้น'];
          return null;
        }
        if ($size <= 0 || $size > $maxSize) {
          $msg = ['type' => 'danger', 'text' => 'ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5MB'];
          return null;
        }

        $name = $prefix . '_U' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(2)) . '.' . $ext;
        $path = $baseDir . $name;
        if (move_uploaded_file($file['tmp_name'], $path)) {
          return 'uploads/kyc/' . $user_id . '/' . $name; // เก็บ path แบบ relative
        } else {
          $msg = ['type' => 'danger', 'text' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์: ' . $prefix];
          return null;
        }
      };

      $selfie = $uploadImg('selfie_with_id', 'SELFIE');
      // ถ้า $selfie ไม่ผ่าน $msg จะถูกตั้งค่าแล้ว ให้หยุดเลย
      if ($selfie !== null) {
        $idimg = $uploadImg('id_card_image', 'IDCARD');
      }

      if ($selfie && $idimg) {
        // บันทึกคำขอ
        try {
          q(
            "INSERT INTO kycs (user_id, phone, id_card_number, bank_account, selfie_with_id, id_card_image, status, created_at)
                       VALUES (?,?,?,?,?,?, 'pending', NOW())", // เพิ่ม created_at
            [$user_id, $phone, $idno, $bank, $selfie, $idimg]
          );
          $ok = true;
          $latest = q1("SELECT * FROM kycs WHERE user_id=? ORDER BY id DESC LIMIT 1", [$user_id]); // ดึงข้อมูลล่าสุดหลัง insert
          $msg = ['type' => 'success', 'text' => 'ส่งคำขอ KYC สำเร็จ! กรุณารอเจ้าหน้าที่ตรวจสอบข้อมูล'];
          $old_data = []; // เคลียร์ค่าเก่าเมื่อสำเร็จ
        } catch (Throwable $e) {
          // อาจจะ log error จริงไว้ดู $e->getMessage();
          $msg = ['type' => 'danger', 'text' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล'];
        }
      } elseif (!$msg) { // ถ้า $msg ยังไม่ถูกตั้งค่าจาก $uploadImg
        $msg = ['type' => 'danger', 'text' => 'เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ กรุณาลองใหม่อีกครั้ง'];
      }
    }
  }
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
  <title>สมัครเป็นผู้ขาย (E-KYC) - Web Shop Game</title>
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

    .form-control {
      border-radius: 12px;
      padding: .8rem;
      border-color: var(--border);
    }

    .form-control:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
    }

    /* [อัปเดต] สไตล์ Preview รูปภาพ */
    .preview-img {
      width: 100%;
      max-width: 320px;
      aspect-ratio: 16/10;
      object-fit: cover;
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    /* [อัปเดต] สไตล์ Badge สถานะ */
    .badge-status {
      font-size: .85rem;
      font-weight: 600;
      padding: .4em .7em;
    }

    .badge.bg-warning {
      background-color: #fef9c3 !important;
      color: #a16207 !important;
    }

    .badge.bg-success {
      background-color: #dcfce7 !important;
      color: var(--brand-dark) !important;
    }

    .badge.bg-danger {
      background-color: #fee2e2 !important;
      color: #b91c1c !important;
    }

    .badge.bg-secondary {
      background-color: #e2e8f0 !important;
      color: #475569 !important;
    }

    /* เพิ่มสีเทา */
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

  <div class="container py-4" style="max-width:920px">
    <h2 class="mb-3 fw-bold"><i class="bi bi-person-check-fill me-2"></i>สมัครเป็นผู้ขาย (E‑KYC)</h2>
    <p class="text-secondary mb-4">กรุณากรอกข้อมูลและอัปโหลดเอกสารเพื่อยืนยันตัวตน</p>

    <?php if ($msg): ?>
      <div class="alert alert-<?= h($msg['type']) ?> alert-dismissible fade show" role="alert">
        <?= h($msg['text']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if ($latest): ?>
      <div class="card mb-4">
        <div class="card-header fw-semibold">สถานะคำขอล่าสุด</div>
        <div class="card-body">
          <div class="row g-3 align-items-center mb-3">
            <div class="col-md-auto"><strong>สถานะ:</strong>
              <?php
              // แปลสถานะ + กำหนดสี
              $status_text = 'ไม่ทราบ';
              $status_color = 'secondary';
              switch ($latest['status']) {
                case 'pending':
                  $status_text = 'รอตรวจสอบ';
                  $status_color = 'warning';
                  break;
                case 'approved':
                  $status_text = 'อนุมัติแล้ว';
                  $status_color = 'success';
                  break;
                case 'rejected':
                  $status_text = 'ถูกปฏิเสธ';
                  $status_color = 'danger';
                  break;
              }
              ?>
              <span class="badge badge-status bg-<?= $status_color ?>">
                <?= h($status_text) ?>
              </span>
            </div>
            <div class="col-md-4"><strong>ยื่นเมื่อ:</strong> <?= date('d/m/Y H:i', strtotime($latest['created_at'])) ?></div>
            <div class="col-md-5"><strong>เบอร์โทร:</strong> <?= h($latest['phone'] ?? '-') ?></div>
          </div>
          <hr>
          <div class="row g-4">
            <div class="col-md-6 text-center">
              <h6 class="mb-2 text-secondary">เซลฟี่คู่บัตรประชาชน</h6>
              <?php if (!empty($latest['selfie_with_id'])): ?>
                <a href="<?= h($latest['selfie_with_id']) ?>" target="_blank">
                  <img class="preview-img" src="<?= h($latest['selfie_with_id']) ?>" alt="Selfie with ID">
                </a>
              <?php else: ?>
                <div class="text-secondary mt-3">(ไม่มีรูปภาพ)</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6 text-center">
              <h6 class="mb-2 text-secondary">รูปบัตรประชาชน</h6>
              <?php if (!empty($latest['id_card_image'])): ?>
                <a href="<?= h($latest['id_card_image']) ?>" target="_blank">
                  <img class="preview-img" src="<?= h($latest['id_card_image']) ?>" alt="ID Card Image">
                </a>
              <?php else: ?>
                <div class="text-secondary mt-3">(ไม่มีรูปภาพ)</div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($latest['status'] === 'rejected' && !empty($latest['reject_reason'])): ?>
            <div class="alert alert-danger mt-3 mb-0">
              <strong>เหตุผลที่ถูกปฏิเสธ:</strong> <?= h($latest['reject_reason']) ?>
              <br><small>กรุณากรอกข้อมูลและส่งคำขอใหม่อีกครั้ง</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$latest || $latest['status'] === 'rejected'): ?>
      <div class="card">
        <div class="card-header fw-semibold">กรอกข้อมูลเพื่อยืนยันตัวตน</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
              <label for="phone" class="form-label">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
              <input type="tel" name="phone" id="phone" class="form-control" value="<?= h($old_data['phone'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label for="id_card_number" class="form-label">หมายเลขบัตรประชาชน <span class="text-danger">*</span></label>
              <input type="text" name="id_card_number" id="id_card_number" class="form-control" value="<?= h($old_data['id_card_number'] ?? '') ?>" required pattern="\d{13}">
              <div class="form-text">กรอกเลข 13 หลักติดกันไม่ต้องมีขีด</div>
            </div>
            <div class="col-md-6">
              <label for="bank_account" class="form-label">เลขบัญชีธนาคาร (สำหรับรับเงิน) <span class="text-danger">*</span></label>
              <input type="text" name="bank_account" id="bank_account" class="form-control" value="<?= h($old_data['bank_account'] ?? '') ?>" required>
              <div class="form-text">กรุณาตรวจสอบให้ถูกต้อง</div>
            </div>

            <div class="col-md-6">
              <label for="selfie_with_id" class="form-label">รูปเซลฟี่คู่บัตรประชาชน <span class="text-danger">*</span></label>
              <input type="file" name="selfie_with_id" id="selfie_with_id" accept=".jpg,.jpeg,.png,.webp" class="form-control" required>
              <div class="form-text">ถ่ายรูปหน้าตัวเองพร้อมถือบัตรประชาชนให้เห็นชัดเจน</div>
            </div>
            <div class="col-md-6">
              <label for="id_card_image" class="form-label">รูปถ่ายบัตรประชาชน <span class="text-danger">*</span></label>
              <input type="file" name="id_card_image" id="id_card_image" accept=".jpg,.jpeg,.png,.webp" class="form-control" required>
              <div class="form-text">ถ่ายเฉพาะด้านหน้าบัตรประชาชนให้ชัดเจน</div>
            </div>

            <div class="col-12 mt-4">
              <button type="submit" class="btn btn-brand"><i class="bi bi-send-check-fill me-1"></i> ส่งคำขอเป็นผู้ขาย</button>
              <a href="index.php" class="btn btn-outline-secondary ms-2">ยกเลิก</a>
            </div>
          </form>
        </div>
      </div>
    <?php elseif ($latest['status'] === 'approved'): ?>
      <div class="alert alert-success mt-3">
        <i class="bi bi-check-circle-fill me-1"></i> คุณได้รับการอนุมัติเป็นผู้ขายแล้ว! ตอนนี้คุณสามารถ <a href="manage_products.php" class="alert-link">เพิ่มสินค้าเพื่อเริ่มขาย</a> ได้เลย
      </div>
      <a href="index.php" class="btn btn-outline-secondary mt-2"><i class="bi bi-house"></i> กลับหน้าหลัก</a>
    <?php elseif ($latest['status'] === 'pending'): ?>
      <div class="alert alert-info mt-3">
        <i class="bi bi-hourglass-split me-1"></i> คำขอของคุณอยู่ระหว่างการตรวจสอบโดยเจ้าหน้าที่
      </div>
      <a href="index.php" class="btn btn-outline-secondary mt-2"><i class="bi bi-house"></i> กลับหน้าหลัก</a>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>