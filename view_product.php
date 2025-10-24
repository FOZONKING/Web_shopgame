<?php
if (session_status() === PHP_SESSION_NONE) session_start();
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  header("Location: shop.php");
  exit;
}

// ดึงข้อมูลสินค้าพร้อมชื่อหมวดหมู่และชื่อผู้ขาย
$product = q1("SELECT p.*, c.name AS category_name, u.name AS seller_name, u.email AS seller_email
               FROM products p
               LEFT JOIN categories c ON p.category_id = c.id
               LEFT JOIN users u ON p.seller_id = u.id
               WHERE p.id = ?", [$id]);

if (!$product) {
  // header("HTTP/1.0 404 Not Found"); // อาจจะไม่จำเป็น แค่แสดงข้อความก็พอ
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบสินค้าที่ต้องการ (ID: ' . $id . ')'];
  header("Location: shop.php"); // ส่งกลับไปหน้า shop พร้อมข้อความ
  exit;
}

// คำนวณสต็อกคงเหลือ
$quantity = $product['quantity'];
$reserved = $product['reserved'];
$stock = ($quantity === null) ? 'ไม่จำกัด' : max(0, (int)$quantity - (int)$reserved);
$is_available = ($quantity === null) || ($stock > 0);

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
  <title><?= h($product['name']) ?> - Web Shop Game</title>
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
      border-radius: 20px;
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

    /* ปรับขนาดปุ่ม */
    .btn-brand:hover {
      background: var(--brand-dark);
      border-color: var(--brand-dark);
    }

    .btn-outline-secondary {
      border-radius: 12px;
      padding: .8rem 1.5rem;
    }

    /* --- การแสดงผลรูปภาพสินค้า (Art Gallery Style) --- */
    .product-img-container {
      aspect-ratio: 1/1;
      /* กำหนดกรอบเป็นสี่เหลี่ยมจัตุรัส */
      background-color: #f8f9fa;
      /* สีพื้นหลังสำหรับพื้นที่ว่าง */
      padding: 2rem;
      /* เพิ่ม padding */
      border-radius: 20px;
      /* ทำให้ขอบมนเท่า card */
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid var(--border);
      /* เพิ่มเส้นขอบ */
    }

    .product-img {
      max-width: 100%;
      max-height: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      /* แสดงรูปเต็ม ไม่ตัดขอบ */
    }

    /* รายละเอียดสินค้า */
    .product-meta span {
      display: inline-block;
      background-color: #f8f9fa;
      padding: 0.3rem 0.8rem;
      border-radius: 8px;
      font-size: 0.9rem;
      margin-right: 0.5rem;
      margin-bottom: 0.5rem;
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

  <main class="container py-4">
    <nav aria-label="breadcrumb" class="mb-3">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="shop.php">ร้านค้า</a></li>
        <?php if ($product['category_id']): ?>
          <li class="breadcrumb-item"><a href="shop.php?category=<?= (int)$product['category_id'] ?>"><?= h($product['category_name'] ?? 'หมวดหมู่') ?></a></li>
        <?php endif; ?>
        <li class="breadcrumb-item active" aria-current="page"><?= h($product['name']) ?></li>
      </ol>
    </nav>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="product-img-container mb-3">
          <img class="product-img" src="<?= h($product['image1'] ?: 'https://via.placeholder.com/600?text=No+Image') ?>" alt="<?= h($product['name']) ?>">
        </div>
      </div>

      <div class="col-lg-6">
        <div class="d-flex flex-column h-100">
          <h1 class="fw-bold mb-2"><?= h($product['name']) ?></h1>
          <p class="display-5 fw-bolder text-success mb-3">฿<?= number_format((float)$product['price'], 0) ?></p>

          <div class="product-meta mb-3">
            <?php if ($product['category_name']): ?>
              <span><i class="bi bi-tag me-1"></i><?= h($product['category_name']) ?></span>
            <?php endif; ?>
            <?php
            $seller_display = $product['seller_name'] ?: ($product['seller_email'] ?: 'ไม่ระบุ');
            ?>
            <span><i class="bi bi-shop me-1"></i><?= h($seller_display) ?></span>
            <span><i class="bi bi-box-seam me-1"></i>สต็อก: <?= h($stock) ?></span>
          </div>

          <h5 class="fw-semibold mt-2">รายละเอียดสินค้า</h5>
          <p class="text-secondary"><?= nl2br(h($product['description'] ?? 'ไม่มีรายละเอียด')) ?></p>

          <div class="mt-auto pt-3 border-top">
            <?php if ($is_available): ?>
              <a href="add_to_cart.php?id=<?= (int)$product['id'] ?>" class="btn btn-brand">
                <i class="bi bi-cart-plus-fill me-1"></i> เพิ่มลงตะกร้า
              </a>
            <?php else: ?>
              <button class="btn btn-secondary" disabled>สินค้าหมด</button>
            <?php endif; ?>
            <a href="shop.php<?= $product['category_id'] ? '?category=' . (int)$product['category_id'] : '' ?>" class="btn btn-outline-secondary ms-2">
              <i class="bi bi-arrow-left"></i> กลับไปหน้าร้าน
            </a>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>