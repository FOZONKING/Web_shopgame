<?php 
require __DIR__.'/db.php';

function q($sql,$params=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
function q1($sql,$params=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($params); return $st->fetch(PDO::FETCH_ASSOC); }

$user = $_SESSION['user'] ?? null;
$role = $user['role'] ?? null;

$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// ดึงหมวดหมู่ทั้งหมด
$categories = q("SELECT id, name FROM categories ORDER BY name ASC");

// ดึงสินค้าตามหมวดหมู่ (ถ้าเลือกหมวด)
if ($categoryId > 0) {
    $products = q("
        SELECT p.id, p.name, p.price, p.image1
        FROM products p
        WHERE p.category_id = ? AND (p.quantity IS NULL OR p.quantity > 0)
        ORDER BY p.created_at DESC
    ", [$categoryId]);
    $categoryName = q1("SELECT name FROM categories WHERE id = ?", [$categoryId])['name'] ?? '';
} else {
    $products = q("
        SELECT p.id, p.name, p.price, p.image1
        FROM products p
        WHERE (p.quantity IS NULL OR p.quantity > 0)
        ORDER BY p.created_at DESC
    ");
    $categoryName = "สินค้าทั้งหมด";
}

// ดึงและเคลียร์ flash ถ้ามี
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shop - Web Shop Game</title>
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

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav ms-auto">
          <?php if ($role === 'seller' || $role === 'admin'): ?>
            <li class="nav-item"><a class="nav-link" href="manage_products.php">จัดการสินค้า</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="cart.php">ตะกร้า</a></li>
          <?php if(!$user): ?>
            <li class="nav-item ms-lg-2"><a class="btn btn-success btn-sm" href="login.php">เข้าสู่ระบบ</a></li>
          <?php else: ?>
            <li class="nav-item ms-lg-2"><a class="btn btn-outline-light btn-sm" href="logout.php">ออกจากระบบ</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <main class="container py-4">

    <?php if($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['msg'] ?? '') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if ($role === 'seller' || $role === 'admin'): ?>
      <div class="d-flex justify-content-end mb-3 gap-2">
        <a href="manage_products.php" class="btn btn-success">+ เพิ่มสินค้า</a>
        <?php if ($role === 'admin'): ?>
          <a href="manage_categories.php" class="btn btn-outline-light">จัดการหมวดหมู่</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <!-- Sidebar หมวดหมู่ -->
      <div class="col-lg-3 mb-3">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-3">หมวดหมู่</h5>
            <div class="list-group">
              <a href="shop.php" class="list-group-item list-group-item-action <?= $categoryId==0?'active':'' ?>">
                ทั้งหมด
              </a>
              <?php foreach($categories as $c): ?>
                <a href="shop.php?category=<?= (int)$c['id'] ?>" 
                   class="list-group-item list-group-item-action <?= $categoryId==$c['id']?'active':'' ?>">
                  <?= htmlspecialchars($c['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- สินค้า -->
      <div class="col-lg-9">
        <h3 class="mb-3"><?= htmlspecialchars($categoryName) ?></h3>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
          <?php if(!$products): ?>
            <div class="col"><div class="alert alert-info">ยังไม่มีสินค้า</div></div>
          <?php else: foreach($products as $p): ?>
            <div class="col">
              <div class="card h-100">
                <img class="product-img w-100" 
                     src="<?= htmlspecialchars($p['image1'] ?: 'https://via.placeholder.com/480x270?text=No+Image') ?>" 
                     alt="<?= htmlspecialchars($p['name']) ?>">
                <div class="card-body d-flex flex-column">
                  <strong class="text-truncate" title="<?= htmlspecialchars($p['name']) ?>">
                    <?= htmlspecialchars($p['name']) ?>
                  </strong>
                  <div class="mt-1 fw-bold text-success">฿<?= number_format((float)$p['price'], 2) ?></div>
                  <div class="mt-auto d-flex gap-2">
                    <a class="btn btn-outline-light btn-sm" href="view_product.php?id=<?= (int)$p['id'] ?>">ดูรายละเอียด</a>
                    <a class="btn btn-success btn-sm" href="add_to_cart.php?id=<?= (int)$p['id'] ?>">เพิ่มลงตะกร้า</a>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
