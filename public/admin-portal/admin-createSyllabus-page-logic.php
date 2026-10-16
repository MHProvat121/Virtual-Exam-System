<?php
// =========================================================================
// ADMIN CREATE / EDIT SYLLABUS - BACKEND HANDLER
// Handles syllabus updates, file uploads, and status redirects for Admins
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// RBAC Check - Administrator permissions required
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// Search through common relative configuration locations
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
        // Check for standard PDO connection variable
        $dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null) ?? $conn ?? null;
        break;
    }
}

if (!$dbConn) {
    die("Database connection error: Unable to load database configuration file. Please verify that config.php exists in src/config/ or the project root.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_id         = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : 0;
    $syllabus_content = $_POST['syllabus_content'] ?? '';
    $course_code      = trim($_POST['course_code'] ?? '');
    $credit           = trim($_POST['credit'] ?? '');

    if ($batch_id <= 0) {
        header("Location: admin-courseSyllabus-page.php?error=invalid_batch");
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
        // Find or create uploads directory
        $uploadDirs = [
            __DIR__ . '/uploads/syllabus/',
            __DIR__ . '/../../uploads/syllabus/',
            __DIR__ . '/../uploads/syllabus/'
        ];

        $targetDir = $uploadDirs[0];
        foreach ($uploadDirs as $dir) {
            if (is_dir(dirname($dir))) {
                $targetDir = $dir;
                break;
            }
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = time() . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "", basename($_FILES['syllabus_file']['name']));
        $targetFile = rtrim($targetDir, '/') . '/' . $safeName;

        if (move_uploaded_file($_FILES['syllabus_file']['tmp_name'], $targetFile)) {
            $filePath = 'uploads/syllabus/' . $safeName;
        }
    }

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

        $_SESSION['success_message'] = "Syllabus updated successfully!";
        header("Location: admin-viewSyllabus-page.php?id=" . $batch_id . "&status=saved");
        exit();

    } catch (PDOException $e) {
        error_log("Error saving syllabus: " . $e->getMessage());
        header("Location: admin-createSyllabus-page.php?id=" . $batch_id . "&error=db_error");
        exit();
    }
} else {
    header("Location: admin-courseSyllabus-page.php");
    exit();
}
