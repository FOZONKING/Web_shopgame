<?php
require __DIR__.'/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email && $pass) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password'])) {
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
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<div class="container py-5" style="max-width:500px">
  <h3 class="mb-3">เข้าสู่ระบบ</h3>
  <?php if($msg): ?><div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if(isset($_GET['registered'])): ?><div class="alert alert-success">สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ</div><?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label>อีเมล</label>
      <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>รหัสผ่าน</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button class="btn btn-success w-100">เข้าสู่ระบบ</button>
  </form>
  <p class="mt-3">ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a></p>
</div>
</body>
</html>
