<?php
// admin_payment_action.php
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

// ตรวจสิทธิ์แอดมิน
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สำหรับผู้ดูแลระบบเท่านั้น'];
    header('Location: index.php');
    exit;
}
$admin_id = (int)$user['id'];

// CSRF
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'CSRF token ไม่ถูกต้อง'];
    header('Location: admin_payments_review.php');
    exit;
}

// รับพารามิเตอร์
$pid    = (int)($_POST['payment_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');

// โหลดข้อมูล payment + order
$pay = q1("SELECT p.*, o.user_id, o.order_number, o.total_amount, o.status AS order_status
           FROM payments p
           JOIN orders o ON o.id = p.order_id
           WHERE p.id=?", [$pid]);

if (!$pay) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ไม่พบรายการชำระเงิน'];
    header('Location: admin_payments_review.php');
    exit;
}

try {
    if ($action === 'approve') {
        // เงื่อนไข: อนุมัติได้เฉพาะ payment pending + order ต้องอยู่ awaiting_verification
        if ($pay['status'] !== 'pending' || $pay['order_status'] !== 'awaiting_verification') {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'สถานะไม่ถูกต้อง ไม่สามารถอนุมัติได้'];
            header('Location: admin_payments_review.php?status=pending');
            exit;
        }

        $pdo->beginTransaction();

        // 1) อัปเดต payment
        q("UPDATE payments
       SET status='approved', reviewed_by=?, reviewed_at=NOW(), reject_reason=NULL
       WHERE id=? AND status='pending'", [$admin_id, $pid]);

        // 2) อัปเดต order → เงินเข้าบัญชีคนกลาง
        q("UPDATE orders
       SET status='escrow_held', updated_at=NOW()
       WHERE id=? AND status='awaiting_verification'", [$pay['order_id']]);

        // 3) เขียน escrow ledger (hold)
        q(
            "INSERT INTO escrow_ledger(order_id, action, amount, note, actor_id)
       VALUES(?, 'hold', ?, 'admin approved payment & hold in escrow', ?)",
            [$pay['order_id'], $pay['total_amount'], $admin_id]
        );

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'อนุมัติสลิปสำเร็จ: เงินถูกพักไว้กับ Escrow แล้ว'];
        header('Location: admin_payments_review.php?status=pending');
        exit;
    } elseif ($action === 'reject') {
        // เงื่อนไข: ปฏิเสธได้เฉพาะ payment pending
        if ($pay['status'] !== 'pending') {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'รายการนี้ไม่ได้อยู่สถานะรอตรวจ'];
            header('Location: admin_payments_review.php?status=pending');
            exit;
        }
        if ($reason === '') $reason = 'หลักฐานไม่ถูกต้อง';

        $pdo->beginTransaction();

        // 1) อัปเดต payment
        q("UPDATE payments
       SET status='rejected', reviewed_by=?, reviewed_at=NOW(), reject_reason=?
       WHERE id=? AND status='pending'", [$admin_id, $reason, $pid]);

        // 2) คืนสถานะออเดอร์ให้กลับไปชำระใหม่ (ถ้าอยู่ระหว่างตรวจ)
        q("UPDATE orders
       SET status='awaiting_payment', updated_at=NOW()
       WHERE id=? AND status IN ('awaiting_verification')", [$pay['order_id']]);

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ปฏิเสธสลิปแล้ว: ผู้ซื้อจะต้องอัปโหลดใหม่'];
        header('Location: admin_payments_review.php?status=pending');
        exit;
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คำสั่งไม่ถูกต้อง'];
        header('Location: admin_payments_review.php');
        exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'เกิดข้อผิดพลาดในการบันทึก'];
    header('Location: admin_payments_review.php');
    exit;
}
