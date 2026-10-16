<?php
// =========================================================================
// student-examTable-page.php
// Prototype of Student Exam Schedule grouped by Enrolled Courses & Batches
// Feature 1: Live search bar below the header to filter batches & exams
// Feature 2: Pin button on each batch header to pin tables to the top
// Feature 3: Archive button on each batch header to archive tables
// Feature 4: Archived Batch Exams button on top right with count badge
// Feature 5: In archive section: Restore and Pin buttons only
// ENROLLMENT FILTER: Displays separate batch table cards for each enrolled batch from student_enrollments.
// Inside each batch table, shows that batch's exams (or an empty table state if no exams are scheduled).
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
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
$examTimezone = new DateTimeZone('Asia/Dhaka');
$utcTimezone = new DateTimeZone('UTC');

// Initialize Session State for Pinned & Archived Batches
if (!isset($_SESSION['pinned_exam_batches'])) {
    $_SESSION['pinned_exam_batches'] = [];
}
if (!isset($_SESSION['archived_exam_batches'])) {
    $_SESSION['archived_exam_batches'] = [];
}

// 4. FETCH LOGGED-IN STUDENT DETAILS
$student = [];
if (isset($_SESSION['email'])) {
    $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
    $stmt_student->execute(['email' => $_SESSION['email']]);
    $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (empty($student) && isset($_SESSION['user_id'])) {
    $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `id` = :uid LIMIT 1");
    $stmt_student->execute(['uid' => $_SESSION['user_id']]);
    $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (empty($student) && isset($_SESSION['user_id'])) {
    try {
        $stmt_student = $dbConn->prepare("
            SELECT s.* FROM `students` s 
            JOIN `users-table` u ON s.email = u.email 
            WHERE u.id = :uid LIMIT 1
        ");
        $stmt_student->execute(['uid' => $_SESSION['user_id']]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {}
}

$studentDbId         = (int)($student['id'] ?? $_SESSION['user_id'] ?? 0);
$logged_student_id   = !empty($student['student_id']) ? $student['student_id'] : ($_SESSION['student_id'] ?? (string)$studentDbId);
$logged_student_name = !empty($student['full_name']) ? $student['full_name'] : ($_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Student');
$logged_batch        = !empty($student['batch']) ? $student['batch'] : ($_SESSION['batch'] ?? '');

// -------------------------------------------------------------------------
// 5. HANDLE ACTIONS (PIN, ARCHIVE, RESTORE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_exam_batches'])) {
            $_SESSION['pinned_exam_batches'] = array_values(array_diff($_SESSION['pinned_exam_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_exam_batches'][] = $batchName;
        }
        $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
        header("Location: " . $redirectUrl);
        exit();
    }

    // Feature 3: Archive Batch Action
    if ($action === 'archive_batch' && !empty($batchName)) {
        if (!in_array($batchName, $_SESSION['archived_exam_batches'])) {
            $_SESSION['archived_exam_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Feature 5: Restore from Archive Action
    if ($action === 'restore_batch' && !empty($batchName)) {
        $_SESSION['archived_exam_batches'] = array_values(array_diff($_SESSION['archived_exam_batches'], [$batchName]));
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
        exit();
    }
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// -------------------------------------------------------------------------
// 6. FETCH ENROLLED BATCHES & COURSES (MATCHING student-enroll-page LOGIC)
// -------------------------------------------------------------------------
$studentEnrollments = [];
$enrollmentsByBatch = [];
$enrolledCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];
$enrolledBatches = [];

if ($dbConn && ($studentDbId > 0 || !empty($logged_student_id) || !empty($_SESSION['email']))) {
    // Auto-clean older cancelled enrollments if student now has an approved ('Enrolled') enrollment for that batch
    try {
        $dbConn->exec("
            DELETE ec FROM `student_enrollment_courses` ec
            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.status = 'Cancelled' 
              AND e2.status = 'Enrolled'
        ");
        $dbConn->exec("
            DELETE e FROM `student_enrollments` e
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.status = 'Cancelled' 
              AND e2.status = 'Enrolled'
        ");
    } catch (PDOException $e) {}

    // Auto-clean older rejected withdrawals if student now has an approved ('Withdrawn') withdrawal for that batch
    try {
        $dbConn->exec("
            DELETE wc FROM `student_withdrawal_courses` wc
            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.withdraw_status = 'Rejected' 
              AND e2.withdraw_status = 'Withdrawn'
        ");
        $dbConn->exec("
            DELETE e FROM `student_enrollments` e
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.withdraw_status = 'Rejected' 
              AND e2.withdraw_status = 'Withdrawn'
        ");
    } catch (PDOException $e) {}

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

            // Withdrawn courses are NOT enrolled! Remove withdrawn courses from enrolled courses list
            foreach ($withdrawnCourseCodesByBatch as $bKey => $wCodes) {
                if (!empty($enrolledCourseCodesByBatch[$bKey])) {
                    $enrolledCourseCodesByBatch[$bKey] = array_values(array_diff($enrolledCourseCodesByBatch[$bKey], $wCodes));
                }
            }

            // If a batch's latest/current enrollment has withdraw_status = 'Withdrawn' or status = 'Withdrawn'
            // and no course-specific withdrawal records were stored, it means the whole batch was withdrawn
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

// Identify candidate enrolled batches
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

// Resolve batch names case-insensitively, matching the database's usual
// case-insensitive collation before using them as PHP array keys.
$enrolledBatchKeyByNormalized = [];
foreach ($enrolledBatches as $enrolledBatchName) {
    $enrolledBatchKeyByNormalized[strtolower(trim($enrolledBatchName))] = $enrolledBatchName;
}

// Initialize grouped exams structure: EACH enrolled batch receives its own table
$groupedExamsByBatch = [];
foreach ($enrolledBatches as $bName) {
    $groupedExamsByBatch[$bName] = [];
}

// Fetch batch meta info (Teacher & Creation date)
$batchMeta = [];
if (!empty($enrolledBatches) && $dbConn) {
    try {
        $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
        $stmtMeta = $dbConn->prepare("
            SELECT b.batch, COALESCE(t.full_name, 'Instructor') AS teacher_name, b.created_at 
            FROM `batches` b 
            LEFT JOIN `teachers` t 
                ON (b.teacher_id COLLATE utf8mb4_unicode_ci = t.teacher_id COLLATE utf8mb4_unicode_ci 
                 OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(t.id AS CHAR) COLLATE utf8mb4_unicode_ci) 
            WHERE b.batch IN ($inClause)
            ORDER BY b.id DESC
        ");
        $stmtMeta->execute($enrolledBatches);
        while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($batchMeta[$m['batch']])) {
                $batchMeta[$m['batch']] = $m;
            }
        }
    } catch (Exception $e) {
        error_log("Batch meta query error: " . $e->getMessage());
    }
}

// Fetch courses definition from `batches` table to accurately match exams with course_code
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
    } catch (Exception $e) {
        error_log("Batch courses map query error: " . $e->getMessage());
    }
}

// Fetch real exams from `exam_header` (teacher-quesTable-page) for the enrolled batches
// and ONLY keep those exams whose corresponding courses have status "Enrolled"
if (!empty($enrolledBatches) && $dbConn) {
    try {
        $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
        $sql = "
            SELECT e.id,
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
                   e.marks,
                   e.status,
                   e.created_at,
                   e.updated_at,
                   UNIX_TIMESTAMP(e.updated_at) AS updated_at_epoch,
                   UNIX_TIMESTAMP(e.timer_updated_at) AS timer_updated_at_epoch,
                   e.remaining_seconds,
                   s.id AS submission_id,
                   s.status AS submission_status, 
                   s.submitted_at, 
                   COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained
            FROM `exam_header` e
            LEFT JOIN `exam_submissions` s 
                   ON e.id = s.exam_id AND (s.student_id = ? OR s.student_id = ?)
            LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
            WHERE e.batch IN ($inClause)
            ORDER BY e.id DESC
        ";
        $params = array_merge([strval($logged_student_id), strval($studentDbId)], $enrolledBatches);
        $stmtExams = $dbConn->prepare($sql);
        $stmtExams->execute($params);

        while ($row = $stmtExams->fetch(PDO::FETCH_ASSOC)) {
            $normalizedBatch = strtolower(trim($row['batch'] ?? ''));
            $b = $enrolledBatchKeyByNormalized[$normalizedBatch] ?? ($row['batch'] ?? '');
            if (!isset($groupedExamsByBatch[$b])) continue;

            $coursesInBatch = $batchCoursesMap[$b] ?? [];
            $matchedCourse = null;
            $exSub   = trim($row['subject'] ?? '');
            $exTitle = trim($row['exam_title'] ?? '');

            // 1. Match exam to course defined in `batches` table
            foreach ($coursesInBatch as $c) {
                $cCode  = trim($c['course_code'] ?? '');
                $cSub   = trim($c['subject'] ?? '');
                $cTitle = trim($c['exam_title'] ?? '');

                if ($cSub !== '' && strcasecmp($cSub, $exSub) === 0) {
                    $matchedCourse = $c;
                    break;
                }
                if ($cCode !== '' && (strcasecmp($cCode, $exSub) === 0 || strcasecmp($cCode, $exTitle) === 0)) {
                    $matchedCourse = $c;
                    break;
                }
                if ($cTitle !== '' && strcasecmp($cTitle, $exTitle) === 0) {
                    $matchedCourse = $c;
                    break;
                }
            }

            // Substring fallback matching
            if (!$matchedCourse) {
                foreach ($coursesInBatch as $c) {
                    $cCode  = trim($c['course_code'] ?? '');
                    $cSub   = trim($c['subject'] ?? '');
                    $cTitle = trim($c['exam_title'] ?? '');

                    if ($cSub !== '' && (stripos($exSub, $cSub) !== false || stripos($exTitle, $cSub) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                    if ($cCode !== '' && (stripos($exSub, $cCode) !== false || stripos($exTitle, $cCode) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                    if ($cTitle !== '' && (stripos($exTitle, $cTitle) !== false || stripos($exSub, $cTitle) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                }
            }

            // Fallback: If only one course in batch, that course is the match
            if (!$matchedCourse && count($coursesInBatch) === 1) {
                $matchedCourse = $coursesInBatch[0];
            }

            // The saved exam course code is the authoritative link to the
            // student's enrolled course; older rows are backfilled by migration.
            $examCourseCode = trim($row['course_code'] ?? '');
            if ($examCourseCode !== '') {
                $matchedCourse = ['course_code' => $examCourseCode];
                foreach ($coursesInBatch as $courseCandidate) {
                    if (strcasecmp(trim($courseCandidate['course_code'] ?? ''), $examCourseCode) === 0) {
                        $matchedCourse = $courseCandidate;
                        break;
                    }
                }
            }

            $matchedCourseCode = trim($matchedCourse['course_code'] ?? '');

            // 2. Verify Course-level Enrollment Status (Matching student-enroll-page)
            $batchEnrolledList  = $enrolledCourseCodesByBatch[$b] ?? [];
            $batchWithdrawnList = $withdrawnCourseCodesByBatch[$b] ?? [];
            $hasEnrolledList    = !empty($batchEnrolledList);

            $enrDetails = $studentEnrollments[$b] ?? [];
            $batchEnrollmentList = $enrollmentsByBatch[$b] ?? [];

            $hasAnyActiveEnrolledRecord = false;
            foreach ($batchEnrollmentList as $bEnr) {
                if (($bEnr['status'] ?? '') === 'Enrolled' && ($bEnr['withdraw_status'] ?? '') !== 'Withdrawn') {
                    $hasAnyActiveEnrolledRecord = true;
                    break;
                }
            }

            $currentWithdrawStatus = $enrDetails['withdraw_status'] ?? 'None';
            $currentStatus = (!empty($enrDetails['status'])) ? $enrDetails['status'] : 'Unenrolled';
            if ($hasAnyActiveEnrolledRecord && $currentStatus !== 'Enrolled') {
                $currentStatus = 'Enrolled';
            }

            // Check if withdrawn
            $isThisCourseWithdrawn = false;
            if (!empty($batchWithdrawnList)) {
                if ($matchedCourseCode !== '') {
                    foreach ($batchWithdrawnList as $wc) {
                        if (strcasecmp(trim($wc), $matchedCourseCode) === 0) {
                            $isThisCourseWithdrawn = true;
                            break;
                        }
                    }
                }
                if (!$isThisCourseWithdrawn && $exSub !== '') {
                    foreach ($batchWithdrawnList as $wc) {
                        if (strcasecmp(trim($wc), $exSub) === 0) {
                            $isThisCourseWithdrawn = true;
                            break;
                        }
                    }
                }
            } else {
                $isThisCourseWithdrawn = ($currentWithdrawStatus === 'Withdrawn');
            }

            // Check if enrolled
            $isThisCourseEnrolled = false;
            if ($isThisCourseWithdrawn) {
                $isThisCourseEnrolled = false;
            } elseif ($hasEnrolledList) {
                if ($matchedCourseCode !== '') {
                    foreach ($batchEnrolledList as $ec) {
                        if (strcasecmp(trim($ec), $matchedCourseCode) === 0) {
                            $isThisCourseEnrolled = true;
                            break;
                        }
                    }
                }
                if (!$isThisCourseEnrolled && $exSub !== '') {
                    foreach ($batchEnrolledList as $ec) {
                        if (strcasecmp(trim($ec), $exSub) === 0 || stripos($exSub, trim($ec)) !== false || stripos($exTitle, trim($ec)) !== false) {
                            $isThisCourseEnrolled = true;
                            break;
                        }
                    }
                }
            } else {
                $isThisCourseEnrolled = ($currentStatus === 'Enrolled');
            }

            // Course active enrollment verification
            $isCourseActiveEnrolled = $isThisCourseEnrolled && !$isThisCourseWithdrawn;

            // ONLY show exams whose course status is "Enrolled"
            if ($isCourseActiveEnrolled) {
                $row['course_code']          = $matchedCourseCode;
                $row['course_enroll_status'] = 'Enrolled';
                $row['course_enroll_class']  = 'status-enrolled';
                if (empty($row['teacher_id']) && !empty($matchedCourse['teacher_id'])) {
                    $row['teacher_id'] = $matchedCourse['teacher_id'];
                }
                $groupedExamsByBatch[$b][] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Exam header query error: " . $e->getMessage());
    }
}

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedExamsByBatch as $batchName => $exams) {
    if (in_array($batchName, $_SESSION['archived_exam_batches'])) {
        $archivedBatches[$batchName] = $exams;
    } else {
        $activeBatches[$batchName] = $exams;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_exam_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_exam_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top as well
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_exam_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_exam_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Select which batch list to render
$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
$archivedCount  = count($archivedBatches);

$justSubmitted   = isset($_GET['submitted']) && $_GET['submitted'] == '1';
$submittedExamId = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$pageServerTimeMs = (int) round(microtime(true) * 1000);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Exams' : 'Scheduled Exams & Assessments'; ?> - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-examTable-page.css">
 </head>

<body data-student-id="<?php echo htmlspecialchars($logged_student_id); ?>">
    <div class="main-layout">

        <!-- STUDENT HEADER AND SIDEBAR -->
        <?php 
            if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
                require_once __DIR__ . '/student-header-sidebar-section.php'; 
            }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <?php if ($currentView === 'archived'): ?>
                        <h1><i class="fa-solid fa-box-archive" style="color: #d97706;"></i> Archived Batch Exams</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            Viewing archived course exam tables. You can pin priority tables or restore them back to your active exams.
                        </p>
                    <?php else: ?>
                        <h1><i class="fa-solid fa-graduation-cap" style="color: #0266cc;"></i> Scheduled Exams & Assessments</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            Student: <strong><?php echo htmlspecialchars($logged_student_name); ?></strong> (<?php echo htmlspecialchars($logged_student_id ?: 'N/A'); ?>) &bull; Enrolled Batches: <strong><?php echo !empty($enrolledBatches) ? htmlspecialchars(implode(', ', $enrolledBatches)) : 'None'; ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Feature 4: Archived Batch Exams Button on Top Right -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($currentView === 'archived'): ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-archive-page" style="background:#0266cc;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Enrolled Exams
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?view=archived'; ?>" class="btn-archive-page">
                            <i class="fa-solid fa-box-archive"></i> Archived Batch Exams
                            <?php if ($archivedCount > 0): ?>
                                <span class="badge-count"><?php echo $archivedCount; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($justSubmitted): ?>
                <div class="alert-box alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Exam #<?php echo $submittedExamId; ?> submitted successfully. You can use the <strong>Revise</strong> button to update your responses as long as the duration has not expired.</span>
                </div>
            <?php endif; ?>

            <!-- Feature 1: Search Bar below Header -->
            <div class="search-container">
                <div class="search-input-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input 
                        type="text" 
                        id="examBatchSearch" 
                        onkeyup="filterExamBatches()" 
                        placeholder="<?php echo $currentView === 'archived' ? 'Search archived batches, subjects, exams, or teachers...' : 'Search exams by batch, subject, course title, or teacher...'; ?>"
                    >
                </div>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or exams match your search query.</p>
            </div>

            <!-- Batches Container: Separate Table for each Enrolled Batch -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $exams): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_exam_batches']);
                    $meta = $batchMeta[$batchName] ?? [];
                    $teacherName = !empty($meta['teacher_name']) ? $meta['teacher_name'] : (!empty($exams[0]['teacher_name']) ? $exams[0]['teacher_name'] : 'Course Instructor');
                    $examsCount = count($exams);
                ?>
                    <div class="batch-card" data-batch="<?php echo strtolower(htmlspecialchars($batchName)); ?>" style="border: 1px solid <?php echo $isPinned ? '#93c5fd' : '#e2e8f0'; ?>;">
                        
                        <!-- Batch Card Header with Pin and Archive Controls -->
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
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-clipboard-list" style="color: #059669;"></i> <strong>Scheduled Exams:</strong> <?php echo $examsCount; ?></span>
                                </div>
                            </div>

                            <!-- Right Side Action Buttons: Pin, Archive / Restore -->
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($currentView !== 'archived'): ?>
                                    <!-- Feature 2: Pin Logo Button -->
                                    <form method="POST" style="display: inline; margin: 0;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table on top'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <!-- Feature 3: Archive Logo Button -->
                                    <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Archive exam table for batch: <?php echo htmlspecialchars(addslashes($batchName)); ?>?');">
                                        <input type="hidden" name="action" value="archive_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-archive-table" title="Archive table into archives">
                                            <i class="fa-solid fa-box-archive"></i> Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Feature 5: In Archive View, Pin and Restore Buttons Only -->
                                    <form method="POST" style="display: inline; margin: 0;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <input type="hidden" name="current_view" value="archived">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin archived table' : 'Pin table to top of archives'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <form method="POST" style="display: inline; margin: 0;">
                                        <input type="hidden" name="action" value="restore_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-restore-table" title="Restore back to enrolled exams">
                                            <i class="fa-solid fa-rotate-left"></i> Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Table of Exams for this Specific Batch -->
                        <div class="table-responsive">
                            <table class="exam-table">
                                <thead>
                                    <tr>
                                        <th>Exam ID</th>
                                        <th>Teacher Name</th>
                                        <th>Teacher ID</th>
                                        <th>Batch</th>
                                        <th>Subject/Course</th>
                                        <th>Course Title</th>
                                        <th>Exam Type</th>
                                        <th>Date & Time</th>
                                        <th>Exam Starts in</th>
                                        <th>Duration</th>
                                        <th>Duration Left</th>
                                        <th>Total Mark</th>
                                        <th style="text-align: center;">Enrollment Status</th>
                                        <th>Exam Status</th>
                                        <th>Exam Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($exams)): ?>
                                        <?php foreach ($exams as $examRow):
                                            $examId = intval($examRow['id']);
                                            $currentStatus = $examRow['status'] ?? 'Published';
                                            $raw_exam_date = trim($examRow['exam_date'] ?? '');
                                            $raw_exam_time = trim($examRow['exam_time'] ?? '');
                                            $start_datetime = (!empty($raw_exam_date) && !empty($raw_exam_time))
                                                ? ($raw_exam_date . ' ' . $raw_exam_time)
                                                : (!empty($raw_exam_time) ? ((new DateTimeImmutable('now', $examTimezone))->format('Y-m-d') . ' ' . $raw_exam_time) : '');
                                            $durationMins = intval($examRow['duration'] ?? 0);
                                            $durationSecs = $durationMins * 60;
                                            $scheduledStart = null;
                                            if ($start_datetime !== '') {
                                                try {
                                                    $scheduledStart = new DateTimeImmutable($start_datetime, $examTimezone);
                                                } catch (Exception $e) {
                                                    $scheduledStart = null;
                                                }
                                            }
                                            $startTimestamp = $scheduledStart ? $scheduledStart->getTimestamp() : 0;
                                            $timerUpdatedAtEpoch = (int)($examRow['timer_updated_at_epoch'] ?? 0);
                                            $remainingBaseSeconds = max(0, (int)($examRow['remaining_seconds'] ?? $durationSecs));
                                            $remainingSeconds = $remainingBaseSeconds;
                                            $now = time();
                                            $submissionStatus = $examRow['submission_status'] ?? '';
                                            $isSubmitted = ($submissionStatus === 'Submitted' || $submissionStatus === 'Under Review' || $submissionStatus === 'Graded');

                                            $isRescheduled = (strcasecmp($currentStatus, 'Edited/Rescheduled') === 0 || stripos($currentStatus, 'Rescheduled') !== false);

                $isOnHold = (strcasecmp($currentStatus, 'Cancelled/On Hold') === 0);
                $isRunning = (!$isOnHold && strcasecmp($currentStatus, 'Running') === 0);
                $isCompleted = (strcasecmp($currentStatus, 'Completed') === 0);
                // Legacy teacher status updates changed updated_at but did not
                // set timer_updated_at. Use the status-change timestamp as a
                // fallback so a missing anchor is not interpreted as expired.
                if ($isRunning && $timerUpdatedAtEpoch <= 0) {
                    $timerUpdatedAtEpoch = (int)($examRow['updated_at_epoch'] ?? 0);
                }
                if ($isRunning) {
                                                $remainingSeconds = max(0, $remainingSeconds - max(0, $now - $timerUpdatedAtEpoch));
                                                if ($remainingSeconds === 0) $isCompleted = true;
                                            }
                                            if ($isOnHold && $remainingSeconds === 0) $isCompleted = true;

                                            $hasEntered = $isSubmitted 
                                                || !empty($_SESSION['exam_entered_' . $examId])
                                                || (isset($_COOKIE['exam_entered_' . $examId]) && $_COOKIE['exam_entered_' . $examId] === '1');
                                        ?>
                                            <tr class="exam-row student-exam-row"
                                                id="exam-row-<?php echo $examId; ?>"
                                                data-exam-id="<?php echo $examId; ?>"
                                                data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>"
                                                data-status="<?php echo htmlspecialchars($isCompleted ? 'Completed' : $currentStatus); ?>"
                                                data-start-time="<?php echo htmlspecialchars($start_datetime); ?>"
                                                data-start-timestamp="<?php echo (int)$startTimestamp; ?>"
                                                data-duration="<?php echo $durationMins; ?>"
                                                data-timer-updated-at-epoch="<?php echo $timerUpdatedAtEpoch; ?>"
                                                data-remaining-seconds="<?php echo $remainingBaseSeconds; ?>"
                                                data-server-time-ms="<?php echo $pageServerTimeMs; ?>"
                                                data-is-rescheduled="<?php echo $isRescheduled ? '1' : '0'; ?>"
                                                data-is-submitted="<?php echo ($isSubmitted || $hasEntered) ? '1' : '0'; ?>"
                                                data-is-completed="<?php echo $isCompleted ? '1' : '0'; ?>"
                                                >

                                                <!-- Exam ID from teacher-quesTable-page -->
                                                <td><strong>#<?php echo $examId; ?></strong></td>

                                                <!-- Teacher Name & ID from teacher-quesTable-page -->
                                                <td>
                                                    <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <?php echo htmlspecialchars($examRow['teacher_name'] ?? 'Instructor'); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($examRow['teacher_id'] ?? 'N/A'); ?></td>

                                                <td><strong style="color: #4338ca;"><?php echo htmlspecialchars($examRow['batch'] ?? $batchName); ?></strong></td>
                                                <td><?php echo htmlspecialchars($examRow['subject'] ?? 'N/A'); ?></td>
                                                <td><strong><?php echo htmlspecialchars($examRow['exam_title'] ?? 'N/A'); ?></strong></td>
                                                <td><span class="badge badge-type"><?php echo htmlspecialchars($examRow['exam_type'] ?? 'MCQ'); ?></span></td>
                                                <td>
                                                    <?php
                                                        if ($scheduledStart) {
                                                            echo htmlspecialchars($scheduledStart->format('d/m/Y h:i A') . ' (Asia/Dhaka)');
                                                            echo '<br><small>UTC: ' . htmlspecialchars($scheduledStart->setTimezone($utcTimezone)->format('d/m/Y h:i A')) . '</small>';
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                    ?>
                                                </td>

                                                <!-- Starts in Countdown -->
                                                <td>
                                                    <?php if ($isCompleted): ?>
                                                        <span class="timer-badge start-timer finished">Finished</span>
                                                    <?php elseif ($isOnHold): ?>
                                                        <span class="timer-badge start-timer on-hold">On Hold</span>
                                                    <?php elseif ($isRunning): ?>
                                                        <span class="timer-badge start-timer running">In Progress</span>
                                                    <?php elseif ($startTimestamp > 0 && $now < $startTimestamp): ?>
                                                        <span class="timer-badge start-timer active"><?php echo gmdate('H:i:s', $startTimestamp - $now); ?></span>
                                                    <?php else: ?>
                                                        <span class="timer-badge start-timer ready">Ready to Start</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Duration -->
                                                <td><strong><?php echo $durationMins; ?> min</strong></td>

                                                <!-- Duration Left -->
                                                <td>
                                                    <?php if ($isCompleted): ?>
                                                        <span class="timer-badge duration-timer finished">0</span>
                                                    <?php elseif ($isOnHold): ?>
                                                        <span class="timer-badge duration-timer on-hold"><?php echo gmdate('H:i:s', $remainingSeconds); ?> (On Hold)</span>
                                                    <?php elseif ($isRunning): ?>
                                                        <span class="timer-badge duration-timer active"><?php echo gmdate('H:i:s', $remainingSeconds); ?></span>
                                                    <?php else: ?>
                                                        <span class="timer-badge duration-timer"><?php echo $durationMins; ?> min</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td><strong><?php echo htmlspecialchars($examRow['total_marks'] ?? $examRow['marks'] ?? '0'); ?></strong></td>

                                                <!-- Enrollment Status <td> (only showing exams whose courses status are "enrolled") -->
                                                <td style="vertical-align: middle; text-align: center; background: #fff;">
                                                    <span class="status-badge status-enrolled">
                                                        <?php echo htmlspecialchars($examRow['course_enroll_status'] ?? 'Enrolled'); ?>
                                                    </span>
                                                </td>

                                                <!-- Status Badge -->
                                                <td class="status-cell">
                                                    <?php if ($isCompleted): ?>
                                                        <span class="badge badge-completed"><i class="fa-solid fa-check"></i> Completed</span>
                                                    <?php elseif ($isOnHold): ?>
                                                        <span class="badge badge-hold"><i class="fa-solid fa-pause"></i> On Hold</span>
                                                    <?php elseif ($isRunning): ?>
                                                        <span class="badge badge-running"><span class="pulse-dot"></span> Running</span>
                                                    <?php elseif ($isRescheduled): ?>
                                                        <span class="badge badge-rescheduled"><i class="fa-solid fa-clock-rotate-left"></i> Rescheduled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-published"><i class="fa-regular fa-clock"></i> Published</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Exam Actions -->
                                                <td class="action-cell">
                                                    <div class="action-btn-group">
                                                        <?php if ($isCompleted): ?>
                                                            <button type="button" class="btn btn-finished is-completed" disabled title="Exam duration has ended">
                                                                <i class="fa-solid fa-check-double"></i> Complete
                                                            </button>
                                                        <?php elseif ($isOnHold): ?>
                                                            <button type="button" class="btn btn-on-hold" disabled title="Exam is temporarily on hold">
                                                                <i class="fa-solid fa-pause"></i> On Hold
                                                            </button>
                                                        <?php elseif ($isRunning): ?>
                                                            <?php if ($isSubmitted || $hasEntered): ?>
                                                                <a href="student-giveExam-page.php?exam_id=<?php echo $examId; ?>&mode=revise" class="btn btn-revise" title="Revise submitted responses">
                                                                    <i class="fa-solid fa-pen-to-square"></i> Revise
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="student-giveExam-page.php?exam_id=<?php echo $examId; ?>" class="btn btn-give-exam" title="Take this exam">
                                                                    <i class="fa-solid fa-play"></i> Give Exam
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-not-started" disabled title="Exam has not started yet">
                                                                <i class="fa-regular fa-clock"></i> Not Started
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Enrolled in Batch, but No Exams Scheduled Yet -->
                                        <tr>
                                            <td colspan="15" style="text-align: center; padding: 28px; color: #64748b;">
                                                <i class="fa-regular fa-folder-open" style="font-size: 1.8rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                                <strong>No scheduled exams available for Batch <?php echo htmlspecialchars($batchName); ?>.</strong><br>
                                                <span style="font-size: 0.85rem; color: #94a3b8;">You are enrolled in this batch, but no exams have been scheduled yet.</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card">
                        <h3><?php echo $currentView === 'archived' ? 'No archived batch exam tables found.' : 'No enrolled courses found.'; ?></h3>
                        <p><?php echo $currentView === 'archived' ? 'Tables you archive will appear here safely. You can restore them anytime.' : 'Please enroll in available batches to access your scheduled examinations.'; ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Student Exam Table Scripts -->
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    <script src="../assets/js/student/student-examTable-page.js"></script>
 </body>
</html>
