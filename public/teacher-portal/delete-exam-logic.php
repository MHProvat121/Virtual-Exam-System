<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../src/config/config.php';

// RBAC: Session gatekeeper for teacher
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

$dbConn = $pdo ?? $conn ?? null;

$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_POST['exam_id']) ? intval($_POST['exam_id']) : 0);
$current_teacher_id = $_SESSION['teacher_id'] ?? '';
$session_uid        = $_SESSION['user_id'] ?? 0;

if ($exam_id > 0 && $dbConn) {
    try {
        if ($dbConn instanceof PDO) {
            $dbConn->beginTransaction();

            // 0. Delete student answers and submissions if any
            try {
                $delAns = $dbConn->prepare("DELETE FROM student_answers WHERE exam_id = :exam_id");
                $delAns->execute([':exam_id' => $exam_id]);

                $delSub = $dbConn->prepare("DELETE FROM exam_submissions WHERE exam_id = :exam_id");
                $delSub->execute([':exam_id' => $exam_id]);
            } catch (Exception $e) {
                // Table might not exist yet if not used
            }

            // 1. Fetch all question IDs belonging to this exam
            $qStmt = $dbConn->prepare("SELECT id FROM questions WHERE exam_id = :exam_id");
            $qStmt->execute([':exam_id' => $exam_id]);
            $questionIds = $qStmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Delete question options for these questions
            if (!empty($questionIds)) {
                $inClause = implode(',', array_fill(0, count($questionIds), '?'));
                $delOpt = $dbConn->prepare("DELETE FROM question_options WHERE question_id IN ($inClause)");
                $delOpt->execute($questionIds);
            }

            // 3. Delete questions belonging to this exam
            $delQ = $dbConn->prepare("DELETE FROM questions WHERE exam_id = :exam_id");
            $delQ->execute([':exam_id' => $exam_id]);

            // 4. Delete the exam_details record regardless of status (Published, Running, Completed, etc.)
            $delExam = $dbConn->prepare("
                DELETE FROM exam_details 
                WHERE id = :exam_id AND (teacher_id = :teacher_id OR teacher_id = :uid)
            ");
            $delExam->execute([
                ':exam_id'    => $exam_id,
                ':teacher_id' => strval($current_teacher_id),
                ':uid'        => strval($session_uid)
            ]);

            $dbConn->commit();
            $_SESSION['success'] = "Exam #{$exam_id} deleted successfully.";

        } elseif ($dbConn instanceof mysqli) {
            // MySQLi implementation
            try {
                $dbConn->query("DELETE FROM student_answers WHERE exam_id = " . intval($exam_id));
                $dbConn->query("DELETE FROM exam_submissions WHERE exam_id = " . intval($exam_id));
            } catch (Exception $e) {}

            $stmt = $dbConn->prepare("SELECT id FROM questions WHERE exam_id = ?");
            $stmt->bind_param("i", $exam_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $qIds = [];
            while ($row = $res->fetch_assoc()) {
                $qIds[] = $row['id'];
            }

            if (!empty($qIds)) {
                $inList = implode(',', array_map('intval', $qIds));
                $dbConn->query("DELETE FROM question_options WHERE question_id IN ($inList)");
            }

            $stmtQ = $dbConn->prepare("DELETE FROM questions WHERE exam_id = ?");
            $stmtQ->bind_param("i", $exam_id);
            $stmtQ->execute();

            $stmtE = $dbConn->prepare("DELETE FROM exam_details WHERE id = ? AND (teacher_id = ? OR teacher_id = ?)");
            $tIdStr = strval($current_teacher_id);
            $uIdStr = strval($session_uid);
            $stmtE->bind_param("iss", $exam_id, $tIdStr, $uIdStr);
            $stmtE->execute();

            $_SESSION['success'] = "Exam #{$exam_id} deleted successfully.";
        }
    } catch (Exception $e) {
        if ($dbConn instanceof PDO && $dbConn->inTransaction()) {
            $dbConn->rollBack();
        }
        error_log("Delete Exam Error in delete-exam-logic: " . $e->getMessage());
        $_SESSION['error'] = "Failed to delete exam: " . $e->getMessage();
    }
}

// Return JSON if requested by AJAX
if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_GET['format']) && $_GET['format'] === 'json')) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => !empty($_SESSION['success']),
        'message' => $_SESSION['success'] ?? $_SESSION['error'] ?? 'Action completed'
    ]);
    exit();
}

header("Location: teacher-quesTable-page.php");
exit();
