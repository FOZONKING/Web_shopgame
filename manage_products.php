<?php
session_start();
require __DIR__.'/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q($sql,$p=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($p); return $st; }
function q1($sql,$p=[]){ return q($sql,$p)->fetch(PDO::FETCH_ASSOC); }

$user = $_SESSION['user'] ?? null;
$user_id = (int)($user['id'] ?? 0);
$role = $user['role'] ?? '';

if(!$user_id || !in_array($role, ['seller','admin'], true)){
  $_SESSION['flash'] = ['type'=>'warning','msg'=>'ต้องเป็นผู้ขายหรือแอดมินเท่านั้น'];
  header('Location: login.php'); exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$categories = q("SELECT id,name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ===== helper upload (เฉพาะ image1-3) ===== */
function save_upload($field, $subdir='products'){
  if(!isset($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) return null;

  $tmp  = $_FILES[$field]['tmp_name'];
  $size = (int)$_FILES[$field]['size'];
  if($size<=0 || $size>5*1024*1024) throw new RuntimeException('ไฟล์ใหญ่เกินไป (จำกัด 5MB)');

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($tmp);
  $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
  if(!isset($map[$mime])) throw new RuntimeException('อนุญาตเฉพาะ JPG/PNG/WebP/GIF');

  $base = __DIR__."/uploads/$subdir";
  if(!is_dir($base)) @mkdir($base, 0775, true);

  $name = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$map[$mime];
  $dest = "$base/$name";
  if(!move_uploaded_file($tmp, $dest)) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');

  return "uploads/$subdir/$name"; // path สำหรับเก็บใน DB
}

/* ===== ACTIONS ===== */
if($_SERVER['REQUEST_METHOD']==='POST' && hash_equals($CSRF, $_POST['csrf'] ?? '')){
  $act = $_POST['act'] ?? '';

  // อนุญาตให้เปลี่ยนแปลงได้เฉพาะ "เจ้าของสินค้า"
  $isOwner = function($productId) use ($user_id){
    $row = q1("SELECT seller_id FROM products WHERE id=?", [(int)$productId]);
    if(!$row) return false;
    return ((int)$row['seller_id'] === $user_id); // ไม่มีสิทธิ์พิเศษสำหรับ admin
  };

  if($act==='create'){
    $name  = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $qty   = (isset($_POST['quantity']) && $_POST['quantity']!=='') ? (int)$_POST['quantity'] : null;
    $cat   = (int)($_POST['category_id'] ?? 0);
    $desc  = trim($_POST['description'] ?? '');

    try{
      $img1 = save_upload('file_image1','products');
      $img2 = save_upload('file_image2','products');
      $img3 = save_upload('file_image3','products');
    }catch(Throwable $e){
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'อัปโหลดรูปไม่สำเร็จ: '.$e->getMessage()];
      header('Location: manage_products.php'); exit;
    }

    if($name==='' || $price<=0){
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'กรอกชื่อสินค้าและราคาที่ถูกต้อง'];
    }else{
      q("INSERT INTO products
          (name, price, quantity, category_id, description, image1, image2, image3, seller_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())",
        [$name,$price,$qty, $cat ?: null, $desc, $img1, $img2, $img3, $user_id]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'เพิ่มสินค้าเรียบร้อย'];
    }
    header('Location: manage_products.php'); exit;
  }

  if($act==='delete'){
    $pid = (int)($_POST['id'] ?? 0);
    if(!$pid || !$isOwner($pid)){
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'ไม่มีสิทธิ์ลบสินค้านี้'];
    }else{
      // ความปลอดภัยชั้นสอง: ลบเฉพาะของตัวเองเท่านั้น
      q("DELETE FROM products WHERE id=? AND seller_id=?", [$pid, $user_id]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'ลบสินค้าแล้ว'];
    }
    header('Location: manage_products.php'); exit;
  }

  if($act==='quick_update'){
    $pid = (int)($_POST['id'] ?? 0);
    if(!$pid || !$isOwner($pid)){
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'ไม่มีสิทธิ์แก้ไข'];
    }else{
      $price = (float)($_POST['price'] ?? 0);
      $qty   = $_POST['quantity']==='' ? null : (int)$_POST['quantity'];
      // ความปลอดภัยชั้นสอง: อัปเดตเฉพาะของตัวเองเท่านั้น
      q("UPDATE products SET price=?, quantity=? WHERE id=? AND seller_id=?", [$price, $qty, $pid, $user_id]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'อัปเดตราคา/สต็อกสำเร็จ'];
    }
    header('Location: manage_products.php'); exit;
  }
}else if($_SERVER['REQUEST_METHOD']==='POST'){
  $_SESSION['flash'] = ['type'=>'danger','msg'=>'CSRF token ไม่ถูกต้อง'];
  header('Location: manage_products.php'); exit;
}

/* ===== LIST ===== */
$kw = trim($_GET['q'] ?? '');
$catId = (int)($_GET['category'] ?? 0);

$where = [];
$p = [];

/* admin มองเห็นได้ทุกคน แต่ "แก้ไขไม่ได้" ถ้าไม่ใช่เจ้าของ */
/* seller เห็นเฉพาะของตัวเอง */
$where[] = "p.seller_id=?"; $p[]=$user_id;
if($kw!==''){ $where[]="(p.name LIKE ? OR p.description LIKE ?)"; $p[]="%$kw%"; $p[]="%$kw%"; }
if($catId>0){ $where[]="p.category_id=?"; $p[]=$catId; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$products = q("
  SELECT p.*, c.name AS category_name
  FROM products p
  LEFT JOIN categories c ON c.id=p.category_id
  $wsql
  ORDER BY p.created_at DESC, p.id DESC
  LIMIT 200
",$p)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการสินค้าของฉัน</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --bs-body-bg:#0b0f14; --bs-card-bg:#0f1620; --bs-border-color:rgba(148,163,184,.18); }
    .product-img{width:120px;height:80px;object-fit:cover;border:1px solid var(--bs-border-color);border-radius:10px}
    .preview{width:100%;max-width:160px;height:100px;object-fit:cover;border:1px dashed var(--bs-border-color);border-radius:12px;background:#0b0f14}
    .label-chip{font-size:.8rem;padding:.2rem .5rem;border:1px solid var(--bs-border-color);border-radius:999px}
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg border-bottom bg-dark-subtle bg-opacity-50 sticky-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
        <span class="rounded-circle" style="width:28px;height:28px;background:linear-gradient(135deg,#22c55e,#0ea5e9)"></span>
        <strong>Web Shop Game</strong>
      </a>
      <div class="ms-auto d-flex gap-2">
        <a class="btn btn-outline-light btn-sm" href="shop.php">ไปหน้าร้าน</a>
        <a class="btn btn-outline-light btn-sm" href="logout.php">ออกจากระบบ</a>
      </div>
    </div>
  </nav>

  <div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
      <h3 class="mb-0">สินค้าของฉัน</h3>
      <a class="btn btn-success" data-bs-toggle="collapse" href="#addForm">+ เพิ่มสินค้า</a>
    </div>

    <?php if($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?>
        <button class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- ฟิลเตอร์ค้นหา -->
    <form class="row g-2 align-items-end mb-3" method="get">
      <div class="col-md-4">
        <label class="form-label">ค้นหา</label>
        <input class="form-control" name="q" value="<?= h($kw) ?>" placeholder="ชื่อ/คำอธิบาย">
      </div>
      <div class="col-md-3">
        <label class="form-label">หมวดหมู่</label>
        <select class="form-select" name="category">
          <option value="0">-- ทั้งหมด --</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <button class="btn btn-outline-light">ค้นหา</button>
      </div>
    </form>

    <!-- ฟอร์มเพิ่มสินค้า -->
    <div class="collapse" id="addForm">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div><span class="label-chip">เพิ่มสินค้าใหม่</span></div>
        </div>
        <form class="card-body row g-3" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-md-6">
            <label class="form-label">ชื่อสินค้า *</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">ราคา (บาท) *</label>
            <input type="number" step="0.01" min="0" class="form-control" name="price" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">สต็อก (เว้นว่าง = ไม่จำกัด)</label>
            <input type="number" min="0" class="form-control" name="quantity">
          </div>

          <div class="col-md-4">
            <label class="form-label">หมวดหมู่</label>
            <select class="form-select" name="category_id">
              <option value="">-- เลือก --</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-8">
            <label class="form-label">คำอธิบาย</label>
            <textarea class="form-control" name="description" rows="3"></textarea>
          </div>

          <!-- อัปโหลดรูป 1-3 -->
          <div class="col-md-4">
            <label class="form-label">รูปหลัก (image1)</label>
            <input type="file" name="file_image1" class="form-control" accept="image/*" onchange="preview(this,'pv1')">
            <small class="text-secondary">JPG/PNG/WebP/GIF ≤ 5MB</small>
            <img id="pv1" class="preview mt-2" alt="">
          </div>
          <div class="col-md-4">
            <label class="form-label">รูปเสริม (image2)</label>
            <input type="file" name="file_image2" class="form-control" accept="image/*" onchange="preview(this,'pv2')">
            <img id="pv2" class="preview mt-2" alt="">
          </div>
          <div class="col-md-4">
            <label class="form-label">รูปเสริม (image3)</label>
            <input type="file" name="file_image3" class="form-control" accept="image/*" onchange="preview(this,'pv3')">
            <img id="pv3" class="preview mt-2" alt="">
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-success">บันทึกสินค้า</button>
            <button class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#addForm">ยกเลิก</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ตารางสินค้าของฉัน -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-success">
            <tr>
              <th>#</th>
              <th>รูป</th>
              <th>ชื่อ</th>
              <th>หมวดหมู่</th>
              <th class="text-end">ราคา</th>
              <th class="text-end">สต็อก</th>
              <th>อัปเดตด่วน</th>
              <th class="text-end">การจัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$products): ?>
              <tr><td colspan="8" class="text-secondary p-4">ยังไม่มีสินค้า</td></tr>
            <?php else: foreach($products as $i=>$p): 
              $owned = ((int)$p['seller_id'] === $user_id); // เป็นเจ้าของสินค้านี้หรือไม่
            ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><img class="product-img" src="<?= h($p['image1'] ?: 'https://via.placeholder.com/240x160?text=No+Image') ?>" alt=""></td>
                <td>
                  <div class="fw-bold"><?= h($p['name']) ?></div>
                  <div class="small text-secondary">สร้างเมื่อ: <?= h($p['created_at']) ?></div>
                </td>
                <td><?= h($p['category_name'] ?? '-') ?></td>
                <td class="text-end">฿<?= number_format((float)$p['price'],2) ?></td>
                <td class="text-end"><?= $p['quantity']!==null ? (int)$p['quantity'] : '-' ?></td>

                <td>
                  <?php if($owned): ?>
                  <form class="d-flex gap-2" method="post">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="act" value="quick_update">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="number" step="0.01" name="price" class="form-control form-control-sm" style="width:110px" value="<?= h($p['price']) ?>" placeholder="ราคา">
                    <input type="number" name="quantity" class="form-control form-control-sm" style="width:90px" value="<?= h($p['quantity']) ?>" placeholder="สต็อก">
                    <button class="btn btn-sm btn-outline-light">อัปเดต</button>
                  </form>
                  <?php else: ?>
                    <span class="text-secondary small">—</span>
                  <?php endif; ?>
                </td>

                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2">
                    <a class="btn btn-sm btn-outline-light" href="view_product.php?id=<?= (int)$p['id'] ?>" target="_blank">ดูหน้า</a>
                    <?php if($owned): ?>
                      <a class="btn btn-sm btn-success" href="edit_product.php?id=<?= (int)$p['id'] ?>">แก้ไข</a>
                      <form method="post" onsubmit="return confirm('ลบสินค้า &quot;<?= h($p['name']) ?>&quot; ?');">
                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-danger">ลบ</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <a class="btn btn-outline-light" href="index.php">กลับหน้าแรก</a>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function preview(input, id){
      const el = document.getElementById(id);
      if (!el) return;
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => el.src = e.target.result;
        reader.readAsDataURL(input.files[0]);
      } else {
        el.src = '';
      }
    }
  </script>
</body>
</html>
