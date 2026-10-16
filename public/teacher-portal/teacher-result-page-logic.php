<?php
// =========================================================================
// teacher-result-page-logic.php
// Backend API handler for Teacher Result Management, Evaluation & Remarks
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$dbConn = $pdo ?? $conn ?? null;

// Authorization check: Teacher or Admin session
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if (!$dbConn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No database connection available.']);
    exit();
}

$logged_teacher_id   = $_SESSION['teacher_id'] ?? $_SESSION['user_id'] ?? '';
$logged_teacher_name = $_SESSION['teacher_name'] ?? $_SESSION['user_name'] ?? 'Instructor';

// Handle AJAX POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action         = trim($_POST['action'] ?? '');
    $submission_id  = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
    $feedback       = trim($_POST['teacher_feedback'] ?? '');
    $status         = trim($_POST['status'] ?? 'Graded');

    if ($submission_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid submission ID.']);
        exit();
    }

    if ($action === 'update_evaluation' || $action === 'save_feedback') {
        try {
            $status = ($status === 'Submitted') ? 'Pending' : $status;
            if (!in_array($status, ['Pending', 'Under Review', 'Graded'], true)) $status = 'Graded';

            if ($dbConn instanceof PDO) {
                $checkStmt = $dbConn->prepare("
                    SELECT s.id, s.exam_id, e.marks AS total_marks,
                           COALESCE((SELECT SUM(sa.marks_obtained)
                                     FROM student_answers sa
                                     WHERE sa.submission_id = s.id AND sa.exam_id = s.exam_id), 0) AS answer_marks
                    FROM exam_submissions s
                    JOIN exam_header e ON s.exam_id = e.id
                    WHERE s.id = :sid AND s.status = 'Submitted'
                      AND (e.teacher_id = :tid OR e.teacher_id = :uid)
                    LIMIT 1
                ");
                $checkStmt->execute([
                    ':sid' => $submission_id,
                    ':tid' => strval($logged_teacher_id),
                    ':uid' => strval($_SESSION['user_id'] ?? '')
                ]);
                $sub = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$sub) {
                    echo json_encode(['success' => false, 'message' => 'Submission not found or unauthorized to evaluate this exam.']);
                    exit();
                }

                // The answer sheet is authoritative for marks. This quick action can
                // publish status/feedback, but cannot create a conflicting total.
                $marks_obtained = min(
                    max(0.0, floatval($sub['total_marks'])),
                    max(0.0, floatval($sub['answer_marks']))
                );
                $evalBy = $logged_teacher_name . ' (' . $logged_teacher_id . ')';
                $nowUtc = gmdate('Y-m-d H:i:s');
                $evaluatedAt = $status === 'Pending' ? null : $nowUtc;
                $stmt = $dbConn->prepare("
                    INSERT INTO exam_evaluations
                        (submission_id, status, total_marks_obtained, teacher_feedback, evaluated_by, evaluated_at, created_at, updated_at)
                    VALUES
                        (:sid, :status, :marks, :feedback, :eval_by, :evaluated_at, :created_at, :updated_at)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        total_marks_obtained = VALUES(total_marks_obtained),
                        teacher_feedback = VALUES(teacher_feedback),
                        evaluated_by = VALUES(evaluated_by),
                        evaluated_at = VALUES(evaluated_at),
                        updated_at = VALUES(updated_at)
                ");
                $stmt->execute([
                    ':sid'      => $submission_id,
                    ':status'   => $status,
                    ':marks'    => $marks_obtained,
                    ':feedback' => $feedback,
                    ':eval_by'  => $evalBy,
                    ':evaluated_at' => $evaluatedAt,
                    ':created_at' => $nowUtc,
                    ':updated_at' => $nowUtc
                ]);

                echo json_encode([
                    'success'        => true,
                    'message'        => 'Student evaluation and feedback saved successfully.',
                    'submission_id'  => $submission_id,
                    'marks_obtained' => $marks_obtained,
                    'status'         => $status === 'Pending' ? 'Submitted' : $status,
                    'feedback'       => $feedback
                ]);
                exit();
            } elseif ($dbConn instanceof mysqli) {
                $check = $dbConn->prepare("
                    SELECT s.exam_id, e.marks AS total_marks,
                           COALESCE((SELECT SUM(sa.marks_obtained)
                                     FROM student_answers sa
                                     WHERE sa.submission_id = s.id AND sa.exam_id = s.exam_id), 0) AS answer_marks
                    FROM exam_submissions s
                    JOIN exam_header e ON s.exam_id = e.id
                    WHERE s.id = ? AND s.status = 'Submitted' AND (e.teacher_id = ? OR e.teacher_id = ?)
                    LIMIT 1
                ");
                $tIdStr = strval($logged_teacher_id);
                $uIdStr = strval($_SESSION['user_id'] ?? '');
                $check->bind_param("iss", $submission_id, $tIdStr, $uIdStr);
                $check->execute();
                $sub = $check->get_result()->fetch_assoc();
                if (!$sub) {
                    echo json_encode(['success' => false, 'message' => 'Submission not found or unauthorized to evaluate this exam.']);
                    exit();
                }

                $marks_obtained = min(max(0.0, floatval($sub['total_marks'])), max(0.0, floatval($sub['answer_marks'])));
                $evalByStr = $logged_teacher_name . ' (' . $logged_teacher_id . ')';
                $nowUtc = gmdate('Y-m-d H:i:s');
                $evaluatedAt = $status === 'Pending' ? null : $nowUtc;
                $stmt = $dbConn->prepare("
                    INSERT INTO exam_evaluations
                        (submission_id, status, total_marks_obtained, teacher_feedback, evaluated_by, evaluated_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        total_marks_obtained = VALUES(total_marks_obtained),
                        teacher_feedback = VALUES(teacher_feedback),
                        evaluated_by = VALUES(evaluated_by),
                        evaluated_at = VALUES(evaluated_at),
                        updated_at = VALUES(updated_at)
                ");
                $stmt->bind_param("isdsssss", $submission_id, $status, $marks_obtained, $feedback, $evalByStr, $evaluatedAt, $nowUtc, $nowUtc);
                $stmt->execute();

                echo json_encode([
                    'success'        => true,
                    'message'        => 'Student evaluation and feedback saved successfully.',
                    'submission_id'  => $submission_id,
                    'marks_obtained' => $marks_obtained,
                    'status'         => $status === 'Pending' ? 'Submitted' : $status,
                    'feedback'       => $feedback
                ]);
                exit();
            }
        } catch (Exception $e) {
            error_log("Error saving evaluation: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit();
}

// Fallback response for GET
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
exit();
