<?php
session_start();
require __DIR__ . '/db.php';

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
  header("Location: login.php");
  exit;
}

$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) {
  echo "ไม่พบคำสั่งซื้อ";
  exit;
}

/* ดึง order พร้อมชื่อเจ้าของ */
$stmt = $pdo->prepare("
  SELECT o.*, u.name AS owner_name, u.email AS owner_email
  FROM orders o
  JOIN users u ON u.id = o.user_id
  WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  echo "ไม่พบคำสั่งซื้อ";
  exit;
}

$items = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <title>รายละเอียดคำสั่งซื้อ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body {
      background: #0b0f14;
      color: #e5e7eb;
      font-family: 'Segoe UI', sans-serif;
    }

    .card {
      background: #0f172a;
      border: 1px solid rgba(148, 163, 184, .2);
      border-radius: 16px;
      padding: 20px;
    }

    .status-badge {
      font-size: .9rem;
      padding: .4rem .8rem;
      border-radius: 8px;
    }

    .table th,
    .table td {
      vertical-align: middle;
    }

    .order-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
  </style>
</head>

<body>
  <div class="container py-4">
    <div class="order-header mb-3">
      <h2><i class="bi bi-receipt me-2"></i> คำสั่งซื้อ #<?= h($order['order_number']) ?></h2>
      <?php
      $color = match ($order['status']) {
        'awaiting_payment' => 'warning',
        'awaiting_verification' => 'secondary',
        'escrow_held' => 'info',
        'shipped' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
        default => 'secondary'
      };
      ?>
      <span class="badge bg-<?= $color ?> status-badge"><?= h($order['status']) ?></span>
    </div>

    <!-- เจ้าของออเดอร์ -->
    <div class="card mb-4">
      <h5 class="mb-3"><i class="bi bi-person-circle me-2"></i>เจ้าของคำสั่งซื้อ</h5>
      <p class="mb-1"><strong>ชื่อ:</strong> <?= h($order['owner_name'] ?: '-') ?></p>
      <p class="mb-0 text-secondary"><strong>อีเมล:</strong> <?= h($order['owner_email'] ?: '-') ?></p>
    </div>

    <!-- รายละเอียดสินค้า -->
    <div class="card mb-4">
      <h5>รายละเอียดสินค้า</h5>
      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle">
          <thead>
            <tr>
              <th>สินค้า</th>
              <th class="text-end">ราคา</th>
              <th class="text-center">จำนวน</th>
              <th class="text-end">รวม</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= h($it['name']) ?></td>
                <td class="text-end">฿<?= number_format($it['price'], 2) ?></td>
                <td class="text-center"><?= h($it['quantity']) ?></td>
                <td class="text-end">฿<?= number_format($it['subtotal'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="text-end">รวมทั้งหมด</th>
              <th class="text-end text-success fs-5">฿<?= number_format($order['total_amount'], 2) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="d-flex gap-2">
      <a href="order_list.php" class="btn btn-outline-light"><i class="bi bi-card-list"></i> กลับไปดูรายการ</a>
      <a href="index.php" class="btn btn-success"><i class="bi bi-house"></i> หน้าหลัก</a>
    </div>
  </div>
</body>

</html>