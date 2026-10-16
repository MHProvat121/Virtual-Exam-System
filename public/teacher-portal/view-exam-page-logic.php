<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../src/config/config.php';

$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// RBAC Gatekeeper
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_exam') {
    $exam_id            = isset($_POST['exam_id']) ? intval($_POST['exam_id']) : 0;
    $current_teacher_id = $_SESSION['teacher_id'] ?? '';
    $session_uid        = $_SESSION['user_id'] ?? 0;

    if ($exam_id > 0 && $dbConn) {
        try {
            // Foreign keys with ON DELETE CASCADE automatically remove questions & question_options
            // STEP 3: Delete exam from `exam_header` table
            $stmt = $dbConn->prepare("
                DELETE FROM exam_header 
                WHERE id = :id AND (teacher_id = :teacher_id OR teacher_id = :uid)
            ");
            $stmt->execute([
                ':id'         => $exam_id,
                ':teacher_id' => strval($current_teacher_id),
                ':uid'        => strval($session_uid)
            ]);

            $_SESSION['success'] = "Exam paper cancelled and deleted successfully from exam_header.";
        } catch (PDOException $e) {
            error_log("Delete Exam Error: " . $e->getMessage());
            $_SESSION['error'] = "Failed to cancel exam paper.";
        }
    }
    
    header("Location: teacher-quesTable-page.php");
    exit();
} else {
    header("Location: teacher-quesTable-page.php");
    exit();
}
