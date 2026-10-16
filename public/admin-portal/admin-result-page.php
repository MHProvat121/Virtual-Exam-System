<?php
// =========================================================================
// student-result-page.php
// Student Examination Results & Grade Sheets grouped by Enrolled Courses & Batches
// Designed with the identical architecture and visual standards of student-examTable-page.php
// Feature 1: Live search bar below the header to filter batches, course codes, subjects & grades
// Feature 2: Pin button on each batch header to pin result tables to the top
// Feature 3: Archive button on each batch header to archive result tables
// Feature 4: Archived Batch Results button on top right with count badge
// Feature 5: In archive section: Restore and Pin buttons only
// ENROLLMENT FILTER: Displays separate batch result cards for each enrolled batch from student_enrollments.
// SCHEMA ALIGNED: Follows exam_submissions, exam_header, batches, students and student_enrollments tables.
// Includes <th>Course Code</th> right after <th>Batch</th>.
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Session Gatekeeper: Restrict access to authorized administrators and teachers
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}
if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator', 'teacher', 'student'])) {
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
$utcTimezone = new DateTimeZone('UTC');

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

// Initialize Session State for Pinned & Archived Result Batches
if (!isset($_SESSION['pinned_result_batches'])) {
    $_SESSION['pinned_result_batches'] = [];
}
if (!isset($_SESSION['archived_result_batches'])) {
    $_SESSION['archived_result_batches'] = [];
}

// 4. FETCH LOGGED-IN USER & ROLE DETAILS
$is_admin = (isset($_SESSION['user_role']) && in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator'])) || isset($_SESSION['admin_id']);
$logged_admin_id   = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? '';
$logged_admin_name = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? ($is_admin ? 'Administrator' : 'Student');

$student = [];
if (!$is_admin) {
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
}

$studentDbId         = (int)($student['id'] ?? ($is_admin ? 0 : ($_SESSION['user_id'] ?? 0)));
$logged_student_id   = !empty($student['student_id']) ? $student['student_id'] : ($is_admin ? '' : ($_SESSION['student_id'] ?? (string)$studentDbId));
$logged_student_name = !empty($student['full_name']) ? $student['full_name'] : ($is_admin ? $logged_admin_name : ($_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Student'));
$logged_batch        = !empty($student['batch']) ? $student['batch'] : ($_SESSION['batch'] ?? '');

// -------------------------------------------------------------------------
// 5. HANDLE ACTIONS (PIN, ARCHIVE, RESTORE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_result_batches'])) {
            $_SESSION['pinned_result_batches'] = array_values(array_diff($_SESSION['pinned_result_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_result_batches'][] = $batchName;
        }
        $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
        header("Location: " . $redirectUrl);
        exit();
    }

    // Feature 3: Archive Batch Action
    if ($action === 'archive_batch' && !empty($batchName)) {
        if (!in_array($batchName, $_SESSION['archived_result_batches'])) {
            $_SESSION['archived_result_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Feature 5: Restore from Archive Action
    if ($action === 'restore_batch' && !empty($batchName)) {
        $_SESSION['archived_result_batches'] = array_values(array_diff($_SESSION['archived_result_batches'], [$batchName]));
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
        exit();
    }
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// -------------------------------------------------------------------------
// 6. FETCH ENROLLED BATCHES & COURSES (MATCHING student-examTable-page LOGIC)
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

// Identify candidate batches (All batches for admin, enrolled batches for student)
if ($is_admin) {
    $adminBatches = [];
    if ($dbConn) {
        try {
            $stmtB = $dbConn->query("SELECT DISTINCT `batch` FROM `batches` WHERE `batch` IS NOT NULL AND TRIM(`batch`) != '' ORDER BY `batch` ASC");
            if ($stmtB) {
                while ($bRow = $stmtB->fetch(PDO::FETCH_ASSOC)) {
                    $bName = trim($bRow['batch']);
                    if ($bName !== '') $adminBatches[] = $bName;
                }
            }
            $stmtE = $dbConn->query("SELECT DISTINCT `batch` FROM `exam_header` WHERE `batch` IS NOT NULL AND TRIM(`batch`) != '' ORDER BY `batch` ASC");
            if ($stmtE) {
                while ($eRow = $stmtE->fetch(PDO::FETCH_ASSOC)) {
                    $bName = trim($eRow['batch']);
                    if ($bName !== '') $adminBatches[] = $bName;
                }
            }
        } catch (Exception $e) {}
    }
    $enrolledBatches = array_values(array_unique(array_filter(array_map('trim', $adminBatches))));
} else {
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
}

// Resolve batch names case-insensitively
$enrolledBatchKeyByNormalized = [];
foreach ($enrolledBatches as $enrolledBatchName) {
    $enrolledBatchKeyByNormalized[strtolower(trim($enrolledBatchName))] = $enrolledBatchName;
}

// Initialize grouped results structure: EACH enrolled batch receives its own table
$groupedResultsByBatch = [];
foreach ($enrolledBatches as $bName) {
    $groupedResultsByBatch[$bName] = [];
}

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
        error_log("Batch meta query error in student results: " . $e->getMessage());
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
        error_log("Batch courses map query error in results: " . $e->getMessage());
    }
}

// Helper function to calculate academic letter grade and grade point
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

// Global Summary Stats for Logged-in Student
$totalExamsEnrolled = 0;
$totalExamsSubmitted = 0;
$totalExamsGraded = 0;
$cumulativeObtained = 0.0;
$cumulativeTotal = 0.0;

// Fetch real exams from `exam_header` and joined submission results from `exam_submissions`
if (!empty($enrolledBatches) && $dbConn) {
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
                       e.exam_time,
                       e.duration,
                       COALESCE(e.marks, 0) AS total_marks,
                       e.status AS exam_status,
                       e.created_at AS exam_created_at,
                       s.id AS submission_id,
                       s.student_id,
                       COALESCE(s.student_name, st.full_name) AS student_name,
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
                LEFT JOIN `exam_submissions` s ON e.id = s.exam_id
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
        }
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

            if (!$matchedCourse && count($coursesInBatch) === 1) {
                $matchedCourse = $coursesInBatch[0];
            }

            // The saved exam course code is the authoritative link
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

            // 2. Verify Course-level Enrollment Status
            $batchEnrolledList  = $enrolledCourseCodesByBatch[$b] ?? [];
            $batchWithdrawnList = $withdrawnCourseCodesByBatch[$b] ?? [];
            $hasEnrolledList    = !empty($batchEnrolledList);
            $enrDetails         = $studentEnrollments[$b] ?? [];
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

            $isCourseActiveEnrolled = $is_admin ? true : ($isThisCourseEnrolled && !$isThisCourseWithdrawn);

            if ($isCourseActiveEnrolled) {
                $row['course_code'] = !empty($matchedCourseCode) ? $matchedCourseCode : (!empty($row['course_code']) ? $row['course_code'] : 'N/A');
                $row['course_enroll_status'] = 'Enrolled';
                if (empty($row['teacher_name']) && !empty($matchedCourse['teacher_id'])) {
                    $row['teacher_name'] = $matchedCourse['teacher_id'];
                }

                // Compute Evaluation & Grade Details
                $subStatus = $row['submission_status'] ?? '';
                $totMarks  = floatval($row['total_marks'] ?? 0);
                $obtMarks  = floatval($row['marks_obtained'] ?? 0);

                $totalExamsEnrolled++;
                if (!empty($row['submission_id']) && ($row['attempt_status'] ?? '') !== 'In Progress') {
                    $totalExamsSubmitted++;
                }

                if ($subStatus === 'Graded') {
                    $totalExamsGraded++;
                    $cumulativeObtained += $obtMarks;
                    $cumulativeTotal    += $totMarks;
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
        error_log("Results fetch error: " . $e->getMessage());
    }
}

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedResultsByBatch as $batchName => $results) {
    if (in_array($batchName, $_SESSION['archived_result_batches'])) {
        $archivedBatches[$batchName] = $results;
    } else {
        $activeBatches[$batchName] = $results;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_result_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_result_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_result_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_result_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Select which batch list to render
$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
$archivedCount  = count($archivedBatches);

$overallAvgPercentage = ($cumulativeTotal > 0) ? round(($cumulativeObtained / $cumulativeTotal) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Results' : 'Examination Results & Grades'; ?> - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-result-page.css">
</head>

<body data-user-id="<?php echo htmlspecialchars($logged_admin_id); ?>">
    <div class="main-layout">

        <!-- HEADER AND SIDEBAR (ADMIN / STUDENT) -->
        <?php 
            if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
                require_once __DIR__ . '/admin-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
                require_once __DIR__ . '/student-header-sidebar-section.php'; 
            }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <?php if ($currentView === 'archived'): ?>
                        <h1><i class="fa-solid fa-box-archive" style="color: #d97706;"></i> Archived Batch Results</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            Viewing archived course examination result sheets. You can pin priority tables or restore them back to your active results.
                        </p>
                    <?php else: ?>
                        <h1><i class="fa-solid fa-chart-line" style="color: #0266cc;"></i> Examination Results & Grade Sheets</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            <?php echo $is_admin ? 'Administrator' : 'Student'; ?>: <strong><?php echo htmlspecialchars($is_admin ? $logged_admin_name : $logged_student_name); ?></strong> (<?php echo htmlspecialchars(($is_admin ? $logged_admin_id : $logged_student_id) ?: 'Authorized'); ?>) &bull; Managed Batches: <strong><?php echo !empty($enrolledBatches) ? htmlspecialchars(implode(', ', $enrolledBatches)) : 'None'; ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Feature 4: Archived Batch Results Button on Top Right -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($currentView === 'archived'): ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-archive-page" style="background:#0266cc;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Active Batches
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?view=archived'; ?>" class="btn-archive-page">
                            <i class="fa-solid fa-box-archive"></i> Archived Batch Results
                            <?php if ($archivedCount > 0): ?>
                                <span class="badge-count"><?php echo $archivedCount; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary KPI Metric Cards (Only shown in Active View) -->
            <?php if ($currentView !== 'archived'): ?>
                <div class="stats-overview-grid">
                    <div class="stat-metric-card">
                        <div class="stat-metric-icon stat-icon-scheduled">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Total Scheduled</h4>
                            <div class="metric-value"><?php echo $totalExamsEnrolled; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon stat-icon-submitted">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Submitted</h4>
                            <div class="metric-value"><?php echo $totalExamsSubmitted; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon stat-icon-graded">
                            <i class="fa-solid fa-medal"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Graded & Published</h4>
                            <div class="metric-value"><?php echo $totalExamsGraded; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon stat-icon-average">
                            <i class="fa-solid fa-percent"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Overall Average</h4>
                            <div class="metric-value"><?php echo $totalExamsGraded > 0 ? $overallAvgPercentage . '%' : 'N/A'; ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CONTROLS ROW (STATUS TABS + SEARCH) - PROTOTYPED FROM admin-manageEnroll-page -->
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
                        placeholder="<?php echo $currentView === 'archived' ? 'Search archived results by batch, course code, student, subject, or grade...' : 'Search results by batch, student, course code, subject, title, teacher, or grade...'; ?>"
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
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $results): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_result_batches']);
                    $meta = $batchMeta[$batchName] ?? $batchMeta[strtolower(trim($batchName))] ?? [];
                    $teacherName = !empty($meta['teacher_name']) && $meta['teacher_name'] !== 'Unknown Teacher' 
                        ? $meta['teacher_name'] 
                        : (!empty($results[0]['teacher_name']) && $results[0]['teacher_name'] !== 'Unknown Teacher' ? $results[0]['teacher_name'] : 'Course Advisor');
                    $resultsCount = count($results);
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
                                <div class="batch-meta-info" style="font-size: 13px; color: #64748b; margin-top: 4px;">
                                    <span><i class="fa-solid fa-user-tie" style="color: #0266cc;"></i> <strong>Teacher:</strong> <?php echo htmlspecialchars($teacherName); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-square-poll-vertical" style="color: #059669;"></i> <strong>Evaluated Assessments:</strong> <?php echo $resultsCount; ?></span>
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
                                    <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Archive result table for batch: <?php echo htmlspecialchars(addslashes($batchName)); ?>?');">
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
                                        <button type="submit" class="btn-restore-table" title="Restore back to enrolled results">
                                            <i class="fa-solid fa-rotate-left"></i> Restore
                                        </button>
                                    </form>
                                <?php endif; ?>
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
                                        <th style="text-align: center;">Result Action</th>
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
                                            $hasSubmission= ($subId > 0);

                                            $rawDate = trim($resultRow['exam_date'] ?? '');
                                            $dateFormatted = formatStudentExamScheduleDhaka($rawDate, $resultRow['exam_time'] ?? '');
                                            $rawSubmittedAt = trim($resultRow['submitted_at'] ?? '');
                                            $submittedDhakaFormatted = !empty($rawSubmittedAt) ? formatStudentResultDhaka($rawSubmittedAt, 'd/m/Y h:i A') : 'N/A';
                                            $feedbackText = trim($resultRow['teacher_feedback'] ?? '');

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

                                                <!-- Course Code (Inserted right after Student ID) -->
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

                                                <!-- Result Actions -->
                                                <td class="action-cell" style="text-align: center;">
                                                    <div class="action-btn-group" style="justify-content: center;">
                                                        <?php if ($isParticipating): ?>
                                                            <button type="button" class="btn btn-not-started" disabled title="Your exam attempt is in progress">
                                                                <i class="fa-solid fa-hourglass-half"></i> In Progress
                                                            </button>
                                                        <?php elseif ($hasSubmission): ?>
                                                            <a href="admin-viewAnswerSheet-page.php?exam_id=<?php echo $examId; ?>&submission_id=<?php echo $subId; ?>&student_id=<?php echo urlencode($rowStudentId); ?>" class="btn btn-view-result" title="View Full Detailed Result Sheet">
                                                                <i class="fa-solid fa-square-poll-vertical"></i> View Sheet
                                                            </a>
                                                            <?php if (!empty($feedbackText)): ?>
                                                                <button type="button" 
                                                                        class="btn-feedback-quick" 
                                                                        title="View Evaluator Remarks"
                                                                        onclick="showFeedbackModal('<?php echo htmlspecialchars(addslashes($resultRow['exam_title'])); ?>', '<?php echo htmlspecialchars(addslashes($rowTeacherName)); ?>', '<?php echo htmlspecialchars(addslashes($feedbackText)); ?>', '<?php echo htmlspecialchars($resultRow['calculated_grade']); ?>', '<?php echo number_format($obtMarks, 2); ?> / <?php echo number_format($totMarks, 2); ?>')">
                                                                    <i class="fa-regular fa-comment-dots"></i> Remarks
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-not-started" disabled title="No submission found for this exam">
                                                                <i class="fa-solid fa-ban"></i> No Submission
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Enrolled in Batch, but No Exam Results Found -->
                                        <tr>
                                            <td colspan="18" style="text-align: center; padding: 28px; color: #64748b;">
                                                <i class="fa-regular fa-folder-open" style="font-size: 1.8rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                                <strong>No examination results available for Batch <?php echo htmlspecialchars($batchName); ?>.</strong><br>
                                                <span style="font-size: 0.85rem; color: #94a3b8;"><?php echo $is_admin ? 'No student assessments have been submitted or graded yet for this batch.' : 'You are enrolled in this batch, but no assessments have been graded or published yet.'; ?></span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card">
                        <h3><?php echo $currentView === 'archived' ? 'No archived batch result tables found.' : ($is_admin ? 'No batch result tables found.' : 'No enrolled courses found.'); ?></h3>
                        <p><?php echo $currentView === 'archived' ? 'Result tables you archive will appear here safely. You can restore them anytime.' : ($is_admin ? 'Batch result tables will appear here once exams and assessments are available.' : 'Please enroll in available batches to access your examination results.'); ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Quick Feedback / Remarks Modal -->
    <div id="feedbackModalBackdrop" class="result-modal-backdrop" onclick="closeFeedbackModal()">
        <div class="result-modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalExamTitle" class="modal-title">
                    <i class="fa-solid fa-comments" style="color: #0266cc;"></i> Teacher Feedback
                </h3>
                <button type="button" onclick="closeFeedbackModal()" class="modal-close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-kpi-grid">
                    <div>
                        <small class="modal-label">Evaluated Marks</small>
                        <strong id="modalMarksObtained" class="modal-value-marks">-</strong>
                    </div>
                    <div>
                        <small class="modal-label">Letter Grade</small>
                        <strong id="modalGrade" class="modal-value-grade">-</strong>
                    </div>
                    <div>
                        <small class="modal-label">Evaluator</small>
                        <span id="modalEvaluator" class="modal-value-evaluator">-</span>
                    </div>
                </div>
                <div>
                    <label class="modal-section-title">Evaluator Remarks & Feedback:</label>
                    <div id="modalFeedbackContent" class="modal-feedback-box">
                        No remarks recorded.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeFeedbackModal()" class="btn btn-modal-close">Close</button>
            </div>
        </div>
    </div>

    <!-- Admin Result Table Scripts -->
    <script src="../assets/js/admin/admin-result-page.js"></script>
    <script src="admin-result-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
