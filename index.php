<?php
session_start();
require __DIR__ . '/db.php';

/* ---------- helpers ---------- */
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function qAll($sql, $p = [])
{
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function q1($sql, $p = [])
{
  global $pdo;
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetch(PDO::FETCH_ASSOC);
}

/* ---------- user ---------- */
$user = $_SESSION['user'] ?? null;
$uid  = $user['id'] ?? null;
$role = $user['role'] ?? null;

/* ---------- cart count (per-user) ---------- */
$cartCount = 0;
if ($uid) {
  $userCart = $_SESSION['carts'][$uid] ?? [];
  $cartCount = is_array($userCart) ? array_sum($userCart) : 0;
}

/* ---------- order count badge ---------- */
$orderCount = 0;
if ($uid) {
  if ($role === 'seller') {
    $row = q1("SELECT COUNT(DISTINCT o.id) c
               FROM orders o
               JOIN order_items oi ON oi.order_id=o.id
               WHERE oi.seller_id=?", [$uid]);
    $orderCount = (int)($row['c'] ?? 0);
  } elseif ($role === 'admin') {
    $row = q1("SELECT COUNT(*) c FROM orders");
    $orderCount = (int)($row['c'] ?? 0);
  } else {
    $row = q1("SELECT COUNT(*) c FROM orders WHERE user_id=?", [$uid]);
    $orderCount = (int)($row['c'] ?? 0);
  }
}

/* ---------- seller orders badge ---------- */
$sellerOrdersCount = 0;
if ($uid && $role === 'seller') {
  $r = q1("SELECT COUNT(DISTINCT o.id) c
           FROM orders o
           JOIN order_items oi ON oi.order_id=o.id
           WHERE oi.seller_id=?", [$uid]);
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

/* ---------- featured products (compact: 6 items) ---------- */
$featuredProducts = qAll("
  SELECT id, name, price, image1, quantity, reserved, created_at
  FROM products
  ORDER BY COALESCE(created_at, NOW()) DESC
  LIMIT 6
");
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <title>Web Shop Game</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --bg: #0b0f14;
      --card: #0f1620;
      --border: rgba(148, 163, 184, .18);
      --muted: #94a3b8;
      --brand: #22c55e;
      --brand2: #0ea5e9;
    }

    body {
      background: var(--bg);
      color: #e5e7eb;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }

    .navbar {
      backdrop-filter: blur(8px);
      background: rgba(15, 23, 42, .88) !important;
      border-bottom: 1px solid var(--border);
    }

    .brand-dot {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand), var(--brand2));
    }

    .btn-icon {
      position: relative;
      width: 40px;
      height: 40px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #0f172a;
      color: #e5e7eb;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .badge-dot {
      position: absolute;
      top: -4px;
      right: -6px;
      min-width: 18px;
      height: 18px;
      padding: 0 4px;
      border-radius: 9px;
      font-size: .7rem;
    }

    .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand), var(--brand2));
      display: flex;
      align-items: center;
      justify-content: center;
      color: #001b0f;
      font-weight: 800;
      box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .2);
    }

    .btn-brand {
      background: linear-gradient(90deg, var(--brand), #34d399);
      border: none;
      color: #052e1a;
      font-weight: 700;
    }

    .btn-seller {
      background: linear-gradient(90deg, #38bdf8, #60a5fa);
      border: none;
      color: #051827;
      font-weight: 700;
    }

    /* HERO — compact */
    .hero {
      padding: 70px 0 46px;
      text-align: center;
      background:
        radial-gradient(900px 300px at 20% -10%, rgba(34, 197, 94, .12), transparent),
        radial-gradient(700px 240px at 90% -15%, rgba(14, 165, 233, .10), transparent);
      border-bottom: 1px solid var(--border);
    }

    .hero h1 {
      font-weight: 900;
      letter-spacing: .2px;
      font-size: 2.2rem;
    }

    .hero p {
      color: var(--muted);
      max-width: 640px;
      margin: 12px auto 0;
    }

    /* small selling points */
    .mini-cards .card {
      background: #0d1420;
      border: 1px solid var(--border);
      border-radius: 14px;
    }

    /* sections */
    .section {
      padding: 36px 0;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      transition: .25s;
    }

    .card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 28px rgba(0, 0, 0, .35);
    }

    .category-card {
      padding: 16px;
      border-radius: 12px;
      background: #122032;
      border: 1px solid var(--border);
      text-align: center;
    }

    .product-img {
      aspect-ratio: 16/9;
      width: 100%;
      object-fit: cover;
      border-top-left-radius: 16px;
      border-top-right-radius: 16px;
    }

    .badge-oos {
      background: #475569;
      border: 1px solid rgba(255, 255, 255, .15);
    }

    .badge-low {
      background: #1f2937;
      border: 1px solid rgba(34, 197, 94, .35);
      color: #86efac;
    }

    footer {
      color: #64748b;
      margin-top: 48px;
    }
  </style>
</head>

<body>

  <!-- NAV -->
  <nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
        <div class="brand-dot"></div><strong>Web Shop Game</strong>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
          <!-- Orders icon -->
          <li class="nav-item">
            <a href="order_list.php" class="btn-icon" title="คำสั่งซื้อของฉัน">
              <i class="bi bi-receipt"></i>
              <?php if ($orderCount > 0): ?><span class="badge bg-success badge-dot"><?= $orderCount ?></span><?php endif; ?>
            </a>
          </li>

          <!-- Seller orders (only for sellers) -->
          <?php if ($uid && $role === 'seller'): ?>
            <li class="nav-item d-none d-lg-block">
              <a href="seller_orders.php" class="btn btn-sm btn-outline-info d-flex align-items-center">
                <i class="bi bi-receipt-cutoff me-1"></i> ออเดอร์ร้านฉัน
                <?php if ($sellerOrdersCount > 0): ?><span class="badge bg-info ms-2"><?= $sellerOrdersCount ?></span><?php endif; ?>
              </a>
            </li>
          <?php endif; ?>

          <!-- Cart icon -->
          <li class="nav-item">
            <a href="cart.php" class="btn-icon" title="ตะกร้าสินค้า">
              <i class="bi bi-cart3"></i>
              <?php if ($cartCount > 0): ?><span class="badge bg-danger badge-dot"><?= $cartCount ?></span><?php endif; ?>
            </a>
          </li>

          <?php if ($uid): ?>
            <!-- (show Apply Seller only if NOT seller) -->
            <?php if ($role !== 'seller'): ?>
              <li class="nav-item d-none d-lg-block">
                <a href="register_seller.php" class="btn btn-sm btn-outline-success">
                  <i class="bi bi-patch-check"></i> สมัครเป็นผู้ขาย
                </a>
              </li>
            <?php endif; ?>

            <!-- Profile -->
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                <div class="avatar"><?= strtoupper(substr($user['name'] ?? $user['email'], 0, 1)) ?></div>
                <span class="d-none d-sm-inline"><?= h($user['name'] ?? $user['email']) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow">
                <?php if ($role === 'admin'): ?>
                  <li><a class="dropdown-item" href="admin_payments_review.php"><i class="bi bi-cash-stack me-1"></i> ตรวจสลิป</a></li>
                  <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-patch-check me-1"></i> ตรวจคำขอผู้ขาย</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php endif; ?>

                <?php if ($role === 'seller'): ?>
                  <li><a class="dropdown-item" href="manage_products.php"><i class="bi bi-box-seam me-1"></i> จัดการสินค้า</a></li>
                  <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-1"></i> ออเดอร์ร้านฉัน</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php else: ?>
                  <li><a class="dropdown-item" href="register_seller.php"><i class="bi bi-patch-check me-1"></i> สมัครเป็นผู้ขาย</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php endif; ?>

                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-1"></i> โปรไฟล์</a></li>
                <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-1"></i> คำสั่งซื้อของฉัน</a></li>
                <li><a class="dropdown-item" href="cart.php"><i class="bi bi-cart3 me-1"></i> ไปตะกร้า</a></li>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ</a></li>
              </ul>
            </li>
          <?php else: ?>
            <li class="nav-item"><a href="login.php" class="btn btn-brand">เข้าสู่ระบบ</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <!-- HERO (compact & clean) -->
  <section class="hero">
    <div class="container">
      <h1>ซื้อขายปลอดภัยด้วย <span class="text-success">Escrow</span> + <span class="text-info">eKYC</span></h1>
      <p>พักเงินกลาง ปล่อยเมื่อผู้ซื้อยืนยัน เพิ่มความมั่นใจทั้งสองฝ่าย</p>

      <div class="d-flex justify-content-center gap-2 mt-3 flex-wrap">
        <a href="shop.php" class="btn btn-brand btn-lg px-4">
          <i class="bi bi-shop me-1"></i> เริ่มช้อป
        </a>

        <?php if ($role === 'seller'): ?>
          <a href="seller_orders.php" class="btn btn-seller btn-lg px-4">
            <i class="bi bi-receipt-cutoff me-1"></i> ออเดอร์ร้านฉัน
            <?php if ($sellerOrdersCount > 0): ?><span class="badge bg-dark ms-2"><?= $sellerOrdersCount ?></span><?php endif; ?>
          </a>
          <a href="manage_products.php" class="btn btn-outline-light btn-lg px-4">
            <i class="bi bi-box-seam me-1"></i> วางขายสินค้า
          </a>
        <?php elseif ($uid): ?>
          <!-- Show apply-seller button only to logged-in non-sellers -->
          <a href="register_seller.php" class="btn btn-outline-success btn-lg px-4">
            <i class="bi bi-patch-check me-1"></i> สมัครเป็นผู้ขาย
          </a>
        <?php endif; ?>
      </div>

      <!-- 3 selling points (minimal) -->
      <div class="row g-3 mt-4 mini-cards">
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <i class="bi bi-shield-check text-success fs-3 mb-2"></i>
            Escrow ปลอดภัย
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <i class="bi bi-person-badge text-info fs-3 mb-2"></i>
            ยืนยันตัวตนผู้ขาย
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card p-3 text-center h-100">
            <i class="bi bi-lock text-warning fs-3 mb-2"></i>
            สถานะโปร่งใส
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- POPULAR CATEGORIES -->
  <section class="section">
    <div class="container">
      <h4 class="mb-3">หมวดหมู่ยอดนิยม</h4>
      <div class="row g-3">
        <?php if (!$popularCategories): ?>
          <div class="text-muted">ยังไม่มีหมวดหมู่</div>
          <?php else: foreach ($popularCategories as $c): ?>
            <div class="col-6 col-md-4 col-lg-2">
              <a href="shop.php?category=<?= (int)$c['id'] ?>" class="text-decoration-none text-light">
                <div class="category-card">
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

  <!-- FEATURED PRODUCTS -->
  <section class="section pt-0">
    <div class="container">
      <h4 class="mb-3">มาใหม่ล่าสุด</h4>
      <div class="row g-4">
        <?php if (!$featuredProducts): ?>
          <div class="text-muted">ยังไม่มีสินค้า</div>
          <?php else: foreach ($featuredProducts as $p):
            $qty = $p['quantity'];
            $res = $p['reserved'];
            $remain = ($qty === null) ? null : ((int)$qty - (int)$res);
            $available = ($qty === null) || ($remain > 0);
            $low = $available && $qty !== null && $remain <= 3;
          ?>
            <div class="col-sm-6 col-lg-4">
              <div class="card h-100 position-relative">
                <?php if (!$available): ?>
                  <span class="position-absolute top-0 end-0 m-2 badge badge-oos">หมด</span>
                <?php elseif ($low): ?>
                  <span class="position-absolute top-0 end-0 m-2 badge badge-low">ใกล้หมด</span>
                <?php endif; ?>
                <img src="<?= h($p['image1'] ?: 'https://via.placeholder.com/480x270?text=No+Image') ?>" class="product-img" alt="">
                <div class="card-body d-flex flex-column">
                  <h6 class="fw-bold text-truncate" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></h6>
                  <div class="text-success fw-bold mb-2">฿<?= number_format($p['price'], 2) ?></div>
                  <div class="mt-auto d-flex gap-2">
                    <a href="view_product.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-light">ดู</a>
                    <?php if ($available): ?>
                      <a href="add_to_cart.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-brand">ใส่ตะกร้า</a>
                    <?php else: ?>
                      <button class="btn btn-sm btn-secondary" disabled>หมด</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
  </section>

  <footer class="text-center py-4">
    <small>© <?= date('Y') ?> Web Shop Game • Secure Marketplace</small>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>