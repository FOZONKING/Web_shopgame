<?php
require __DIR__.'/db.php';

// ต้องล็อกอิน
if (!isset($_SESSION['user'])) {
  header('Location: login.php');
  exit;
}

$user = $_SESSION['user'];
$user_id = (int)$user['id'];

// helper สั้นๆ
function q($sql,$p=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($p); return $st; }
function q1($sql,$p=[]){ return q($sql,$p)->fetch(PDO::FETCH_ASSOC); }

// สถานะ KYC ล่าสุดของผู้ใช้
$latest = q1("SELECT * FROM kycs WHERE user_id=? ORDER BY created_at DESC LIMIT 1", [$user_id]);

$msg = null;
$ok = false;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // ถ้ามี KYC ที่ยัง pending หรือถูกอนุมัติแล้ว ไม่ให้ยื่นซ้ำ
  if ($latest && in_array($latest['status'], ['pending','approved'], true)) {
    $msg = ['type'=>'warning','text'=>'คุณมีคำขอที่ยังรออนุมัติหรือได้รับอนุมัติแล้ว'];
  } else {
    $phone  = trim($_POST['phone'] ?? '');
    $idno   = trim($_POST['id_card_number'] ?? '');
    $bank   = trim($_POST['bank_account'] ?? '');

    // ตรวจข้อมูลเบื้องต้น
    if ($phone==='' || $idno==='' || $bank==='') {
      $msg = ['type'=>'danger','text'=>'กรุณากรอกข้อมูลให้ครบ'];
    } else {
      // เตรียมโฟลเดอร์
      $baseDir = __DIR__ . '/uploads/kyc/' . $user_id . '/';
      if (!is_dir($baseDir)) { @mkdir($baseDir, 0777, true); }

      // ฟังก์ชันอัปโหลดรูป (จำกัดเฉพาะภาพ)
      $uploadImg = function($field, $prefix) use ($baseDir, $user_id) {
        if (empty($_FILES[$field]['name'])) return null;
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        $okExt = ['jpg','jpeg','png','webp'];
        if (!in_array($ext,$okExt,true)) return null;
        $name = $prefix.'_U'.$user_id.'_'.time().'_'.bin2hex(random_bytes(2)).'.'.$ext;
        $path = $baseDir.$name;
        if (move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
          // เก็บพาธสัมพัทธ์
          return 'uploads/kyc/'.$user_id.'/'.$name;
        }
        return null;
      };

      $selfie = $uploadImg('selfie_with_id','SELFIE');
      $idimg  = $uploadImg('id_card_image','IDCARD');

      if (!$selfie || !$idimg) {
        $msg = ['type'=>'danger','text'=>'กรุณาอัปโหลดรูปเซลฟี่คู่บัตร และรูปบัตรประชาชน (เฉพาะ jpg/jpeg/png/webp)'];
      } else {
        // บันทึกคำขอ
        q("INSERT INTO kycs (user_id, phone, id_card_number, bank_account, selfie_with_id, id_card_image, status) 
           VALUES (?,?,?,?,?,?, 'pending')", 
          [$user_id, $phone, $idno, $bank, $selfie, $idimg]
        );
        $ok = true;
        $latest = q1("SELECT * FROM kycs WHERE user_id=? ORDER BY created_at DESC LIMIT 1", [$user_id]);
        $msg = ['type'=>'success','text'=>'ส่งคำขอสำเร็จ! กรุณารอแอดมินตรวจสอบ'];
      }
    }
  }
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สมัครเป็นผู้ขาย (E-KYC)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --bs-body-bg:#0b0f14; --bs-card-bg:#0f1620; --bs-border-color:rgba(148,163,184,.18);
    }
    .preview{width:100%; max-width:280px; aspect-ratio:4/3; object-fit:cover; border:1px solid var(--bs-border-color); border-radius:10px}
  </style>
</head>
<body class="bg-dark text-light">
  <nav class="navbar navbar-expand-lg border-bottom bg-dark-subtle bg-opacity-50 sticky-top">
    <div class="container">
      <a class="navbar-brand" href="index.php">Web Shop Game</a>
      <div class="ms-auto d-flex gap-2">
        <a href="shop.php" class="btn btn-outline-light btn-sm">ร้านค้า</a>
        <a href="manage_products.php" class="btn btn-outline-light btn-sm">จัดการสินค้า</a>
        <a href="logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container py-4" style="max-width:920px">
    <h3 class="mb-3">สมัครเป็นผู้ขาย (E‑KYC)</h3>

    <?php if($msg): ?>
      <div class="alert alert-<?= htmlspecialchars($msg['type']) ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <?php if($latest): ?>
      <div class="card mb-4">
        <div class="card-header">สถานะคำขอล่าสุด</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3"><strong>สถานะ:</strong> 
              <span class="badge 
                <?= $latest['status']==='approved' ? 'bg-success' : ($latest['status']==='pending' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                <?= htmlspecialchars($latest['status']) ?>
              </span>
            </div>
            <div class="col-md-4"><strong>ยื่นเมื่อ:</strong> <?= htmlspecialchars($latest['created_at']) ?></div>
            <div class="col-md-5"><strong>เบอร์โทร:</strong> <?= htmlspecialchars($latest['phone'] ?? '-') ?></div>
          </div>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <div class="small text-secondary mb-1">เซลฟี่คู่บัตร</div>
              <?php if(!empty($latest['selfie_with_id'])): ?>
                <img class="preview" src="<?= h($latest['selfie_with_id']) ?>" alt="">
              <?php else: ?>
                <div class="text-secondary">ไม่มีรูป</div>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <div class="small text-secondary mb-1">รูปบัตรประชาชน</div>
              <?php if(!empty($latest['id_card_image'])): ?>
                <img class="preview" src="<?= h($latest['id_card_image']) ?>" alt="">
              <?php else: ?>
                <div class="text-secondary">ไม่มีรูป</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if(!$latest || $latest['status']==='rejected'): ?>
      <div class="card">
        <div class="card-header">กรอกข้อมูลเพื่อยืนยันตัวตน</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">เบอร์โทรศัพท์</label>
              <input type="text" name="phone" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">หมายเลขบัตรประชาชน</label>
              <input type="text" name="id_card_number" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">เลขบัญชีธนาคาร</label>
              <input type="text" name="bank_account" class="form-control" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">รูปเซลฟี่คู่บัตร (jpg/png/webp)</label>
              <input type="file" name="selfie_with_id" accept=".jpg,.jpeg,.png,.webp" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">รูปบัตรประชาชน (jpg/png/webp)</label>
              <input type="file" name="id_card_image" accept=".jpg,.jpeg,.png,.webp" class="form-control" required>
            </div>

            <div class="col-12">
              <button class="btn btn-success">ส่งคำขอเป็นผู้ขาย</button>
              <a href="index.php#profile" class="btn btn-outline-light ms-2">กลับหน้าบัญชี</a>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-info mt-3">
        คุณได้ส่งคำขอแล้ว สถานะปัจจุบัน: <strong><?= htmlspecialchars($latest['status']) ?></strong>
        <?php if($latest['status']==='approved'): ?>
          <div class="mt-2">ตอนนี้คุณสามารถ <a href="manage_products.php">เพิ่มสินค้า</a> ได้เลย</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
