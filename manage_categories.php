<?php
session_start();
require __DIR__.'/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q($sql,$params=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
function q1($sql,$params=[]){ global $pdo; $st=$pdo->prepare($sql); $st->execute($params); return $st->fetch(PDO::FETCH_ASSOC); }

$user = $_SESSION['user'] ?? null;
$role = $user['role'] ?? null;
if($role !== 'admin'){
  $_SESSION['flash'] = ['type'=>'warning','msg'=>'สำหรับแอดมินเท่านั้น'];
  header('Location: index.php'); exit;
}

/* CSRF */
if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
function check_csrf(){
  if(($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')){ http_response_code(403); exit('Bad CSRF'); }
}

/* ACTIONS: create / update / delete */
if($_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $action = $_POST['action'] ?? '';
  if($action==='create'){
    $name = trim($_POST['name'] ?? '');
    if($name===''){
      $_SESSION['flash']=['type'=>'danger','msg'=>'กรุณากรอกชื่อหมวดหมู่'];
    }else{
      $exists = q1("SELECT id FROM categories WHERE name = ?",[$name]);
      if($exists){
        $_SESSION['flash']=['type'=>'warning','msg'=>'มีชื่อหมวดนี้อยู่แล้ว'];
      }else{
        $ok = q("INSERT INTO categories(name, created_at) VALUES(?, NOW())",[$name]);
        $_SESSION['flash']=['type'=>'success','msg'=>'เพิ่มหมวดหมู่สำเร็จ'];
      }
    }
    header('Location: manage_categories.php'); exit;
  }

  if($action==='update'){
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if($id<=0 || $name===''){
      $_SESSION['flash']=['type'=>'danger','msg'=>'ข้อมูลไม่ครบ'];
    }else{
      $dup = q1("SELECT id FROM categories WHERE name=? AND id<>?",[$name,$id]);
      if($dup){
        $_SESSION['flash']=['type'=>'warning','msg'=>'มีชื่อหมวดนี้อยู่แล้ว'];
      }else{
        q("UPDATE categories SET name=? WHERE id=?",[$name,$id]);
        $_SESSION['flash']=['type'=>'success','msg'=>'บันทึกการแก้ไขแล้ว'];
      }
    }
    header('Location: manage_categories.php'); exit;
  }

  if($action==='delete'){
    $id = (int)($_POST['id'] ?? 0);
    if($id>0){
      $cnt = q1("SELECT COUNT(*) c FROM products WHERE category_id=?",[$id]);
      if((int)($cnt['c']??0) > 0){
        $_SESSION['flash']=['type'=>'warning','msg'=>'ไม่สามารถลบได้: ยังมีสินค้าอยู่ในหมวดนี้'];
      }else{
        q("DELETE FROM categories WHERE id=?",[$id]);
        $_SESSION['flash']=['type'=>'success','msg'=>'ลบหมวดหมู่แล้ว'];
      }
    }
    header('Location: manage_categories.php'); exit;
  }
}

/* LIST + search + pagination */
$kw = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if($kw!==''){ $where = "WHERE name LIKE ?"; $params[] = "%$kw%"; }

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)(q1("SELECT COUNT(*) c FROM categories $where", $params)['c'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page-1) * $perPage;

$rows = q("SELECT id,name,created_at FROM categories $where ORDER BY name ASC LIMIT $perPage OFFSET $offset", $params);
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการหมวดหมู่ - Web Shop Game</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --bs-body-bg:#0b0f14; --bs-card-bg:#0f1620; --bs-border-color:rgba(148,163,184,.18); }
    .table td, .table th { vertical-align: middle; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg border-bottom bg-dark-subtle bg-opacity-50 sticky-top">
  <div class="container">
    <a class="navbar-brand" href="index.php">Web Shop Game</a>
    <div class="collapse navbar-collapse" id="navMain"></div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-light btn-sm">หน้าแรก</a>
      <a href="manage_products.php" class="btn btn-outline-light btn-sm">จัดการสินค้า</a>
    </div>
  </div>
</nav>

<main class="container py-4">
  <?php if($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
      <?= h($flash['msg'] ?? '') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">เพิ่มหมวดหมู่</div>
        <div class="card-body">
          <form method="post" class="d-grid gap-2">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="action" value="create">
            <label class="form-label">ชื่อหมวดหมู่</label>
            <input class="form-control" name="name" maxlength="100" required>
            <button class="btn btn-success mt-2">บันทึก</button>
          </form>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">ค้นหา</div>
        <div class="card-body">
          <form class="d-flex gap-2" method="get">
            <input class="form-control" name="q" value="<?= h($kw) ?>" placeholder="พิมพ์ชื่อหมวด">
            <button class="btn btn-outline-light">ค้นหา</button>
            <?php if($kw!==''): ?><a class="btn btn-outline-secondary" href="manage_categories.php">ล้าง</a><?php endif; ?>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>รายการหมวดหมู่</div>
          <small class="text-secondary">ทั้งหมด <?= number_format($total) ?> รายการ</small>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
              <tr>
                <th style="width:70px">ID</th>
                <th>ชื่อ</th>
                <th style="width:180px">จัดการ</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$rows): ?>
              <tr><td colspan="3" class="text-secondary">ไม่พบข้อมูล</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= h($r['name']) ?></td>
                <td>
                  <div class="d-flex gap-2">
                    <!-- Edit button triggers modal -->
                    <button class="btn btn-sm btn-outline-light"
                            data-bs-toggle="modal"
                            data-bs-target="#editModal"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= h($r['name']) ?>">แก้ไข</button>

                    <form method="post" onsubmit="return confirm('ลบหมวดนี้?');">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">ลบ</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if($pages>1): ?>
        <div class="card-footer">
          <nav>
            <ul class="pagination mb-0">
              <?php for($i=1;$i<=$pages;$i++): 
                $qs = http_build_query(array_filter(['page'=>$i, 'q'=>$kw ?: null])); ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                  <a class="page-link" href="?<?= $qs ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
            </ul>
          </nav>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title">แก้ไขหมวดหมู่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit-id">
        <label class="form-label">ชื่อหมวดหมู่</label>
        <input class="form-control" name="name" id="edit-name" required maxlength="100">
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const editModal = document.getElementById('editModal');
  editModal.addEventListener('show.bs.modal', e=>{
    const btn = e.relatedTarget;
    document.getElementById('edit-id').value = btn.getAttribute('data-id');
    document.getElementById('edit-name').value = btn.getAttribute('data-name');
  });
</script>
</body>
</html>
