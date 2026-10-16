<?php
// create-syllabus-page-logic.php
session_start();

$configPaths = [
    __DIR__ . '/../../src/config/config.php',
    __DIR__ . '/../src/config/config.php',
    __DIR__ . '/src/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../config.php'
];
$dbConn = null;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null) ?? $conn ?? null;
        break;
    }
}

if (!$dbConn) {
    die("Database connection failed. Unable to locate or establish database configuration. Checked paths: " . implode(', ', $configPaths));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_id         = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
    $syllabus_content = $_POST['syllabus_content'] ?? '';
    $course_code      = trim($_POST['course_code'] ?? '');
    $credit           = trim($_POST['credit'] ?? '');

    if ($batch_id <= 0) {
        $errorRedirect = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')
            ? "admin-courseSyllabus-page.php?error=invalid_batch"
            : "teacher-courseSyllabus-page.php?error=invalid_batch";
        header("Location: " . $errorRedirect);
        exit();
    }

    // Ensure credit column exists in batches
    if ($dbConn) {
        try {
            $chkCol = $dbConn->query("SHOW COLUMNS FROM `batches` LIKE 'credit'");
            if ($chkCol && $chkCol->rowCount() === 0) {
                $dbConn->exec("ALTER TABLE `batches` ADD COLUMN `credit` VARCHAR(50) NULL DEFAULT NULL AFTER `course_code`");
            }
        } catch (Exception $e) {
            // Continue safely
        }
    }

    // Handle File Upload if provided
    $filePath = null;
    if (isset($_FILES['syllabus_file']) && $_FILES['syllabus_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/syllabus/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "", basename($_FILES['syllabus_file']['name']));
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['syllabus_file']['tmp_name'], $targetFile)) {
            $filePath = 'uploads/syllabus/' . $fileName;
        }
    }

    // Prepare Update query
    try {
        if ($filePath) {
            $stmt = $dbConn->prepare("
                UPDATE `batches` 
                SET `course_code` = :course_code, `credit` = :credit, `syllabus_details` = :content, `syllabus_file` = :file, `updated_at` = NOW() 
                WHERE `id` = :id
            ");
            $stmt->execute([
                ':course_code' => $course_code,
                ':credit'      => $credit,
                ':content'     => $syllabus_content,
                ':file'        => $filePath,
                ':id'          => $batch_id
            ]);
        } else {
            $stmt = $dbConn->prepare("
                UPDATE `batches` 
                SET `course_code` = :course_code, `credit` = :credit, `syllabus_details` = :content, `updated_at` = NOW() 
                WHERE `id` = :id
            ");
            $stmt->execute([
                ':course_code' => $course_code,
                ':credit'      => $credit,
                ':content'     => $syllabus_content,
                ':id'          => $batch_id
            ]);
        }
    } catch (PDOException $e) {
        // Fallback if credit column somehow fails
        if ($filePath) {
            $stmt = $dbConn->prepare("
                UPDATE `batches` 
                SET `course_code` = :course_code, `syllabus_details` = :content, `syllabus_file` = :file, `updated_at` = NOW() 
                WHERE `id` = :id
            ");
            $stmt->execute([
                ':course_code' => $course_code,
                ':content'     => $syllabus_content,
                ':file'        => $filePath,
                ':id'          => $batch_id
            ]);
        } else {
            $stmt = $dbConn->prepare("
                UPDATE `batches` 
                SET `course_code` = :course_code, `syllabus_details` = :content, `updated_at` = NOW() 
                WHERE `id` = :id
            ");
            $stmt->execute([
                ':course_code' => $course_code,
                ':content'     => $syllabus_content,
                ':id'          => $batch_id
            ]);
        }
    }

    $redirectTarget = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')
        ? "admin-viewSyllabus-page.php?id=" . $batch_id . "&status=saved"
        : "teacher-viewSyllabus-page.php?id=" . $batch_id . "&status=saved";
    header("Location: " . $redirectTarget);
    exit();
} else {
    $fallbackRedirect = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')
        ? "admin-courseSyllabus-page.php"
        : "teacher-courseSyllabus-page.php";
    header("Location: " . $fallbackRedirect);
    exit();
}
