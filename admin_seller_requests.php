<?php
// admin_seller_requests.php  — ตรวจ/อนุมัติคำขอเป็นผู้ขาย (KYC)
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
  return $st;
}
function q1($sql, $p = [])
{
  return q($sql, $p)->fetch(PDO::FETCH_ASSOC);
}

/* ตรวจสิทธิ์แอดมิน */
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
  $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ดูแลระบบเท่านั้น'];
  header('Location: index.php');
  exit;
}
$admin_id = (int)$user['id'];

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

/* รับตัวกรอง */
$status = $_GET['status'] ?? 'pending'; // pending | approved | rejected | all
$kw = trim($_GET['q'] ?? '');

/* จัดการ อนุมัติ / ปฏิเสธ (PRG) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    header("Location: admin_seller_requests.php?status=" . urlencode($status) . "&q=" . urlencode($kw));
    exit;
  }

  $kid    = (int)($_POST['kyc_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  $note   = trim($_POST['note'] ?? '');

  $kyc = q1("SELECT k.*, u.id AS uid, u.email, u.name, u.role
             FROM kycs k
             JOIN users u ON u.id=k.user_id
             WHERE k.id=?", [$kid]);

  if (!$kyc) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบคำขอ'];
    header("Location: admin_seller_requests.php?status=" . urlencode($status) . "&q=" . urlencode($kw));
    exit;
  }

  try {
    if ($action === 'approve') {
      if ($kyc['status'] === 'approved') {
        $_SESSION['flash'] = ['type' => 'info', 'msg' => 'คำขอนี้ได้รับอนุมัติแล้ว'];
      } else {
        $pdo->beginTransaction();

        // อัปเดตสถานะ KYC
        q("UPDATE kycs
           SET status='approved', approved_at=NOW(), approved_by=?, note=?
           WHERE id=?", [$admin_id, $note, $kid]);

        // อัปเดตสิทธิ์ผู้ใช้ -> seller (หากยังไม่ใช่ admin/seller)
        q("UPDATE users SET role='seller'
           WHERE id=? AND role NOT IN ('admin','seller')", [$kyc['uid']]);

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อนุมัติสำเร็จ และอัปเดตสิทธิ์เป็นผู้ขายแล้ว'];
      }
    } elseif ($action === 'reject') {
      if ($kyc['status'] === 'rejected') {
        $_SESSION['flash'] = ['type' => 'info', 'msg' => 'คำขอนี้ถูกปฏิเสธไปแล้ว'];
      } else {
        if ($note === '') $note = 'เอกสารไม่ครบถ้วน';
        q("UPDATE kycs
           SET status='rejected', note=?, approved_at=NULL, approved_by=NULL
           WHERE id=?", [$note, $kid]);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ปฏิเสธคำขอเรียบร้อย'];
      }
    } else {
      $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คำสั่งไม่ถูกต้อง'];
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการดำเนินการ'];
  }

  // PRG
  header("Location: admin_seller_requests.php?status=" . urlencode($status) . "&q=" . urlencode($kw));
  exit;
}

/* เงื่อนไขค้นหา/กรอง */
$where = [];
$params = [];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
  $where[] = "k.status = ?";
  $params[] = $status;
}
if ($kw !== '') {
  $where[] = "(u.email LIKE ? OR u.name LIKE ? OR k.phone LIKE ? OR k.id_card_number LIKE ?)";
  $like = "%$kw%";
  array_push($params, $like, $like, $like, $like);
}
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ดึงรายการ */
$rows = q("
  SELECT k.*, u.email, u.name
  FROM kycs k
  JOIN users u ON u.id = k.user_id
  $wsql
  ORDER BY
    CASE k.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
    k.created_at ASC
  LIMIT 200
", $params)->fetchAll(PDO::FETCH_ASSOC);

/* นับสรุปสถานะ */
$counts = q1("
  SELECT
    SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS p,
    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS a,
    SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS r
  FROM kycs
");
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ตรวจคำขอผู้ขาย (KYC)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bs-body-bg: #0b0f14;
      --bs-card-bg: #0f1620;
      --bs-border-color: rgba(148, 163, 184, .18);
    }

    .thumb {
      width: 84px;
      height: 64px;
      object-fit: cover;
      border: 1px solid var(--bs-border-color);
      border-radius: 8px
    }

    .w-wrap {
      max-width: 260px;
      word-break: break-word
    }

    .badge-status {
      font-size: .85rem
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg border-bottom bg-dark-subtle bg-opacity-50 sticky-top">
    <div class="container">
      <a class="navbar-brand" href="index.php">Web Shop Game</a>
      <div class="ms-auto d-flex gap-2">
        <a class="btn btn-outline-light btn-sm" href="manage_categories.php">หมวดหมู่</a>
        <a class="btn btn-outline-light btn-sm" href="manage_products.php">สินค้า</a>
        <a class="btn btn-success btn-sm" href="admin_seller_requests.php">ตรวจคำขอผู้ขาย</a>
        <a class="btn btn-outline-light btn-sm" href="logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container py-4">
    <h3 class="mb-3">ตรวจคำขอเป็นผู้ขาย (KYC)</h3>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
      <div class="col-md-8">
        <?php $curr = in_array($status, ['pending', 'approved', 'rejected']) ? $status : 'all'; ?>
        <div class="btn-group" role="group">
          <a class="btn btn-<?= $curr === 'pending'  ? 'success' : 'outline-light' ?>" href="?status=pending">รอตรวจ (<?= (int)($counts['p'] ?? 0) ?>)</a>
          <a class="btn btn-<?= $curr === 'approved' ? 'success' : 'outline-light' ?>" href="?status=approved">อนุมัติแล้ว (<?= (int)($counts['a'] ?? 0) ?>)</a>
          <a class="btn btn-<?= $curr === 'rejected' ? 'success' : 'outline-light' ?>" href="?status=rejected">ปฏิเสธแล้ว (<?= (int)($counts['r'] ?? 0) ?>)</a>
          <a class="btn btn-<?= $curr === 'all'      ? 'success' : 'outline-light' ?>" href="?status=all">ทั้งหมด</a>
        </div>
      </div>
      <div class="col-md-4">
        <form class="input-group" method="get">
          <input type="hidden" name="status" value="<?= h($status) ?>">
          <input class="form-control" name="q" placeholder="ค้นหา: อีเมล / ชื่อ / เบอร์โทร / เลขบัตร" value="<?= h($kw) ?>">
          <button class="btn btn-outline-light">ค้นหา</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-success">
            <tr>
              <th>#</th>
              <th>ผู้ใช้</th>
              <th>ข้อมูล</th>
              <th>รูป</th>
              <th>สถานะ</th>
              <th class="text-end">ดำเนินการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="6" class="text-secondary p-4">ไม่มีคำขอ</td>
              </tr>
              <?php else: foreach ($rows as $i => $r): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td class="w-wrap">
                    <div class="fw-bold"><?= h($r['name'] ?: '-') ?></div>
                    <div class="small text-secondary"><?= h($r['email'] ?: '-') ?></div>
                    <div class="small text-secondary">ยื่นเมื่อ: <?= h($r['created_at']) ?></div>
                  </td>
                  <td class="w-wrap">
                    <div>โทร: <?= h($r['phone'] ?: '-') ?></div>
                    <div>บัตร: <?= h($r['id_card_number'] ?: '-') ?></div>
                    <div>บัญชี: <?= h($r['bank_account'] ?: '-') ?></div>
                    <?php if (!empty($r['note'])): ?>
                      <div class="small text-warning mt-1">หมายเหตุ: <?= h($r['note']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <?php if (!empty($r['selfie_with_id'])): ?>
                        <a href="<?= h($r['selfie_with_id']) ?>" target="_blank" title="เซลฟี่คู่บัตร">
                          <img class="thumb" src="<?= h($r['selfie_with_id']) ?>" alt="">
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($r['id_card_image'])): ?>
                        <a href="<?= h($r['id_card_image']) ?>" target="_blank" title="บัตรประชาชน">
                          <img class="thumb" src="<?= h($r['id_card_image']) ?>" alt="">
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php $cls = $r['status'] === 'approved' ? 'bg-success' : ($r['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-danger'); ?>
                    <span class="badge badge-status <?= $cls ?>"><?= h($r['status']) ?></span>
                    <?php if (!empty($r['approved_at'])): ?>
                      <div class="small text-secondary mt-1">อนุมัติเมื่อ: <?= h($r['approved_at']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <button class="btn btn-sm btn-success"
                        data-bs-toggle="modal" data-bs-target="#mdlApprove<?= $r['id'] ?>"
                        <?= $r['status'] === 'approved' ? 'disabled' : ''; ?>>อนุมัติ</button>
                      <button class="btn btn-sm btn-outline-light"
                        data-bs-toggle="modal" data-bs-target="#mdlReject<?= $r['id'] ?>"
                        <?= $r['status'] === 'rejected' ? 'disabled' : ''; ?>>ปฏิเสธ</button>
                    </div>

                    <!-- Modal อนุมัติ -->
                    <div class="modal fade" id="mdlApprove<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <form class="modal-content" method="post">
                          <div class="modal-header">
                            <h5 class="modal-title">อนุมัติผู้ขาย</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="kyc_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <div class="mb-2">จะอนุมัติ <strong><?= h($r['name'] ?: $r['email']) ?></strong> เป็นผู้ขาย และอัปเดตสิทธิ์ในระบบ</div>
                            <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                            <textarea class="form-control" name="note" rows="2" placeholder="เช่น เอกสารครบถ้วน"></textarea>
                          </div>
                          <div class="modal-footer">
                            <button class="btn btn-success">ยืนยันอนุมัติ</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                          </div>
                        </form>
                      </div>
                    </div>

                    <!-- Modal ปฏิเสธ -->
                    <div class="modal fade" id="mdlReject<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <form class="modal-content" method="post">
                          <div class="modal-header">
                            <h5 class="modal-title">ปฏิเสธคำขอ</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="kyc_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <label class="form-label">เหตุผลการปฏิเสธ</label>
                            <textarea class="form-control" name="note" rows="3" required placeholder="เช่น รูปถ่ายไม่ชัดเจน / ข้อมูลไม่ครบ"></textarea>
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