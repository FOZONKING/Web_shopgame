<?php
session_start();
require __DIR__ . '/db.php'; // Includes PDO, helpers h(), q(), q1(), getStatusInfo() if defined

/* ---------- helpers ---------- */
// Ensure helpers are defined (or rely on db.php)
if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('qAll')) {
  function qAll($sql, $p = [])
  {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
if (!function_exists('q1')) {
  function q1($sql, $p = [])
  {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC);
  }
}


/* ---------- user ---------- */
$user = $_SESSION['user'] ?? null;
$uid  = $user['id'] ?? null;
$role = $user['role'] ?? null; // Role is null if not logged in

/* ---------- cart count (Buyer & Seller) ---------- */
$cartCount = 0;
if (($role === 'buyer' || $role === 'seller') && $uid) {
  $userCart = $_SESSION['carts'][$uid] ?? [];
  $cartCount = is_array($userCart) ? array_sum($userCart) : 0;
}

/* ---------- order count badge (Buyer & Seller view their own placed orders) ---------- */
$orderCount = 0;
if ($uid && ($role === 'buyer' || $role === 'seller')) { // Only calc for buyer/seller view
  $row = q1("SELECT COUNT(*) c FROM orders WHERE user_id=?", [$uid]);
  $orderCount = (int)($row['c'] ?? 0);
}

/* ---------- seller orders badge (Specific to Seller role) ---------- */
$sellerOrdersCount = 0;
if ($role === 'seller' && $uid) {
  $r = q1("SELECT COUNT(DISTINCT o.id) c FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE oi.seller_id=?", [$uid]);
  $sellerOrdersCount = (int)($r['c'] ?? 0);
}

/* ---------- popular categories ---------- */
$popularCategories = qAll("
  SELECT c.id, c.name, COUNT(p.id) AS cnt
  FROM categories c
  LEFT JOIN products p ON p.category_id=c.id
  GROUP BY c.id, c.name
  ORDER BY cnt DESC, c.name ASC
  LIMIT 6
");

/* ---------- featured products ---------- */
$featuredProducts = qAll("
  SELECT p.id, p.name, p.price, p.image1, p.quantity, p.reserved, p.created_at, p.seller_id, u.name as seller_name /* Added seller name */
  FROM products p
  LEFT JOIN users u ON p.seller_id = u.id /* Join users table */
  WHERE (p.quantity IS NULL OR p.quantity > p.reserved)
  ORDER BY COALESCE(p.created_at, NOW()) DESC
  LIMIT 6
");
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <title>Web Shop Game - ตลาดกลางซื้อขายไอเทมเกม</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
      --muted: #6c757d;
      --text: #1a202c;
      --brand: #22c55e;
      --brand-dark: #16a34a;
      --brand-light: #dcfce7;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Sora', sans-serif;
    }

    .navbar {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, .85) !important;
      border-bottom: 1px solid var(--border);
    }

    .navbar-brand strong {
      font-weight: 800;
    }

    .brand-dot {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--brand);
    }

    .btn-icon {
      position: relative;
      width: 42px;
      height: 42px;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--card);
      color: var(--text);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all .2s;
    }

    .btn-icon:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, .08);
      color: var(--brand);
    }

    .badge-dot {
      position: absolute;
      top: -2px;
      right: -4px;
      min-width: 20px;
      height: 20px;
      border-radius: 10px;
      font-size: .75rem;
      border: 2px solid var(--card);
    }

    .avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: var(--brand);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
    }

    .btn-brand {
      background: var(--brand);
      border: none;
      color: #ffffff;
      font-weight: 700;
      border-radius: 12px;
      padding: .6rem 1.2rem;
      transition: all .2s;
    }

    .btn-brand:hover {
      background: var(--brand-dark);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(34, 197, 94, .3);
    }

    .btn.btn-outline-success {
      border-color: var(--brand);
      color: var(--brand);
      font-weight: 600;
      border-radius: 12px;
      transition: all .2s;
    }

    .btn.btn-outline-success:hover {
      background: var(--brand);
      color: #fff;
    }

    .hero {
      padding: 80px 0 60px;
      text-align: center;
      background-image: radial-gradient(circle at 10% 20%, rgba(34, 197, 94, 0.08), transparent 40%), radial-gradient(circle at 90% 80%, rgba(34, 197, 94, 0.08), transparent 40%);
      border-bottom: 1px solid var(--border);
    }

    .hero h1 {
      font-weight: 800;
      font-size: 2.8rem;
    }

    .hero p {
      color: var(--muted);
      max-width: 600px;
      margin: 16px auto 0;
      font-size: 1.1rem;
    }

    /* --- [เพิ่ม] Hero Search Bar Styles --- */
    .hero-search-form {
      max-width: 600px;
      margin: 2rem auto 0;
      /* Add space above */
      position: relative;
    }

    .hero-search-input {
      height: 3.5rem;
      /* Taller input */
      padding: 0.5rem 4rem 0.5rem 1.5rem;
      /* Space for icon and button */
      border-radius: 999px;
      /* Pill shape */
      border: 1px solid var(--border);
      font-size: 1rem;
      box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
    }

    .hero-search-input:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 4px rgba(34, 197, 94, .15);
    }

    .hero-search-button {
      position: absolute;
      top: 5px;
      right: 5px;
      bottom: 5px;
      border-radius: 999px;
      /* Match input shape */
      padding: 0 1.5rem;
      font-weight: 700;
      background-color: var(--brand);
      border: none;
      color: white;
      transition: background-color 0.2s;
    }

    .hero-search-button:hover {
      background-color: var(--brand-dark);
    }

    .hero-search-button i {
      margin-right: 0.3rem;
    }

    /* --- [จบ] Hero Search Bar Styles --- */

    .mini-cards .card {
      background: rgba(255, 255, 255, .7);
      border: 1px solid #fff;
      backdrop-filter: blur(5px);
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .04);
    }

    .section {
      padding: 48px 0;
    }

    .section-title {
      font-weight: 700;
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

    .category-card {
      padding: 16px;
      border-radius: 16px;
      text-align: center;
      border: 1px solid var(--border);
      transition: all .2s;
    }

    .category-card:hover {
      border-color: var(--brand);
      color: var(--brand-dark) !important;
      transform: translateY(-3px);
    }

    .product-img-container {
      aspect-ratio: 1/1;
      background-color: #f8f9fa;
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
    }

    .badge {
      font-weight: 600;
      padding: .4em .7em;
    }

    .badge-oos {
      background-color: #f1f5f9;
      color: #64748b;
    }

    .badge-low {
      background-color: var(--brand-light);
      color: var(--brand-dark);
    }

    footer {
      color: #94a3b8;
      margin-top: 48px;
      border-top: 1px solid var(--border);
      /* Added */
      padding-top: 1rem;
      /* Added */
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

    .seller-info {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 0.5rem;
    }

    /* Style for seller name */
  </style>
</head>

<body>

  <nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
        <div class="brand-dot"></div><strong class="fw-bolder">Web Shop Game</strong>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
          <li class="nav-item"> <a href="shop.php" class="nav-link fw-semibold">ร้านค้า</a> </li>
          <?php if ($uid): // Logged in 
          ?>
            <?php if ($role === 'buyer' || $role === 'seller'): ?>
              <li class="nav-item"> <a href="order_list.php" class="btn-icon" title="คำสั่งซื้อของฉัน"> <i class="bi bi-receipt fs-5"></i> <?php if ($orderCount > 0): ?><span class="badge bg-success badge-dot"><?= $orderCount ?></span><?php endif; ?> </a> </li>
              <li class="nav-item"> <a href="cart.php" class="btn-icon" title="ตะกร้าสินค้า"> <i class="bi bi-cart3 fs-5"></i> <?php if ($cartCount > 0): ?><span class="badge bg-danger badge-dot"><?= $cartCount ?></span><?php endif; ?> </a> </li>
            <?php endif; ?>
            <?php if ($role === 'seller'): ?>
              <li class="nav-item d-none d-lg-block"> <a href="seller_orders.php" class="btn btn-sm btn-outline-info d-flex align-items-center" title="ออเดอร์ร้านฉัน"> <i class="bi bi-receipt-cutoff me-1"></i> ออเดอร์ร้าน <?= $sellerOrdersCount > 0 ? '<span class="badge bg-info ms-1">' . $sellerOrdersCount . '</span>' : '' ?> </a> </li>
            <?php endif; ?>
            <?php if ($role === 'buyer'): ?>
              <li class="nav-item d-none d-lg-block"> <a href="register_seller.php" class="btn btn-sm btn-outline-success"> <i class="bi bi-patch-check"></i> สมัครเป็นผู้ขาย </a> </li>
            <?php endif; ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                <div class="avatar"><?= strtoupper(substr($user['name'] ?? $user['email'], 0, 1)) ?></div>
                <span class="d-none d-sm-inline fw-semibold"><?= h($user['name'] ?? $user['email']) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 1rem;">
                <?php if ($role === 'admin'): ?>
                  <li><a class="dropdown-item" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                  <li><a class="dropdown-item" href="admin_payments_review.php"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป</a></li>
                  <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-person-check me-2"></i>ตรวจสอบคำขอผู้ขาย</a></li>
                  <li><a class="dropdown-item" href="admin_manage_users.php"><i class="bi bi-people me-2"></i>จัดการผู้ใช้</a></li>
                  <li><a class="dropdown-item" href="manage_categories.php"><i class="bi bi-tags me-2"></i>จัดการหมวดหมู่</a></li>
                  <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                <?php elseif ($role === 'seller'): ?>
                  <li><a class="dropdown-item" href="seller_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Seller Dashboard</a></li>
                  <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                  <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-2"></i>ออเดอร์ร้านฉัน</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                  <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                  <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                  <li><a class="dropdown-item" href="cart.php"><i class="bi bi-cart3 me-2"></i>ตะกร้าสินค้า</a></li>
                <?php elseif ($role === 'buyer'): ?>
                  <li><a class="dropdown-item" href="register_seller.php"><i class="bi bi-patch-check me-2"></i>สมัครเป็นผู้ขาย</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                  <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                  <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                  <li><a class="dropdown-item" href="cart.php"><i class="bi bi-cart3 me-2"></i>ตะกร้าสินค้า</a></li>
                <?php endif; ?>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
              </ul>
            </li>
          <?php else: // Not logged in 
          ?>
            <li class="nav-item"> <a href="register.php" class="btn btn-outline-success">สมัครสมาชิก</a> </li>
            <li class="nav-item"> <a href="login.php" class="btn btn-brand">เข้าสู่ระบบ</a> </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <section class="hero">
    <div class="container">
      <h1>ซื้อขายปลอดภัย ด้วย<br><span style="color:var(--brand)">Escrow</span> + <span class="text-info">eKYC</span></h1>
      <p>พักเงินกลาง ปล่อยเมื่อผู้ซื้อยืนยัน เพิ่มความมั่นใจทั้งสองฝ่าย</p>

      <form action="shop.php" method="get" class="hero-search-form">
        <input type="search" name="q" class="form-control hero-search-input" placeholder="ค้นหาไอเทม หรือ ชื่อเกม..." aria-label="Search">
        <button type="submit" class="btn hero-search-button">
          <i class="bi bi-search"></i> ค้นหา
        </button>
      </form>
      <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
        <a href="shop.php" class="btn btn-brand btn-lg px-4"> <i class="bi bi-shop me-1"></i> เริ่มช้อปเลย </a>
        <?php if ($uid && $role === 'buyer'): ?>
          <a href="register_seller.php" class="btn btn-outline-success btn-lg px-4"> <i class="bi bi-patch-check me-1"></i> สมัครเป็นผู้ขาย </a>
        <?php endif; ?>
        <?php if ($role === 'seller'): ?>
          <a href="manage_products.php" class="btn btn-outline-secondary btn-lg px-4"> <i class="bi bi-box-seam me-1"></i> จัดการสินค้า </a>
          <a href="seller_orders.php" class="btn btn-outline-info btn-lg px-4"> <i class="bi bi-receipt-cutoff me-1"></i> ดูออเดอร์ร้าน </a>
        <?php endif; ?>
      </div>

      <div class="row g-3 mt-5 mini-cards">
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <h5 class="mb-0"><i class="bi bi-shield-check text-success fs-4 me-2"></i>Escrow ปลอดภัย</h5>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <h5 class="mb-0"><i class="bi bi-person-badge text-info fs-4 me-2"></i>ยืนยันตัวตนผู้ขาย</h5>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <h5 class="mb-0"><i class="bi bi-lock text-warning fs-4 me-2"></i>สถานะโปร่งใส</h5>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <h3 class="mb-4 section-title">หมวดหมู่ยอดนิยม</h3>
      <div class="row g-3">
        <?php if (!$popularCategories): ?>
          <div class="text-muted">ยังไม่มีหมวดหมู่</div>
          <?php else: foreach ($popularCategories as $c): ?>
            <div class="col-6 col-md-4 col-lg-2">
              <a href="shop.php?category=<?= (int)$c['id'] ?>" class="text-decoration-none text-dark">
                <div class="card category-card">
                  <div class="fw-semibold text-truncate"><?= h($c['name']) ?></div>
                  <div class="small text-secondary mt-1"><?= (int)$c['cnt'] ?> รายการ</div>
                </div>
              </a>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
  </section>

  <section class="section pt-0">
    <div class="container">
      <h3 class="mb-4 section-title">มาใหม่ล่าสุด</h3>
      <div class="row g-4">
        <?php if (!$featuredProducts): ?>
          <div class="col-12">
            <div class="alert alert-light text-center">ยังไม่มีสินค้าใหม่</div>
          </div>
          <?php else: foreach ($featuredProducts as $p):
            $qty = $p['quantity'];
            $res = $p['reserved'];
            $remain = ($qty === null) ? null : ((int)$qty - (int)$res);
            $available = ($qty === null) || ($remain > 0);
            $low = $available && $qty !== null && $remain <= 3;
            $canAddToCart = $available && ($role === 'buyer' || ($role === 'seller' && $p['seller_id'] != $uid));
          ?>
            <div class="col-sm-6 col-lg-4">
              <div class="card h-100 product-card position-relative">
                <div class="position-absolute top-0 end-0 m-3 z-1"> <?php if (!$available): ?><span class="badge badge-oos">หมดสต็อก</span> <?php elseif ($low): ?><span class="badge badge-low">ใกล้หมด</span> <?php endif; ?> </div>
                <a href="view_product.php?id=<?= (int)$p['id'] ?>">
                  <div class="product-img-container"> <img class="product-img" src="<?= h($p['image1'] ?: 'https://via.placeholder.com/400?text=No+Image') ?>" alt="<?= h($p['name']) ?>"> </div>
                </a>
                <div class="card-body d-flex flex-column">
                  <h5 class="fw-bold text-truncate mb-1" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></h5>
                  <div class="seller-info"><i class="bi bi-shop me-1"></i><?= h($p['seller_name'] ?: 'ไม่ระบุ') ?></div>
                  <div class="h4 fw-bolder mb-3" style="color:var(--brand)">฿<?= number_format($p['price'], 0) ?></div>

                  <div class="mt-auto pt-2 card-actions">
                    <div class="d-grid gap-2 d-sm-flex">
                      <a href="view_product.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary flex-grow-1">ดูรายละเอียด</a>
                      <?php if ($canAddToCart): ?> <a href="add_to_cart.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-brand"><i class="bi bi-cart3"></i></a>
                      <?php elseif (!$available) : ?> <button class="btn btn-sm btn-secondary" disabled><i class="bi bi-cart-x"></i></button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
      <div class="text-center mt-4"> <a href="shop.php" class="btn btn-outline-success btn-lg">ดูสินค้าทั้งหมด <i class="bi bi-arrow-right"></i></a> </div>
    </div>
  </section>

  <footer class="text-center py-4 mt-4 border-top"> <small>© <?= date('Y') ?> Web Shop Game • Secure Marketplace</small> </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>