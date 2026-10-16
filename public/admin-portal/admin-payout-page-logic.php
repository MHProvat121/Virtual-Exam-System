<?php
// ========================================================
// ADMIN PAYOUT BACKEND HANDLER (ADD, UPDATE, DELETE)
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

// Resolve PDO Connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if (!$dbConn) {
    $_SESSION['flash_message'] = "Database connection error.";
    $_SESSION['flash_type'] = "error";
    header("Location: admin-payout-page.php");
    exit();
}

$dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// RBAC Gatekeeper: Admin Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin-payout-page.php");
    exit();
}

$action = trim($_POST['action'] ?? '');

// Helper to format/sanitize comma separated registered courses
function sanitizeCoursesInput($coursesRaw): string {
    if (is_array($coursesRaw)) {
        $coursesRaw = implode(',', $coursesRaw);
    }
    $parts = explode(',', (string)$coursesRaw);
    $cleaned = [];
    foreach ($parts as $p) {
        $trimmed = trim($p);
        if (!empty($trimmed) && strtolower($trimmed) !== 'n/a') {
            $cleaned[] = $trimmed;
        }
    }
    return !empty($cleaned) ? implode(', ', $cleaned) : 'N/A';
}

// --------------------------------------------------------
// 1. ADD NEW PAYOUT RECORD
// --------------------------------------------------------
if ($action === 'add_payout') {
    $teacher_id   = trim($_POST['teacher_id'] ?? '');
    $courses      = sanitizeCoursesInput($_POST['registered_courses'] ?? '');
    $amount       = floatval($_POST['amount'] ?? 0);
    $payment_date = trim($_POST['payment_date'] ?? '');
    $method       = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $status       = trim($_POST['status'] ?? 'Paid');
    $payor_id     = trim($_POST['payor_id'] ?? '');

    if (empty($teacher_id) || $amount <= 0 || empty($payment_date) || empty($payor_id)) {
        $_SESSION['flash_message'] = "Please fill in all required payout fields accurately.";
        $_SESSION['flash_type'] = "error";
        header("Location: admin-payout-page.php");
        exit();
    }

    try {
        $stmt = $dbConn->prepare("
            INSERT INTO `teacher_salaries` 
            (`teacher_id`, `registered_courses`, `amount`, `payment_date`, `payment_method`, `payor_id`, `status`) 
            VALUES (:tid, :courses, :amount, :pdate, :method, :payor, :status)
        ");

        $stmt->execute([
            'tid'     => $teacher_id,
            'courses' => $courses,
            'amount'  => $amount,
            'pdate'   => $payment_date,
            'method'  => $method,
            'payor'   => $payor_id,
            'status'  => $status
        ]);

        $_SESSION['flash_message'] = "Payout issued successfully to teacher!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Database Error: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: admin-payout-page.php");
    exit();
}

// --------------------------------------------------------
// 2. UPDATE EXISTING PAYOUT RECORD
// --------------------------------------------------------
if ($action === 'update_payout') {
    $payout_id   = intval($_POST['payout_id'] ?? 0);
    $teacher_id  = trim($_POST['teacher_id'] ?? '');
    $courses     = sanitizeCoursesInput($_POST['registered_courses'] ?? '');
    $amount      = floatval($_POST['amount'] ?? 0);
    $payment_date= trim($_POST['payment_date'] ?? '');
    $method      = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $status      = trim($_POST['status'] ?? 'Paid');
    $payor_id    = trim($_POST['payor_id'] ?? '');

    if ($payout_id <= 0 || empty($teacher_id) || $amount <= 0) {
        $_SESSION['flash_message'] = "Invalid payout update details.";
        $_SESSION['flash_type'] = "error";
        header("Location: admin-payout-page.php");
        exit();
    }

    try {
        $stmt = $dbConn->prepare("
            UPDATE `teacher_salaries` SET 
                `teacher_id` = :tid,
                `registered_courses` = :courses,
                `amount` = :amount,
                `payment_date` = :pdate,
                `payment_method` = :method,
                `payor_id` = :payor,
                `status` = :status
            WHERE `id` = :pid LIMIT 1
        ");

        $stmt->execute([
            'tid'     => $teacher_id,
            'courses' => $courses,
            'amount'  => $amount,
            'pdate'   => $payment_date,
            'method'  => $method,
            'payor'   => $payor_id,
            'status'  => $status,
            'pid'     => $payout_id
        ]);

        $_SESSION['flash_message'] = "Payout record updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Database Error: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: admin-payout-page.php");
    exit();
}

// --------------------------------------------------------
// 3. DELETE PAYOUT RECORD
// --------------------------------------------------------
if ($action === 'delete_payout') {
    $payout_id = intval($_POST['payout_id'] ?? 0);

    if ($payout_id > 0) {
        try {
            $stmt = $dbConn->prepare("DELETE FROM `teacher_salaries` WHERE `id` = :pid LIMIT 1");
            $stmt->execute(['pid' => $payout_id]);

            $_SESSION['flash_message'] = "Payout log record deleted successfully.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['flash_message'] = "Failed to delete payout record: " . $e->getMessage();
            $_SESSION['flash_type'] = "error";
        }
    }

    header("Location: admin-payout-page.php");
    exit();
}

header("Location: admin-payout-page.php");
exit();