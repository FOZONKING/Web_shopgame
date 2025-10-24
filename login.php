<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  if ($email && $pass) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($pass, $user['password'])) {
      unset($user['password']); // ไม่เก็บรหัสผ่านใน session
      $_SESSION['user'] = $user;
      header("Location: index.php");
      exit;
    } else {
      $msg = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
    }
  } else {
    $msg = 'กรุณากรอกข้อมูลให้ครบ';
  }
}
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบ - Web Shop Game</title>
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
    }

    body {
      background-color: var(--bg);
      font-family: 'Sora', sans-serif;
      color: var(--text);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
    }

    .login-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, .08);
      padding: 2.5rem;
      width: 100%;
      max-width: 450px;
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

  <div class="login-card">
    <div class="text-center mb-4">
      <a class="navbar-brand d-inline-flex align-items-center gap-2" href="index.php">
        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--brand);"></div>
        <strong class="fs-4 fw-bolder">Web Shop Game</strong>
      </a>
    </div>
    <h3 class="fw-bold text-center mb-1">เข้าสู่ระบบ</h3>
    <p class="text-center text-secondary mb-4">ยินดีต้อนรับกลับมา!</p>

    <?php if ($msg): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>
    <?php if (isset($_GET['registered'])): ?><div class="alert alert-success">สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ</div><?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label for="email" class="form-label fw-semibold">อีเมล</label>
        <input type="email" name="email" id="email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label fw-semibold">รหัสผ่าน</label>
        <input type="password" name="password" id="password" class="form-control" required>
      </div>
      <div class="d-grid mt-4">
        <button type="submit" class="btn btn-brand w-100">เข้าสู่ระบบ</button>
      </div>
    </form>
    <p class="mt-4 text-center text-secondary">
      ยังไม่มีบัญชี? <a href="register.php" class="fw-bold text-decoration-none" style="color:var(--brand)">สมัครสมาชิกที่นี่</a>
    </p>
  </div>

</body>

</html>