<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../src/config/config.php';

// Authorization check: Teacher or Admin session
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$dbConn = $pdo ?? $conn ?? null;

if (!$dbConn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No database connection available.']);
    exit();
}

// AJAX POST Request Handler: Status updates & Delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $exam_id = isset($_POST['exam_id']) ? intval($_POST['exam_id']) : 0;
    $action  = trim($_POST['action'] ?? '');
    $status  = trim($_POST['status'] ?? '');

    $teacher_id = $_SESSION['teacher_id'] ?? $_SESSION['user_id'] ?? '';
    $uid        = $_SESSION['user_id'] ?? '';

    if ($exam_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid exam ID.']);
        exit();
    }

    /* ---------------------------------------------------------
       ACTION: DELETE EXAM FROM exam_header
       --------------------------------------------------------- */
    if ($action === 'delete' || $action === 'delete_exam') {
        try {
            if ($dbConn instanceof PDO) {
                $dbConn->beginTransaction();

                $qStmt = $dbConn->prepare("SELECT id FROM questions WHERE exam_id = :exam_id");
                $qStmt->execute([':exam_id' => $exam_id]);
                $qIds = $qStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($qIds)) {
                    $inClause = implode(',', array_fill(0, count($qIds), '?'));
                    $delOpt = $dbConn->prepare("DELETE FROM question_options WHERE question_id IN ($inClause)");
                    $delOpt->execute($qIds);
                }

                $delQ = $dbConn->prepare("DELETE FROM questions WHERE exam_id = :exam_id");
                $delQ->execute([':exam_id' => $exam_id]);

                // Delete from exam_header
                $delE = $dbConn->prepare("DELETE FROM exam_header WHERE id = :exam_id AND (teacher_id = :tid OR teacher_id = :uid)");
                $delE->execute([
                    ':exam_id' => $exam_id,
                    ':tid'     => strval($teacher_id),
                    ':uid'     => strval($uid)
                ]);

                $dbConn->commit();
            } elseif ($dbConn instanceof mysqli) {
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

                $stmtE = $dbConn->prepare("DELETE FROM exam_header WHERE id = ? AND (teacher_id = ? OR teacher_id = ?)");
                $tStr = strval($teacher_id);
                $uStr = strval($uid);
                $stmtE->bind_param("iss", $exam_id, $tStr, $uStr);
                $stmtE->execute();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Exam deleted successfully from exam_header.',
                'exam_id' => $exam_id
            ]);
            exit();

        } catch (Exception $e) {
            if ($dbConn instanceof PDO && $dbConn->inTransaction()) {
                $dbConn->rollBack();
            }
            error_log("Delete Exam Error in logic: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error deleting exam: ' . $e->getMessage()]);
            exit();
        }
    }

    /* ---------------------------------------------------------
       ACTION: UPDATE EXAM STATUS IN exam_header
       --------------------------------------------------------- */
    $allowedStatuses = ['Published', 'Running', 'Completed', 'Cancelled/On Hold', 'Edited/Rescheduled'];

    if (!in_array($status, $allowedStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status parameter.']);
        exit();
    }

    try {
        if ($status === 'Cancelled/On Hold') {
            $remainingUpdate = "CASE WHEN status = 'Running' THEN GREATEST(0, COALESCE(remaining_seconds, duration * 60) - GREATEST(0, TIMESTAMPDIFF(SECOND, timer_updated_at, CURRENT_TIMESTAMP()))) ELSE COALESCE(remaining_seconds, duration * 60) END";
        } elseif ($status === 'Running') {
            $remainingUpdate = "CASE WHEN status = 'Running' THEN GREATEST(0, COALESCE(remaining_seconds, duration * 60) - GREATEST(0, TIMESTAMPDIFF(SECOND, timer_updated_at, CURRENT_TIMESTAMP()))) WHEN status = 'Cancelled/On Hold' THEN COALESCE(remaining_seconds, duration * 60) ELSE duration * 60 END";
        } elseif ($status === 'Completed') {
            $remainingUpdate = '0';
        } else {
            $remainingUpdate = 'duration * 60';
        }
        $timerAnchorUpdate = ($status === 'Running' || $status === 'Cancelled/On Hold') ? 'CURRENT_TIMESTAMP()' : 'NULL';

        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = $remainingUpdate, status = :status, timer_updated_at = $timerAnchorUpdate, updated_at = CURRENT_TIMESTAMP() WHERE id = :exam_id");
            $stmt->execute([':status' => $status, ':exam_id' => $exam_id]);
            $read = $dbConn->prepare("SELECT remaining_seconds, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = :exam_id LIMIT 1");
            $read->execute([':exam_id' => $exam_id]);
            $timerState = $read->fetch(PDO::FETCH_ASSOC) ?: [];
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = $remainingUpdate, status = ?, timer_updated_at = $timerAnchorUpdate, updated_at = CURRENT_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param("si", $status, $exam_id);
            $stmt->execute();
            $read = $dbConn->prepare("SELECT remaining_seconds, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = ? LIMIT 1");
            $read->bind_param("i", $exam_id);
            $read->execute();
            $timerState = $read->get_result()->fetch_assoc() ?: [];
        } else {
            $timerState = [];
        }

        echo json_encode([
            'success'            => true,
            'message'            => 'Status updated successfully.',
            'status'             => $status,
            'exam_id'            => $exam_id,
            'remaining_seconds'  => intval($timerState['remaining_seconds'] ?? 0),
            'timer_updated_at_epoch' => intval($timerState['timer_updated_at_epoch'] ?? 0),
            'server_time_ms'     => (int) round(microtime(true) * 1000)
        ]);
        exit();

    } catch (Exception $e) {
        error_log("Database Update Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()]);
        exit();
    }
}

// Fallback for direct GET
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
exit();
