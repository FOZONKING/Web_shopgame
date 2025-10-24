<?php
session_start();
require __DIR__ . '/db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Helper function for security
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Create a placeholder for the first letter of the name for the avatar
$initial = strtoupper(substr($user['name'] ?? $user['email'], 0, 1));

?>
<!doctype html>
<html lang="th" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>โปรไฟล์ของฉัน</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            --muted: #6c757d;
            --brand: #22c55e;
            /* Vibrant Green */
            --brand-dark: #16a34a;
        }

        body {
            background-color: var(--bg);
            font-family: 'Sora', sans-serif;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .profile-card {
            background-color: var(--card);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 40px rgba(0, 0, 0, .07);
            overflow: hidden;
            /* Important for keeping rounded corners with the cover image */
            max-width: 450px;
            width: 100%;
        }

        .profile-cover {
            height: 160px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            background-image: url('https://images.unsplash.com/photo-1579546929518-9e396f3cc809?q=80&w=1000&auto=format&fit=crop');
            /* Cool gradient background */
            background-size: cover;
            background-position: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 3rem;
            font-weight: 800;
            margin: -60px auto 0;
            /* Pulls the avatar up */
            position: relative;
            border: 6px solid var(--card);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .1);
        }

        .profile-body {
            padding: 24px;
            text-align: center;
        }

        .profile-name {
            font-weight: 800;
            font-size: 1.75rem;
        }

        .profile-email {
            color: var(--muted);
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        .profile-role {
            display: inline-block;
            background-color: #e8f9f0;
            color: var(--brand-dark);
            padding: 6px 16px;
            border-radius: 30px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .btn-brand-outline {
            color: var(--brand);
            border-color: var(--brand);
            border-radius: 12px;
            font-weight: 600;
            padding: .6rem 1.2rem;
            transition: all .2s;
        }

        .btn-brand-outline:hover {
            background-color: var(--brand);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <div class="profile-card mx-auto">
            <div class="profile-cover"></div>
            <div class="profile-avatar">
                <?= h($initial) ?>
            </div>
            <div class="profile-body">
                <h2 class="profile-name mb-1"><?= h($user['name'] ?? 'ไม่ได้ตั้งชื่อ') ?></h2>
                <p class="profile-email"><?= h($user['email']) ?></p>
                <div class="mb-4">
                    <span class="profile-role">
                        <i class="bi bi-person-badge me-1"></i> <?= h($user['role']) ?>
                    </span>
                </div>
                <a href="index.php" class="btn btn-brand-outline">
                    <i class="bi bi-arrow-left-circle me-1"></i> กลับหน้าหลัก
                </a>
            </div>
        </div>
    </div>
</body>

</html>