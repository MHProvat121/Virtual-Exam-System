<?php
// ========================================================
// UPDATE TEACHER PROFILE BACKEND HANDLER
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

if (isset($conn)) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: teacher-profile-page.php");
    exit();
}

$section = isset($_POST['section']) ? trim($_POST['section']) : '';

// Resolve Teacher ID safely
$teacher_id = 0;
if (isset($_SESSION['teacher_db_id']) && is_numeric($_SESSION['teacher_db_id'])) {
    $teacher_id = intval($_SESSION['teacher_db_id']);
} elseif (isset($_POST['teacher_id']) && is_numeric($_POST['teacher_id'])) {
    $teacher_id = intval($_POST['teacher_id']);
} elseif (isset($_SESSION['email'])) {
    $stmt_find = $conn->prepare("SELECT `id` FROM `teachers` WHERE `email` = :email LIMIT 1");
    $stmt_find->execute(['email' => $_SESSION['email']]);
    $found = $stmt_find->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $teacher_id = intval($found['id']);
    }
}

if ($teacher_id <= 0) {
    $_SESSION['flash_message'] = "Error: Invalid teacher profile session. Please re-login.";
    $_SESSION['flash_type'] = "error";
    header("Location: teacher-profile-page.php");
    exit();
}

// ========================================================
// 1. UPDATE PERSONAL INFORMATION
// ========================================================
if ($section === 'personal') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $teacher_code     = trim($_POST['teacher_code'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $designation      = trim($_POST['designation'] ?? '');
    $present_address  = trim($_POST['present_address'] ?? '');
    $permanent_address= trim($_POST['permanent_address'] ?? '');

    if (empty($full_name) || empty($email)) {
        $_SESSION['flash_message'] = "Full Name and Email cannot be empty.";
        $_SESSION['flash_type'] = "error";
        header("Location: teacher-profile-page.php");
        exit();
    }

    try {
        $conn->beginTransaction();

        $old_email = $_SESSION['email'] ?? '';

        // A. Update teachers table (registration_date removed to prevent PDO error)
        $stmt = $conn->prepare("UPDATE `teachers` SET 
            `full_name` = :full_name,
            `email` = :email,
            `teacher_id` = :teacher_code,
            `contact_number` = :contact,
            `designation` = :designation,
            `present_address` = :present,
            `permanent_address` = :permanent
            WHERE `id` = :id LIMIT 1");

        $stmt->execute([
            'full_name'    => $full_name,
            'email'        => $email,
            'teacher_code' => $teacher_code,
            'contact'      => $contact_number,
            'designation'  => $designation,
            'present'      => $present_address,
            'permanent'    => $permanent_address,
            'id'           => $teacher_id
        ]);

        // B. Update users-table to sync username, full name, and email
        if (!empty($old_email)) {
            $stmt_u = $conn->prepare("UPDATE `users-table` SET 
                `name` = :fname,
                `email` = :new_email" . 
                (!empty($username) ? ", `username` = :uname" : "") . 
                " WHERE `email` = :old_email LIMIT 1");

            $params = [
                'fname'     => $full_name,
                'new_email' => $email,
                'old_email' => $old_email
            ];

            if (!empty($username)) {
                $params['uname'] = $username;
            }

            $stmt_u->execute($params);
        }

        $conn->commit();

        // Update Session Values
        $_SESSION['email'] = $email;
        if (!empty($teacher_code)) {
            $_SESSION['teacher_id'] = $teacher_code;
        }

        $_SESSION['flash_message'] = "Personal Information updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['flash_message'] = "Error updating personal info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: teacher-profile-page.php");
    exit();
}

// ========================================================
// 2. UPDATE EDUCATION INFORMATION
// ========================================================
if ($section === 'education') {
    $institution = trim($_POST['institution'] ?? '');
    $program     = trim($_POST['program'] ?? '');

    try {
        $stmt_check = $conn->prepare("SELECT `id` FROM `teacher_education` WHERE `teacher_id` = :tid LIMIT 1");
        $stmt_check->execute(['tid' => $teacher_id]);
        $exists = $stmt_check->fetch();

        if ($exists) {
            $stmt = $conn->prepare("UPDATE `teacher_education` SET 
                `institution` = :inst,
                `program` = :prog
                WHERE `teacher_id` = :tid LIMIT 1");
        } else {
            $stmt = $conn->prepare("INSERT INTO `teacher_education` (`teacher_id`, `institution`, `program`) VALUES (:tid, :inst, :prog)");
        }

        $stmt->execute([
            'inst' => $institution,
            'prog' => $program,
            'tid'  => $teacher_id
        ]);

        $_SESSION['flash_message'] = "Education Information updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error updating education info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: teacher-profile-page.php");
    exit();
}

// ========================================================
// 3. UPDATE PAYMENT INFORMATION
// ========================================================
if ($section === 'payment') {
    $bank_name      = trim($_POST['bank_name'] ?? '');
    $account_name   = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $mobile_payment = trim($_POST['mobile_banking_number'] ?? '');

    try {
        $stmt_check = $conn->prepare("SELECT `id` FROM `teacher_payments` WHERE `teacher_id` = :tid LIMIT 1");
        $stmt_check->execute(['tid' => $teacher_id]);
        $exists = $stmt_check->fetch();

        if ($exists) {
            $stmt = $conn->prepare("UPDATE `teacher_payments` SET 
                `bank_name` = :bank,
                `account_name` = :acc_name,
                `account_number` = :acc_num,
                `mobile_banking_number` = :mobile
                WHERE `teacher_id` = :tid LIMIT 1");
        } else {
            $stmt = $conn->prepare("INSERT INTO `teacher_payments` (`teacher_id`, `bank_name`, `account_name`, `account_number`, `mobile_banking_number`) VALUES (:tid, :bank, :acc_name, :acc_num, :mobile)");
        }

        $stmt->execute([
            'bank'     => $bank_name,
            'acc_name' => $account_name,
            'acc_num'  => $account_number,
            'mobile'   => $mobile_payment,
            'tid'      => $teacher_id
        ]);

        $_SESSION['flash_message'] = "Payment Information updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error updating payment info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: teacher-profile-page.php");
    exit();
}

// ========================================================
// 4. UPDATE AVATAR / PROFILE IMAGE
// ========================================================
if ($section === 'avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $uploadDir = __DIR__ . '/../assets/images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'avatar_teacher_' . $teacher_id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newFileName;
            $dbPath = 'assets/images/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $stmt = $conn->prepare("UPDATE `teachers` SET `profile_image` = :img WHERE `id` = :id LIMIT 1");
                $stmt->execute(['img' => $dbPath, 'id' => $teacher_id]);

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
        $_SESSION['flash_message'] = "No image file selected or upload error occurred.";
        $_SESSION['flash_type'] = "error";
    }

    header("Location: teacher-profile-page.php");
    exit();
}

header("Location: teacher-profile-page.php");
exit();