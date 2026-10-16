<?php
// ========================================================
// TEACHER SETTINGS BACKEND LOGIC HANDLER
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection configuration
require_once __DIR__ . '/../../src/config/config.php';

// 1. RBAC Gatekeeper: Verify User Session
if (!isset($_SESSION['user_id']) && !isset($_SESSION['email'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 2. Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: teacher-setting-page.php");
    exit();
}

// Determine which settings form action was submitted
$action = $_POST['action'] ?? '';

// ========================================================
// ACTION: CHANGE PASSWORD
// ========================================================
if (isset($_POST['update_password']) || $action === 'change_password') {
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Validation: Empty Fields
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['flash_message'] = "All password fields are required.";
        $_SESSION['flash_type'] = "error";
        header("Location: teacher-setting-page.php");
        exit();
    }

    // 2. Validation: Password Match
    if ($new_password !== $confirm_password) {
        $_SESSION['flash_message'] = "New password and confirm password do not match.";
        $_SESSION['flash_type'] = "error";
        header("Location: teacher-setting-page.php");
        exit();
    }

    // 3. Validation: Minimum Password Length
    if (strlen($new_password) < 6) {
        $_SESSION['flash_message'] = "New password must be at least 6 characters long.";
        $_SESSION['flash_type'] = "error";
        header("Location: teacher-setting-page.php");
        exit();
    }

    // 4. DB Operation: Verify Current Password & Update in users-table
    try {
        $session_uid   = $_SESSION['user_id'] ?? 0;
        $session_email = $_SESSION['email'] ?? '';

        // Fetch user password hash from users-table
        $stmt = $conn->prepare("
            SELECT `id`, `password` 
            FROM `users-table` 
            WHERE `id` = :uid OR (`email` = :email AND :email != '') 
            LIMIT 1
        ");
        $stmt->execute([
            'uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
            'email' => strval($session_email)
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['flash_message'] = "User account record not found.";
            $_SESSION['flash_type'] = "error";
            header("Location: teacher-setting-page.php");
            exit();
        }

        // Verify current password against database hash
        if (!password_verify($current_password, $user['password'])) {
            $_SESSION['flash_message'] = "Incorrect current password. Please try again.";
            $_SESSION['flash_type'] = "error";
            header("Location: teacher-setting-page.php");
            exit();
        }

        // Hash new password securely
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);

        // Update password for logged-in user
        $update_stmt = $conn->prepare("
            UPDATE `users-table` 
            SET `password` = :new_hash 
            WHERE `id` = :uid 
            LIMIT 1
        ");
        $update_stmt->execute([
            'new_hash' => $new_hash,
            'uid'      => $user['id']
        ]);

        $_SESSION['flash_message'] = "Password updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        error_log("Error changing password: " . $e->getMessage());
        $_SESSION['flash_message'] = "A database error occurred while updating password.";
        $_SESSION['flash_type'] = "error";
    }

    header("Location: teacher-setting-page.php");
    exit();
}

// Default Fallback Redirect
header("Location: teacher-setting-page.php");
exit();