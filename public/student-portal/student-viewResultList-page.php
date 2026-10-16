<?php
// =========================================================================
// student-view-resultList-page.php
// Student Examination Results & Grade Sheets grouped by Enrolled Courses & Batches
// Designed with the visual standards of admin-result-page.php
// Feature 1: Live search bar below the header to filter batches, course codes, subjects & grades
// Feature 2: Pin button on each batch header to pin result tables to the top
// Feature 3: Status filter tabs (All, Graded, Under Review, Submitted, Not Taken)
// Feature 4: "Back to Dashboard" button linking to student-dashboard-page.php
// Clean view: No stats overview grid and no result action column
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Session Gatekeeper: Restrict access to authorized students (and admins)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}
if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['student', 'admin', 'superadmin', 'administrator'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Database Connection
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if (file_exists(__DIR__ . '/../common-portal/exam-submission-finalizer.php')) {
    require_once __DIR__ . '/../common-portal/exam-submission-finalizer.php';
    if (function_exists('finalizeExpiredExamSubmissions')) {
        finalizeExpiredExamSubmissions($dbConn);
    }
}

$examTimezone = new DateTimeZone('Asia/Dhaka');
$utcTimezone  = new DateTimeZone('UTC');

function formatStudentResultDhaka($value, $format) {
    if (empty($value)) return 'N/A';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Dhaka'))
            ->format($format) . ' (Asia/Dhaka)';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function formatStudentExamScheduleDhaka($date, $time) {
    if (empty($date)) return 'N/A';
    try {
        $dateTime = trim($date) . (!empty($time) ? ' ' . trim($time) : '');
        return (new DateTimeImmutable($dateTime, new DateTimeZone('Asia/Dhaka')))
            ->format(!empty($time) ? 'd/m/Y h:i A' : 'd/m/Y') . ' (Asia/Dhaka)';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

// Initialize Session State for Pinned Result Batches
if (!isset($_SESSION['pinned_student_result_batches'])) {
    $_SESSION['pinned_student_result_batches'] = [];
}

// 4. FETCH LOGGED-IN STUDENT DETAILS
$session_uid   = $_SESSION['user_id'] ?? 0;
$session_email = $_SESSION['email'] ?? '';

$student = [];
if ($dbConn) {
    if (!empty($session_email)) {
        try {
            $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
            $stmt_student->execute(['email' => $session_email]);
            $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    if (empty($student) && !empty($session_uid)) {
        try {
            $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `id` = :uid LIMIT 1");
            $stmt_student->execute(['uid' => $session_uid]);
            $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    if (empty($student) && !empty($session_uid)) {
        try {
            $stmt_student = $dbConn->prepare("
                SELECT s.* FROM `students` s 
                JOIN `users-table` u ON s.email = u.email 
                WHERE u.id = :uid LIMIT 1
            ");
            $stmt_student->execute(['uid' => $session_uid]);
            $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }
}

$studentDbId         = (int)($student['id'] ?? ($session_uid ?: 0));
$logged_student_id   = !empty($student['student_id']) ? $student['student_id'] : ($_SESSION['student_id'] ?? (string)$studentDbId);
$logged_student_name = !empty($student['full_name']) ? $student['full_name'] : ($_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Student');
$logged_batch        = !empty($student['batch']) ? $student['batch'] : ($_SESSION['batch'] ?? '');

// 5. HANDLE PIN ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_student_result_batches'])) {
            $_SESSION['pinned_student_result_batches'] = array_values(array_diff($_SESSION['pinned_student_result_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_student_result_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// 6. FETCH ENROLLED BATCHES & COURSES FOR THIS STUDENT
$studentEnrollments = [];
$enrollmentsByBatch = [];
$enrolledCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];
$enrolledBatches = [];

if ($dbConn && ($studentDbId > 0 || !empty($logged_student_id) || !empty($session_email))) {
    try {
        $stmtEnr = $dbConn->prepare("
            SELECT e.*
            FROM `student_enrollments` e
            INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
                    ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
            WHERE (e.`student_id` = ? OR CAST(e.`student_id` AS CHAR) = ?)
            ORDER BY e.id DESC
        ");
        $stmtEnr->execute([$studentDbId, $logged_student_id ?: (string)$studentDbId]);
        $enrIds = [];
        $enrollmentMapById = [];

        while ($row = $stmtEnr->fetch(PDO::FETCH_ASSOC)) {
            $b = $row['batch'];
            $enrollmentsByBatch[$b][] = $row;
            $enrollmentMapById[$row['id']] = $row;
            if (!isset($studentEnrollments[$b])) {
                $studentEnrollments[$b] = $row;
            }
            if (!empty($row['id'])) {
                $enrIds[$row['id']] = $b;
            }
        }

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
                    if ($bName) {
                        $wStatus = $rec['withdraw_status'] ?? 'None';
                        if ($wStatus === 'Withdrawn') {
                            $withdrawnCourseCodesByBatch[$bName][] = $wRow['course_code'];
                        }
                    }
                }
            } catch (PDOException $e) {}

            foreach ($withdrawnCourseCodesByBatch as $bKey => $wCodes) {
                if (!empty($enrolledCourseCodesByBatch[$bKey])) {
                    $enrolledCourseCodesByBatch[$bKey] = array_values(array_diff($enrolledCourseCodesByBatch[$bKey], $wCodes));
                }
            }

            foreach ($studentEnrollments as $bKey => $sRec) {
                if (($sRec['withdraw_status'] ?? '') === 'Withdrawn' || (($sRec['status'] ?? '') === 'Withdrawn' && ($sRec['withdraw_status'] ?? '') !== 'Rejected' && ($sRec['withdraw_status'] ?? '') !== 'Pending')) {
                    if (empty($withdrawnCourseCodesByBatch[$bKey])) {
                        $enrolledCourseCodesByBatch[$bKey] = [];
                    }
                }
            }
        }
    } catch (PDOException $e) {}
}

foreach ($enrollmentsByBatch as $bName => $bList) {
    foreach ($bList as $bEnr) {
        if (($bEnr['status'] ?? '') === 'Enrolled' && ($bEnr['withdraw_status'] ?? '') !== 'Withdrawn') {
            if (!isset($enrolledCourseCodesByBatch[$bName]) || !empty($enrolledCourseCodesByBatch[$bName])) {
                $enrolledBatches[] = $bName;
                break;
            }
        }
    }
}
$enrolledBatches = array_values(array_unique(array_filter(array_map('trim', $enrolledBatches))));

// Case-insensitive mapping
$enrolledBatchKeyByNormalized = [];
foreach ($enrolledBatches as $enrolledBatchName) {
    $enrolledBatchKeyByNormalized[strtolower(trim($enrolledBatchName))] = $enrolledBatchName;
}

$groupedResultsByBatch = [];
foreach ($enrolledBatches as $bName) {
    $groupedResultsByBatch[$bName] = [];
}

// Fetch batch meta info (Teacher / Course Advisor & Creation date)
$batchMeta = [];
$teachersLookup = [];
$adminsLookup   = [];

if (!empty($enrolledBatches) && $dbConn) {
    try {
        try {
            $stmtT = $dbConn->query("SELECT `id`, `teacher_id`, `full_name` FROM `teachers`");
            if ($stmtT) {
                while ($tRow = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                    $fn = trim($tRow['full_name'] ?? '');
                    if (!empty($tRow['id'])) $teachersLookup[strval($tRow['id'])] = $fn;
                    if (!empty($tRow['teacher_id'])) {
                        $tid = trim($tRow['teacher_id']);
                        $teachersLookup[$tid] = $fn;
                        $teachersLookup[strtolower($tid)] = $fn;
                    }
                    if ($fn !== '') $teachersLookup[strtolower($fn)] = $fn;
                }
            }
        } catch (Exception $e) {}

        try {
            $stmtA = $dbConn->query("SELECT `id`, `admin_id`, `full_name` FROM `admins`");
            if ($stmtA) {
                while ($aRow = $stmtA->fetch(PDO::FETCH_ASSOC)) {
                    $fn = trim($aRow['full_name'] ?? '');
                    if (!empty($aRow['id'])) $adminsLookup[strval($aRow['id'])] = $fn;
                    if (!empty($aRow['admin_id'])) {
                        $aid = trim($aRow['admin_id']);
                        $adminsLookup[$aid] = $fn;
                        $adminsLookup[strtolower($aid)] = $fn;
                    }
                    if ($fn !== '') $adminsLookup[strtolower($fn)] = $fn;
                }
            }
        } catch (Exception $e) {}

        $inClauseMeta = implode(',', array_fill(0, count($enrolledBatches), '?'));
        $stmtMeta = $dbConn->prepare("
            SELECT b.id, b.batch, b.course_code, b.subject, b.exam_title, b.created_at, b.teacher_id,
                   COALESCE(t.full_name, adm.full_name) AS joined_teacher_name
            FROM `batches` b 
            LEFT JOIN `teachers` t ON (b.teacher_id = t.teacher_id OR b.teacher_id = CAST(t.id AS CHAR) OR b.teacher_id = t.full_name)
            LEFT JOIN `admins` adm ON (b.teacher_id = adm.admin_id OR b.teacher_id = CAST(adm.id AS CHAR) OR b.teacher_id = adm.full_name OR (LOWER(TRIM(b.teacher_id)) = 'admin' AND adm.id = 1))
            WHERE b.batch IN ($inClauseMeta)
            ORDER BY b.batch ASC, b.id DESC
        ");
        $stmtMeta->execute($enrolledBatches);

        $batchRowsMap = [];
        while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
            $b = $m['batch'];
            $batchRowsMap[$b][] = $m;
            $norm = strtolower(trim($b));
            if ($norm !== $b) $batchRowsMap[$norm][] = $m;
        }

        foreach ($enrolledBatches as $bName) {
            $rows = $batchRowsMap[$bName] ?? $batchRowsMap[strtolower(trim($bName))] ?? [];
            $advisorRecord = !empty($rows) ? $rows[0] : null;
            $resolvedTeacherName = '';
            $rawTeacherId = trim($advisorRecord['teacher_id'] ?? '');

            if (!empty($advisorRecord['joined_teacher_name']) && $advisorRecord['joined_teacher_name'] !== 'Unknown Teacher') {
                $resolvedTeacherName = $advisorRecord['joined_teacher_name'];
            }
            if (empty($resolvedTeacherName) && $rawTeacherId !== '') {
                $resolvedTeacherName = $teachersLookup[$rawTeacherId] ?? $adminsLookup[$rawTeacherId] ?? ($rawTeacherId !== '' && !is_numeric($rawTeacherId) ? $rawTeacherId : 'Course Advisor');
            }
            if (empty($resolvedTeacherName)) $resolvedTeacherName = 'Course Advisor';

            $metaItem = [
                'batch'        => $bName,
                'teacher_id'   => $rawTeacherId,
                'teacher_name' => $resolvedTeacherName,
                'created_at'   => $advisorRecord['created_at'] ?? null
            ];
            $batchMeta[$bName] = $metaItem;
            $batchMeta[strtolower(trim($bName))] = $metaItem;
        }
    } catch (Exception $e) {
        error_log("Student batch meta error: " . $e->getMessage());
    }
}

// Fetch course definitions from batches
$batchCoursesMap = [];
if (!empty($enrolledBatches) && $dbConn) {
    try {
        $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
        $stmtBC = $dbConn->prepare("
            SELECT DISTINCT batch, course_code, subject, exam_title, credit, teacher_id 
            FROM `batches` 
            WHERE batch IN ($inClause)
        ");
        $stmtBC->execute($enrolledBatches);
        while ($bc = $stmtBC->fetch(PDO::FETCH_ASSOC)) {
            $normalizedBatch = strtolower(trim($bc['batch'] ?? ''));
            $batchKey = $enrolledBatchKeyByNormalized[$normalizedBatch] ?? ($bc['batch'] ?? '');
            $batchCoursesMap[$batchKey][] = $bc;
        }
    } catch (Exception $e) {}
}

// Grade calculation helper
function calculateGrade($marksObtained, $totalMarks) {
    if ($totalMarks <= 0) return ['grade' => 'N/A', 'point' => '0.00', 'class' => 'grade-na', 'status' => 'Pending'];
    $percentage = ($marksObtained / $totalMarks) * 100;

    if ($percentage >= 80) return ['grade' => 'A+', 'point' => '4.00', 'class' => 'grade-a-plus', 'status' => 'Passed'];
    if ($percentage >= 75) return ['grade' => 'A',  'point' => '3.75', 'class' => 'grade-a',      'status' => 'Passed'];
    if ($percentage >= 70) return ['grade' => 'A-', 'point' => '3.50', 'class' => 'grade-a-minus', 'status' => 'Passed'];
    if ($percentage >= 65) return ['grade' => 'B+', 'point' => '3.25', 'class' => 'grade-b-plus', 'status' => 'Passed'];
    if ($percentage >= 60) return ['grade' => 'B',  'point' => '3.00', 'class' => 'grade-b',      'status' => 'Passed'];
    if ($percentage >= 55) return ['grade' => 'B-', 'point' => '2.75', 'class' => 'grade-b-minus', 'status' => 'Passed'];
    if ($percentage >= 50) return ['grade' => 'C+', 'point' => '2.50', 'class' => 'grade-c-plus', 'status' => 'Passed'];
    if ($percentage >= 45) return ['grade' => 'C',  'point' => '2.25', 'class' => 'grade-c',      'status' => 'Passed'];
    if ($percentage >= 40) return ['grade' => 'D',  'point' => '2.00', 'class' => 'grade-d',      'status' => 'Passed'];
    return ['grade' => 'F', 'point' => '0.00', 'class' => 'grade-f', 'status' => 'Failed'];
}

// Fetch student examination results
if (!empty($enrolledBatches) && $dbConn) {
    try {
        $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
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
                   e.exam_time,
                   e.duration,
                   COALESCE(e.marks, 0) AS total_marks,
                   e.status AS exam_status,
                   e.created_at AS exam_created_at,
                   s.id AS submission_id,
                   s.student_id,
                   s.student_name,
                   s.submitted_at,
                   s.status AS attempt_status,
                   CASE
                       WHEN s.id IS NULL THEN NULL
                       WHEN s.status = 'In Progress' THEN 'Participating'
                       WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                       ELSE ev.status
                   END AS submission_status,
                   COALESCE(ev.total_marks_obtained, 0) AS marks_obtained,
                   ev.teacher_feedback,
                   ev.evaluated_by,
                   ev.evaluated_at
            FROM `exam_header` e
            LEFT JOIN `exam_submissions` s 
                   ON e.id = s.exam_id AND (s.student_id = ? OR s.student_id = ?)
            LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
            WHERE e.batch IN ($inClause)
            ORDER BY e.exam_date DESC, e.id DESC
        ";
        $params = array_merge([strval($logged_student_id), strval($studentDbId)], $enrolledBatches);
        $stmtResults = $dbConn->prepare($sql);
        $stmtResults->execute($params);

        while ($row = $stmtResults->fetch(PDO::FETCH_ASSOC)) {
            $normalizedBatch = strtolower(trim($row['batch'] ?? ''));
            $b = $enrolledBatchKeyByNormalized[$normalizedBatch] ?? ($row['batch'] ?? '');
            if (!isset($groupedResultsByBatch[$b])) continue;

            $coursesInBatch = $batchCoursesMap[$b] ?? [];
            $matchedCourse = null;
            $exSub   = trim($row['subject'] ?? '');
            $exTitle = trim($row['exam_title'] ?? '');

            foreach ($coursesInBatch as $c) {
                $cCode  = trim($c['course_code'] ?? '');
                $cSub   = trim($c['subject'] ?? '');
                $cTitle = trim($c['exam_title'] ?? '');

                if ($cSub !== '' && strcasecmp($cSub, $exSub) === 0) { $matchedCourse = $c; break; }
                if ($cCode !== '' && (strcasecmp($cCode, $exSub) === 0 || strcasecmp($cCode, $exTitle) === 0)) { $matchedCourse = $c; break; }
                if ($cTitle !== '' && strcasecmp($cTitle, $exTitle) === 0) { $matchedCourse = $c; break; }
            }

            $examCourseCode = trim($row['course_code'] ?? '');
            if ($examCourseCode !== '') {
                $matchedCourse = ['course_code' => $examCourseCode];
            }
            $matchedCourseCode = trim($matchedCourse['course_code'] ?? '');

            $batchEnrolledList  = $enrolledCourseCodesByBatch[$b] ?? [];
            $batchWithdrawnList = $withdrawnCourseCodesByBatch[$b] ?? [];
            $hasEnrolledList    = !empty($batchEnrolledList);
            $enrDetails         = $studentEnrollments[$b] ?? [];

            $isThisCourseWithdrawn = false;
            if (!empty($batchWithdrawnList) && $matchedCourseCode !== '') {
                foreach ($batchWithdrawnList as $wc) {
                    if (strcasecmp(trim($wc), $matchedCourseCode) === 0) { $isThisCourseWithdrawn = true; break; }
                }
            }

            $isThisCourseEnrolled = false;
            if ($isThisCourseWithdrawn) {
                $isThisCourseEnrolled = false;
            } elseif ($hasEnrolledList && $matchedCourseCode !== '') {
                foreach ($batchEnrolledList as $ec) {
                    if (strcasecmp(trim($ec), $matchedCourseCode) === 0) { $isThisCourseEnrolled = true; break; }
                }
            } else {
                $isThisCourseEnrolled = (($enrDetails['status'] ?? '') === 'Enrolled');
            }

            if ($isThisCourseEnrolled && !$isThisCourseWithdrawn) {
                $row['course_code'] = !empty($matchedCourseCode) ? $matchedCourseCode : (!empty($row['course_code']) ? $row['course_code'] : 'N/A');
                $subStatus = $row['submission_status'] ?? '';
                $totMarks  = floatval($row['total_marks'] ?? 0);
                $obtMarks  = floatval($row['marks_obtained'] ?? 0);

                if ($subStatus === 'Graded') {
                    $gradeInfo = calculateGrade($obtMarks, $totMarks);
                } elseif ($subStatus === 'Under Review') {
                    $gradeInfo = ['grade' => 'Under Review', 'point' => '-', 'class' => 'grade-review', 'status' => 'Reviewing'];
                } elseif ($subStatus === 'Submitted') {
                    $gradeInfo = ['grade' => 'Pending', 'point' => '-', 'class' => 'grade-submitted', 'status' => 'Evaluating'];
                } elseif ($subStatus === 'Participating') {
                    $gradeInfo = ['grade' => 'In Progress', 'point' => '-', 'class' => 'grade-na', 'status' => 'Participating'];
                } else {
                    $gradeInfo = ['grade' => '-', 'point' => '-', 'class' => 'grade-na', 'status' => 'Not Taken'];
                }

                $row['calculated_grade'] = $gradeInfo['grade'];
                $row['grade_point']      = $gradeInfo['point'];
                $row['grade_class']      = $gradeInfo['class'];
                $row['pass_fail_status'] = $gradeInfo['status'];
                $row['percentage']       = ($totMarks > 0 && $subStatus === 'Graded') ? round(($obtMarks / $totMarks) * 100, 1) : null;

                $groupedResultsByBatch[$b][] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Student results query error: " . $e->getMessage());
    }
}

// Sort Batches with Pinned Batches placed on top
uksort($groupedResultsByBatch, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_student_result_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_student_result_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Examination Results &amp; Grade Sheets - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-examTable-page.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-result-page.css">
</head>

<body data-user-id="<?php echo htmlspecialchars($logged_student_id); ?>">
    <div class="main-layout">

        <!-- HEADER AND SIDEBAR (STUDENT) -->
        <?php 
            if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
                require_once __DIR__ . '/student-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/../../src/views/common/header.php')) {
                include_once __DIR__ . '/../../src/views/common/header.php';
            }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fa-solid fa-chart-line" style="color: #0266cc;"></i> Examination Results &amp; Grade Sheets</h1>
                    <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                        Student: <strong><?php echo htmlspecialchars($logged_student_name); ?></strong> (<?php echo htmlspecialchars($logged_student_id ?: 'Enrolled'); ?>) &bull; Enrolled Batches: <strong><?php echo !empty($enrolledBatches) ? htmlspecialchars(implode(', ', $enrolledBatches)) : 'None'; ?></strong>
                    </p>
                </div>

                <!-- Back to Dashboard Action Button -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="student-dashboard-page.php" class="btn-archive-page" style="background:#0266cc;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- CONTROLS ROW (STATUS TABS + SEARCH) -->
            <div class="controls-card">
                <div class="filter-tabs-group" id="resultStatusTabs">
                    <a href="javascript:void(0)" onclick="filterResultStatus('All', this)" class="tab-link active" data-status="all">
                        All
                    </a>
                    <a href="javascript:void(0)" onclick="filterResultStatus('Graded', this)" class="tab-link" data-status="graded">
                        <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Graded
                    </a>
                    <a href="javascript:void(0)" onclick="filterResultStatus('Under Review', this)" class="tab-link" data-status="under review">
                        <i class="fa-solid fa-hourglass-half" style="color: #f59e0b;"></i> Under Review
                    </a>
                    <a href="javascript:void(0)" onclick="filterResultStatus('Submitted', this)" class="tab-link" data-status="submitted">
                        <i class="fa-solid fa-file-arrow-up" style="color: #3b82f6;"></i> Submitted
                    </a>
                    <a href="javascript:void(0)" onclick="filterResultStatus('Not Taken', this)" class="tab-link" data-status="not taken">
                        <i class="fa-regular fa-clock" style="color: #94a3b8;"></i> Not Taken
                    </a>
                </div>

                <form method="GET" class="search-form" onsubmit="event.preventDefault(); filterResultBatches();">
                    <input 
                        type="text" 
                        id="resultBatchSearch" 
                        class="search-input-field"
                        onkeyup="filterResultBatches()" 
                        placeholder="Search results by batch, course code, subject, title, teacher, or grade..."
                    >
                    <button type="button" onclick="filterResultBatches()" class="btn-search-submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </form>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or exam results match your search query.</p>
            </div>

            <!-- Batches Container: Separate Table for each Enrolled Batch -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($groupedResultsByBatch)): foreach ($groupedResultsByBatch as $batchName => $results): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_student_result_batches']);
                    $meta = $batchMeta[$batchName] ?? $batchMeta[strtolower(trim($batchName))] ?? [];
                    $teacherName = !empty($meta['teacher_name']) && $meta['teacher_name'] !== 'Unknown Teacher' 
                        ? $meta['teacher_name'] 
                        : (!empty($results[0]['teacher_name']) && $results[0]['teacher_name'] !== 'Unknown Teacher' ? $results[0]['teacher_name'] : 'Course Advisor');
                    $resultsCount = count($results);
                ?>
                    <div class="batch-card" data-batch="<?php echo strtolower(htmlspecialchars($batchName)); ?>" style="border: 1px solid <?php echo $isPinned ? '#93c5fd' : '#e2e8f0'; ?>;">
                        
                        <!-- Batch Card Header with Pin Control -->
                        <div class="batch-header" style="background: <?php echo $isPinned ? '#eff6ff' : '#f8fafc'; ?>;">
                            <div class="batch-title-info">
                                <h2>
                                    <i class="fa-solid fa-users-rectangle"></i> Batch: <?php echo htmlspecialchars($batchName); ?>
                                    <?php if ($isPinned): ?>
                                        <span class="badge-pinned"><i class="fa-solid fa-thumbtack"></i> Pinned</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="batch-meta-info">
                                    <span><i class="fa-solid fa-user-tie" style="color: #0266cc;"></i> <strong>Teacher:</strong> <?php echo htmlspecialchars($teacherName); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-file-signature" style="color: #059669;"></i> <strong>Scheduled Exams:</strong> <?php echo $resultsCount; ?></span>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 8px;">
                                <form method="POST" style="display: inline; margin: 0;">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                    <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table on top'; ?>">
                                        <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Table of Results for this Specific Batch -->
                        <div class="table-responsive">
                            <table class="exam-table">
                                <thead>
                                    <tr>
                                        <th>Exam ID</th>
                                        <th>Batch</th>
                                        <th>Teacher Name</th>
                                        <th>Teacher Id</th>
                                        <th>Student Name</th>
                                        <th>Student ID</th>
                                        <th>Course Code</th>
                                        <th>Subject/Course</th>
                                        <th>Course Title</th>
                                        <th>Exam Type</th>
                                        <th>Exam Date &amp; Time (Asia/Dhaka)</th>
                                        <th>Submitted At (Asia/Dhaka)</th>
                                        <th>Total Marks</th>
                                        <th>Marks Obtained</th>
                                        <th>Percentage</th>
                                        <th>Letter Grade</th>
                                        <th>Evaluation Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($results)): ?>
                                        <?php foreach ($results as $resultRow):
                                            $examId       = intval($resultRow['exam_id']);
                                            $subId        = intval($resultRow['submission_id'] ?? 0);
                                            $subStatus    = $resultRow['submission_status'] ?? '';
                                            $totMarks     = floatval($resultRow['total_marks'] ?? 0);
                                            $obtMarks     = floatval($resultRow['marks_obtained'] ?? 0);
                                            $isGraded     = ($subStatus === 'Graded');
                                            $isReview     = ($subStatus === 'Under Review');
                                            $isSubmitted  = ($subStatus === 'Submitted' || $subStatus === 'Pending');
                                            $isParticipating = ($subStatus === 'Participating');

                                            $rawDate = trim($resultRow['exam_date'] ?? '');
                                            $dateFormatted = formatStudentExamScheduleDhaka($rawDate, $resultRow['exam_time'] ?? '');
                                            $rawSubmittedAt = trim($resultRow['submitted_at'] ?? '');
                                            $submittedDhakaFormatted = !empty($rawSubmittedAt) ? formatStudentResultDhaka($rawSubmittedAt, 'd/m/Y h:i A') : 'N/A';

                                            $rowTeacherId = !empty($resultRow['teacher_id']) ? $resultRow['teacher_id'] : ($meta['teacher_id'] ?? 'N/A');
                                            $rowTeacherName = !empty($resultRow['teacher_name']) && $resultRow['teacher_name'] !== 'Unknown Teacher'
                                                ? $resultRow['teacher_name']
                                                : ($teachersLookup[$rowTeacherId] ?? $adminsLookup[$rowTeacherId] ?? ($meta['teacher_name'] ?? 'Instructor'));

                                            $rowStudentName = !empty($resultRow['student_name']) ? $resultRow['student_name'] : $logged_student_name;
                                            $rowStudentId   = !empty($resultRow['student_id']) ? $resultRow['student_id'] : $logged_student_id;
                                        ?>
                                            <tr class="exam-row student-result-row"
                                                data-exam-id="<?php echo $examId; ?>"
                                                data-course-code="<?php echo strtolower(htmlspecialchars($resultRow['course_code'] ?? '')); ?>"
                                                data-subject="<?php echo strtolower(htmlspecialchars($resultRow['subject'] ?? '')); ?>"
                                                data-title="<?php echo strtolower(htmlspecialchars($resultRow['exam_title'] ?? '')); ?>"
                                                data-teacher="<?php echo strtolower(htmlspecialchars($rowTeacherName)); ?>"
                                                data-teacher-id="<?php echo strtolower(htmlspecialchars($rowTeacherId)); ?>"
                                                data-student-name="<?php echo strtolower(htmlspecialchars($rowStudentName)); ?>"
                                                data-student-id="<?php echo strtolower(htmlspecialchars($rowStudentId)); ?>"
                                                data-grade="<?php echo strtolower(htmlspecialchars($resultRow['calculated_grade'] ?? '')); ?>"
                                                data-status="<?php echo strtolower(htmlspecialchars($subStatus ?: 'nottaken')); ?>"
                                                >

                                                <!-- Exam ID -->
                                                <td><strong>#<?php echo $examId; ?></strong></td>

                                                <!-- Batch -->
                                                <td><strong style="color: #4338ca;"><?php echo htmlspecialchars($resultRow['batch'] ?? $batchName); ?></strong></td>

                                                <!-- Teacher Name -->
                                                <td>
                                                    <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <?php echo htmlspecialchars($rowTeacherName); ?>
                                                </td>

                                                <!-- Teacher Id -->
                                                <td><?php echo htmlspecialchars($rowTeacherId); ?></td>

                                                <!-- Student Name -->
                                                <td>
                                                    <i class="fa-solid fa-user-graduate" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <strong><?php echo htmlspecialchars($rowStudentName); ?></strong>
                                                </td>

                                                <!-- Student ID -->
                                                <td><?php echo htmlspecialchars($rowStudentId); ?></td>

                                                <!-- Course Code -->
                                                <td><strong style="color: #0266cc;"><?php echo htmlspecialchars(!empty($resultRow['course_code']) ? $resultRow['course_code'] : 'N/A'); ?></strong></td>

                                                <!-- Subject/Course -->
                                                <td><?php echo htmlspecialchars($resultRow['subject'] ?? 'N/A'); ?></td>

                                                <!-- Course Title -->
                                                <td><strong><?php echo htmlspecialchars($resultRow['exam_title'] ?? 'N/A'); ?></strong></td>

                                                <!-- Exam Type -->
                                                <td><span class="badge badge-type"><?php echo htmlspecialchars($resultRow['exam_type'] ?? 'MCQ'); ?></span></td>

                                                <!-- Exam Date -->
                                                <td><?php echo htmlspecialchars($dateFormatted); ?></td>

                                                <!-- Submitted At (Asia/Dhaka) -->
                                                <td><?php echo htmlspecialchars($submittedDhakaFormatted); ?></td>

                                                <!-- Total Marks -->
                                                <td><strong><?php echo number_format($totMarks, 2); ?></strong></td>

                                                <!-- Marks Obtained -->
                                                <td>
                                                    <?php if ($isGraded): ?>
                                                        <strong style="color: #059669; font-size: 0.95rem;">
                                                            <?php echo number_format($obtMarks, 2); ?>
                                                        </strong>
                                                    <?php elseif ($isReview || $isSubmitted): ?>
                                                        <span style="color: #d97706; font-size: 0.85rem; font-style: italic;">Evaluating</span>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Percentage -->
                                                <td>
                                                    <?php if ($isGraded && $resultRow['percentage'] !== null): ?>
                                                        <strong><?php echo $resultRow['percentage']; ?>%</strong>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Letter Grade -->
                                                <td>
                                                    <span class="grade-badge <?php echo $resultRow['grade_class']; ?>">
                                                        <?php echo htmlspecialchars($resultRow['calculated_grade']); ?>
                                                    </span>
                                                </td>

                                                <!-- Evaluation Status Badge -->
                                                <td class="status-cell">
                                                    <?php if ($isGraded): ?>
                                                        <span class="badge badge-graded"><i class="fa-solid fa-circle-check"></i> Graded</span>
                                                    <?php elseif ($isReview): ?>
                                                        <span class="badge badge-review"><i class="fa-solid fa-hourglass-half"></i> Under Review</span>
                                                    <?php elseif ($isSubmitted): ?>
                                                        <span class="badge badge-submitted"><i class="fa-solid fa-file-arrow-up"></i> Submitted</span>
                                                    <?php elseif ($isParticipating): ?>
                                                        <span class="badge badge-review"><i class="fa-solid fa-person-circle-check"></i> Participating</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-not-taken"><i class="fa-regular fa-clock"></i> Not Taken</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="17" style="text-align: center; padding: 28px; color: #64748b;">
                                                <i class="fa-regular fa-folder-open" style="font-size: 1.8rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                                <strong>No examination results available for Batch <?php echo htmlspecialchars($batchName); ?>.</strong><br>
                                                <span style="font-size: 0.85rem; color: #94a3b8;">You are enrolled in this batch, but no assessments have been graded or published yet.</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card" style="text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; color: #94a3b8; margin-bottom: 12px; display: block;"></i>
                        <h3 style="color: #1e293b; margin-bottom: 6px;">No enrolled courses found.</h3>
                        <p style="color: #64748b; margin-bottom: 16px;">Please enroll in available batches to access your examination results.</p>
                        <a href="student-dashboard-page.php" class="btn" style="background: #0266cc; color: #fff; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                            <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- JavaScript for Dynamic Filter Tabs & Search -->
    <script>
        var currentActiveStatus = 'all';

        function filterResultStatus(status, tabElement) {
            currentActiveStatus = status.toLowerCase().trim();

            var tabs = document.querySelectorAll('#resultStatusTabs .tab-link');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            if (tabElement) {
                tabElement.classList.add('active');
            }

            filterResultBatches();
        }

        function filterResultBatches() {
            var searchInput = document.getElementById('resultBatchSearch');
            var query = searchInput ? searchInput.value.toLowerCase().trim() : '';

            var batchCards = document.querySelectorAll('.batch-card');
            var anyMatchFound = false;

            batchCards.forEach(function(card) {
                var rows = card.querySelectorAll('.exam-row');
                var visibleRowCount = 0;

                rows.forEach(function(row) {
                    var rowStatus = (row.getAttribute('data-status') || '').toLowerCase().trim();
                    var courseCode = (row.getAttribute('data-course-code') || '').toLowerCase();
                    var subject = (row.getAttribute('data-subject') || '').toLowerCase();
                    var title = (row.getAttribute('data-title') || '').toLowerCase();
                    var teacher = (row.getAttribute('data-teacher') || '').toLowerCase();
                    var grade = (row.getAttribute('data-grade') || '').toLowerCase();
                    var rowText = row.textContent.toLowerCase();

                    // Status Matching
                    var statusMatch = false;
                    if (currentActiveStatus === 'all') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'graded' && rowStatus === 'graded') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'under review' && (rowStatus === 'under review' || rowStatus === 'reviewing')) {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'submitted' && (rowStatus === 'submitted' || rowStatus === 'evaluating')) {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'not taken' && (rowStatus === 'not taken' || rowStatus === 'nottaken' || rowStatus === '')) {
                        statusMatch = true;
                    }

                    // Query Matching
                    var queryMatch = false;
                    if (query === '') {
                        queryMatch = true;
                    } else if (
                        rowText.indexOf(query) !== -1 ||
                        courseCode.indexOf(query) !== -1 ||
                        subject.indexOf(query) !== -1 ||
                        title.indexOf(query) !== -1 ||
                        teacher.indexOf(query) !== -1 ||
                        grade.indexOf(query) !== -1
                    ) {
                        queryMatch = true;
                    }

                    if (statusMatch && queryMatch) {
                        row.style.display = '';
                        visibleRowCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (visibleRowCount > 0) {
                    card.style.display = '';
                    anyMatchFound = true;
                } else {
                    card.style.display = 'none';
                }
            });

            var noResults = document.getElementById('searchNoResultsMessage');
            if (noResults) {
                noResults.style.display = (anyMatchFound || batchCards.length === 0) ? 'none' : 'block';
            }
        }
    </script>

    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
