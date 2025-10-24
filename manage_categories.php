<?php
// manage_categories.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php'; // Includes PDO, helpers h(), q(), q1()

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
function check_csrf()
{ // Define the function locally if not in db.php
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    header('Location: manage_categories.php'); // Redirect back
    exit;
  }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------ Action Handler (Add/Edit/Delete) ------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf(); // Check CSRF token first
  $action = $_POST['action'] ?? '';
  $category_id = (int)($_POST['id'] ?? $_POST['category_id'] ?? 0); // Accept 'id' from delete form
  $category_name = trim($_POST['name'] ?? '');

  try {
    if ($action === 'create' && $category_name !== '') { // Use 'create' consistently
      $existing = q1("SELECT id FROM categories WHERE name = ?", [$category_name]);
      if ($existing) {
        $flash = ['type' => 'warning', 'msg' => 'ชื่อหมวดหมู่นี้มีอยู่แล้ว'];
      } else {
        q("INSERT INTO categories (name, created_at) VALUES (?, NOW())", [$category_name]);
        $flash = ['type' => 'success', 'msg' => 'เพิ่มหมวดหมู่ "' . h($category_name) . '" เรียบร้อยแล้ว'];
      }
    } elseif ($action === 'update' && $category_id > 0 && $category_name !== '') { // Use 'update'
      $existing = q1("SELECT id FROM categories WHERE name = ? AND id != ?", [$category_name, $category_id]);
      if ($existing) {
        $flash = ['type' => 'warning', 'msg' => 'ชื่อหมวดหมู่นี้ซ้ำกับหมวดหมู่อื่น'];
      } else {
        $stmt = q("UPDATE categories SET name = ? WHERE id = ?", [$category_name, $category_id]);
        if ($stmt->rowCount() > 0) {
          $flash = ['type' => 'success', 'msg' => 'แก้ไขหมวดหมู่ ID ' . $category_id . ' เป็น "' . h($category_name) . '" เรียบร้อยแล้ว'];
        } else {
          $flash = ['type' => 'info', 'msg' => 'ไม่พบหมวดหมู่ ID ' . $category_id . ' หรือไม่มีการเปลี่ยนแปลง'];
        }
      }
    } elseif ($action === 'delete' && $category_id > 0) {
      $product_count = q1("SELECT COUNT(*) as c FROM products WHERE category_id = ?", [$category_id])['c'] ?? 0;
      if ($product_count > 0) {
        $flash = ['type' => 'danger', 'msg' => 'ไม่สามารถลบหมวดหมู่ ID ' . $category_id . ' ได้ (ยังมีสินค้า ' . $product_count . ' รายการ)'];
      } else {
        $stmt = q("DELETE FROM categories WHERE id = ?", [$category_id]);
        if ($stmt->rowCount() > 0) {
          $flash = ['type' => 'success', 'msg' => 'ลบหมวดหมู่ ID ' . $category_id . ' เรียบร้อยแล้ว'];
        } else {
          $flash = ['type' => 'info', 'msg' => 'ไม่พบหมวดหมู่ ID ' . $category_id];
        }
      }
    } else {
      $flash = ['type' => 'warning', 'msg' => 'ข้อมูลไม่ครบถ้วนหรือไม่ถูกต้อง'];
    }
  } catch (Throwable $e) {
    // Log $e->getMessage()
    $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการจัดการข้อมูลหมวดหมู่'];
  }
  // Store flash message and redirect
  $_SESSION['flash'] = $flash;
  header("Location: manage_categories.php");
  exit;
}

// ------ LIST + search + pagination ------
$kw = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($kw !== '') {
  $where = "WHERE name LIKE ?";
  $params[] = "%$kw%";
}

$perPage = 10; // Items per page
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)(q1("SELECT COUNT(*) c FROM categories $where", $params)['c'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$rows = q("SELECT id, name, created_at FROM categories $where ORDER BY name ASC LIMIT $perPage OFFSET $offset", $params)->fetchAll(); // Use fetchAll()

// --- [โค้ด NAVBAR Data] ---
$userNav = $user;
$uidNav = $admin_id;
$roleNav = $user['role'];
$cartCountNav = 0;
// --- [จบโค้ด NAVBAR Data] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการหมวดหมู่ (Admin) - Web Shop Game</title>
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
      --muted-color: #6c757d;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Sora', sans-serif;
    }

    .navbar {
      background: rgba(255, 255, 255, .85);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .03);
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

    .table {
      --bs-table-border-color: var(--border);
    }

    .table thead {
      background-color: #f8f9fa;
      color: #495057;
    }

    .table th {
      font-weight: 600;
    }

    .table tbody tr:hover {
      background-color: #f0fdf4;
    }

    .form-control {
      border-radius: 12px;
      padding: .8rem;
      border-color: var(--border);
    }

    .form-control:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
    }

    .modal-content {
      background-color: var(--card);
      border-color: var(--border);
      color: var(--text);
    }

    .modal-header,
    .modal-footer {
      border-color: var(--border);
    }

    .pagination .page-item .page-link {
      border-radius: 0.5rem;
      margin: 0 2px;
      border-color: var(--border);
      color: var(--muted-color);
    }

    .pagination .page-item.active .page-link {
      background-color: var(--brand);
      border-color: var(--brand);
      color: #fff;
    }

    .pagination .page-item:not(.active) .page-link:hover {
      background-color: #e9ecef;
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
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"> <span class="navbar-toggler-icon"></span> </button>
      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-2 gap-lg-3">
          <li class="nav-item"><a href="shop.php" class="nav-link fw-semibold">ร้านค้า</a></li>
          <?php if ($uidNav): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                <div class="avatar d-flex align-items-center justify-content-center" style="width:36px; height:36px; background-color:var(--brand); color:#fff; border-radius:50%; font-weight:700;"> <?= strtoupper(substr($userNav['name'] ?? $userNav['email'], 0, 1)) ?> </div>
                <span class="d-none d-sm-inline fw-semibold"><?= h($userNav['name'] ?? $userNav['email']) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 1rem;">
                <?php if ($roleNav === 'admin'): ?>
                  <li><a class="dropdown-item" href="admin_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                  <li><a class="dropdown-item" href="admin_payments_review.php"><i class="bi bi-credit-card-2-front me-2"></i>ตรวจสอบสลิป</a></li>
                  <li><a class="dropdown-item" href="admin_seller_requests.php"><i class="bi bi-person-check me-2"></i>ตรวจสอบคำขอผู้ขาย</a></li>
                  <li><a class="dropdown-item" href="admin_manage_users.php"><i class="bi bi-people me-2"></i>จัดการผู้ใช้</a></li>
                  <li><a class="dropdown-item active" href="admin_manage_categories.php"><i class="bi bi-tags me-2"></i>จัดการหมวดหมู่</a></li>
                  <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php endif; ?>
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
    <h2 class="mb-3 fw-bold"><i class="bi bi-tags-fill me-2"></i>จัดการหมวดหมู่สินค้า</h2>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= h($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="vstack gap-4">
          <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-1"></i> เพิ่มหมวดหมู่ใหม่</div>
            <div class="card-body">
              <form method="post" class="d-grid gap-2">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="create">
                <div>
                  <label for="add-name" class="form-label">ชื่อหมวดหมู่</label>
                  <input type="text" id="add-name" class="form-control" name="name" maxlength="100" required>
                </div>
                <button type="submit" class="btn btn-brand mt-2"><i class="bi bi-plus-lg"></i> เพิ่มหมวดหมู่</button>
              </form>
            </div>
          </div>

          <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-search me-1"></i> ค้นหาหมวดหมู่</div>
            <div class="card-body">
              <form class="d-flex gap-2" method="get">
                <input class="form-control" name="q" value="<?= h($kw) ?>" placeholder="พิมพ์ชื่อหมวด...">
                <button type="submit" class="btn btn-outline-secondary flex-shrink-0"><i class="bi bi-search"></i></button>
                <?php if ($kw !== ''): ?>
                  <a class="btn btn-outline-danger flex-shrink-0" href="manage_categories.php" title="ล้างการค้นหา"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-1"></i> รายการหมวดหมู่</span>
            <small class="text-secondary">พบ <?= number_format($total) ?> รายการ</small>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th>ชื่อหมวดหมู่</th>
                  <th style="width:180px" class="text-end">ดำเนินการ</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="3" class="text-center text-secondary p-4">ไม่พบข้อมูลหมวดหมู่ <?= $kw ? 'ที่ตรงกับคำค้น' : '' ?></td>
                  </tr>
                  <?php else: foreach ($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td class="fw-semibold"><?= h($r['name']) ?></td>
                      <td class="text-end">
                        <div class="btn-group btn-group-sm">
                          <button class="btn btn-outline-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#editModal"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= h($r['name']) ?>">
                            <i class="bi bi-pencil-square"></i> แก้ไข
                          </button>
                          <button class="btn btn-outline-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteModal"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= h($r['name']) ?>">
                            <i class="bi bi-trash"></i> ลบ
                          </button>
                        </div>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($pages > 1): // Pagination 
          ?>
            <div class="card-footer d-flex justify-content-center">
              <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                  <?php // Previous Page 
                  ?>
                  <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?><?= $kw ? '&q=' . urlencode($kw) : '' ?>">&laquo;</a>
                  </li>
                  <?php // Page Numbers 
                  ?>
                  <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                      <a class="page-link" href="?page=<?= $i ?><?= $kw ? '&q=' . urlencode($kw) : '' ?>"><?= $i ?></a>
                    </li>
                  <?php endfor; ?>
                  <?php // Next Page 
                  ?>
                  <li class="page-item <?= ($page >= $pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?><?= $kw ? '&q=' . urlencode($kw) : '' ?>">&raquo;</a>
                  </li>
                </ul>
              </nav>
            </div>
          <?php endif; ?>
        </div>
        <div class="mt-3">
          <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับ Dashboard</a>
        </div>
      </div>
    </div>
    </main>

    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
          <div class="modal-header">
            <h5 class="modal-title" id="editModalLabel">แก้ไขหมวดหมู่</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">
            <label for="edit-name" class="form-label">ชื่อหมวดหมู่ใหม่</label>
            <input class="form-control" name="name" id="edit-name" required maxlength="100">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> บันทึกการเปลี่ยนแปลง</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-exclamation-triangle-fill"></i> ยืนยันการลบ</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id">
            <p>คุณแน่ใจหรือไม่ว่าต้องการลบหมวดหมู่ <strong id="delete-name"></strong>?</p>
            <p class="text-danger small">คำเตือน: การกระทำนี้ไม่สามารถย้อนกลับได้ และจะลบได้เฉพาะหมวดหมู่ที่ไม่มีสินค้าอยู่เท่านั้น</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i> ยืนยันการลบ</button>
          </div>
        </form>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Script for Edit Modal
      const editModal = document.getElementById('editModal');
      if (editModal) {
        editModal.addEventListener('show.bs.modal', event => {
          const button = event.relatedTarget;
          const catId = button.getAttribute('data-id');
          const catName = button.getAttribute('data-name');
          editModal.querySelector('#edit-id').value = catId;
          editModal.querySelector('#edit-name').value = catName;
          editModal.querySelector('.modal-title').textContent = `แก้ไขหมวดหมู่ ID: ${catId}`;
        });
      }

      // Script for Delete Modal
      const deleteModal = document.getElementById('deleteModal');
      if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', event => {
          const button = event.relatedTarget;
          const catId = button.getAttribute('data-id');
          const catName = button.getAttribute('data-name');
          deleteModal.querySelector('#delete-id').value = catId;
          deleteModal.querySelector('#delete-name').textContent = catName;
        });
      }
    </script>
</body>

</html>