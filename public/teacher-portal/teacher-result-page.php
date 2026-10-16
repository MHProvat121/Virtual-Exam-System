<?php
// =========================================================================
// teacher-result-page.php
// Teacher Examination Results & Grade Sheet Evaluation Management grouped by Batches
// Designed with identical architecture and visual standards of student-result-page.php & student-examTable-page.php
// Feature 1: Controls-card technology (Live status filter tabs + real-time search)
// Feature 2: Pin button on each batch header to pin tables to the top
// Feature 3: Archive button on each batch header to archive tables
// Feature 4: Archived Batch Results button on top right with count badge
// Feature 5: In archive section: Restore and Pin buttons only
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

// 2. RBAC Session Gatekeeper: Restrict access to authorized teachers only
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
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
require_once __DIR__ . '/../common-portal/exam-submission-finalizer.php';
finalizeExpiredExamSubmissions($dbConn);

function formatTeacherResultUtc($value, $format) {
    if (empty($value)) return 'N/A';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format($format) . ' UTC';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function formatTeacherResultDhaka($value, $format) {
    if (empty($value)) return 'N/A';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Dhaka'))
            ->format($format) . ' (Asia/Dhaka)';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function formatTeacherExamScheduleDhaka($date, $time) {
    if (empty($date)) return 'N/A';
    try {
        $dateTime = trim($date) . (!empty($time) ? ' ' . trim($time) : '');
        return (new DateTimeImmutable($dateTime, new DateTimeZone('Asia/Dhaka')))
            ->format(!empty($time) ? 'd/m/Y h:i A' : 'd/m/Y') . ' (Asia/Dhaka)';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

// Resolve logged-in teacher info
$teacher = [];
if ($dbConn) {
    if (isset($_SESSION['email'])) {
        try {
            $stmt_teacher = $dbConn->prepare("SELECT * FROM `teachers` WHERE `email` = :email LIMIT 1");
            $stmt_teacher->execute(['email' => $_SESSION['email']]);
            $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}
    }
    if (empty($teacher) && isset($_SESSION['user_id'])) {
        try {
            $stmt_teacher = $dbConn->prepare("
                SELECT t.* FROM `teachers` t 
                JOIN `users-table` u ON t.email = u.email 
                WHERE u.id = :uid LIMIT 1
            ");
            $stmt_teacher->execute(['uid' => $_SESSION['user_id']]);
            $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}
    }
    if (empty($teacher) && isset($_SESSION['teacher_id'])) {
        try {
            $stmt_teacher = $dbConn->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = :tid OR `id` = :tid LIMIT 1");
            $stmt_teacher->execute(['tid' => $_SESSION['teacher_id']]);
            $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}
    }
}
$logged_teacher_id   = !empty($teacher['teacher_id']) ? $teacher['teacher_id'] : ($_SESSION['teacher_id'] ?? $_SESSION['user_id'] ?? '');
$logged_teacher_name = !empty($teacher['full_name']) ? $teacher['full_name'] : ($_SESSION['teacher_name'] ?? $_SESSION['user_name'] ?? 'Teacher');

// Initialize Session State for Pinned & Archived Result Batches
if (!isset($_SESSION['pinned_teacher_result_batches'])) {
    $_SESSION['pinned_teacher_result_batches'] = [];
}
if (!isset($_SESSION['archived_teacher_result_batches'])) {
    $_SESSION['archived_teacher_result_batches'] = [];
}

// -------------------------------------------------------------------------
// 4. HANDLE ACTIONS (PIN, ARCHIVE, RESTORE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_teacher_result_batches'])) {
            $_SESSION['pinned_teacher_result_batches'] = array_values(array_diff($_SESSION['pinned_teacher_result_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_teacher_result_batches'][] = $batchName;
        }
        $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
        header("Location: " . $redirectUrl);
        exit();
    }

    // Feature 3: Archive Batch Action
    if ($action === 'archive_batch' && !empty($batchName)) {
        if (!in_array($batchName, $_SESSION['archived_teacher_result_batches'])) {
            $_SESSION['archived_teacher_result_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Feature 5: Restore from Archive Action
    if ($action === 'restore_batch' && !empty($batchName)) {
        $_SESSION['archived_teacher_result_batches'] = array_values(array_diff($_SESSION['archived_teacher_result_batches'], [$batchName]));
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
        exit();
    }
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// Helper function to calculate academic letter grade and grade point
function calculateTeacherGrade($marksObtained, $totalMarks) {
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

// -------------------------------------------------------------------------
// 5. FETCH EXAMS AND STUDENT SUBMISSIONS FOR THIS TEACHER
// -------------------------------------------------------------------------
$groupedResultsByBatch = [];
$managedBatches = [];

// Summary metrics
$totalSubmissions = 0;
$totalGraded = 0;
$totalUnderReview = 0;
$cumulativeObtained = 0.0;
$cumulativeTotal = 0.0;

if ($dbConn && ($logged_teacher_id || isset($_SESSION['user_id']))) {
    try {
        $sql = "
            SELECT 
                e.id AS exam_id,
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
            LEFT JOIN `exam_submissions` s ON e.id = s.exam_id
            LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
            WHERE (e.teacher_id = :tid OR e.teacher_id = :uid)
            ORDER BY s.submitted_at DESC, e.id DESC
        ";
        $stmt = $dbConn->prepare($sql);
        $stmt->execute([
            ':tid' => strval($logged_teacher_id),
            ':uid' => strval($_SESSION['user_id'] ?? '')
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $b = trim($row['batch'] ?? 'General');
            if ($b === '') $b = 'General';
            $managedBatches[$b] = true;

            $hasSub = !empty($row['submission_id']);
            $totMarks = floatval($row['total_marks'] ?? 0);
            $obtMarks = floatval($row['marks_obtained'] ?? 0);

            if ($hasSub) {
                $subStatus = trim($row['submission_status'] ?? 'Submitted');
                if ($subStatus !== 'Participating') {
                    $totalSubmissions++;
                }
                if ($subStatus === 'Graded') {
                    $totalGraded++;
                    $cumulativeObtained += $obtMarks;
                    $cumulativeTotal += $totMarks;
                } elseif ($subStatus === 'Under Review' || $subStatus === 'Submitted') {
                    $totalUnderReview++;
                }

                $gradeData = calculateTeacherGrade($obtMarks, $totMarks);
                $row['calculated_grade'] = $gradeData['grade'];
                $row['grade_class']      = $gradeData['class'];
                $row['percentage']       = $totMarks > 0 ? round(($obtMarks / $totMarks) * 100, 1) : 0;
            } else {
                $row['calculated_grade'] = 'N/A';
                $row['grade_class']      = 'grade-na';
                $row['percentage']       = null;
            }

            $groupedResultsByBatch[$b][] = $row;
        }
    } catch (Exception $e) {
        error_log("Teacher results fetch error: " . $e->getMessage());
        $groupedResultsByBatch = [];
    }
}

// Fetch batch meta info (Teacher / Course Advisor & Creation date)
$batchMeta = [];
$allTeacherBatches = array_keys($groupedResultsByBatch);

if (!empty($allTeacherBatches) && $dbConn) {
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

        // 2. Fetch all batch records for managed batches sorted by id DESC
        $inClauseMeta = implode(',', array_fill(0, count($allTeacherBatches), '?'));
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
        $stmtMeta->execute($allTeacherBatches);

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
            $stmtEH->execute($allTeacherBatches);
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
            $stmtED->execute($allTeacherBatches);
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
        foreach ($allTeacherBatches as $bName) {
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
                    $resolvedTeacherName = !empty($logged_teacher_name) && $logged_teacher_name !== 'Teacher' ? $logged_teacher_name : 'Course Advisor';
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
        error_log("Batch meta query error in teacher results: " . $e->getMessage());
    }
}

// Calculate Overall Class Average
$classAvgPercentage = $cumulativeTotal > 0 ? round(($cumulativeObtained / $cumulativeTotal) * 100, 1) : 0;

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedResultsByBatch as $batchName => $results) {
    if (in_array($batchName, $_SESSION['archived_teacher_result_batches'])) {
        $archivedBatches[$batchName] = $results;
    } else {
        $activeBatches[$batchName] = $results;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_teacher_result_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_teacher_result_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_teacher_result_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_teacher_result_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
$archivedCount  = count($archivedBatches);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Results' : 'Student Results & Grade Evaluation'; ?> - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-examTable-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-quesTable-page.css">

    <style>
        /* Dedicated Result Badges & Styling extensions */
        .badge-graded {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .badge-review {
            background-color: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .badge-submitted {
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .badge-not-taken {
            background-color: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        /* Grade Pill Styling */
        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            padding: 3px 9px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }
        .grade-a-plus { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .grade-a      { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .grade-a-minus{ background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .grade-b-plus { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .grade-b      { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .grade-b-minus{ background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; }
        .grade-c-plus { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .grade-c      { background: #fffbeb; color: #b45309; border: 1px solid #fef08a; }
        .grade-d      { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .grade-f      { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .grade-na     { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }

        /* Summary Stats Cards on top */
        .stats-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
            margin-bottom: 5px;
        }
        .stat-metric-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
        }
        .stat-metric-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .stat-metric-info h4 {
            margin: 0;
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.4px;
        }
        .stat-metric-info .metric-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: #1e293b;
            margin-top: 3px;
        }

        /* Action Buttons */
        .btn-view-result {
            background-color: #0266cc !important;
            color: #ffffff !important;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .btn-view-result:hover {
            background-color: #0252a3 !important;
        }
        .btn-feedback-quick {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155 !important;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none !important;
        }
        .btn-feedback-quick:hover {
            background-color: #eff6ff;
            color: #0266cc !important;
            border-color: #93c5fd;
        }

        /* Modal for teacher evaluation & remarks */
        .result-modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .result-modal-content {
            background: #ffffff;
            border-radius: 12px;
            max-width: 550px;
            width: 100%;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php 
            if (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
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
                            Viewing archived student result tables. You can pin priority tables or restore them back to your active result sheets.
                        </p>
                    <?php else: ?>
                        <h1><i class="fa-solid fa-chart-pie" style="color: #0266cc;"></i> Student Results & Grade Evaluation</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            Teacher: <strong><?php echo htmlspecialchars($logged_teacher_name); ?></strong> (<?php echo htmlspecialchars($logged_teacher_id ?: 'N/A'); ?>) &bull; Managed Batches: <strong><?php echo !empty($managedBatches) ? htmlspecialchars(implode(', ', array_keys($managedBatches))) : 'None'; ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Header Actions (Archived Results Button) -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($currentView === 'archived'): ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-archive-page" style="background:#0266cc;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Active Results
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
                        <div class="stat-metric-icon" style="background: #eff6ff; color: #0266cc;">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Total Submissions</h4>
                            <div class="metric-value"><?php echo $totalSubmissions; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Graded & Published</h4>
                            <div class="metric-value"><?php echo $totalGraded; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon" style="background: #fefce8; color: #ca8a04;">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Needs Evaluation</h4>
                            <div class="metric-value"><?php echo $totalUnderReview; ?></div>
                        </div>
                    </div>

                    <div class="stat-metric-card">
                        <div class="stat-metric-icon" style="background: #fdf2f8; color: #db2777;">
                            <i class="fa-solid fa-percent"></i>
                        </div>
                        <div class="stat-metric-info">
                            <h4>Class Average</h4>
                            <div class="metric-value"><?php echo $totalGraded > 0 ? $classAvgPercentage . '%' : 'N/A'; ?></div>
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

                <form method="GET" class="search-form" onsubmit="event.preventDefault(); filterTeacherResults();">
                    <input 
                        type="text" 
                        id="teacherResultSearch" 
                        class="search-input-field"
                        onkeyup="filterTeacherResults()" 
                        placeholder="<?php echo $currentView === 'archived' ? 'Search archived results by student, ID, batch, course code, or grade...' : 'Search results by student, ID, batch, course code, subject, title, or grade...'; ?>"
                    >
                    <button type="button" onclick="filterTeacherResults()" class="btn-search-submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </form>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or student results match your search query.</p>
            </div>

            <!-- Batches Container: Separate Table for each Batch -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $results): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_teacher_result_batches']);
                    $meta = $batchMeta[$batchName] ?? $batchMeta[strtolower(trim($batchName))] ?? [];
                    $batchTeacherName = !empty($meta['teacher_name']) && $meta['teacher_name'] !== 'Unknown Teacher' 
                        ? $meta['teacher_name'] 
                        : (!empty($results[0]['teacher_name']) && $results[0]['teacher_name'] !== 'Unknown Teacher' ? $results[0]['teacher_name'] : $logged_teacher_name);
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
                                    <span><i class="fa-solid fa-user-tie" style="color: #0266cc;"></i> <strong>Teacher:</strong> <?php echo htmlspecialchars($batchTeacherName); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-square-poll-vertical" style="color: #059669;"></i> <strong>Total Submissions:</strong> <?php echo $resultsCount; ?></span>
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
                                        <button type="submit" class="btn-restore-table" title="Restore back to active results">
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
                                        <th>Submission ID</th>
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
                                        <th>Submitted At (UTC)</th>
                                        <th>Submitted At (Asia/Dhaka)</th>
                                        <th>Total Marks</th>
                                        <th>Marks Obtained</th>
                                        <th>Percentage</th>
                                        <th>Letter Grade</th>
                                        <th>Evaluation Status</th>
                                        <th style="text-align: center;">Evaluation Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($results)): ?>
                                        <?php foreach ($results as $resRow):
                                            $subId = intval($resRow['submission_id'] ?? 0);
                                            $examId = intval($resRow['exam_id']);
                                            $hasSubmission = ($subId > 0);
                                            $subStatus = trim($resRow['submission_status'] ?? '');
                                            if (!$hasSubmission) {
                                                $subStatus = 'Not Taken';
                                            }
                                            $totMarks = floatval($resRow['total_marks'] ?? 0);
                                            $obtMarks = floatval($resRow['marks_obtained'] ?? 0);
                                            $isGraded = ($subStatus === 'Graded');
                                            $isReview = ($subStatus === 'Under Review');
                                            $isSubmitted = ($subStatus === 'Submitted');
                                            $isParticipating = ($subStatus === 'Participating');

                                            $rawSubmittedAt = trim($resRow['submitted_at'] ?? '');
                                            $submittedUtcFormatted = !empty($rawSubmittedAt) ? formatTeacherResultUtc($rawSubmittedAt, 'd/m/Y h:i A') : 'N/A';
                                            $submittedDhakaFormatted = !empty($rawSubmittedAt) ? formatTeacherResultDhaka($rawSubmittedAt, 'd/m/Y h:i A') : 'N/A';
                                            $examScheduleFormatted = formatTeacherExamScheduleDhaka($resRow['exam_date'] ?? '', $resRow['exam_time'] ?? '');
                                            $studentName = !empty($resRow['student_name']) ? $resRow['student_name'] : 'Student #' . ($resRow['student_id'] ?? 'N/A');

                                            $rowTeacherId = !empty($resRow['teacher_id']) ? $resRow['teacher_id'] : ($meta['teacher_id'] ?? $logged_teacher_id);
                                            $rowTeacherName = !empty($resRow['teacher_name']) && $resRow['teacher_name'] !== 'Unknown Teacher'
                                                ? $resRow['teacher_name']
                                                : ($teachersLookup[$rowTeacherId] ?? $adminsLookup[$rowTeacherId] ?? ($meta['teacher_name'] ?? $logged_teacher_name));
                                        ?>
                                            <tr class="exam-row teacher-result-row"
                                                id="submission-row-<?php echo $subId; ?>"
                                                data-submission-id="<?php echo $subId; ?>"
                                                data-exam-id="<?php echo $examId; ?>"
                                                data-student-id="<?php echo htmlspecialchars($resRow['student_id'] ?? ''); ?>"
                                                data-student-name="<?php echo htmlspecialchars($studentName); ?>"
                                                data-teacher-name="<?php echo htmlspecialchars($rowTeacherName); ?>"
                                                data-teacher-id="<?php echo htmlspecialchars($rowTeacherId); ?>"
                                                data-total-marks="<?php echo $totMarks; ?>"
                                                data-batch="<?php echo strtolower(htmlspecialchars($batchName)); ?>"
                                                data-course-code="<?php echo strtolower(htmlspecialchars($resRow['course_code'] ?? '')); ?>"
                                                data-status="<?php echo strtolower(htmlspecialchars($subStatus)); ?>"
                                                data-grade="<?php echo strtolower(htmlspecialchars($resRow['calculated_grade'] ?? '')); ?>">

                                                <td><strong><?php echo $hasSubmission ? '#' . $subId : 'N/A'; ?></strong></td>
                                                <td>#<?php echo $examId; ?></td>
                                                <td><strong style="color: #4338ca;"><?php echo htmlspecialchars($batchName); ?></strong></td>
                                                <td>
                                                    <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <?php echo htmlspecialchars($rowTeacherName); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($rowTeacherId); ?></td>
                                                <td>
                                                    <i class="fa-solid fa-user-graduate" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <strong><?php echo htmlspecialchars($studentName); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($resRow['student_id'] ?? 'N/A'); ?></td>

                                                <!-- Course Code (Inserted right after Student ID) -->
                                                <td><strong style="color: #0266cc;"><?php echo htmlspecialchars(!empty($resRow['course_code']) ? $resRow['course_code'] : 'N/A'); ?></strong></td>

                                                <td><?php echo htmlspecialchars($resRow['subject'] ?? 'N/A'); ?></td>
                                                <td><strong><?php echo htmlspecialchars($resRow['exam_title'] ?? 'N/A'); ?></strong></td>
                                                <td><span class="badge badge-type"><?php echo htmlspecialchars($resRow['exam_type'] ?? 'MCQ'); ?></span></td>
                                                <td><?php echo htmlspecialchars($examScheduleFormatted); ?></td>
                                                <td><?php echo htmlspecialchars($submittedUtcFormatted); ?></td>
                                                <td><?php echo htmlspecialchars($submittedDhakaFormatted); ?></td>
                                                <td><strong><?php echo number_format($totMarks, 2); ?></strong></td>

                                                <!-- Marks Obtained -->
                                                <td class="cell-marks-obtained">
                                                    <?php if ($isGraded): ?>
                                                        <strong style="color: #059669; font-size: 0.95rem;">
                                                             <?php echo number_format($obtMarks, 2); ?>
                                                        </strong>
                                                    <?php elseif ($isReview || $isSubmitted): ?>
                                                        <span style="color: #d97706; font-size: 0.85rem; font-style: italic;">
                                                            <?php echo number_format($obtMarks, 2); ?> (Pending)
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Percentage -->
                                                <td class="cell-percentage">
                                                    <?php if ($hasSubmission && $resRow['percentage'] !== null): ?>
                                                        <strong><?php echo $resRow['percentage']; ?>%</strong>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Letter Grade -->
                                                <td>
                                                    <span class="grade-badge <?php echo $resRow['grade_class']; ?>">
                                                        <?php echo htmlspecialchars($resRow['calculated_grade']); ?>
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

                                                <!-- Evaluation Actions -->
                                                <td class="action-cell" style="text-align: center;">
                                                    <div class="action-btn-group" style="justify-content: center;">
                                                        <?php if ($isParticipating): ?>
                                                            <button type="button" class="btn btn-not-started" disabled title="Student is currently taking the exam">
                                                                <i class="fa-solid fa-hourglass-half"></i> In Progress
                                                            </button>
                                                        <?php elseif ($hasSubmission): ?>
                                                            <a href="teacher-answerSheet-page.php?exam_id=<?php echo $examId; ?>&submission_id=<?php echo $subId; ?>&student_id=<?php echo urlencode($resRow['student_id'] ?? ''); ?>" 
                                                               class="btn-feedback-quick" 
                                                               title="Open full answer sheet & evaluate student assessment">
                                                                <i class="fa-solid fa-pen-to-square"></i> Evaluate
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-not-started" disabled title="No submission record">
                                                                <i class="fa-solid fa-ban"></i> No Submission
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="20" style="text-align: center; padding: 28px; color: #64748b;">
                                                <i class="fa-regular fa-folder-open" style="font-size: 1.8rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                                <strong>No student submissions recorded for Batch <?php echo htmlspecialchars($batchName); ?>.</strong>
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
                        <h3 style="color: #1e293b; margin-bottom: 6px;"><?php echo $currentView === 'archived' ? 'No archived batch result tables found.' : 'No student examination submissions found.'; ?></h3>
                        <p style="color: #64748b; margin-bottom: 16px;">Student assessment responses and submissions will appear here for grading and evaluation.</p>
                    </div>
                <?php endif; ?>
            </section>

        </main>

    </div>

    <!-- Teacher Evaluation & Remarks Modal -->
    <div id="evaluationModalBackdrop" class="result-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="result-modal-content">
            <div style="padding: 16px 20px; background: #eff6ff; border-bottom: 1px solid #bfdbfe; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1.15rem; color: #1e40af; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-nib"></i> Evaluate Student Assessment
                </h3>
                <button type="button" onclick="closeEvaluationModal()" style="background: none; border: none; font-size: 1.25rem; color: #64748b; cursor: pointer;">&times;</button>
            </div>
            
            <form id="evaluationForm" style="padding: 20px;">
                <input type="hidden" name="submission_id" id="evalModalSubmissionId">

                <div style="margin-bottom: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                    <div style="font-size: 0.82rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Assessment</div>
                    <div id="evalModalExamTitle" style="font-weight: 700; color: #1e293b; font-size: 1rem; margin-top: 2px;">Exam Title</div>
                    <div style="margin-top: 6px; font-size: 0.85rem; color: #475569;">
                        Student: <strong id="evalModalStudentName">Student Name</strong> &bull; Total Marks: <strong id="evalModalTotalMarks">100</strong>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div>
                        <label for="evalMarksObtained" style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Marks Obtained (from answer sheet)
                        </label>
                        <input type="number" step="0.01" min="0" name="marks_obtained" id="evalMarksObtained" required readonly class="search-input-field" style="width: 100%; box-sizing: border-box;">
                    </div>

                    <div>
                        <label for="evalStatus" style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Evaluation Status
                        </label>
                        <select name="status" id="evalStatus" class="search-input-field" style="width: 100%; box-sizing: border-box;">
                            <option value="Graded">Graded (Final)</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Submitted">Submitted (Pending)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label for="evalFeedback" style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Teacher Feedback / Remarks
                    </label>
                    <textarea name="teacher_feedback" id="evalFeedback" rows="3" class="search-input-field" style="width: 100%; box-sizing: border-box; resize: vertical;" placeholder="Add constructive notes or feedback for this student..."></textarea>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 14px; flex-wrap: wrap; gap: 10px;">
                    <a id="evalModalFullSheetBtn" href="teacher-answerSheet-page.php" class="btn" style="background-color: #4f46e5; color: #fff; text-decoration: none;" title="Open full answer sheet with question-by-question grading">
                        <i class="fa-solid fa-clipboard-check"></i> Open Full Answer Sheet
                    </a>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" onclick="closeEvaluationModal()" class="btn" style="background-color: #64748b; color: #fff;">Cancel</button>
                        <button type="submit" id="evalSaveBtn" class="btn" style="background-color: #0266cc; color: #fff; font-weight: 600;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Evaluation
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../assets/js/teacher/teacher-result-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
