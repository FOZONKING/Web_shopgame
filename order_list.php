<?php
session_start();
require __DIR__ . '/db.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q($sql, $p = [])
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

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header("Location: login.php");
    exit;
}

$uid  = (int)$user['id'];
$role = $user['role'] ?? 'buyer';

/* --- ลบออเดอร์ --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    $allow = false;

    if ($role === 'buyer') {
        $check = q1("SELECT id FROM orders WHERE id=? AND user_id=?", [$del_id, $uid]);
        if ($check) $allow = true;
    } elseif ($role === 'seller') {
        $check = q1("SELECT o.id 
                     FROM orders o 
                     JOIN order_items oi ON oi.order_id=o.id 
                     WHERE o.id=? AND oi.seller_id=?", [$del_id, $uid]);
        if ($check) $allow = true;
    } elseif ($role === 'admin') {
        $allow = true;
    }

    if ($allow) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM payments WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM shipments WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM escrow_ledger WHERE order_id=?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$del_id]);
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'ลบคำสั่งซื้อเรียบร้อยแล้ว'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์ลบคำสั่งซื้อนี้'];
    }

    header("Location: order_list.php");
    exit;
}

/* --- ดึงออเดอร์ตามบทบาท --- */
if ($role === 'buyer') {
    $orders = q("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC", [$uid]);
} elseif ($role === 'seller') {
    $orders = q("SELECT DISTINCT o.* 
                 FROM orders o 
                 JOIN order_items oi ON oi.order_id=o.id 
                 WHERE oi.seller_id=? 
                 ORDER BY o.id DESC", [$uid]);
} else {
    $orders = q("SELECT * FROM orders ORDER BY id DESC");
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <title>รายการคำสั่งซื้อ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            background: #0b0f14;
            color: #e5e7eb;
            font-family: system-ui;
        }

        .card {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, .2);
            border-radius: 12px;
        }

        .badge-status {
            font-size: .85rem;
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <h2 class="mb-4"><i class="bi bi-receipt me-2"></i>คำสั่งซื้อของฉัน</h2>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if (!$orders): ?>
            <div class="alert alert-info">ยังไม่มีคำสั่งซื้อ</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark align-middle table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>เลขที่คำสั่งซื้อ</th>
                            <th>ยอดรวม</th>
                            <th>สถานะ</th>
                            <th>วันที่สร้าง</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?= (int)$o['id'] ?></td>
                                <td><?= h($o['order_number'] ?? ('#' . $o['id'])) ?></td>
                                <td>฿<?= number_format((float)$o['total_amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $status = $o['status'] ?? '';
                                    $color = match ($status) {
                                        'pending'               => 'secondary',
                                        'awaiting_payment'      => 'warning',
                                        'awaiting_verification' => 'info',
                                        'escrow_held'           => 'primary',
                                        'shipped'               => 'primary',
                                        'completed'             => 'success',
                                        'cancelled'             => 'danger',
                                        default                 => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $color ?> badge-status"><?= h($status) ?></span>
                                </td>
                                <td><?= h($o['created_at'] ?? '') ?></td>
                                <td class="d-flex gap-1">
                                    <a href="order_detail.php?order_number=<?= urlencode($o['order_number']) ?>"
                                        class="btn btn-sm btn-outline-light">
                                        <i class="bi bi-eye"></i> ดู
                                    </a>
                                    <?php if (in_array($role, ['buyer', 'seller', 'admin'])): ?>
                                        <form method="post" onsubmit="return confirm('แน่ใจว่าต้องการลบออเดอร์นี้?');">
                                            <input type="hidden" name="delete_id" value="<?= $o['id'] ?>">
                                            <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> ลบ</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="mt-3">
            <a href="index.php" class="btn btn-outline-light"><i class="bi bi-house"></i> กลับหน้าหลัก</a>
        </div>
    </div>
</body>

</html>