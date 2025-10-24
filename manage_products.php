<?php
// manage_products.php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db.php'; // Includes PDO, helpers h(), q(), q1(), getStatusInfo()

// ------ Auth: Seller or Admin only ------
$user = $_SESSION['user'] ?? null;
$user_id = (int)($user['id'] ?? 0);
$role = $user['role'] ?? '';

if (!$user_id || !in_array($role, ['seller', 'admin'], true)) {
  $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ต้องเป็นผู้ขายหรือแอดมินเท่านั้น'];
  header('Location: login.php');
  exit;
}

// ------ CSRF ------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
function check_csrf()
{ // Local CSRF check function
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    // Build redirect URL with existing query parameters
    $query_string = http_build_query($_GET);
    header('Location: manage_products.php' . ($query_string ? '?' . $query_string : ''));
    exit;
  }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------ Fetch Categories for forms/filters ------
$categories = q("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); // *** [แก้ไข] เพิ่ม fetchAll() ***

/* ===== Helper: Upload Image (image1-3) ===== */
function save_upload($field, $subdir = 'products')
{
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null; // No file or upload error

  $file = $_FILES[$field];
  $tmp  = $file['tmp_name'];
  $size = (int)$file['size'];
  if ($size <= 0 || $size > 5 * 1024 * 1024) throw new RuntimeException('ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5MB');

  $mime = mime_content_type($tmp);
  $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  if (!isset($map[$mime])) throw new RuntimeException('ไฟล์รูปภาพต้องเป็น JPG, PNG, WebP, หรือ GIF เท่านั้น');

  $base = __DIR__ . "/uploads/$subdir";
  if (!is_dir($base)) @mkdir($base, 0775, true);

  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
  $dest = "$base/$name";
  if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('เกิดข้อผิดพลาดในการย้ายไฟล์ที่อัปโหลด');

  return "uploads/$subdir/$name"; // Relative path for DB
}

/* ===== ACTIONS (Create, Delete, Quick Update) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $act = $_POST['act'] ?? '';
  $product_id = (int)($_POST['id'] ?? 0);

  // --- Ownership Check Function (for edit/delete safety) ---
  $isOwner = function ($pid) use ($user_id, $role) {
    if ($role === 'admin') return false; // Admins don't "own" products this way for editing via seller forms
    $row = q1("SELECT seller_id FROM products WHERE id = ?", [$pid]);
    return $row && ((int)$row['seller_id'] === $user_id);
  };

  try { // Wrap actions in try-catch
    if ($act === 'create' && $role === 'seller') { // Only sellers can create via this form
      $name  = trim($_POST['name'] ?? '');
      $price = (float)($_POST['price'] ?? 0);
      $qty   = (isset($_POST['quantity']) && $_POST['quantity'] !== '') ? max(0, (int)$_POST['quantity']) : null; // Ensure quantity >= 0
      $cat   = (int)($_POST['category_id'] ?? 0);
      $desc  = trim($_POST['description'] ?? '');

      if ($name === '' || $price <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'กรุณากรอกชื่อสินค้าและราคาให้ถูกต้อง (ราคาต้องมากกว่า 0)'];
      } elseif ($cat <= 0) { // *** [แก้ไข] บังคับเลือกหมวดหมู่ ***
        $flash = ['type' => 'danger', 'msg' => 'กรุณาเลือกหมวดหมู่สินค้า'];
      } else {
        $img1 = save_upload('file_image1', 'products');
        $img2 = save_upload('file_image2', 'products');
        $img3 = save_upload('file_image3', 'products');

        q(
          "INSERT INTO products (name, price, quantity, category_id, description, image1, image2, image3, seller_id, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
          [$name, $price, $qty, $cat, $desc, $img1, $img2, $img3, $user_id]
        ); // ส่ง $cat (ไม่ใช่ $cat ?: null)
        $flash = ['type' => 'success', 'msg' => 'เพิ่มสินค้า "' . h($name) . '" เรียบร้อยแล้ว'];
      }
    } elseif ($act === 'delete' && $product_id > 0) {
      // *** [แก้ไข] Admin ลบได้ทุกคน, Seller ลบได้เฉพาะของตัวเอง ***
      $canDelete = ($role === 'admin') || ($role === 'seller' && $isOwner($product_id));
      if (!$canDelete) {
        $flash = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์ลบสินค้านี้'];
      } else {
        $in_order = q1("SELECT 1 FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=? AND o.status NOT IN ('completed','cancelled') LIMIT 1", [$product_id]);
        if ($in_order) {
          $flash = ['type' => 'warning', 'msg' => 'ไม่สามารถลบสินค้าได้ เนื่องจากอยู่ในคำสั่งซื้อที่ยังไม่เสร็จสมบูรณ์'];
        } else {
          // Admin ลบได้เลย, Seller ลบได้เฉพาะของตัวเอง
          $delete_params = ($role === 'admin') ? [$product_id] : [$product_id, $user_id];
          $delete_sql = "DELETE FROM products WHERE id = ?" . ($role === 'seller' ? " AND seller_id = ?" : "");
          $stmt = q($delete_sql, $delete_params);
          if ($stmt->rowCount() > 0) {
            $flash = ['type' => 'success', 'msg' => 'ลบสินค้า ID ' . $product_id . ' แล้ว'];
            // TODO: Add code here to delete image files from server
          } else {
            $flash = ['type' => 'info', 'msg' => 'ไม่พบสินค้า หรือไม่มีสิทธิ์ลบ'];
          }
        }
      }
    } elseif ($act === 'quick_update' && $product_id > 0) {
      if (!$isOwner($product_id)) { // Only owner can quick update
        $flash = ['type' => 'danger', 'msg' => 'คุณไม่มีสิทธิ์แก้ไขสินค้านี้'];
      } else {
        $price = max(0.01, (float)($_POST['price'] ?? 0)); // Price must be > 0
        $qty   = ($_POST['quantity'] === '' || $_POST['quantity'] === null) ? null : max(0, (int)$_POST['quantity']); // Allow null, ensure >= 0

        q(
          "UPDATE products SET price=?, quantity=?, updated_at=NOW() WHERE id=? AND seller_id=?",
          [$price, $qty, $product_id, $user_id]
        );
        $flash = ['type' => 'success', 'msg' => 'อัปเดตราคา/สต็อกสินค้า ID ' . $product_id . ' สำเร็จ'];
      }
    } else {
      $flash = ['type' => 'warning', 'msg' => 'การดำเนินการไม่ถูกต้อง'];
    }
  } catch (Throwable $e) { // Catch upload errors and DB errors
    $flash = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
  }

  // Redirect after action, preserving filters
  $_SESSION['flash'] = $flash;
  $redirect_query = http_build_query(array_filter(['q' => ($_GET['q'] ?? ''), 'category' => ($_GET['category'] ?? 0), 'page' => ($_GET['page'] ?? 1)]));
  header('Location: manage_products.php' . ($redirect_query ? '?' . $redirect_query : ''));
  exit;
}

/* ===== LIST PRODUCTS with PAGINATION ===== */
$kw = trim($_GET['q'] ?? '');
$catId = (int)($_GET['category'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1)); // Current page
$perPage = 15; // Number of items per page

$where_conditions = [];
$params = [];

// Adjust query based on role
if ($role === 'seller') {
  $where_conditions[] = "p.seller_id = ?"; // Seller sees only their products
  $params[] = $user_id;
} // Admin sees all by default

if ($kw !== '') {
  $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
  $params[] = "%$kw%";
  $params[] = "%$kw%";
}
if ($catId > 0) {
  $where_conditions[] = "p.category_id = ?";
  $params[] = $catId;
}
$wsql = !empty($where_conditions) ? ('WHERE ' . implode(' AND ', $where_conditions)) : '';

// Count total products matching filters
$total_sql = "SELECT COUNT(*) c FROM products p $wsql";
$total = (int)(q1($total_sql, $params)['c'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages); // Ensure page doesn't exceed total pages
$offset = ($page - 1) * $perPage;

// Fetch products for the current page
$products_sql = "
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    $wsql
    ORDER BY p.id DESC
    LIMIT $perPage OFFSET $offset
";
$products = q($products_sql, $params)->fetchAll(PDO::FETCH_ASSOC); // *** [แก้ไข] เพิ่ม fetchAll() ***

// --- [โค้ด NAVBAR Data] ---
$userNav = $user;
$uidNav = $user_id;
$roleNav = $role;
$cartCountNav = 0;
// --- [จบโค้ด NAVBAR Data] ---
?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการสินค้า<?= $role === 'admin' ? ' (Admin)' : '' ?> - Web Shop Game</title>
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

    .form-control,
    .form-select {
      border-radius: 12px;
      padding: .8rem;
      border-color: var(--border);
    }

    .form-control:focus,
    .form-select:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 .25rem rgba(34, 197, 94, .25);
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
      font-size: 0.9rem;
    }

    .table tbody tr:hover {
      background-color: #f0fdf4;
    }

    .product-img {
      width: 80px;
      height: 60px;
      object-fit: cover;
      border: 1px solid var(--border);
      border-radius: 8px;
    }

    .preview {
      width: 100%;
      max-width: 150px;
      height: auto;
      aspect-ratio: 4/3;
      object-fit: cover;
      border: 1px dashed var(--border);
      border-radius: 12px;
      background: #f8f9fa;
      color: var(--muted-color);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
    }

    .preview:not([src]),
    .preview[src=""] {
      content: 'Preview';
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--muted-color);
    }

    .label-chip {
      font-size: .8rem;
      padding: .2rem .5rem;
      border: 1px solid var(--border);
      border-radius: 999px;
      background-color: #f8f9fa;
      color: #495057;
    }

    .table td,
    .table th {
      vertical-align: middle;
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

    .pagination .page-item.disabled .page-link {
      color: #dee2e6;
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
          <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): ?>
            <li class="nav-item"> <a href="cart.php" class="btn btn-outline-secondary btn-sm position-relative"> <i class="bi bi-cart3 fs-5"></i> <?php if ($cartCountNav > 0): ?> <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $cartCountNav ?></span> <?php endif; ?> </a> </li>
          <?php endif; ?>
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
                  <li><a class="dropdown-item active" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                  <li><a class="dropdown-item" href="admin_manage_categories.php"><i class="bi bi-tags me-2"></i>จัดการหมวดหมู่</a></li>
                  <li><a class="dropdown-item" href="admin_manage_orders.php"><i class="bi bi-receipt me-2"></i>จัดการคำสั่งซื้อ</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php elseif ($roleNav === 'seller'): ?>
                  <li><a class="dropdown-item" href="seller_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Seller Dashboard</a></li>
                  <li><a class="dropdown-item active" href="manage_products.php"><i class="bi bi-box-seam me-2"></i>จัดการสินค้า</a></li>
                  <li><a class="dropdown-item" href="seller_orders.php"><i class="bi bi-receipt-cutoff me-2"></i>ออเดอร์ร้านฉัน</a></li>
                  <li>
                    <hr class="dropdown-divider">
                  </li>
                <?php endif; ?>
                <?php if ($roleNav !== 'admin'): ?>
                  <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                <?php endif; ?>
                <?php if ($roleNav === 'buyer' || $roleNav === 'seller'): ?>
                  <li><a class="dropdown-item" href="order_list.php"><i class="bi bi-card-checklist me-2"></i>คำสั่งซื้อของฉัน</a></li>
                <?php endif; ?>
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
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h2 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i><?= $role === 'admin' ? 'จัดการสินค้าทั้งหมด' : 'สินค้าของฉัน' ?></h2>
      <?php if ($role === 'seller'): ?>
        <button class="btn btn-brand" data-bs-toggle="collapse" href="#addForm" role="button" aria-expanded="false" aria-controls="addForm">
          <i class="bi bi-plus-lg"></i> เพิ่มสินค้าใหม่
        </button>
      <?php endif; ?>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= h($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
          <div class="col-md-5 col-lg-4">
            <label for="q" class="form-label">ค้นหาชื่อ/คำอธิบาย</label>
            <input type="search" id="q" class="form-control form-control-sm" name="q" value="<?= h($kw) ?>" placeholder="คำค้นหา...">
          </div>
          <div class="col-md-4 col-lg-3">
            <label for="category" class="form-label">หมวดหมู่</label>
            <select class="form-select form-select-sm" name="category" id="category">
              <option value="0">-- ทั้งหมด --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $catId == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 col-lg-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-search"></i> ค้นหา</button>
          </div>
          <?php if ($kw !== '' || $catId > 0): ?>
            <div class="col-md-3 col-lg-auto">
              <a href="manage_products.php" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-lg"></i> ล้าง</a>
            </div>
          <?php endif; ?>
          <input type="hidden" name="page" value="1">
        </form>
      </div>
    </div>


    <?php if ($role === 'seller'): ?>
      <div class="collapse" id="addForm">
        <div class="card mb-4 border-success">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center bg-success-subtle"> <span><i class="bi bi-plus-circle me-1"></i> กรอกรายละเอียดสินค้าใหม่</span>
            <button type="button" class="btn-close small" data-bs-toggle="collapse" data-bs-target="#addForm" aria-label="Close"></button>
          </div>
          <form class="card-body row g-3" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="act" value="create">

            <div class="col-md-6">
              <label for="add-name" class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
              <input type="text" id="add-name" class="form-control" name="name" required maxlength="200">
            </div>
            <div class="col-md-3">
              <label for="add-price" class="form-label">ราคา (บาท) <span class="text-danger">*</span></label>
              <input type="number" id="add-price" step="0.01" min="0.01" class="form-control" name="price" required>
            </div>
            <div class="col-md-3">
              <label for="add-quantity" class="form-label">สต็อก</label>
              <input type="number" id="add-quantity" min="0" class="form-control" name="quantity" placeholder="เว้นว่าง = ไม่จำกัด">
            </div>

            <div class="col-md-4">
              <label for="add-category" class="form-label">หมวดหมู่ <span class="text-danger">*</span></label> <select class="form-select" name="category_id" id="add-category" required>
                <option value="" disabled selected>-- กรุณาเลือก --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label for="add-description" class="form-label">คำอธิบายสินค้า</label>
              <textarea class="form-control" id="add-description" name="description" rows="3"></textarea>
            </div>

            <div class="col-md-4">
              <label for="file_image1" class="form-label">รูปภาพหลัก <span class="text-danger">*</span></label>
              <input type="file" id="file_image1" name="file_image1" class="form-control" accept="image/*" onchange="preview(this,'pv1')" required>
              <small class="text-secondary">รูปแรกสุด สำคัญที่สุด</small>
              <img id="pv1" class="preview mt-2" alt="Preview Image 1">
            </div>
            <div class="col-md-4">
              <label for="file_image2" class="form-label">รูปภาพเสริม 2</label>
              <input type="file" id="file_image2" name="file_image2" class="form-control" accept="image/*" onchange="preview(this,'pv2')">
              <small class="text-secondary">&nbsp;</small> <img id="pv2" class="preview mt-2" alt="Preview Image 2">
            </div>
            <div class="col-md-4">
              <label for="file_image3" class="form-label">รูปภาพเสริม 3</label>
              <input type="file" id="file_image3" name="file_image3" class="form-control" accept="image/*" onchange="preview(this,'pv3')">
              <small class="text-secondary">&nbsp;</S> <img id="pv3" class="preview mt-2" alt="Preview Image 3">
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-brand"><i class="bi bi-save me-1"></i> บันทึกสินค้า</button>
              <button type="button" class="btn btn-secondary" data-bs-toggle="collapse" data-bs-target="#addForm">ยกเลิก</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>


    <div class="card overflow-hidden">
      <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-1"></i> <?= $role === 'admin' ? 'สินค้าทั้งหมด' : 'สินค้าของฉัน' ?></span>
        <small class_exists="text-secondary">พบ <?= number_format($total) ?> รายการ</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>รูป</th>
              <th>ชื่อสินค้า</th>
              <th>หมวดหมู่</th>
              <?php if ($role === 'admin'): ?><th>ผู้ขาย</th><?php endif; ?>
              <th class="text-end">ราคา</th>
              <th class="text-end">สต็อก</th>
              <th>อัปเดตด่วน</th>
              <th class="text-end">การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$products): ?>
              <tr>
                <td colspan="<?= $role === 'admin' ? '9' : '8' ?>" class="text-center text-secondary p-4"><i class="bi bi-info-circle me-2"></i>ไม่พบสินค้า <?= ($kw || $catId > 0) ? 'ที่ตรงกับเงื่อนไขการค้นหา' : ($role === 'seller' ? 'ของคุณ' : '') ?></td>
              </tr>
              <?php else: foreach ($products as $i => $p):
                $is_owner = ($role === 'seller' && (int)$p['seller_id'] === $user_id);
              ?>
                <tr>
                  <td><?= ($page - 1) * $perPage + $i + 1 ?></td>
                  <td><img class="product-img" src="<?= h($p['image1'] ?: 'https://via.placeholder.com/100x75?text=N/A') ?>" alt=""></td>
                  <td>
                    <div class="fw-bold"><?= h($p['name']) ?></div>
                    <div class="small text-secondary">ID: <?= (int)$p['id'] ?> | สร้างเมื่อ: <?= date('d/m/y', strtotime($p['created_at'])) ?></div>
                  </td>
                  <td><?= h($p['category_name'] ?? '-') ?></td>
                  <?php if ($role === 'admin'): ?>
                    <td><small class="text-secondary"><?= h(q1("SELECT name FROM users WHERE id=?", [$p['seller_id']])['name'] ?? 'ID: ' . $p['seller_id']) ?></small></td>
                  <?php endif; ?>
                  <td class="text-end">฿<?= number_format((float)$p['price'], 2) ?></td>
                  <td class="text-end"><?= $p['quantity'] !== null ? number_format((int)$p['quantity']) : '<span class="text-info small">ไม่จำกัด</span>' ?></td>
                  <td>
                    <?php if ($is_owner): ?>
                      <form class="d-flex gap-1" method="post">
                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                        <input type="hidden" name="act" value="quick_update">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <input type="number" step="0.01" name="price" class="form-control form-control-sm" style="width:100px" value="<?= h($p['price']) ?>" placeholder="ราคา" required title="ราคา">
                        <input type="number" name="quantity" class="form-control form-control-sm" style="width:80px" value="<?= h($p['quantity']) ?>" placeholder="สต็อก" title="สต็อก (ว่าง=ไม่จำกัด)">
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="บันทึก"><i class="bi bi-save"></i></button>
                      </form>
                    <?php else: ?>
                      <span class="text-secondary small">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-secondary" href="view_product.php?id=<?= (int)$p['id'] ?>" target="_blank" title="ดูหน้าสินค้า"><i class="bi bi-eye"></i></a>
                      <?php if ($is_owner || $role === 'admin'): ?>
                        <?php if ($is_owner): // Only owner can edit details 
                        ?>
                          <a class="btn btn-outline-primary" href="edit_product.php?id=<?= (int)$p['id'] ?>" title="แก้ไขรายละเอียด"><i class="bi bi-pencil-square"></i></a>
                        <?php endif; ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('ต้องการลบสินค้า <?= h(addslashes($p['name'])) ?>?');">
                          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                          <input type="hidden" name="act" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                          <button type="submit" class="btn btn-outline-danger" title="ลบสินค้า"><i class="bi bi-trash"></i></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-center pt-3">
          <nav aria-label="Page navigation">
            <ul class="pagination pagination-sm mb-0">
              <?php // Previous Page Link 
              ?>
              <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?><?= $kw ? '&q=' . urlencode($kw) : '' ?><?= $catId > 0 ? '&category=' . $catId : '' ?>" aria-label="Previous">
                  <span aria-hidden="true">&laquo;</span>
                </a>
              </li>
              <?php // Page Number Links (show a few pages around current)
              $startPage = max(1, $page - 2);
              $endPage = min($pages, $page + 2);
              if ($startPage > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                  <a class="page-link" href="?page=<?= $i ?><?= $kw ? '&q=' . urlencode($kw) : '' ?><?= $catId > 0 ? '&category=' . $catId : '' ?>"><?= $i ?></a>
                </li>
              <?php endfor;
              if ($endPage < $pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              ?>
              <?php // Next Page Link 
              ?>
              <li class="page-item <?= ($page >= $pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?><?= $kw ? '&q=' . urlencode($kw) : '' ?><?= $catId > 0 ? '&category=' . $catId : '' ?>" aria-label="Next">
                  <span aria-hidden="true">&raquo;</span>
                </a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-3">
      <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : ($role === 'seller' ? 'seller_dashboard.php' : 'index.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับ <?= $role === 'admin' ? 'Dashboard' : 'หน้าหลัก' ?></a>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Preview image function
    function preview(input, previewId) {
      const file = input.files[0];
      const previewElement = document.getElementById(previewId);
      if (previewElement) {
        if (file && file.type.startsWith('image/')) {
          const reader = new FileReader();
          reader.onload = function(e) {
            previewElement.src = e.target.result;
            previewElement.style.display = 'block';
            previewElement.textContent = '';
          }
          reader.readAsDataURL(file);
        } else {
          previewElement.src = "";
          previewElement.removeAttribute('src');
          previewElement.style.display = 'flex';
          previewElement.textContent = 'Preview';
          if (file) { // If a file was selected but it wasn't an image
            input.value = ''; // Clear the invalid file
            alert('กรุณาเลือกไฟล์รูปภาพ (JPG, PNG, WebP, GIF)');
          }
        }
      }
    }
    // Setup preview placeholders on page load
    document.querySelectorAll('.preview').forEach(img => {
      if (!img.getAttribute('src') || img.getAttribute('src') === '') {
        img.style.display = 'flex';
        img.textContent = 'Preview';
      }
      img.onerror = function() {
        this.style.display = 'flex';
        this.removeAttribute('src');
        this.textContent = 'Preview';
      }
    });
  </script>
</body>

</html>