<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$msg = '';
$old_name = ''; // เก็บค่าเก่าไว้กรอกกลับ
$old_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $cpass = $_POST['confirm_password'] ?? '';

  // เก็บค่าเก่าไว้เผื่อกรอกผิด
  $old_name = $name;
  $old_email = $email;

  if ($name && $email && $pass && $cpass) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $msg = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (mb_strlen($pass) < 6) { // เพิ่มเงื่อนไขความยาวรหัสผ่าน
      $msg = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($pass !== $cpass) {
      $msg = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
      // เช็คว่ามี email ซ้ำหรือไม่
      $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        $msg = 'อีเมลนี้ถูกใช้งานแล้ว กรุณาใช้อีเมลอื่น';
      } else {
        // ไม่มีปัญหา สร้างบัญชีใหม่
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'buyer')"); // กำหนด role เริ่มต้น
        if ($stmt->execute([$name, $email, $hash])) {
          // ส่งไปหน้า Login พร้อมแจ้งว่าสำเร็จ
          header("Location: login.php?registered=1");
          exit;
        } else {
          $msg = 'เกิดข้อผิดพลาดในการสร้างบัญชี';
        }
      }
    }
  } else {
    $msg = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
  }
}
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สมัครสมาชิก - Web Shop Game</title>
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
    }

    body {
      background-color: var(--bg);
      font-family: 'Sora', sans-serif;
      color: var(--text);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 2rem 0;
      /* เพิ่ม padding บนล่างเผื่อฟอร์มยาว */
    }

    .register-card {
      /* ตั้งชื่อ card ให้สื่อความหมาย */
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, .08);
      padding: 2.5rem;
      width: 100%;
      max-width: 480px;
      /* ขยาย card นิดหน่อย */
    }

    .form-control {
      border-radius: 12px;
      padding: .9rem;
      border-color: var(--border);
    }

    .form-control:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
    }

    .btn-brand {
      background: var(--brand);
      border-color: var(--brand);
      color: #fff;
      font-weight: 700;
      border-radius: 12px;
      padding: .9rem;
      transition: all .2s;
    }

    .btn-brand:hover {
      background: var(--brand-dark);
      border-color: var(--brand-dark);
      transform: translateY(-2px);
    }
  </style>
</head>

<body>

  <div class="register-card">
    <div class="text-center mb-4">
      <a class="navbar-brand d-inline-flex align-items-center gap-2" href="index.php">
        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--brand);"></div>
        <strong class="fs-4 fw-bolder">Web Shop Game</strong>
      </a>
    </div>
    <h3 class="fw-bold text-center mb-1">สร้างบัญชีใหม่</h3>
    <p class="text-center text-secondary mb-4">เริ่มต้นใช้งานง่ายๆ เพียงไม่กี่ขั้นตอน</p>

    <?php if ($msg): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label for="name" class="form-label fw-semibold">ชื่อที่แสดง</label>
        <input type="text" name="name" id="name" class="form-control" value="<?= h($old_name) ?>" required>
      </div>
      <div class="mb-3">
        <label for="email" class="form-label fw-semibold">อีเมล</label>
        <input type="email" name="email" id="email" class="form-control" value="<?= h($old_email) ?>" required>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label fw-semibold">รหัสผ่าน</label>
        <input type="password" name="password" id="password" class="form-control" required minlength="6">
        <div class="form-text">ต้องมีอย่างน้อย 6 ตัวอักษร</div>
      </div>
      <div class="mb-3">
        <label for="confirm_password" class="form-label fw-semibold">ยืนยันรหัสผ่าน</label>
        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="6">
      </div>
      <div class="d-grid mt-4">
        <button type="submit" class="btn btn-brand w-100">สมัครสมาชิก</button>
      </div>
    </form>
    <p class="mt-4 text-center text-secondary">
      มีบัญชีอยู่แล้ว? <a href="login.php" class="fw-bold text-decoration-none" style="color:var(--brand)">เข้าสู่ระบบที่นี่</a>
    </p>
  </div>

</body>

</html>