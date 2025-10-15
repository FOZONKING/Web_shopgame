<?php
require __DIR__.'/db.php';

function q1($sql,$params=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($params); return $st->fetch(PDO::FETCH_ASSOC); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: shop.php");
    exit;
}

$product = q1("SELECT p.*, c.name AS category_name, u.name AS seller_name 
               FROM products p
               LEFT JOIN categories c ON p.category_id = c.id
               LEFT JOIN users u ON p.seller_id = u.id
               WHERE p.id = ?", [$id]);

if (!$product) {
    header("HTTP/1.0 404 Not Found");
    echo "<h1>ไม่พบสินค้า</h1>";
    exit;
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($product['name']) ?> - Web Shop Game</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --bs-body-bg: #0b0f14;
      --bs-card-bg:#0f1620;
      --bs-border-color: rgba(148,163,184,.18);
    }
    .product-img{aspect-ratio: 16/9; object-fit:cover}
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg border-bottom bg-dark-subtle bg-opacity-50 sticky-top">
    <div class="container">
      <a class="navbar-brand" href="index.php">Web Shop Game</a>
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="shop.php">สินค้า</a></li>
        <li class="nav-item"><a class="nav-link" href="cart.php">ตะกร้า</a></li>
      </ul>
    </div>
  </nav>

  <main class="container py-4">
    <div class="row g-4">
      <div class="col-md-6">
        <div class="card">
          <img class="w-100 product-img" src="<?= htmlspecialchars($product['image1'] ?: 'https://via.placeholder.com/800x500?text=No+Image') ?>" alt="">
        </div>
      </div>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body d-flex flex-column">
            <h3 class="fw-bold"><?= htmlspecialchars($product['name']) ?></h3>
            <div class="text-success fs-4 mb-2">฿<?= number_format((float)$product['price'], 2) ?></div>
            <div class="text-secondary mb-2">หมวดหมู่: <?= htmlspecialchars($product['category_name'] ?? '-') ?></div>
            <div class="text-secondary mb-3">ผู้ขาย: <?= htmlspecialchars($product['seller_name'] ?? '-') ?></div>
            <div class="mb-3">สต็อก: <?= (int)($product['quantity'] ?? 0) ?> ชิ้น</div>
            <p><?= nl2br(htmlspecialchars($product['description'] ?? '')) ?></p>
            <div class="mt-auto">
              <a href="add_to_cart.php?id=<?= (int)$product['id'] ?>" class="btn btn-success">เพิ่มลงตะกร้า</a>
              <a href="shop.php" class="btn btn-outline-light ms-2">กลับไปหน้าร้าน</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
