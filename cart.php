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

/* ต้องล็อกอิน */
if (empty($_SESSION['user'])) {
  header("Location: login.php");
  exit;
}
$user = $_SESSION['user'];

/* CSRF (สำหรับปุ่มสั่งซื้อ) */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* cart helpers */
function get_user_cart(): array
{
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  return $_SESSION['carts'][$uid] ?? [];
}
function set_user_cart(array $cart): void
{
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $_SESSION['carts'][$uid] = $cart;
}

/* โหลดสินค้าในตะกร้า */
$cart   = get_user_cart();
$total  = 0.0;
$groups = []; // seller_id => [...]

if ($cart && is_array($cart)) {
  $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($v) => $v > 0));
  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = q(
      "SELECT p.id, p.name, p.price, p.image1, p.seller_id, u.name AS seller_name, u.email AS seller_email
             FROM products p LEFT JOIN users u ON u.id = p.seller_id WHERE p.id IN ($ph)",
      $ids
    );

    $current_cart_ids = array_keys($cart);
    $valid_ids = [];

    foreach ($rows as $p) {
      $pid = (int)$p['id'];
      $qty = (int)($cart[$pid] ?? 0);
      if ($qty <= 0) continue;

      $price    = (float)$p['price'];
      $subtotal = $qty * $price;
      $total   += $subtotal;
      $sellerId = (int)($p['seller_id'] ?? 0);
      $sellerName = $p['seller_name'] ?: ($p['seller_email'] ?: 'ไม่ระบุผู้ขาย');

      if (!isset($groups[$sellerId])) {
        $groups[$sellerId] = ['seller_name' => $sellerName, 'items' => [], 'subtotal' => 0.0];
      }

      $groups[$sellerId]['items'][] = ['id' => $pid, 'name' => $p['name'], 'price' => $price, 'image1' => $p['image1'], 'qty' => $qty, 'subtotal' => $subtotal];
      $groups[$sellerId]['subtotal'] += $subtotal;
      $valid_ids[] = $pid;
    }

    $invalid_ids = array_diff($current_cart_ids, $valid_ids);
    if (!empty($invalid_ids)) {
      foreach ($invalid_ids as $id) unset($cart[(int)$id]);
      set_user_cart($cart);
    }
  }
}

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
  <title>ตะกร้าสินค้า - Web Shop Game</title>
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
    }

    body {
      background: var(--bg);
      color: var(--text);
      /* เปลี่ยนสีตัวอักษรหลัก */
      font-family: 'Sora', sans-serif;
    }

    .navbar {
      background: rgba(255, 255, 255, .85);
      /* เปลี่ยนเป็นพื้นหลังสว่าง */
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
      /* ใช้เส้นขอบสีอ่อน */
      font-family: 'Sora', sans-serif;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
    }

    .product-img {
      width: 70px;
      height: 70px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid var(--border);
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .35rem .7rem;
      border: 1px solid var(--border);
      border-radius: 999px;
    }

    .qty-btn {
      width: 34px;
    }

    .seller-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .9rem 1rem;
      border-bottom: 1px solid var(--border);
      background-color: #f8f9fa;
      /* เพิ่มสีพื้นหลังหัวการ์ด */
      border-top-left-radius: 16px;
      border-top-right-radius: 16px;
    }

    .seller-name {
      font-weight: 600;
    }

    .seller-sub {
      color: #6c757d;
      font-size: .95rem;
    }

    /* ปรับสีตัวอักษรรอง */
    .totals-sticky {
      position: sticky;
      bottom: 1rem;
      z-index: 5;
      background: linear-gradient(180deg, transparent, var(--bg) 12%);
      padding-top: 1rem;
    }

    .summary-box {
      background: var(--card);
      /* เปลี่ยนพื้นหลัง */
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 1rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, .05);
      /* เพิ่มเงา */
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

  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0 fw-bold"><i class="bi bi-cart3 me-2"></i>ตะกร้าสินค้า</h3>
    </div>

    <?php if (empty($groups)): ?>
      <div class="card p-4 text-center mb-3">
        <div class="fs-5 text-secondary">ตะกร้าว่างเปล่า</div>
      </div>
      <a href="shop.php" class="btn btn-brand"><i class="bi bi-shop"></i> เลือกซื้อสินค้า</a> <?php else: ?>
      <?php foreach ($groups as $sellerId => $g): ?>
        <div class="card mb-3" data-seller="<?= (int)$sellerId ?>">
          <div class="seller-head">
            <div class="seller-name"><i class="bi bi-shop me-2"></i><?= h($g['seller_name']) ?></div>
            <div class="seller-sub">
              รวมร้านนี้: <span class="text-success fw-semibold seller-subtotal">฿<?= number_format($g['subtotal'], 2) ?></span>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-borderless" style="background-color: var(--bg);">
                <tr>
                  <th>สินค้า</th>
                  <th class="text-end">ราคา</th>
                  <th class="text-center">จำนวน</th>
                  <th class="text-end">รวม</th>
                  <th class="text-center">ลบ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($g['items'] as $it): ?>
                  <tr data-id="<?= (int)$it['id'] ?>">
                    <td>
                      <img src="<?= h($it['image1'] ?: 'https://via.placeholder.com/80') ?>" class="product-img me-2" alt="">
                      <strong><?= h($it['name']) ?></strong>
                    </td>
                    <td class="text-end">฿<?= number_format($it['price'], 2) ?></td>
                    <td class="text-center">
                      <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-secondary qty-btn btn-decrease">–</button>
                        <input class="form-control form-control-sm text-center" style="width:70px" value="<?= (int)$it['qty'] ?>" readonly>
                        <button class="btn btn-outline-secondary qty-btn btn-increase">+</button>
                      </div>
                    </td>
                    <td class="text-end subtotal">฿<?= number_format($it['subtotal'], 2) ?></td>
                    <td class="text-center">
                      <button class="btn btn-sm btn-danger btn-remove" title="นำออก">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="totals-sticky">
        <div class="summary-box mb-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
          <div class="fs-5">
            <span class="text-secondary me-2">ราคารวมทั้งหมด</span>
            <span id="cartTotal" class="text-success fw-bold">฿<?= number_format($total, 2) ?></span>
          </div>
          <div class="d-flex gap-2">
            <a href="shop.php" class="btn btn-outline-secondary"><i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ</a>
            <form action="create_order.php" method="post">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <button type="submit" class="btn btn-brand"> <i class="bi bi-bag-check"></i> สั่งซื้อทั้งหมด (แยกตามร้าน)
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Javascript ไม่ต้องแก้ เพราะทำงานกับ DOM โดยไม่สนสี
    async function updateCart(id, action, qty = 1) {
      const fd = new FormData();
      fd.append('id', id);
      fd.append('action', action);
      if (action === 'set') fd.append('qty', qty);

      const res = await fetch('update_cart.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.success) return;

      const row = document.querySelector(`tr[data-id="${id}"]`);
      if (action === 'remove' || !data.cart[id]) {
        if (row) {
          const sellerCard = row.closest('.card');
          row.remove();
          if (sellerCard && sellerCard.querySelectorAll('tbody tr').length === 0) {
            sellerCard.remove();
          }
        }
      } else if (row) {
        row.querySelector('input').value = data.cart[id].qty;
        row.querySelector('.subtotal').innerText = '฿' + Number(data.cart[id].subtotal).toLocaleString(undefined, {
          minimumFractionDigits: 2
        });
      }

      if (data.seller_totals) {
        Object.entries(data.seller_totals).forEach(([sellerId, val]) => {
          const card = document.querySelector(`.card[data-seller="${sellerId}"]`);
          if (card) {
            const el = card.querySelector('.seller-subtotal');
            if (el) el.innerText = '฿' + Number(val).toLocaleString(undefined, {
              minimumFractionDigits: 2
            });
            if (Number(val) <= 0 && card.querySelectorAll('tbody tr').length === 0) {
              card.remove();
            }
          }
        });
      }

      const totalEl = document.getElementById('cartTotal');
      if (totalEl) totalEl.innerText = '฿' + Number(data.total).toLocaleString(undefined, {
        minimumFractionDigits: 2
      });

      // อัปเดต cart count บน Navbar
      const navCartBadge = document.querySelector('.navbar .badge');
      if (navCartBadge) {
        if (data.total_items > 0) {
          navCartBadge.innerText = data.total_items;
          navCartBadge.style.display = '';
        } else {
          navCartBadge.style.display = 'none';
        }
      }

      if (!data.cart || Object.keys(data.cart).length === 0) location.reload();
    }

    document.querySelectorAll('tbody tr').forEach(tr => {
      const id = tr.dataset.id;
      tr.querySelector('.btn-increase').onclick = () => updateCart(id, 'increase');
      tr.querySelector('.btn-decrease').onclick = () => updateCart(id, 'decrease');
      tr.querySelector('.btn-remove').onclick = () => confirm('เอาสินค้านี้ออกจากตะกร้า?') && updateCart(id, 'remove');
    });
  </script>
</body>

</html>