<?php
require __DIR__.'/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $cpass = $_POST['confirm_password'] ?? '';

    if ($name && $email && $pass) {
        if ($pass !== $cpass) {
            $msg = 'รหัสผ่านไม่ตรงกัน';
        } else {
            // เช็คว่ามี email ซ้ำหรือไม่
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $msg = 'อีเมลนี้ถูกใช้แล้ว';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$name, $email, $hash]);
                header("Location: login.php?registered=1");
                exit;
            }
        }
    } else {
        $msg = 'กรุณากรอกข้อมูลให้ครบ';
    }
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สมัครสมาชิก</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<div class="container py-5" style="max-width:500px">
  <h3 class="mb-3">สมัครสมาชิก</h3>
  <?php if($msg): ?><div class="alert alert-warning"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label>ชื่อ</label>
      <input type="text" name="name" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>อีเมล</label>
      <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>รหัสผ่าน</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>ยืนยันรหัสผ่าน</label>
      <input type="password" name="confirm_password" class="form-control" required>
    </div>
    <button class="btn btn-success w-100">สมัครสมาชิก</button>
  </form>
  <p class="mt-3">มีบัญชีแล้ว? <a href="login.php">เข้าสู่ระบบ</a></p>
</div>
</body>
</html>
