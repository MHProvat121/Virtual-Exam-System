<?php
// teacher-inspectExam-page-logic.php
// Real-time backend handler for live exam inspection

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header('Content-Type: application/json; charset=utf-8');

// RBAC: Administrator and Teacher session verification
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit();
}
if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator', 'teacher'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$dbConn = $conn ?? $pdo ?? null;

if (!$dbConn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

$action = $_REQUEST['action'] ?? '';
$exam_id = isset($_REQUEST['exam_id']) ? intval($_REQUEST['exam_id']) : (isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0);

if ($exam_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid exam identifier.']);
    exit();
}

try {
    // -------------------------------------------------------------
    // ACTION: poll_live_data
    // Returns live exam status, remaining duration, and all students progress from exam_header
    // -------------------------------------------------------------
    if ($action === 'poll_live_data') {
        // Fetch current exam info from `exam_header`
        $exam = null;
        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("
                SELECT id, exam_title, status, duration, duration AS exam_duration, 
                       marks, marks AS total_marks, exam_date, exam_time, updated_at,
                       COALESCE(remaining_seconds, duration * 60) AS remaining_seconds,
                       UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch,
                       UNIX_TIMESTAMP(updated_at) AS updated_at_epoch
                FROM exam_header 
                WHERE id = :id 
                LIMIT 1
            ");
            $stmt->execute([':id' => $exam_id]);
            $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("
                SELECT id, exam_title, status, duration, duration AS exam_duration, 
                       marks, marks AS total_marks, exam_date, exam_time, updated_at,
                       COALESCE(remaining_seconds, duration * 60) AS remaining_seconds,
                       UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch,
                       UNIX_TIMESTAMP(updated_at) AS updated_at_epoch
                FROM exam_header 
                WHERE id = ? 
                LIMIT 1
            ");
            $stmt->bind_param("i", $exam_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();
        }

        if (!$exam) {
            echo json_encode(['success' => false, 'message' => 'Exam not found in exam_header.']);
            exit();
        }

        // Fetch submissions from `exam_submissions` using `status` column
        $submissions = [];
        if ($dbConn instanceof PDO) {
            $subStmt = $dbConn->prepare("
                SELECT student_id, student_name, status, status AS submission_status, 
                       total_marks_obtained, submitted_at 
                FROM exam_submissions 
                WHERE exam_id = :exam_id
            ");
            $subStmt->execute([':exam_id' => $exam_id]);
            $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($dbConn instanceof mysqli) {
            $subStmt = $dbConn->prepare("
                SELECT student_id, student_name, status, status AS submission_status, 
                       total_marks_obtained, submitted_at 
                FROM exam_submissions 
                WHERE exam_id = ?
            ");
            $subStmt->bind_param("i", $exam_id);
            $subStmt->execute();
            $submissions = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $subMap = [];
        foreach ($submissions as $sub) {
            $subMap[strval($sub['student_id'])] = $sub;
        }

        echo json_encode([
            'success'       => true,
            'exam'          => $exam,
            'submissions'   => $subMap,
            'server_time'    => date('Y-m-d H:i:s'),
            'server_time_ms' => (int) round(microtime(true) * 1000)
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: force_submit
    // Submits the student's paper immediately into exam_submissions
    // -------------------------------------------------------------
    if ($action === 'force_submit') {
        $student_id = trim($_REQUEST['student_id'] ?? '');
        if (empty($student_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing student ID.']);
            exit();
        }

        $student_name = 'Student';
        if ($dbConn instanceof PDO) {
            $stnStmt = $dbConn->prepare("SELECT full_name FROM students WHERE student_id = :sid LIMIT 1");
            $stnStmt->execute([':sid' => $student_id]);
            $stnRow = $stnStmt->fetch(PDO::FETCH_ASSOC);
            if ($stnRow && !empty($stnRow['full_name'])) {
                $student_name = $stnRow['full_name'];
            }
        } elseif ($dbConn instanceof mysqli) {
            $stnStmt = $dbConn->prepare("SELECT full_name FROM students WHERE student_id = ? LIMIT 1");
            $stnStmt->bind_param("s", $student_id);
            $stnStmt->execute();
            $stnRow = $stnStmt->get_result()->fetch_assoc();
            if ($stnRow && !empty($stnRow['full_name'])) {
                $student_name = $stnRow['full_name'];
            }
        }

        $now = date('Y-m-d H:i:s');
        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("
                INSERT INTO exam_submissions (exam_id, student_id, student_name, status, submitted_at, updated_at)
                VALUES (:exam_id, :student_id, :student_name, 'Submitted', :submitted_at, :updated_at)
                ON DUPLICATE KEY UPDATE status = 'Submitted', submitted_at = :submitted_at2, updated_at = :updated_at2
            ");
            $stmt->execute([
                ':exam_id'       => $exam_id,
                ':student_id'    => $student_id,
                ':student_name'  => $student_name,
                ':submitted_at'  => $now,
                ':updated_at'    => $now,
                ':submitted_at2' => $now,
                ':updated_at2'   => $now,
            ]);
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("
                INSERT INTO exam_submissions (exam_id, student_id, student_name, status, submitted_at, updated_at)
                VALUES (?, ?, ?, 'Submitted', ?, ?)
                ON DUPLICATE KEY UPDATE status = 'Submitted', submitted_at = VALUES(submitted_at), updated_at = VALUES(updated_at)
            ");
            $stmt->bind_param("issss", $exam_id, $student_id, $student_name, $now, $now);
            $stmt->execute();
        }

        echo json_encode([
            'success'    => true,
            'message'    => "Exam paper for student #{$student_id} successfully force-submitted.",
            'student_id' => $student_id
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: hold_exam
    // Sets exam status to 'Cancelled/On Hold' in exam_header
    // -------------------------------------------------------------
    if ($action === 'hold_exam') {
        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = CASE WHEN status = 'Running' THEN GREATEST(0, COALESCE(remaining_seconds, duration * 60) - GREATEST(0, TIMESTAMPDIFF(SECOND, timer_updated_at, CURRENT_TIMESTAMP()))) ELSE COALESCE(remaining_seconds, duration * 60) END, status = 'Cancelled/On Hold', timer_updated_at = CURRENT_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP() WHERE id = :id");
            $stmt->execute([':id' => $exam_id]);
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = CASE WHEN status = 'Running' THEN GREATEST(0, COALESCE(remaining_seconds, duration * 60) - GREATEST(0, TIMESTAMPDIFF(SECOND, timer_updated_at, CURRENT_TIMESTAMP()))) ELSE COALESCE(remaining_seconds, duration * 60) END, status = 'Cancelled/On Hold', timer_updated_at = CURRENT_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param("i", $exam_id);
            $stmt->execute();
        }

        echo json_encode(['success' => true, 'status' => 'Cancelled/On Hold', 'message' => 'Exam placed on hold.']);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: resume_exam
    // Resumes exam to 'Running' in exam_header
    // -------------------------------------------------------------
    if ($action === 'resume_exam') {
        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = COALESCE(remaining_seconds, duration * 60), status = 'Running', timer_updated_at = CURRENT_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP() WHERE id = :id");
            $stmt->execute([':id' => $exam_id]);
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("UPDATE exam_header SET remaining_seconds = COALESCE(remaining_seconds, duration * 60), status = 'Running', timer_updated_at = CURRENT_TIMESTAMP(), updated_at = CURRENT_TIMESTAMP() WHERE id = ?");
            $stmt->bind_param("i", $exam_id);
            $stmt->execute();
        }

        echo json_encode(['success' => true, 'status' => 'Running', 'message' => 'Exam resumed successfully.']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
    exit();

} catch (Exception $e) {
    error_log("Inspection backend error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}
