<?php
// ========================================================
// UPDATE STUDENT PROFILE BACKEND HANDLER
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

if (isset($conn)) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: student-profile-page.php");
    exit();
}

$section = isset($_POST['section']) ? trim($_POST['section']) : '';

// Resolve student database primary key (integer ID)
$student_id = 0;

if (isset($_SESSION['student_db_id']) && is_numeric($_SESSION['student_db_id'])) {
    $student_id = intval($_SESSION['student_db_id']);
} elseif (isset($_POST['student_id']) && is_numeric($_POST['student_id'])) {
    $student_id = intval($_POST['student_id']);
} elseif (isset($_SESSION['email'])) {
    $stmt_find = $conn->prepare("SELECT `id` FROM `students` WHERE `email` = :email LIMIT 1");
    $stmt_find->execute(['email' => $_SESSION['email']]);
    $found = $stmt_find->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $student_id = intval($found['id']);
    }
}

if ($student_id <= 0) {
    $_SESSION['flash_message'] = "Error: Invalid student profile session. Please log in again.";
    $_SESSION['flash_type'] = "error";
    header("Location: student-profile-page.php");
    exit();
}

// ========================================================
// 1. UPDATE PERSONAL INFORMATION (students & users-table)
// ========================================================
if ($section === 'personal') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $student_code     = trim($_POST['student_code'] ?? '');
    $batch            = trim($_POST['batch'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $guardian_contact = trim($_POST['guardian_contact'] ?? '');
    $present_address  = trim($_POST['present_address'] ?? '');
    $permanent_address= trim($_POST['permanent_address'] ?? '');

    if (empty($full_name) || empty($email)) {
        $_SESSION['flash_message'] = "Full Name and Email cannot be empty.";
        $_SESSION['flash_type'] = "error";
        header("Location: student-profile-page.php");
        exit();
    }

    try {
        // Update students table
        $stmt = $conn->prepare("UPDATE `students` SET 
            `full_name` = :full_name,
            `email` = :email,
            `student_id` = :student_code,
            `batch` = :batch,
            `contact_number` = :contact,
            `guardian_contact` = :guardian,
            `present_address` = :present,
            `permanent_address` = :permanent
            WHERE `id` = :id LIMIT 1");

        $stmt->execute([
            'full_name'    => $full_name,
            'email'        => $email,
            'student_code' => $student_code,
            'batch'        => $batch,
            'contact'      => $contact_number,
            'guardian'     => $guardian_contact,
            'present'      => $present_address,
            'permanent'    => $permanent_address,
            'id'           => $student_id
        ]);

        // Update username and name in users-table
        $old_email = $_SESSION['email'] ?? $email;
        $stmt_u = $conn->prepare("UPDATE `users-table` SET `name` = :name, `username` = :username, `email` = :email WHERE `email` = :old_email LIMIT 1");
        $stmt_u->execute([
            'name'      => $full_name,
            'username'  => $username,
            'email'     => $email,
            'old_email' => $old_email
        ]);

        $_SESSION['email'] = $email;
        if (!empty($student_code)) {
            $_SESSION['student_id'] = $student_code;
        }

        $_SESSION['flash_message'] = "Personal Information updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error updating personal info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: student-profile-page.php");
    exit();
}

// ========================================================
// 2. UPDATE EDUCATION DETAILS (student_education table)
// ========================================================
if ($section === 'education') {
    $institution = trim($_POST['institution'] ?? '');
    $program     = trim($_POST['program'] ?? '');

    try {
        // Update main program field in students table
        $stmt_s = $conn->prepare("UPDATE `students` SET `program` = :prog WHERE `id` = :id LIMIT 1");
        $stmt_s->execute(['prog' => $program, 'id' => $student_id]);

        // Upsert student_education table
        $stmt_check = $conn->prepare("SELECT `id` FROM `student_education` WHERE `student_id` = :sid LIMIT 1");
        $stmt_check->execute(['sid' => $student_id]);
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $stmt = $conn->prepare("UPDATE `student_education` SET `institution` = :inst, `program` = :prog WHERE `student_id` = :sid LIMIT 1");
            $stmt->execute(['inst' => $institution, 'prog' => $program, 'sid' => $student_id]);
        } else {
            $stmt = $conn->prepare("INSERT INTO `student_education` (`student_id`, `institution`, `program`) VALUES (:sid, :inst, :prog)");
            $stmt->execute(['sid' => $student_id, 'inst' => $institution, 'prog' => $program]);
        }

        $_SESSION['flash_message'] = "Education information updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error updating education info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: student-profile-page.php");
    exit();
}

// ========================================================
// 3. UPDATE PAYMENT DETAILS (student_payments table)
// ========================================================
if ($section === 'payment') {
    $bank_name      = trim($_POST['bank_name'] ?? '');
    $account_name   = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $mobile_banking = trim($_POST['mobile_banking_number'] ?? '');

    try {
        $stmt_check = $conn->prepare("SELECT `id` FROM `student_payments` WHERE `student_id` = :sid LIMIT 1");
        $stmt_check->execute(['sid' => $student_id]);
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $stmt = $conn->prepare("UPDATE `student_payments` SET 
                `bank_name` = :bname,
                `account_name` = :aname,
                `account_number` = :anum,
                `mobile_banking_number` = :mbanking
                WHERE `student_id` = :sid LIMIT 1");

            $stmt->execute([
                'bname'    => $bank_name,
                'aname'    => $account_name,
                'anum'     => $account_number,
                'mbanking' => $mobile_banking,
                'sid'      => $student_id
            ]);
        } else {
            $stmt = $conn->prepare("INSERT INTO `student_payments` 
                (`student_id`, `bank_name`, `account_name`, `account_number`, `mobile_banking_number`) 
                VALUES (:sid, :bname, :aname, :anum, :mbanking)");

            $stmt->execute([
                'sid'      => $student_id,
                'bname'    => $bank_name,
                'aname'    => $account_name,
                'anum'     => $account_number,
                'mbanking' => $mobile_banking
            ]);
        }

        $_SESSION['flash_message'] = "Payment details updated successfully!";
        $_SESSION['flash_type'] = "success";

    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error updating payment info: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    header("Location: student-profile-page.php");
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
            // Target image folder: assets/images/ relative to root
            $uploadDir = __DIR__ . '/../assets/images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $newFileName = 'avatar_student_' . $student_id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newFileName;
            $dbPath = '../assets/images/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $stmt = $conn->prepare("UPDATE `students` SET `profile_image` = :img WHERE `id` = :id LIMIT 1");
                $stmt->execute(['img' => $dbPath, 'id' => $student_id]);

                $_SESSION['flash_message'] = "Profile picture updated successfully!";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Failed to move uploaded file. Check directory write permissions.";
                $_SESSION['flash_type'] = "error";
            }
        } else {
            $_SESSION['flash_message'] = "Invalid image format. Allowed: JPG, PNG, WEBP, GIF.";
            $_SESSION['flash_type'] = "error";
        }
    } else {
        $error_code = $_FILES['avatar']['error'] ?? 'No file';
        $_SESSION['flash_message'] = "Image upload failed. Code: " . $error_code;
        $_SESSION['flash_type'] = "error";
    }

    header("Location: student-profile-page.php");
    exit();
}