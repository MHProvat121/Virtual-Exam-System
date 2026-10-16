<?php
// student-result-page-logic.php
// Backend API handler for student result data, grade summaries, and submission feedback

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

$dbConn = $conn ?? $pdo ?? null;

// Authorization check: Administrator, Teacher, or Student session
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['student_id']) && !isset($_SESSION['teacher_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Resolve logged-in credentials and role
$is_admin = (isset($_SESSION['user_role']) && in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator'])) || isset($_SESSION['admin_id']);
$session_uid       = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
$logged_student_id = $_SESSION['student_id'] ?? ($session_uid ? strval($session_uid) : '');
$logged_batch      = $_SESSION['student_batch'] ?? $_SESSION['batch'] ?? '';

// If student ID or batch is missing in session and not admin, attempt to resolve from `students` table
if (!$is_admin && $dbConn && (empty($logged_batch) || empty($logged_student_id))) {
    try {
        if ($dbConn instanceof PDO) {
            $s_stmt = $dbConn->prepare("
                SELECT id, student_id, batch 
                FROM students 
                WHERE id = :uid OR student_id = :sid OR email = :email 
                LIMIT 1
            ");
            $s_stmt->execute([
                ':uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
                ':sid'   => strval($logged_student_id),
                ':email' => strval($_SESSION['email'] ?? '')
            ]);
            $stu = $s_stmt->fetch(PDO::FETCH_ASSOC);
            if ($stu) {
                if (!empty($stu['student_id'])) $logged_student_id = $stu['student_id'];
                if (!empty($stu['batch']))      $logged_batch = $stu['batch'];
                $_SESSION['student_id'] = $logged_student_id;
                $_SESSION['batch']      = $logged_batch;
            }
        }
    } catch (Exception $e) {
        error_log("Student result logic profile fetch error: " . $e->getMessage());
    }
}

// Fetch candidate batches
$enrolledBatches = [];
$enrolledCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];

if ($is_admin) {
    if ($dbConn instanceof PDO) {
        try {
            $stmtB = $dbConn->query("SELECT DISTINCT `batch` FROM `batches` WHERE `batch` IS NOT NULL AND TRIM(`batch`) != ''");
            if ($stmtB) {
                while ($bRow = $stmtB->fetch(PDO::FETCH_ASSOC)) {
                    $bName = trim($bRow['batch']);
                    if ($bName !== '') $enrolledBatches[] = $bName;
                }
            }
            $stmtE = $dbConn->query("SELECT DISTINCT `batch` FROM `exam_header` WHERE `batch` IS NOT NULL AND TRIM(`batch`) != ''");
            if ($stmtE) {
                while ($eRow = $stmtE->fetch(PDO::FETCH_ASSOC)) {
                    $bName = trim($eRow['batch']);
                    if ($bName !== '') $enrolledBatches[] = $bName;
                }
            }
        } catch (Exception $e) {}
    }
    $enrolledBatches = array_values(array_unique(array_filter(array_map('trim', $enrolledBatches))));
} elseif ($dbConn && ($session_uid > 0 || !empty($logged_student_id))) {
    try {
        if ($dbConn instanceof PDO) {
            $stmtEnr = $dbConn->prepare("
                SELECT se.* 
                FROM `student_enrollments` se 
                JOIN `students` st ON (se.student_id = st.id OR CAST(se.student_id AS CHAR) = CAST(st.id AS CHAR) OR CAST(se.student_id AS CHAR) = st.student_id)
                WHERE (st.id = :uid OR st.student_id = :sid OR st.email = :email) 
                ORDER BY se.id DESC
            ");
            $stmtEnr->execute([
                ':uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
                ':sid'   => strval($logged_student_id),
                ':email' => strval($_SESSION['email'] ?? '')
            ]);
            $enrIds = [];
            $enrollmentMapById = [];
            while ($row = $stmtEnr->fetch(PDO::FETCH_ASSOC)) {
                $b = $row['batch'];
                $enrollmentMapById[$row['id']] = $row;
                if ($row['status'] === 'Enrolled' && ($row['withdraw_status'] ?? '') !== 'Withdrawn') {
                    $enrolledBatches[] = $b;
                }
                if (!empty($row['id'])) {
                    $enrIds[$row['id']] = $b;
                }
            }
            $enrolledBatches = array_values(array_unique(array_filter(array_map('trim', $enrolledBatches))));

            if (!empty($enrIds)) {
                $idList = implode(',', array_map('intval', array_keys($enrIds)));
                try {
                    $stmtEC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_enrollment_courses` WHERE `enrollment_id` IN ($idList)");
                    while ($eRow = $stmtEC->fetch(PDO::FETCH_ASSOC)) {
                        $eid = (int)$eRow['enrollment_id'];
                        $bName = $enrIds[$eid] ?? '';
                        $rec = $enrollmentMapById[$eid] ?? [];
                        if ($bName && ($rec['status'] ?? '') === 'Enrolled') {
                            $enrolledCourseCodesByBatch[$bName][] = $eRow['course_code'];
                        }
                    }
                } catch (PDOException $e) {}

                try {
                    $stmtWC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_withdrawal_courses` WHERE `enrollment_id` IN ($idList)");
                    while ($wRow = $stmtWC->fetch(PDO::FETCH_ASSOC)) {
                        $eid = (int)$wRow['enrollment_id'];
                        $bName = $enrIds[$eid] ?? '';
                        $rec = $enrollmentMapById[$eid] ?? [];
                        if ($bName && ($rec['withdraw_status'] ?? '') === 'Withdrawn') {
                            $withdrawnCourseCodesByBatch[$bName][] = $wRow['course_code'];
                        }
                    }
                } catch (PDOException $e) {}

                foreach ($withdrawnCourseCodesByBatch as $bKey => $wCodes) {
                    if (!empty($enrolledCourseCodesByBatch[$bKey])) {
                        $enrolledCourseCodesByBatch[$bKey] = array_values(array_diff($enrolledCourseCodesByBatch[$bKey], $wCodes));
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Enrolled batches fetch error in result logic: " . $e->getMessage());
    }
}

// AJAX action handler
$action = $_REQUEST['action'] ?? '';

if ($action === 'get_student_results') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$dbConn || empty($enrolledBatches)) {
        echo json_encode([
            'success'          => true,
            'server_time'      => gmdate('Y-m-d H:i:s'),
            'student_id'       => $is_admin ? ($session_uid ?: 'admin') : $logged_student_id,
            'enrolled_batches' => $enrolledBatches,
            'results'          => []
        ]);
        exit();
    }

    try {
        $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
        if ($is_admin) {
            $sql = "
                SELECT e.id AS exam_id,
                       e.teacher_id,
                       e.teacher_name,
                       e.batch,
                       e.course_code,
                       e.subject,
                       e.exam_title,
                       e.exam_type,
                       e.exam_date,
                       e.duration,
                       COALESCE(e.marks, 0) AS total_marks,
                       e.status AS exam_status,
                       s.id AS submission_id,
                       s.student_id,
                       COALESCE(s.student_name, st.full_name) AS student_name,
                       s.status AS attempt_status,
                       s.submitted_at,
                       CASE
                           WHEN s.id IS NULL THEN NULL
                           WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                           ELSE ev.status
                       END AS submission_status,
                       COALESCE(ev.total_marks_obtained, 0) AS marks_obtained,
                       ev.teacher_feedback,
                       ev.evaluated_by,
                       ev.evaluated_at
                FROM `exam_header` e
                LEFT JOIN `exam_submissions` s 
                       ON e.id = s.exam_id AND s.status = 'Submitted'
                LEFT JOIN `students` st ON (s.student_id = st.student_id OR CAST(s.student_id AS CHAR) = CAST(st.id AS CHAR))
                LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
                WHERE e.batch IN ($inClause)
                ORDER BY e.exam_date DESC, e.id DESC, s.id DESC
            ";
            $params = $enrolledBatches;
        } else {
            $sql = "
                SELECT e.id AS exam_id,
                       e.teacher_id,
                       e.teacher_name,
                       e.batch,
                       e.course_code,
                       e.subject,
                       e.exam_title,
                       e.exam_type,
                       e.exam_date,
                       e.duration,
                       COALESCE(e.marks, 0) AS total_marks,
                       e.status AS exam_status,
                       s.id AS submission_id,
                       s.student_id,
                       s.student_name,
                       s.status AS attempt_status,
                       s.submitted_at,
                       CASE
                           WHEN s.id IS NULL THEN NULL
                           WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                           ELSE ev.status
                       END AS submission_status,
                       COALESCE(ev.total_marks_obtained, 0) AS marks_obtained,
                       ev.teacher_feedback,
                       ev.evaluated_by,
                       ev.evaluated_at
                FROM `exam_header` e
                LEFT JOIN `exam_submissions` s 
                       ON e.id = s.exam_id AND (s.student_id = ? OR s.student_id = ?) AND s.status = 'Submitted'
                LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
                WHERE e.batch IN ($inClause)
                ORDER BY e.exam_date DESC, e.id DESC
            ";
            $params = array_merge([strval($logged_student_id), strval($session_uid)], $enrolledBatches);
        }

        $stmt = $dbConn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'          => true,
            'server_time'      => gmdate('Y-m-d H:i:s'),
            'student_id'       => $is_admin ? ($session_uid ?: 'admin') : $logged_student_id,
            'enrolled_batches' => $enrolledBatches,
            'results'          => $results
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}
