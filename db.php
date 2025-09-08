<?php
// เริ่ม session แค่ครั้งเดียว
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ข้อมูลการเชื่อมต่อฐานข้อมูล
$DB_HOST = 'localhost';
$DB_NAME = 'Web_shopgame';
$DB_USER = 'root';
$DB_PASS = ''; // ถ้าใช้ XAMPP/MAMP/WAMP ปกติจะว่างเปล่า

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // โหมดแจ้ง Error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // คืนค่าผลลัพธ์เป็น Array แบบ key ชื่อคอลัมน์
            PDO::ATTR_EMULATE_PREPARES => false, // ใช้ prepared statement ของ MySQL จริง
        ]
    );
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}

// ฟังก์ชัน escape HTML
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}
