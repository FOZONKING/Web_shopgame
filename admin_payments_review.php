<?php
// admin_payments_review.php — แอดมินตรวจสลิปและยืนยันเงินเข้า + แสดงสินค้า/ผู้ขายต่อออเดอร์
session_start();
require __DIR__ . '/db.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ------ auth: admin only ------
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ดูแลระบบเท่านั้น'];
    header('Location: index.php');
    exit;
}
$admin_id = (int)$user['id'];

// ------ csrf ------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

// ------ filter + search ------
$tab = $_GET['tab'] ?? 'pending'; // pending|approved|rejected|all
$q   = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if (in_array($tab, ['pending', 'approved', 'rejected'], true)) {
    $where[] = "p.status=?";
    $params[] = $tab;
}
if ($q !== '') {
    $where[] = "(o.order_number LIKE ? OR u.email LIKE ? OR u.name LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ------ action: approve / reject ------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'CSRF ไม่ถูกต้อง'];
    } else {
        $pid    = (int)($_POST['payment_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $note   = trim($_POST['note'] ?? '');

        // ดึง payment + order
        $st = $pdo->prepare("
      SELECT p.*, o.user_id, o.order_number, o.status AS order_status
      FROM payments p
      JOIN orders o ON o.id=p.order_id
      WHERE p.id=? LIMIT 1
    ");
        $st->execute([$pid]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);

        if (!$pay) {
            $flash = ['type' => 'danger', 'msg' => 'ไม่พบรายการชำระเงิน'];
        } else {
            try {
                $pdo->beginTransaction();
                if ($action === 'approve') {
                    if ($pay['status'] !== 'approved') {
                        $pdo->prepare("
              UPDATE payments
              SET status='approved', reviewed_by=?, reviewed_at=NOW(), reject_reason=NULL
              WHERE id=?
            ")->execute([$admin_id, $pid]);

                        $pdo->prepare("
              UPDATE orders
              SET status='escrow_held', updated_at=NOW()
              WHERE id=? AND status IN ('awaiting_verification','awaiting_payment','pending')
            ")->execute([$pay['order_id']]);
                    }
                    $pdo->commit();
                    $flash = ['type' => 'success', 'msg' => 'อนุมัติสลิปแล้ว → เงินถูกพักไว้กับ Escrow'];
                } elseif ($action === 'reject') {
                    if ($pay['status'] !== 'rejected') {
                        $pdo->prepare("
              UPDATE payments
              SET status='rejected', reviewed_by=?, reviewed_at=NOW(), reject_reason=?
              WHERE id=?
            ")->execute([$admin_id, $note, $pid]);

                        $pdo->prepare("
              UPDATE orders
              SET status='awaiting_payment', updated_at=NOW()
              WHERE id=?
            ")->execute([$pay['order_id']]);
                    }
                    $pdo->commit();
                    $flash = ['type' => 'warning', 'msg' => 'ปฏิเสธสลิปแล้ว (เหตุผล: ' . h($note ?: 'ไม่ระบุ') . ')'];
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
            }
        }
    }
}

// ------ fetch list (รวม order_id) ------
$rows = $pdo->prepare("
  SELECT
    p.*,
    p.order_id,                               -- เพิ่มเพื่อไปดึงสินค้าแบบรวบ
    o.order_number, o.total_amount, o.status AS order_status,
    u.name  AS buyer_name,
    u.email AS buyer_email
  FROM payments p
  JOIN orders  o ON o.id = p.order_id
  JOIN users   u ON u.id = o.user_id
  $wsql
  ORDER BY 
    CASE p.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
    p.id DESC
  LIMIT 200
");
$rows->execute($params);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);

// ------ ดึงสินค้า/ผู้ขายสำหรับทุกออเดอร์แบบรวบเดียว ------
$itemsByOrder = [];
if ($list) {
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $list)));
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $sqlItems = "
    SELECT 
      oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
      p.image1,
      s.id AS seller_id, s.name AS seller_name, s.email AS seller_email
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN users   s ON s.id = oi.seller_id
    WHERE oi.order_id IN ($ph)
    ORDER BY oi.order_id, oi.id
  ";
    $stI = $pdo->prepare($sqlItems);
    $stI->execute($orderIds);
    while ($it = $stI->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$it['order_id'];
        $itemsByOrder[$oid][] = $it;
    }
}

// ------ counters ------
$cnt = $pdo->query("
  SELECT 
    SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) p,
    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) a,
    SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) r
  FROM payments
")->fetch(PDO::FETCH_ASSOC) ?: ['p' => 0, 'a' => 0, 'r' => 0];
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <title>ตรวจสลิป/ชำระเงิน (Admin)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0f14;
            --card: #0f1620;
            --border: rgba(148, 163, 184, .18);
        }

        body {
            background: var(--bg);
            color: #e5e7eb;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
        }

        .slip-thumb {
            width: 180px;
            height: 240px;
            object-fit: cover;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .btn-grad {
            background: linear-gradient(90deg, #16a34a, #22c55e);
            border: none;
            font-weight: 700;
        }

        .items-cell {
            max-width: 420px;
        }

        .item-row {
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            padding: .35rem 0;
            border-bottom: 1px dashed rgba(148, 163, 184, .2);
        }

        .item-row:last-child {
            border-bottom: 0;
        }

        .thumb {
            width: 56px;
            height: 42px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .seller {
            color: #94a3b8;
            font-size: .85rem;
        }

        .price {
            white-space: nowrap;
        }
    </style>
</head>

<body>

    <div class="container py-4">
        <h3 class="mb-3">ตรวจสลิป/ชำระเงิน (Admin)</h3>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <form class="d-flex gap-2 mb-3" method="get">
            <?php
            $tabs = [
                'pending'  => 'รอตรวจ (' . (int)$cnt['p'] . ')',
                'approved' => 'อนุมัติแล้ว (' . (int)$cnt['a'] . ')',
                'rejected' => 'ปฏิเสธแล้ว (' . (int)$cnt['r'] . ')',
                'all'      => 'ทั้งหมด'
            ];
            ?>
            <div class="btn-group me-auto">
                <?php foreach ($tabs as $k => $label): ?>
                    <a class="btn btn-<?= $tab === $k ? 'success' : 'outline-light' ?>" href="?tab=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
            <input class="form-control" name="q" placeholder="ค้นหา: เลขออเดอร์ / อีเมล / ชื่อ" value="<?= h($q) ?>">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <button class="btn btn-outline-light">ค้นหา</button>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-success">
                        <tr>
                            <th>#</th>
                            <th>คำสั่งซื้อ</th>
                            <th>ผู้ซื้อ</th>
                            <th class="items-cell">สินค้า / ผู้ขาย</th>
                            <th>ยอดรวม</th>
                            <th>สถานะออเดอร์</th>
                            <th>สลิป</th>
                            <th class="text-end">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$list): ?>
                            <tr>
                                <td colspan="8" class="p-4 text-secondary">ไม่มีรายการ</td>
                            </tr>
                            <?php else: foreach ($list as $i => $r): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="w-25">
                                        <div class="fw-bold"><?= h($r['order_number']) ?></div>
                                        <div class="small text-secondary"><?= h($r['method']) ?> · <?= h($r['paid_at'] ?? '') ?></div>
                                        <div class="small">
                                            <?php $c = $r['status'] === 'approved' ? 'success' : ($r['status'] === 'pending' ? 'warning text-dark' : 'danger'); ?>
                                            <span class="badge bg-<?= $c ?>"><?= h($r['status']) ?></span>
                                        </div>
                                    </td>
                                    <td class="w-25">
                                        <div class="fw-bold"><?= h($r['buyer_name'] ?: '-') ?></div>
                                        <div class="small text-secondary"><?= h($r['buyer_email'] ?: '-') ?></div>
                                        <?php if (!empty($r['reviewed_at'])): ?>
                                            <div class="small text-secondary mt-1">ตรวจเมื่อ: <?= h($r['reviewed_at']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['status'] === 'rejected' && !empty($r['reject_reason'])): ?>
                                            <div class="small text-warning mt-1">เหตุผล: <?= h($r['reject_reason']) ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- สินค้า/ผู้ขาย -->
                                    <td class="items-cell">
                                        <?php $items = $itemsByOrder[(int)$r['order_id']] ?? []; ?>
                                        <?php if (!$items): ?>
                                            <span class="text-secondary">—</span>
                                            <?php else: foreach ($items as $it): ?>
                                                <div class="item-row">
                                                    <img class="thumb" src="<?= h($it['image1'] ?: 'https://via.placeholder.com/56x42?text=No+Img') ?>" alt="">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex justify-content-between">
                                                            <div class="fw-semibold text-truncate" title="<?= h($it['name']) ?>"><?= h($it['name']) ?> × <?= (int)$it['quantity'] ?></div>
                                                            <div class="price">฿<?= number_format((float)$it['price'], 2) ?></div>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <div class="seller">ผู้ขาย: <?= h($it['seller_name'] ?: ('ID ' . $it['seller_id'])) ?></div>
                                                            <div class="seller">รวม: ฿<?= number_format((float)$it['subtotal'], 2) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                        <?php endforeach;
                                        endif; ?>
                                    </td>

                                    <td>฿<?= number_format((float)$r['total_amount'], 2) ?></td>
                                    <td>
                                        <?php
                                        $m = [
                                            'awaiting_payment' => ['รอชำระ', 'warning'],
                                            'awaiting_verification' => ['รอตรวจสลิป', 'secondary'],
                                            'escrow_held' => ['พักเงินกับ Escrow', 'info'],
                                            'shipped' => ['จัดส่งแล้ว', 'primary'],
                                            'completed' => ['สำเร็จ', 'success'],
                                            'cancelled' => ['ยกเลิก', 'dark'],
                                        ];
                                        [$t, $col] = $m[$r['order_status']] ?? [$r['order_status'], 'light'];
                                        ?>
                                        <span class="badge bg-<?= $col ?>"><?= h($t) ?></span>
                                    </td>

                                    <td>
                                        <?php if (!empty($r['slip_path'])): ?>
                                            <a href="<?= h($r['slip_path']) ?>" target="_blank" title="ดูสลิป">
                                                <img class="slip-thumb" src="<?= h($r['slip_path']) ?>" alt="">
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approve<?= $r['id'] ?>" <?= $r['status'] === 'approved' ? 'disabled' : ''; ?>>อนุมัติ</button>
                                            <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#reject<?= $r['id'] ?>" <?= $r['status'] === 'rejected' ? 'disabled' : ''; ?>>ปฏิเสธ</button>
                                        </div>

                                        <!-- modal approve -->
                                        <div class="modal fade" id="approve<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form class="modal-content" method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">อนุมัติสลิป</h5>
                                                        <button class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <p class="mb-0">จะอนุมัติคำสั่งซื้อ <strong><?= h($r['order_number']) ?></strong> และเปลี่ยนสถานะออเดอร์เป็น “พักเงินกับ Escrow”.</p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button class="btn btn-grad">ยืนยันอนุมัติ</button>
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>

                                        <!-- modal reject -->
                                        <div class="modal fade" id="reject<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form class="modal-content" method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">ปฏิเสธสลิป</h5>
                                                        <button class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                                        <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <label class="form-label">เหตุผลการปฏิเสธ</label>
                                                        <textarea class="form-control" name="note" rows="3" required placeholder="เช่น ยอด/เวลาไม่ตรงกับสลิป, รูปไม่ชัด"></textarea>
                                                        <small class="text-secondary d-block mt-2">ออเดอร์จะถูกส่งกลับไปสถานะ “รอชำระเงิน” เพื่อให้อัปโหลดสลิปใหม่</small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button class="btn btn-outline-light">ยืนยันปฏิเสธ</button>
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            <a href="index.php" class="btn btn-outline-light">กลับหน้าแรก</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>