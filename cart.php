<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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

/* cart helpers - แยกตะกร้าต่อ user */
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

/* โหลดสินค้าในตะกร้า + ดึง seller ด้วย */
$cart   = get_user_cart();
$total  = 0.0;
$groups = []; // seller_id => ['seller_name'=>..., 'items'=>[], 'subtotal'=>...]

if ($cart && is_array($cart)) {
  $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($v) => $v > 0));
  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT p.id, p.name, p.price, p.image1, p.seller_id, u.name AS seller_name, u.email AS seller_email
            FROM products p
            LEFT JOIN users u ON u.id = p.seller_id
            WHERE p.id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $valid = [];
    foreach ($rows as $p) {
      $pid = (int)$p['id'];
      $qty = (int)($cart[$pid] ?? 0);
      if ($qty <= 0) continue;

      $price    = (float)$p['price'];
      $subtotal = $qty * $price;
      $total   += $subtotal;

      $sellerId   = (int)($p['seller_id'] ?? 0);
      $sellerName = $p['seller_name'] ?: ($p['seller_email'] ?: 'ไม่ระบุผู้ขาย');

      if (!isset($groups[$sellerId])) {
        $groups[$sellerId] = [
          'seller_name' => $sellerName,
          'items'       => [],
          'subtotal'    => 0.0,
        ];
      }

      $groups[$sellerId]['items'][] = [
        'id'       => $pid,
        'name'     => $p['name'],
        'price'    => $price,
        'image1'   => $p['image1'],
        'qty'      => $qty,
        'subtotal' => $subtotal,
      ];
      $groups[$sellerId]['subtotal'] += $subtotal;
      $valid[] = $pid;
    }

    // ล้าง id ผี
    $changed = false;
    foreach ($cart as $pid => $qty) {
      if (!in_array((int)$pid, $valid, true)) {
        unset($cart[$pid]);
        $changed = true;
      }
    }
    if ($changed) set_user_cart($cart);
  }
}
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <title>ตะกร้าสินค้า - Web Shop Game</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --bg: #0b0f14;
      --card: #0f172a;
      --line: rgba(148, 163, 184, .18);
    }

    body {
      background: var(--bg);
      color: #e5e7eb;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 16px;
    }

    .product-img {
      width: 70px;
      height: 70px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid var(--line);
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .35rem .7rem;
      border: 1px solid var(--line);
      border-radius: 999px;
    }

    .qty-btn {
      width: 34px
    }

    .seller-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .9rem 1rem;
      border-bottom: 1px solid var(--line);
    }

    .seller-name {
      font-weight: 600;
    }

    .seller-sub {
      color: #a1a1aa;
      font-size: .95rem;
    }

    .totals-sticky {
      position: sticky;
      bottom: 1rem;
      z-index: 5;
      background: linear-gradient(180deg, transparent, var(--bg) 12%);
      padding-top: 1rem;
    }

    .summary-box {
      background: #0d1324;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 1rem;
    }

    .btn-outline-light {
      border-color: var(--line);
    }
  </style>
</head>

<body>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0"><i class="bi bi-cart3 me-2"></i>ตะกร้าสินค้า</h3>
      <span class="chip"><i class="bi bi-person-circle"></i><span><?= h($user['name'] ?? $user['email']) ?></span></span>
    </div>

    <?php if (empty($groups)): ?>
      <div class="card p-4 text-center mb-3">
        <div class="fs-5 text-secondary">ตะกร้าว่างเปล่า</div>
      </div>
      <a href="shop.php" class="btn btn-success"><i class="bi bi-shop"></i> เลือกซื้อสินค้า</a>
    <?php else: ?>

      <?php foreach ($groups as $sellerId => $g): ?>
        <div class="card mb-3" data-seller="<?= (int)$sellerId ?>">
          <div class="seller-head">
            <div class="seller-name"><i class="bi bi-shop me-2"></i><?= h($g['seller_name']) ?></div>
            <div class="seller-sub">
              รวมร้านนี้: <span class="text-success fw-semibold seller-subtotal">฿<?= number_format($g['subtotal'], 2) ?></span>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-dark align-middle mb-0">
              <thead class="table-borderless">
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
                        <button class="btn btn-outline-light qty-btn btn-decrease">–</button>
                        <input class="form-control form-control-sm text-center bg-dark text-light" style="width:70px" value="<?= (int)$it['qty'] ?>" readonly>
                        <button class="btn btn-outline-light qty-btn btn-increase">+</button>
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
            <a href="shop.php" class="btn btn-outline-light"><i class="bi bi-shop"></i> เลือกซื้อสินค้าต่อ</a>
            <form action="create_order.php" method="post">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <!-- หมายเหตุ: create_order.php ควร “แยกออเดอร์ตาม seller_id” โดยอ่านจากตะกร้านี้ -->
              <button type="submit" class="btn btn-success">
                <i class="bi bi-bag-check"></i> สั่งซื้อทั้งหมด (แยกตามร้าน)
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
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

      // อัปเดตรายการที่เปลี่ยน
      const row = document.querySelector(`tr[data-id="${id}"]`);
      if (action === 'remove' || !data.cart[id]) {
        if (row) {
          const sellerCard = row.closest('.card');
          row.remove();
          // ถ้าร้านนี้ไม่มีสินค้าแล้ว ลบการ์ดร้าน
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

      // อัปเดตรวมร้าน (per seller)
      if (data.seller_totals) {
        Object.entries(data.seller_totals).forEach(([sellerId, val]) => {
          const card = document.querySelector(`.card[data-seller="${sellerId}"]`);
          if (card) {
            const el = card.querySelector('.seller-subtotal');
            if (el) el.innerText = '฿' + Number(val).toLocaleString(undefined, {
              minimumFractionDigits: 2
            });
            // ถ้ารวมร้านกลายเป็น 0 และไม่มีแถว -> ลบการ์ดได้ (เผื่อกรณี remove ทั้งร้านด้วยการแก้ qty)
            if (Number(val) <= 0 && card.querySelectorAll('tbody tr').length === 0) {
              card.remove();
            }
          }
        });
      }

      // อัปเดตรวมทั้งหมด
      const totalEl = document.getElementById('cartTotal');
      if (totalEl) totalEl.innerText = '฿' + Number(data.total).toLocaleString(undefined, {
        minimumFractionDigits: 2
      });

      // ถ้าตะกร้ากลายเป็นว่าง รีเฟรชเพื่อแสดง empty state
      if (!data.cart || Object.keys(data.cart).length === 0) location.reload();
    }

    // bind ปุ่มทุกแถว
    document.querySelectorAll('tbody tr').forEach(tr => {
      const id = tr.dataset.id;
      tr.querySelector('.btn-increase').onclick = () => updateCart(id, 'increase');
      tr.querySelector('.btn-decrease').onclick = () => updateCart(id, 'decrease');
      tr.querySelector('.btn-remove').onclick = () => confirm('เอาสินค้านี้ออกจากตะกร้า?') && updateCart(id, 'remove');
    });
  </script>
</body>

</html>