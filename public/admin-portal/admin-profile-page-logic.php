<?php
// ========================================================
// UPDATE ADMIN PROFILE BACKEND HANDLER
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

// Resolve PDO Connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if (!$dbConn) {
    die("Database connection error.");
}

$dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: admin-profile-page.php");
    exit();
}

$section = isset($_POST['section']) ? trim($_POST['section']) : '';

// ========================================================
// SAFELY RESOLVE ADMIN DATABASE PRIMARY KEY (INTEGER ID)
// ========================================================
$admin_id = 0;

if (isset($_SESSION['admin_db_id']) && is_numeric($_SESSION['admin_db_id'])) {
    $admin_id = intval($_SESSION['admin_db_id']);
} elseif (isset($_POST['admin_id']) && is_numeric($_POST['admin_id'])) {
    $admin_id = intval($_POST['admin_id']);
} elseif (isset($_SESSION['email'])) {
    $stmt_find = $dbConn->prepare("SELECT `id` FROM `admins` WHERE `email` = :email LIMIT 1");
    $stmt_find->execute(['email' => $_SESSION['email']]);
    $found = $stmt_find->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $admin_id = intval($found['id']);
    }
}

if ($admin_id <= 0) {
    $_SESSION['flash_message'] = "Error: Invalid admin session. Please log in again.";
    $_SESSION['flash_type'] = "error";
    header("Location: admin-profile-page.php");
    exit();
}

// ========================================================
// 1. UPDATE PERSONAL & ADMINISTRATIVE INFORMATION
// ========================================================
if ($section === 'personal') {
    $full_name      = trim($_POST['full_name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $admin_code     = trim($_POST['admin_code'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $designation    = trim($_POST['designation'] ?? '');
    $department     = trim($_POST['department'] ?? '');

    if (empty($full_name) || empty($email) || empty($admin_code)) {
        $_SESSION['flash_message'] = "Full Name, Email, and Admin ID cannot be empty.";
        $_SESSION['flash_type'] = "error";
        header("Location: admin-profile-page.php");
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_message'] = "Invalid email format.";
        $_SESSION['flash_type'] = "error";
        header("Location: admin-profile-page.php");
        exit();
    }

    try {
        // Fetch existing email to sync with users-table
        $stmt_old = $dbConn->prepare("SELECT `email` FROM `admins` WHERE `id` = :id LIMIT 1");
        $stmt_old->execute(['id' => $admin_id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
        $old_email = $old_data['email'] ?? $_SESSION['email'] ?? '';

        // Update `admins` table
        $stmt = $dbConn->prepare("UPDATE `admins` SET 
            `full_name`      = :full_name,
            `email`          = :email,
            `admin_id`       = :admin_code,
            `contact_number` = :contact,
            `designation`    = :designation,
            `department`     = :department
            WHERE `id` = :id LIMIT 1");

        $stmt->execute([
            'full_name'   => $full_name,
            'email'       => $email,
            'admin_code'  => $admin_code,
            'contact'     => $contact_number,
            'designation' => $designation,
            'department'  => $department,
            'id'          => $admin_id
        ]);

        // Synchronize name & email with `users-table`
        if (!empty($old_email)) {
            $stmt_u = $dbConn->prepare("UPDATE `users-table` SET 
                `name`  = :full_name, 
                `email` = :email 
                WHERE `email` = :old_email AND `role` = 'admin' LIMIT 1");
            $stmt_u->execute([
                'full_name' => $full_name,
                'email'     => $email,
                'old_email' => $old_email
            ]);
        }

        // Keep Session synchronized
        $_SESSION['email']       = $email;
        $_SESSION['admin_id']    = $admin_code;
        $_SESSION['full_name']   = $full_name;
        $_SESSION['admin_db_id'] = $admin_id;

        $_SESSION['flash_message'] = "Admin profile updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $_SESSION['flash_message'] = "Error: Email or Admin ID is already in use by another account.";
        } else {
            $_SESSION['flash_message'] = "Error updating profile: " . $e->getMessage();
        }
        $_SESSION['flash_type'] = "error";
    }

    header("Location: admin-profile-page.php");
    exit();
}

// ========================================================
// 2. UPDATE AVATAR / PROFILE IMAGE
// ========================================================
if ($section === 'avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            // File size validation (Max 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                $_SESSION['flash_message'] = "File size exceeds limit (Max: 5MB).";
                $_SESSION['flash_type'] = "error";
                header("Location: admin-profile-page.php");
                exit();
            }

            $uploadDir = __DIR__ . '/../assets/images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Fetch old profile image to cleanup if custom file
            $stmt_old_img = $dbConn->prepare("SELECT `profile_image` FROM `admins` WHERE `id` = :id LIMIT 1");
            $stmt_old_img->execute(['id' => $admin_id]);
            $old_img_row = $stmt_old_img->fetch(PDO::FETCH_ASSOC);

            $newFileName = 'avatar_admin_' . $admin_id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newFileName;
            $dbPath = 'assets/images/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                // Delete old image file if custom
                if (!empty($old_img_row['profile_image']) && strpos($old_img_row['profile_image'], 'user-avatar.jpg') === false) {
                    $oldFilePath = __DIR__ . '/../' . ltrim($old_img_row['profile_image'], '/');
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                }

                $stmt = $dbConn->prepare("UPDATE `admins` SET `profile_image` = :img WHERE `id` = :id LIMIT 1");
                $stmt->execute(['img' => $dbPath, 'id' => $admin_id]);

                $_SESSION['profile_image'] = $dbPath;
                $_SESSION['flash_message'] = "Profile picture updated successfully!";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Failed to upload image file.";
                $_SESSION['flash_type'] = "error";
            }
        } else {
            $_SESSION['flash_message'] = "Invalid image format. Allowed: JPG, PNG, WEBP, GIF.";
            $_SESSION['flash_type'] = "error";
        }
    } else {
        $_SESSION['flash_message'] = "No image selected or upload error occurred.";
        $_SESSION['flash_type'] = "error";
    }

    header("Location: admin-profile-page.php");
    exit();
}

header("Location: admin-profile-page.php");
exit();