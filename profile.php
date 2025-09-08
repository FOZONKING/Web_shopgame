<?php
session_start();
require __DIR__ . '/db.php';
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <title>โปรไฟล์ของฉัน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-dark text-light">
    <div class="container py-4">
        <h2>โปรไฟล์ของฉัน</h2>
        <div class="card bg-secondary text-light p-3">
            <p><strong>ชื่อ:</strong> <?= htmlspecialchars($user['name'] ?? '-') ?></p>
            <p><strong>อีเมล:</strong> <?= htmlspecialchars($user['email'] ?? '-') ?></p>
            <p><strong>Role:</strong> <?= htmlspecialchars($user['role'] ?? '-') ?></p>
        </div>
        <a href="index.php" class="btn btn-outline-light mt-3">กลับหน้าหลัก</a>
    </div>
</body>

</html>