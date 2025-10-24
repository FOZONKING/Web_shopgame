<?php
session_start();
require __DIR__ . '/db.php';

// --- Helpers ---
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q($sql, $params = [])
{
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function q1($sql, $params = [])
{
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetch(PDO::FETCH_ASSOC);
}

$user = $_SESSION['user'] ?? null;
$uid  = $user['id'] ?? null;
$role = $user['role'] ?? null;

$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// --- คำนวณ Cart Count สำหรับ Navbar ---
$cartCount = 0;
if ($uid) {
  $userCart = $_SESSION['carts'][$uid] ?? [];
  $cartCount = is_array($userCart) ? array_sum($userCart) : 0;
}

// --- ดึงข้อมูลหมวดหมู่ทั้งหมด ---
$categories = q("SELECT id, name FROM categories ORDER BY name ASC");

// --- สร้าง SQL Query แบบไดนามิกเพื่อดึงสินค้า ---
$sql = "SELECT p.id, p.name, p.price, p.image1, p.quantity, p.reserved FROM products p";
$conditions = [];
$params = [];

$conditions[] = "(p.quantity IS NULL OR p.quantity > p.reserved)";

if ($categoryId > 0) {
  $conditions[] = "p.category_id = ?";
  $params[] = $categoryId;
  $category = q1("SELECT id, name FROM categories WHERE id = ?", [$categoryId]);
  $categoryName = $category['name'] ?? 'ไม่พบหมวดหมู่';
} else {
  $categoryName = "สินค้าทั้งหมด";
}

if (!empty($conditions)) {
  $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY p.created_at DESC";
$products = q($sql, $params);

// ดึงและเคลียร์ flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($categoryName) ?> - Web Shop Game</title>
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
    }

    .navbar {
      background: rgba(255, 255, 255, .85);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 20px;
      transition: all .25s ease-in-out;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .03);
    }

    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 28px rgba(0, 0, 0, .07);
    }

    /* --- [โค้ดแก้ไข] การแสดงผลรูปภาพสินค้า --- */
    .product-img-container {
      aspect-ratio: 1/1;
      /* กำหนดกรอบเป็นสี่เหลี่ยมจัตุรัส */
      background-color: #f8f9fa;
      /* สีพื้นหลังสำหรับพื้นที่ว่าง */
      padding: 1rem;
      border-top-left-radius: 20px;
      border-top-right-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .product-img {
      max-width: 100%;
      max-height: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      /* แสดงรูปเต็ม ไม่ตัดขอบ */
    }

    .btn-brand {
      background: var(--brand);
      border-color: var(--brand);
      color: #fff;
      font-weight: 700;
      border-radius: 12px;
    }

    .btn-brand:hover {
      background: var(--brand-dark);
      border-color: var(--brand-dark);
    }

    .category-list .list-group-item {
      border-radius: 12px !important;
      margin-bottom: 4px;
      border: 1px solid transparent;
      font-weight: 600;
    }

    .category-list .list-group-item:hover {
      background-color: #f0fdf4;
      color: var(--brand-dark);
    }

    .category-list .list-group-item.active {
      background-color: var(--brand);
      color: #fff;
      border-color: var(--brand);
    }

    .product-card .card-actions {
      visibility: hidden;
      opacity: 0;
      transition: all .2s ease-in-out;
    }

    .product-card:hover .card-actions {
      visibility: visible;
      opacity: 1;
    }
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
              <?php if ($cartCount > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCount ?></span>
              <?php endif; ?>
            </a>
          </li>
          <?php if ($user): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                <div class="avatar d-flex align-items-center justify-content-center" style="width:36px; height:36px; background-color:var(--brand); color:#fff; border-radius:50%; font-weight:700;">
                  <?= strtoupper(substr($user['name'] ?? $user['email'], 0, 1)) ?>
                </div>
                <span class="d-none d-sm-inline fw-semibold"><?= h($user['name'] ?? $user['email']) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 1rem;">
                <?php if ($role === 'seller'): ?>
                  <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
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

  <header class="py-4" style="background-color: #e8f9f0;">
    <div class="container text-center">
      <h1 class="display-5 fw-bold"><?= h($categoryName) ?></h1>
      <p class="text-secondary">เลือกซื้อไอเทมที่คุณต้องการได้เลย!</p>
    </div>
  </header>

  <main class="container py-4">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'success') ?> alert-dismissible fade show" role="alert">
        <?= h($flash['msg'] ?? '') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="row">
      <aside class="col-lg-3 mb-4">
        <div class="card p-2">
          <div class="card-body">
            <h5 class="mb-3 fw-bold">หมวดหมู่</h5>
            <div class="list-group list-group-flush category-list">
              <a href="shop.php" class="list-group-item list-group-item-action <?= $categoryId == 0 ? 'active' : '' ?>">สินค้าทั้งหมด</a>
              <?php foreach ($categories as $c): ?>
                <a href="shop.php?category=<?= (int)$c['id'] ?>" class="list-group-item list-group-item-action <?= $categoryId == $c['id'] ? 'active' : '' ?>"><?= h($c['name']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </aside>

      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">พบ <?= count($products) ?> รายการ</h5>
          <?php if ($role === 'seller' || $role === 'admin'): ?>
            <a href="manage_products.php" class="btn btn-brand btn-sm"><i class="bi bi-plus-lg"></i> เพิ่มสินค้า</a>
          <?php endif; ?>
        </div>

        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-4">
          <?php if (!$products): ?>
            <div class="col-12">
              <div class="alert alert-warning text-center">ไม่พบสินค้าในหมวดหมู่นี้</div>
            </div>
            <?php else: foreach ($products as $p): ?>
              <div class="col">
                <div class="card h-100 product-card">
                  <a href="view_product.php?id=<?= (int)$p['id'] ?>">
                    <div class="product-img-container">
                      <img class="product-img" src="<?= h($p['image1'] ?: 'https://via.placeholder.com/480x270?text=No+Image') ?>" alt="<?= h($p['name']) ?>">
                    </div>
                  </a>
                  <div class="card-body d-flex flex-column">
                    <h6 class="fw-bold text-truncate" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></h6>
                    <p class="h5 fw-bolder text-success mt-1">฿<?= number_format((float)$p['price'], 0) ?></p>

                    <div class="mt-auto pt-2 card-actions">
                      <div class="d-grid gap-2">
                        <a class="btn btn-brand" href="add_to_cart.php?id=<?= (int)$p['id'] ?>"><i class="bi bi-cart3"></i> เพิ่มลงตะกร้า</a>
                        <a class="btn btn-outline-secondary btn-sm" href="view_product.php?id=<?= (int)$p['id'] ?>">ดูรายละเอียด</a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
          <?php endforeach;
          endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>