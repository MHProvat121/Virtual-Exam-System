<?php
// =========================================================================
// student-courseSyllabus-page.php (UPDATED WITH ALL 5 FEATURES)
// Feature 1: Add a search bar at the top down "your enrolled courses" text header
// Feature 2: Add a pin logo button to pin a specific course batch table at the right where the batches name remains to pin the table on top
// Feature 3: Add a archive logo button to archive the table into archives
// Feature 4: Make a archived batch courses button on the top right on which click it will show all the archived tables
// Feature 5: In the archive, tables have restore and permanent delete logo button at the top right in the header
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    if (file_exists(__DIR__ . '/../../src/auth/login-page.php')) {
        header("Location: ../../src/auth/login-page.php");
        exit();
    }
}

// 3. Database Connection
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Initialize Session State for Pinned & Archived Batches
if (!isset($_SESSION['pinned_batches'])) {
    $_SESSION['pinned_batches'] = [];
}
if (!isset($_SESSION['archived_batches'])) {
    $_SESSION['archived_batches'] = [];
}

// 4. FETCH LOGGED-IN STUDENT DETAILS
$student = [];
if ($dbConn) {
    if (isset($_SESSION['email'])) {
        $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
        $stmt_student->execute(['email' => $_SESSION['email']]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if (empty($student) && isset($_SESSION['user_id'])) {
        $stmt_student = $dbConn->prepare("
            SELECT s.* FROM `students` s 
            JOIN `users-table` u ON s.email = u.email 
            WHERE u.id = :uid LIMIT 1
        ");
        $stmt_student->execute(['uid' => $_SESSION['user_id']]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

$studentDbId = $student['id'] ?? 0;
$studentCode = $student['student_id'] ?? '';

// -------------------------------------------------------------------------
// 5. HANDLE ACTIONS (PIN, ARCHIVE, RESTORE, PERMANENT DELETE, COMMENTS)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_batches'])) {
            $_SESSION['pinned_batches'] = array_values(array_diff($_SESSION['pinned_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_batches'][] = $batchName;
        }
        $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
        header("Location: " . $redirectUrl);
        exit();
    }

    // Feature 3: Archive Batch Action
    if ($action === 'archive_batch' && !empty($batchName)) {
        if (!in_array($batchName, $_SESSION['archived_batches'])) {
            $_SESSION['archived_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Feature 5: Restore from Archive Action
    if ($action === 'restore_batch' && !empty($batchName)) {
        $_SESSION['archived_batches'] = array_values(array_diff($_SESSION['archived_batches'], [$batchName]));
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
        exit();
    }
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// -------------------------------------------------------------------------
// 6. FETCH ENROLLED BATCHES & COURSES ONLY (MATCHING student-enroll-page LOGIC)
// -------------------------------------------------------------------------
$studentEnrollments = [];
$enrollmentsByBatch = [];
$enrolledCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];
$enrolledBatches = [];

if ($dbConn && ($studentDbId > 0 || !empty($studentCode))) {
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
        $stmtEnr->execute([$studentDbId, $studentCode ?: (string)$studentDbId]);
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
            $enrolledBatches[] = $bName;
            break;
        }
    }
}
$enrolledBatches = array_values(array_unique($enrolledBatches));

// Fetch batch meta info (Teacher / Course Advisor & Creation date)
$batchMeta = [];
if (!empty($enrolledBatches) && $dbConn) {
    try {
        // 1. Build lookup tables for teachers and admins
        $teachersLookup = [];
        $adminsLookup   = [];

        try {
            $stmtT = $dbConn->query("SELECT `id`, `teacher_id`, `full_name` FROM `teachers`");
            if ($stmtT) {
                while ($tRow = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                    $fn = trim($tRow['full_name'] ?? '');
                    if (!empty($tRow['id'])) {
                        $teachersLookup[strval($tRow['id'])] = $fn;
                    }
                    if (!empty($tRow['teacher_id'])) {
                        $tid = trim($tRow['teacher_id']);
                        $teachersLookup[$tid] = $fn;
                        $teachersLookup[strtolower($tid)] = $fn;
                    }
                    if ($fn !== '') {
                        $teachersLookup[strtolower($fn)] = $fn;
                    }
                }
            }
        } catch (Exception $e) {}

        try {
            $stmtA = $dbConn->query("SELECT `id`, `admin_id`, `full_name` FROM `admins`");
            if ($stmtA) {
                while ($aRow = $stmtA->fetch(PDO::FETCH_ASSOC)) {
                    $fn = trim($aRow['full_name'] ?? '');
                    if (!empty($aRow['id'])) {
                        $adminsLookup[strval($aRow['id'])] = $fn;
                    }
                    if (!empty($aRow['admin_id'])) {
                        $aid = trim($aRow['admin_id']);
                        $adminsLookup[$aid] = $fn;
                        $adminsLookup[strtolower($aid)] = $fn;
                    }
                    if ($fn !== '') {
                        $adminsLookup[strtolower($fn)] = $fn;
                    }
                }
            }
        } catch (Exception $e) {}

        // 2. Fetch all batch records for enrolled batches sorted by id DESC
        $inClauseMeta = implode(',', array_fill(0, count($enrolledBatches), '?'));
        $stmtMeta = $dbConn->prepare("
            SELECT 
                b.id,
                b.batch, 
                b.course_code,
                b.subject,
                b.exam_title,
                b.created_at, 
                b.teacher_id,
                COALESCE(t.full_name, adm.full_name) AS joined_teacher_name
            FROM `batches` b 
            LEFT JOIN `teachers` t 
                ON (b.teacher_id COLLATE utf8mb4_unicode_ci = t.teacher_id COLLATE utf8mb4_unicode_ci 
                 OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(t.id AS CHAR) COLLATE utf8mb4_unicode_ci
                 OR b.teacher_id COLLATE utf8mb4_unicode_ci = t.full_name COLLATE utf8mb4_unicode_ci) 
            LEFT JOIN `admins` adm 
                ON (b.teacher_id COLLATE utf8mb4_unicode_ci = adm.admin_id COLLATE utf8mb4_unicode_ci 
                 OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(adm.id AS CHAR) COLLATE utf8mb4_unicode_ci
                 OR b.teacher_id COLLATE utf8mb4_unicode_ci = adm.full_name COLLATE utf8mb4_unicode_ci
                 OR (LOWER(TRIM(b.teacher_id)) = 'admin' AND adm.id = 1)) 
            WHERE b.batch IN ($inClauseMeta)
            ORDER BY b.batch ASC, b.id DESC
        ");
        $stmtMeta->execute($enrolledBatches);

        $batchRowsMap = [];
        while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
            $b = $m['batch'];
            $batchRowsMap[$b][] = $m;
            $norm = strtolower(trim($b));
            if ($norm !== $b) {
                $batchRowsMap[$norm][] = $m;
            }
        }

        // 3. Fallback teacher names from exam_header and exam_details
        $examHeaderTeacherByBatch = [];
        $examDetailsTeacherByBatch = [];
        try {
            $stmtEH = $dbConn->prepare("SELECT `batch`, `teacher_name` FROM `exam_header` WHERE `batch` IN ($inClauseMeta) AND `teacher_name` IS NOT NULL AND `teacher_name` != '' ORDER BY `id` ASC");
            $stmtEH->execute($enrolledBatches);
            while ($eh = $stmtEH->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($examHeaderTeacherByBatch[$eh['batch']])) {
                    $examHeaderTeacherByBatch[$eh['batch']] = trim($eh['teacher_name']);
                }
                $n = strtolower(trim($eh['batch']));
                if (!isset($examHeaderTeacherByBatch[$n])) {
                    $examHeaderTeacherByBatch[$n] = trim($eh['teacher_name']);
                }
            }
        } catch (Exception $e) {}

        try {
            $stmtED = $dbConn->prepare("SELECT `batch`, `teacher_name` FROM `exam_details` WHERE `batch` IN ($inClauseMeta) AND `teacher_name` IS NOT NULL AND `teacher_name` != '' ORDER BY `id` ASC");
            $stmtED->execute($enrolledBatches);
            while ($ed = $stmtED->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($examDetailsTeacherByBatch[$ed['batch']])) {
                    $examDetailsTeacherByBatch[$ed['batch']] = trim($ed['teacher_name']);
                }
                $n = strtolower(trim($ed['batch']));
                if (!isset($examDetailsTeacherByBatch[$n])) {
                    $examDetailsTeacherByBatch[$n] = trim($ed['teacher_name']);
                }
            }
        } catch (Exception $e) {}

        // 4. Resolve advisor record and teacher name for each batch
        foreach ($enrolledBatches as $bName) {
            $rows = $batchRowsMap[$bName] ?? $batchRowsMap[strtolower(trim($bName))] ?? [];
            $advisorRecord = null;
            if (!empty($rows)) {
                // Priority 1: dedicated header row with empty subject & course_code (most recent from id DESC)
                foreach ($rows as $r) {
                    if (empty(trim($r['subject'] ?? '')) && empty(trim($r['course_code'] ?? ''))) {
                        $advisorRecord = $r;
                        break;
                    }
                }
                // Priority 2: empty subject row
                if (!$advisorRecord) {
                    foreach ($rows as $r) {
                        if (empty(trim($r['subject'] ?? ''))) {
                            $advisorRecord = $r;
                            break;
                        }
                    }
                }
                // Priority 3: earliest creation row of this batch (last element in array)
                if (!$advisorRecord) {
                    $advisorRecord = $rows[count($rows) - 1];
                }
            }

            $resolvedTeacherName = '';
            $rawTeacherId = trim($advisorRecord['teacher_id'] ?? '');

            if (!empty($advisorRecord['joined_teacher_name']) && $advisorRecord['joined_teacher_name'] !== 'Unknown Teacher') {
                $resolvedTeacherName = $advisorRecord['joined_teacher_name'];
            }

            if (empty($resolvedTeacherName) && $rawTeacherId !== '') {
                if (isset($teachersLookup[$rawTeacherId])) {
                    $resolvedTeacherName = $teachersLookup[$rawTeacherId];
                } elseif (isset($teachersLookup[strtolower($rawTeacherId)])) {
                    $resolvedTeacherName = $teachersLookup[strtolower($rawTeacherId)];
                }
            }

            if (empty($resolvedTeacherName) && $rawTeacherId !== '') {
                if (isset($adminsLookup[$rawTeacherId])) {
                    $resolvedTeacherName = $adminsLookup[$rawTeacherId];
                } elseif (isset($adminsLookup[strtolower($rawTeacherId)])) {
                    $resolvedTeacherName = $adminsLookup[strtolower($rawTeacherId)];
                } elseif (strtolower($rawTeacherId) === 'admin') {
                    $resolvedTeacherName = !empty($adminsLookup) ? reset($adminsLookup) : 'Administrator';
                }
            }

            if (empty($resolvedTeacherName)) {
                $normB = strtolower(trim($bName));
                if (!empty($examHeaderTeacherByBatch[$bName])) {
                    $resolvedTeacherName = $examHeaderTeacherByBatch[$bName];
                } elseif (!empty($examHeaderTeacherByBatch[$normB])) {
                    $resolvedTeacherName = $examHeaderTeacherByBatch[$normB];
                } elseif (!empty($examDetailsTeacherByBatch[$bName])) {
                    $resolvedTeacherName = $examDetailsTeacherByBatch[$bName];
                } elseif (!empty($examDetailsTeacherByBatch[$normB])) {
                    $resolvedTeacherName = $examDetailsTeacherByBatch[$normB];
                }
            }

            if (empty($resolvedTeacherName)) {
                if ($rawTeacherId !== '' && !is_numeric($rawTeacherId)) {
                    $resolvedTeacherName = $rawTeacherId;
                } else {
                    $resolvedTeacherName = 'Course Advisor';
                }
            }

            $createdAt = !empty($advisorRecord['created_at']) ? $advisorRecord['created_at'] : null;

            $metaItem = [
                'batch'        => $bName,
                'teacher_id'   => $rawTeacherId,
                'teacher_name' => $resolvedTeacherName,
                'created_at'   => $createdAt
            ];

            $batchMeta[$bName] = $metaItem;
            $batchMeta[strtolower(trim($bName))] = $metaItem;
        }
    } catch (Exception $e) {
        error_log("Batch meta query error in student course syllabus: " . $e->getMessage());
    }
}

$groupedExams = [];
if (!empty($enrolledBatches) && $dbConn) {
    $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
    $stmt = $dbConn->prepare("
        SELECT 
            b.*, 
            COALESCE(t.full_name, adm.full_name, 'Unknown Teacher') AS teacher_full_name, 
            COALESCE(t.teacher_id, adm.admin_id, b.teacher_id, 'N/A') AS display_teacher_id 
        FROM `batches` b 
        LEFT JOIN `teachers` t 
            ON (b.teacher_id COLLATE utf8mb4_unicode_ci = t.teacher_id COLLATE utf8mb4_unicode_ci 
             OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(t.id AS CHAR) COLLATE utf8mb4_unicode_ci) 
        LEFT JOIN `admins` adm 
            ON (b.teacher_id COLLATE utf8mb4_unicode_ci = adm.admin_id COLLATE utf8mb4_unicode_ci 
             OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(adm.id AS CHAR) COLLATE utf8mb4_unicode_ci) 
        WHERE b.batch IN ($inClause)
        ORDER BY b.batch ASC, b.id DESC
    ");
    $stmt->execute($enrolledBatches);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
        $bName = $row['batch'];
        $courseCode = $row['course_code'] ?? '';

        $batchEnrolledList = $enrolledCourseCodesByBatch[$bName] ?? [];
        $batchWithdrawnList = $withdrawnCourseCodesByBatch[$bName] ?? [];
        $hasEnrolledList = !empty($batchEnrolledList);

        $enrDetails = $studentEnrollments[$bName] ?? [];
        $batchEnrollmentList = $enrollmentsByBatch[$bName] ?? [];

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

        // 1. Is this specific course withdrawn?
        if (!empty($batchWithdrawnList)) {
            $isThisCourseWithdrawn = in_array($courseCode, $batchWithdrawnList);
        } else {
            $isThisCourseWithdrawn = ($currentWithdrawStatus === 'Withdrawn');
        }

        // 2. Is this specific course enrolled?
        if ($isThisCourseWithdrawn) {
            $isThisCourseEnrolled = false;
        } elseif ($hasEnrolledList) {
            $isThisCourseEnrolled = in_array($courseCode, $batchEnrolledList);
        } else {
            $isThisCourseEnrolled = ($currentStatus === 'Enrolled');
        }

        // 3. Course active enrollment verification (Must match status enroll from student-enroll-page)
        $isCourseActiveEnrolled = $isThisCourseEnrolled && !$isThisCourseWithdrawn;

        // ONLY show courses that have status 'Enrolled' from student-enroll-page
        if ($isCourseActiveEnrolled) {
            $row['course_enroll_status'] = 'Enrolled';
            $row['course_enroll_class']  = 'status-enrolled';
            $groupedExams[$bName][] = $row;
        }
    }
}

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedExams as $batchName => $exams) {
    if (in_array($batchName, $_SESSION['archived_batches'])) {
        $archivedBatches[$batchName] = $exams;
    } else {
        $activeBatches[$batchName] = $exams;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top as well
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Select which batch list to render
$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Courses' : 'Enrolled Courses & Syllabus'; ?> - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-courseSyllabus-page.css">
    <style>
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 14px 16px; border-top: 1px solid #e2e8f0; vertical-align: middle; word-wrap: break-word; }
        th { background: #f8fafc; font-size: 13px; color: #475569; border-bottom: 2px solid #e2e8f0; text-align: left; }
        td .badge-subject { display: inline-block; max-width: 100%; word-break: break-word; white-space: normal; background-color: #e0f2fe; color: #0369a1; padding: 6px 10px; border-radius: 6px; font-size: 13px; font-weight: 500; }
        .btn-comment { background-color: #0266cc; color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .btn-comment:hover { background-color: #024da1; }
        .start-btn { display: inline-block; padding: 6px 14px; background-color: #059669; color: #fff; text-decoration: none; border-radius: 5px; font-size: 13px; font-weight: 600; }
        
        /* Feature 1: Search bar */
        .search-container { margin: 16px 0 24px 0; display: flex; gap: 12px; }
        .search-input-wrapper { position: relative; width: 100%; max-width: 600px; }
        .search-input-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .search-input-wrapper input { width: 100%; padding: 10px 14px 10px 38px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .search-input-wrapper input:focus { border-color: #0266cc; box-shadow: 0 0 0 3px rgba(2,102,204,0.12); }

        /* Feature 4: Archived Batch Courses Button */
        .btn-archive-page { background-color: #475569; color: #ffffff; text-decoration: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 7px; transition: background-color 0.2s; }
        .btn-archive-page:hover { background-color: #334155; }
        .badge-count { background: #0266cc; color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; }

        /* Feature 2: Pin Logo Button */
        .btn-pin { background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
        .btn-pin:hover { border-color: #0266cc; color: #0266cc; }
        .btn-pin.pinned { background: #0266cc; color: #fff; border-color: #0266cc; }
        .btn-pin.pinned:hover { background: #0252a3; }

        /* Feature 3: Archive Logo Button */
        .btn-archive-table { background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
        .btn-archive-table:hover { background: #fef3c7; border-color: #d97706; color: #b45309; }

        /* Feature 5: Restore Button in Archive */
        .btn-restore-table { background: #059669; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .btn-restore-table:hover { background: #047857; }

        /* Status Badges matching student-enroll-page */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .status-enrolled {
            background-color: #d1fae5;
            color: #059669;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #d97706;
        }
        .status-unenrolled,
        .status-none {
            background-color: #e2e8f0;
            color: #475569;
        }
        .status-cancelled,
        .status-withdrawn {
            background-color: #fee2e2;
            color: #dc2626;
        }
    </style>
</head>
<body>
    <div class="main-layout">
        <?php if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) { require_once __DIR__ . '/student-header-sidebar-section.php'; } ?>

        <main class="dashboard">
            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                <div>
                    <?php if ($currentView === 'archived'): ?>
                        <h1><i class="fa-solid fa-box-archive" style="color: #d97706;"></i> Archived Batch Courses</h1>
                        <p style="color: #64748b; font-size: 13px; margin-top: 4px;">Access your archived course tables, pin high-priority tables, or restore them back to enrolled courses.</p>
                    <?php else: ?>
                        <h1><i class="fa-solid fa-book-open-reader" style="color: #0266cc;"></i> Your Enrolled Courses</h1>
                    <?php endif; ?>
                </div>

                <!-- Feature 4: Archived Batch Courses Button on Top Right -->
                <div class="header-actions">
                    <?php if ($currentView === 'archived'): ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-archive-page" style="background:#0266cc;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Enrolled Courses
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?view=archived'; ?>" class="btn-archive-page">
                            <i class="fa-solid fa-box-archive"></i> Archived Batch Courses
                            <span class="badge-count"><?php echo count($archivedBatches); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Feature 1: Search Bar at the Top Down "Your Enrolled Courses" Text Header -->
            <div class="search-container">
                <div class="search-input-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="courseBatchSearch" placeholder="<?php echo $currentView === 'archived' ? 'Search archived batches by name, subject, exam, teacher...' : 'Search enrolled courses by batch, subject, exam title, or teacher...'; ?>" onkeyup="filterCourseBatches()">
                </div>
            </div>

            <!-- Table Section Container -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $exams): 
                    $meta = $batchMeta[$batchName] ?? $batchMeta[strtolower(trim($batchName))] ?? [];
                    $batchTeacherName = !empty($meta['teacher_name']) && $meta['teacher_name'] !== 'Unknown Teacher' 
                        ? $meta['teacher_name'] 
                        : (!empty($exams[0]['teacher_full_name']) && $exams[0]['teacher_full_name'] !== 'Unknown Teacher' ? $exams[0]['teacher_full_name'] : 'Course Advisor');
                    $batchCreationDate = !empty($meta['created_at']) 
                        ? date('M d, Y', strtotime($meta['created_at'])) 
                        : (!empty($exams[0]['created_at']) ? date('M d, Y', strtotime($exams[0]['created_at'])) : 'N/A');
                    $isPinned = in_array($batchName, $_SESSION['pinned_batches']);
                ?>
                    <div class="batch-card" data-batch="<?php echo strtolower(htmlspecialchars($batchName)); ?>" style="background:#fff; border-radius:12px; border: 1px solid <?php echo $isPinned ? '#93c5fd' : '#e2e8f0'; ?>; margin-bottom: 25px; overflow:hidden;">
                        <div class="batch-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px; background: <?php echo $isPinned ? '#eff6ff' : '#f8fafc'; ?>; border-bottom: 1px solid #e2e8f0;">
                            <div class="batch-title-info">
                                <h2 style="font-size: 18px; color: #1e293b; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-users-rectangle"></i> Batch: <?php echo htmlspecialchars($batchName); ?>
                                    <?php if ($isPinned): ?>
                                        <span style="font-size:11px; background:#0266cc; color:#fff; padding:2px 8px; border-radius:12px;"><i class="fa-solid fa-thumbtack"></i> Pinned</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="batch-meta-info" style="font-size: 13px; color: #64748b; margin-top: 4px;">
                                    <span><i class="fa-solid fa-user" style="color: #0266cc;"></i> <strong>Teacher:</strong> <?php echo htmlspecialchars($batchTeacherName); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-calendar-days" style="color: #059669;"></i> <strong>Created:</strong> <?php echo htmlspecialchars($batchCreationDate); ?></span>
                                </div>
                            </div>

                            <!-- Right Side Action Buttons in Header -->
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($currentView !== 'archived'): ?>
                                    <!-- Feature 2: Pin Logo Button -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table on top'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <!-- Feature 3: Archive Logo Button -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archive batch table: <?php echo htmlspecialchars($batchName); ?>?');">
                                        <input type="hidden" name="action" value="archive_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-archive-table" title="Archive table into archives">
                                            <i class="fa-solid fa-box-archive"></i> Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Archive View: Pin Button (Kept) -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <input type="hidden" name="current_view" value="archived">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin archived table' : 'Pin table to top of archives'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <!-- Archive View: Restore Button (Kept, Delete button removed!) -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="restore_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-restore-table" title="Restore back to enrolled courses">
                                            <i class="fa-solid fa-rotate-left"></i> Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table>
                                <colgroup>
                                    <col style="width: 8%;">
                                    <col style="width: 15%;">
                                    <col style="width: 9%;">
                                    <col style="width: 10%;">
                                    <col style="width: 14%;">
                                    <col style="width: 7%;">
                                    <col style="width: 13%;">
                                    <col style="width: 10%;">
                                    <col style="width: 6%;">
                                    <col style="width: 8%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Teacher Name</th>
                                        <th>Teacher ID</th>
                                        <th>Course Code</th>
                                        <th>Subject</th>
                                        <th>Credit</th>
                                        <th>Exam Title</th>
                                        <th style="text-align: center;">Enrollment Status</th>
                                        <th>Syllabus</th>
                                        <th>Comments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exams as $exam): if (empty($exam['subject'])) continue; ?>
                                    <tr class="exam-row" data-course-code="<?php echo htmlspecialchars($exam['course_code'] ?? ''); ?>">
                                        <td><strong><?php echo htmlspecialchars($exam['batch']); ?></strong></td>
                                        <td>
                                            <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                            <?php echo htmlspecialchars($exam['teacher_full_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($exam['display_teacher_id'] ?? 'N/A'); ?></td>
                                        <td><strong style="color: #4338ca;"><?php echo htmlspecialchars(!empty($exam['course_code']) ? $exam['course_code'] : 'N/A'); ?></strong></td>
                                        <td><span class="badge-subject"><?php echo htmlspecialchars($exam['subject'] ?? 'N/A'); ?></span></td>
                                        <td><span class="badge-credit" style="background:#f1f5f9; color:#334155; padding:4px 8px; border-radius:4px; font-weight:600; font-size:13px; border:1px solid #cbd5e1;"><?php echo htmlspecialchars(!empty($exam['credit']) ? $exam['credit'] : 'N/A'); ?></span></td>

                                        <td><strong><?php echo htmlspecialchars($exam['exam_title'] ?? 'N/A'); ?></strong></td>

                                        <!-- Course Enrollment Status <td> (only status enroll <td> from student-enroll-page) -->
                                        <td style="vertical-align: middle; text-align: center; background: #fff;">
                                            <span class="status-badge <?php echo $exam['course_enroll_class'] ?? 'status-enrolled'; ?>">
                                                <?php echo htmlspecialchars($exam['course_enroll_status'] ?? 'Enrolled'); ?>
                                            </span>
                                        </td>
                                        
                                        <td>
                                            <a href="student-viewSyllabus-page.php?id=<?php echo $exam['id']; ?>" class="start-btn"><i class="fa-solid fa-eye"></i> View</a>
                                        </td>
                                        
                                        <td>
                                            <a href="view-syllabusComment-page.php?id=<?php echo $exam['id']; ?>" class="btn-comment">                                               <i class="fa-solid fa-comments"></i> View Comments
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card" style="text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <h3><?php echo $currentView === 'archived' ? 'No archived batch courses found.' : 'No enrolled courses found.'; ?></h3>
                        <p style="color:#64748b;"><?php echo $currentView === 'archived' ? 'Tables you archive will appear here safely.' : 'Please enroll in available batches to access your course syllabus.'; ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    <script>
        // Feature 1: Search filter for course batches
        function filterCourseBatches() {
            const term = document.getElementById('courseBatchSearch').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.batch-card');
            cards.forEach(card => {
                const text = card.innerText.toLowerCase();
                if (text.includes(term)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>