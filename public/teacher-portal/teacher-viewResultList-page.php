<?php
// =========================================================================
// teacher-view-resultList-page.php
// Teacher Examination Results & Grade Sheets grouped by Assigned Batches
// Designed with the visual standards of admin-result-page.php
// Feature 1: Live search bar below the header to filter batches, course codes, subjects, student names, IDs & grades
// Feature 2: Pin button on each batch header to pin result tables to the top
// Feature 3: Status filter tabs (All, Graded, Under Review, Submitted, Not Taken)
// Feature 4: "Back to Dashboard" button linking to teacher-dashboard-page.php
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

// 2. RBAC Session Gatekeeper: Restrict access to authorized teachers and administrators
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}
if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['teacher', 'admin', 'superadmin', 'administrator', 'instructor'])) {
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

// Initialize Session State for Pinned Teacher Result Batches
if (!isset($_SESSION['pinned_teacher_result_batches'])) {
    $_SESSION['pinned_teacher_result_batches'] = [];
}

// 4. FETCH LOGGED-IN TEACHER / ADMIN DETAILS
$session_uid   = $_SESSION['user_id'] ?? 0;
$session_email = $_SESSION['email'] ?? '';
$session_tid   = $_SESSION['teacher_id'] ?? '';
$is_admin      = (isset($_SESSION['user_role']) && in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator'])) || isset($_SESSION['admin_id']);

$teacher = [];
if ($dbConn && !$is_admin) {
    if (!empty($session_tid)) {
        try {
            $stmt_t = $dbConn->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = :tid LIMIT 1");
            $stmt_t->execute(['tid' => $session_tid]);
            $teacher = $stmt_t->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    if (empty($teacher) && !empty($session_email)) {
        try {
            $stmt_t = $dbConn->prepare("SELECT * FROM `teachers` WHERE `email` = :email LIMIT 1");
            $stmt_t->execute(['email' => $session_email]);
            $teacher = $stmt_t->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    if (empty($teacher) && !empty($session_uid)) {
        try {
            $stmt_t = $dbConn->prepare("SELECT * FROM `teachers` WHERE `id` = :uid LIMIT 1");
            $stmt_t->execute(['uid' => $session_uid]);
            $teacher = $stmt_t->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }

    if (empty($teacher) && !empty($session_uid)) {
        try {
            $stmt_t = $dbConn->prepare("
                SELECT t.* FROM `teachers` t 
                JOIN `users-table` u ON t.email = u.email 
                WHERE u.id = :uid LIMIT 1
            ");
            $stmt_t->execute(['uid' => $session_uid]);
            $teacher = $stmt_t->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}
    }
}

$teacherDbId         = (int)($teacher['id'] ?? ($session_uid ?: 0));
$logged_teacher_id   = !empty($teacher['teacher_id']) ? $teacher['teacher_id'] : ($session_tid ?: ($is_admin ? 'Admin' : (string)$teacherDbId));
$logged_teacher_name = !empty($teacher['full_name']) ? $teacher['full_name'] : ($_SESSION['teacher_name'] ?? $_SESSION['user_name'] ?? ($is_admin ? 'Administrator' : 'Instructor'));

// 5. HANDLE PIN ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_teacher_result_batches'])) {
            $_SESSION['pinned_teacher_result_batches'] = array_values(array_diff($_SESSION['pinned_teacher_result_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_teacher_result_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// 6. FETCH ASSIGNED BATCHES FOR THIS TEACHER
$teacherBatches = [];
if ($dbConn) {
    try {
        if ($is_admin) {
            $stmtBatches = $dbConn->query("
                SELECT DISTINCT `batch` FROM `batches` 
                UNION 
                SELECT DISTINCT `batch` FROM `exam_header` 
                ORDER BY `batch` ASC
            ");
            $teacherBatches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmtBatches = $dbConn->prepare("
                SELECT DISTINCT `batch` FROM `batches` 
                WHERE `teacher_id` = ? OR `teacher_id` = ? OR `teacher_id` = ?
                UNION 
                SELECT DISTINCT `batch` FROM `exam_header` 
                WHERE `teacher_id` = ? OR `teacher_id` = ? OR `teacher_name` = ?
                ORDER BY `batch` ASC
            ");
            $stmtBatches->execute([
                $logged_teacher_id, 
                strval($teacherDbId), 
                $logged_teacher_name,
                $logged_teacher_id, 
                strval($teacherDbId), 
                $logged_teacher_name
            ]);
            $teacherBatches = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

            // If no specific assigned batches found yet, show all active batches from batches table
            if (empty($teacherBatches)) {
                $stmtAllBatches = $dbConn->query("SELECT DISTINCT `batch` FROM `batches` ORDER BY `batch` ASC");
                $teacherBatches = $stmtAllBatches->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher batches: " . $e->getMessage());
    }
}

$teacherBatches = array_values(array_unique(array_filter(array_map('trim', $teacherBatches))));

// Case-insensitive mapping
$batchKeyByNormalized = [];
foreach ($teacherBatches as $batchName) {
    $batchKeyByNormalized[strtolower(trim($batchName))] = $batchName;
}

$groupedResultsByBatch = [];
foreach ($teacherBatches as $bName) {
    $groupedResultsByBatch[$bName] = [];
}

// Fetch batch meta info (Teacher / Course Advisor & Creation date)
$batchMeta = [];
$teachersLookup = [];
$adminsLookup   = [];

if (!empty($teacherBatches) && $dbConn) {
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

        $inClauseMeta = implode(',', array_fill(0, count($teacherBatches), '?'));
        $stmtMeta = $dbConn->prepare("
            SELECT b.id, b.batch, b.course_code, b.subject, b.exam_title, b.created_at, b.teacher_id,
                   COALESCE(t.full_name, adm.full_name) AS joined_teacher_name
            FROM `batches` b 
            LEFT JOIN `teachers` t ON (b.teacher_id = t.teacher_id OR b.teacher_id = CAST(t.id AS CHAR) OR b.teacher_id = t.full_name)
            LEFT JOIN `admins` adm ON (b.teacher_id = adm.admin_id OR b.teacher_id = CAST(adm.id AS CHAR) OR b.teacher_id = adm.full_name OR (LOWER(TRIM(b.teacher_id)) = 'admin' AND adm.id = 1))
            WHERE b.batch IN ($inClauseMeta)
            ORDER BY b.batch ASC, b.id DESC
        ");
        $stmtMeta->execute($teacherBatches);

        $batchRowsMap = [];
        while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
            $b = $m['batch'];
            $batchRowsMap[$b][] = $m;
            $norm = strtolower(trim($b));
            if ($norm !== $b) $batchRowsMap[$norm][] = $m;
        }

        foreach ($teacherBatches as $bName) {
            $rows = $batchRowsMap[$bName] ?? $batchRowsMap[strtolower(trim($bName))] ?? [];
            $advisorRecord = !empty($rows) ? $rows[0] : null;
            $resolvedTeacherName = '';
            $rawTeacherId = trim($advisorRecord['teacher_id'] ?? '');

            if (!empty($advisorRecord['joined_teacher_name'])) {
                $resolvedTeacherName = trim($advisorRecord['joined_teacher_name']);
            }
            if (empty($resolvedTeacherName) && $rawTeacherId !== '') {
                $resolvedTeacherName = $teachersLookup[$rawTeacherId] ?? $adminsLookup[$rawTeacherId] ?? '';
            }
            if (empty($resolvedTeacherName)) {
                $resolvedTeacherName = $logged_teacher_name ?: 'Course Advisor';
            }

            $batchMeta[$bName] = [
                'batch'        => $bName,
                'teacher_id'   => $rawTeacherId ?: $logged_teacher_id,
                'teacher_name' => $resolvedTeacherName,
                'created_at'   => $advisorRecord['created_at'] ?? null
            ];
            $batchMeta[strtolower(trim($bName))] = $batchMeta[$bName];
        }
    } catch (Exception $e) {
        error_log("Batch meta error in teacher results: " . $e->getMessage());
    }
}

// 7. HELPER FUNCTION FOR GRADE CALCULATION
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

// 8. FETCH EXAMS AND STUDENT SUBMISSIONS FOR TEACHER'S BATCHES
if (!empty($teacherBatches) && $dbConn) {
    try {
        $inClause = implode(',', array_fill(0, count($teacherBatches), '?'));
        
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
        ";

        $params = $teacherBatches;

        // If not admin, prioritize exams created by or assigned to this teacher
        if (!$is_admin) {
            $sql .= " AND (
                e.teacher_id = ? OR e.teacher_id = ? OR e.teacher_name = ? OR e.teacher_id = '' OR e.teacher_id IS NULL
            )";
            $params[] = $logged_teacher_id;
            $params[] = strval($teacherDbId);
            $params[] = $logged_teacher_name;
        }

        $sql .= " ORDER BY e.exam_date DESC, e.id DESC, s.id DESC";

        $stmtResults = $dbConn->prepare($sql);
        $stmtResults->execute($params);

        while ($row = $stmtResults->fetch(PDO::FETCH_ASSOC)) {
            $normalizedBatch = strtolower(trim($row['batch'] ?? ''));
            $b = $batchKeyByNormalized[$normalizedBatch] ?? ($row['batch'] ?? '');
            if (!isset($groupedResultsByBatch[$b])) {
                $groupedResultsByBatch[$b] = [];
            }

            $subStatus = $row['submission_status'] ?? '';
            $totMarks  = floatval($row['total_marks'] ?? 0);
            $obtMarks  = floatval($row['marks_obtained'] ?? 0);

            if ($subStatus === 'Graded') {
                $gradeInfo = calculateTeacherGrade($obtMarks, $totMarks);
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
    } catch (Exception $e) {
        error_log("Teacher results query error: " . $e->getMessage());
    }
}
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
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-examTable-page.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-result-page.css">
</head>

<body data-user-id="<?php echo htmlspecialchars($logged_teacher_id); ?>">
    <div class="main-layout">

        <!-- HEADER AND SIDEBAR (TEACHER) -->
        <?php 
            if (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
                include_once __DIR__ . '/admin-header-sidebar-section.php';
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
                        Teacher: <strong><?php echo htmlspecialchars($logged_teacher_name); ?></strong> (<?php echo htmlspecialchars($logged_teacher_id); ?>) &bull; Assigned Batches: <strong><?php echo !empty($teacherBatches) ? htmlspecialchars(implode(', ', $teacherBatches)) : 'None'; ?></strong>
                    </p>
                </div>

                <!-- Back to Dashboard Action Button -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="teacher-dashboard-page.php" class="btn-archive-page" style="background:#0266cc;">
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
                        placeholder="Search results by batch, course code, subject, title, student name, ID, or grade..."
                    >
                    <button type="button" onclick="filterResultBatches()" class="btn-search-submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </form>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or exam results match your search query.</p>
            </div>

            <!-- Batches Container: Separate Table for each Batch -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($groupedResultsByBatch)): foreach ($groupedResultsByBatch as $batchName => $results): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_teacher_result_batches']);
                    $meta = $batchMeta[$batchName] ?? $batchMeta[strtolower(trim($batchName))] ?? [];
                    $teacherName = !empty($meta['teacher_name']) && $meta['teacher_name'] !== 'Unknown Teacher' 
                        ? $meta['teacher_name'] 
                        : (!empty($results[0]['teacher_name']) && $results[0]['teacher_name'] !== 'Unknown Teacher' ? $results[0]['teacher_name'] : $logged_teacher_name);
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
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-file-signature" style="color: #059669;"></i> <strong>Assessments / Submissions:</strong> <?php echo $resultsCount; ?></span>
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

                        <!-- Table of Results for this Specific Batch (Clean view: No result action column) -->
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
                                            $dateFormatted = formatTeacherExamScheduleDhaka($rawDate, $resultRow['exam_time'] ?? '');
                                            $rawSubmittedAt = trim($resultRow['submitted_at'] ?? '');
                                            $submittedDhakaFormatted = !empty($rawSubmittedAt) ? formatTeacherResultDhaka($rawSubmittedAt, 'd/m/Y h:i A') : 'N/A';

                                            $rowTeacherId = !empty($resultRow['teacher_id']) ? $resultRow['teacher_id'] : ($meta['teacher_id'] ?? $logged_teacher_id);
                                            $rowTeacherName = !empty($resultRow['teacher_name']) && $resultRow['teacher_name'] !== 'Unknown Teacher'
                                                ? $resultRow['teacher_name']
                                                : ($teachersLookup[$rowTeacherId] ?? $adminsLookup[$rowTeacherId] ?? $logged_teacher_name);

                                            $rowStudentName = !empty($resultRow['student_name']) ? $resultRow['student_name'] : (!empty($resultRow['student_id']) ? 'Student #' . $resultRow['student_id'] : '-');
                                            $rowStudentId   = !empty($resultRow['student_id']) ? $resultRow['student_id'] : '-';
                                        ?>
                                            <tr class="exam-row teacher-result-row"
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
                                                    <?php if ($rowStudentName !== '-'): ?>
                                                        <i class="fa-solid fa-user-graduate" style="margin-right: 6px; color: #0266cc;"></i>
                                                        <strong><?php echo htmlspecialchars($rowStudentName); ?></strong>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Student ID -->
                                                <td>
                                                    <?php if ($rowStudentId !== '-'): ?>
                                                        <span style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($rowStudentId); ?></span>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">-</span>
                                                    <?php endif; ?>
                                                </td>

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
                                                <span style="font-size: 0.85rem; color: #94a3b8;">No assessments or student submissions have been recorded for this batch yet.</span>
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
                        <h3 style="color: #1e293b; margin-bottom: 6px;">No batches found.</h3>
                        <p style="color: #64748b; margin-bottom: 16px;">Assigned batches and student examination results will appear here.</p>
                        <a href="teacher-dashboard-page.php" class="btn" style="background: #0266cc; color: #fff; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
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
                    var studentName = (row.getAttribute('data-student-name') || '').toLowerCase();
                    var studentId = (row.getAttribute('data-student-id') || '').toLowerCase();
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
                        studentName.indexOf(query) !== -1 ||
                        studentId.indexOf(query) !== -1 ||
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
