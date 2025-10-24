<?php
// เริ่ม session แค่ครั้งเดียว
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ข้อมูลการเชื่อมต่อฐานข้อมูล
$DB_HOST = 'localhost';
$DB_NAME = 'Web_shopgame'; // Make sure this is your correct database name
$DB_USER = 'root';
$DB_PASS = ''; // Usually empty for default XAMPP/MAMP

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch results as associative arrays
            PDO::ATTR_EMULATE_PREPARES => false, // Use native prepared statements
        ]
    );
} catch (PDOException $e) {
    // Stop execution and show error if connection fails
    die("❌ Database connection failed: " . $e->getMessage());
}

// Helper function for escaping HTML output (Prevent XSS)
if (!function_exists('h')) {
    function h($string)
    {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}

// *** [เพิ่ม] Helper function for executing SQL and fetching all results ***
if (!function_exists('q')) {
    function q($sql, $params = [])
    {
        global $pdo; // Access the global $pdo connection variable
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st; // Return the PDOStatement object
        } catch (PDOException $e) {
            // Log the error or handle it more gracefully in a real application
            die("❌ SQL Error: " . $e->getMessage() . "<br>Query: " . $sql);
        }
    }
}

// *** [เพิ่ม] Helper function for executing SQL and fetching a single row ***
if (!function_exists('q1')) {
    function q1($sql, $params = [])
    {
        // Use the q() function to execute
        $st = q($sql, $params);
        // Fetch and return the first row (or false if no rows)
        return $st->fetch();
    }
}

// *** [เพิ่ม] Helper function for getting status text and CSS classes ***
if (!function_exists('getStatusInfo')) {
    function getStatusInfo($status)
    {
        $map = [
            'pending'               => ['รอดำเนินการ', 'secondary', 'bg-light text-dark'],
            'awaiting_payment'      => ['รอชำระเงิน', 'warning', 'bg-warning-subtle text-warning-emphasis'],
            'awaiting_verification' => ['รอตรวจสลิป', 'info', 'bg-info-subtle text-info-emphasis'],
            'approved'              => ['ชำระแล้ว', 'primary', 'bg-primary-subtle text-primary-emphasis'], // Renamed for clarity
            'escrow_held'           => ['พักเงินกลาง', 'primary', 'bg-primary-subtle text-primary-emphasis'],
            'shipped'               => ['จัดส่งแล้ว', 'primary', 'bg-primary-subtle text-primary-emphasis'],
            'completed'             => ['สำเร็จ', 'success', 'bg-success-subtle text-success-emphasis'],
            'cancelled'             => ['ยกเลิก', 'danger', 'bg-danger-subtle text-danger-emphasis'],
            'rejected'              => ['ถูกปฏิเสธ', 'danger', 'bg-danger-subtle text-danger-emphasis'], // Added for KYC/Payments
            // Add other statuses if needed
        ];
        // Returns [Thai Text, Base Bootstrap Color, CSS Classes for Light Theme]
        return $map[$status] ?? [$status, 'secondary', 'bg-light text-dark']; // Default for unknown status
    }
}
