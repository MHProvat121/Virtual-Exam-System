<?php
// =========================================================================
// teacher-quesTable-page.php
// Prototype of Teacher Exam Questions Management grouped by Batches
// Designed with the identical architecture and visual standards of student-examTable-page.php & student-result-page.php
// Feature 1: Controls-card technology (Live status filter tabs + real-time search)
// Feature 2: Pin button on each batch header to pin tables to the top
// Feature 3: Archive button on each batch header to archive tables
// Feature 4: Archived Batch Questions button on top right with count badge
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

// 2. RBAC Session Gatekeeper: Restrict access to authorized administrators and teachers
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}
if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator', 'teacher'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration & Logic
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Resolve database connection
$db = $conn ?? $pdo ?? null;

// Resolve logged-in administrator / teacher credentials
$is_admin = (isset($_SESSION['user_role']) && in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator'])) || isset($_SESSION['admin_id']);
$logged_user_id   = $_SESSION['admin_id'] ?? $_SESSION['teacher_id'] ?? $_SESSION['user_id'] ?? '';
$logged_user_name = $_SESSION['admin_name'] ?? $_SESSION['teacher_name'] ?? $_SESSION['user_name'] ?? ($is_admin ? 'Administrator' : 'Teacher');

// Backward compatibility alias variables
$logged_teacher_id   = $logged_user_id;
$logged_teacher_name = $logged_user_name;

// Initialize Session State for Pinned & Archived Batches
if (!isset($_SESSION['pinned_admin_batches'])) {
    $_SESSION['pinned_admin_batches'] = $_SESSION['pinned_teacher_batches'] ?? [];
}
if (!isset($_SESSION['archived_admin_batches'])) {
    $_SESSION['archived_admin_batches'] = $_SESSION['archived_teacher_batches'] ?? [];
}

// -------------------------------------------------------------------------
// 4. HANDLE ACTIONS (PIN, ARCHIVE, RESTORE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_admin_batches'])) {
            $_SESSION['pinned_admin_batches'] = array_values(array_diff($_SESSION['pinned_admin_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_admin_batches'][] = $batchName;
        }
        $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
        header("Location: " . $redirectUrl);
        exit();
    }

    // Feature 3: Archive Batch Action
    if ($action === 'archive_batch' && !empty($batchName)) {
        if (!in_array($batchName, $_SESSION['archived_admin_batches'])) {
            $_SESSION['archived_admin_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Feature 5: Restore from Archive Action
    if ($action === 'restore_batch' && !empty($batchName)) {
        $_SESSION['archived_admin_batches'] = array_values(array_diff($_SESSION['archived_admin_batches'], [$batchName]));
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
        exit();
    }
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// -------------------------------------------------------------------------
// 5. FETCH EXAM HEADERS (ALL EXAMS FOR ADMIN, OWN EXAMS FOR TEACHER)
// -------------------------------------------------------------------------
$myExamRows = [];

if ($db) {
    try {
        if ($db instanceof PDO) {
            if ($is_admin) {
                // Admin has full oversight to manage all exam headers
                $stmt = $db->query("
                    SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                    FROM exam_header 
                    ORDER BY id DESC
                ");
                $myExamRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $db->prepare("
                    SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                    FROM exam_header 
                    WHERE teacher_id = :teacher_id OR teacher_id = :user_id 
                    ORDER BY id DESC
                ");
                $stmt->execute([
                    ':teacher_id' => strval($logged_user_id),
                    ':user_id'    => strval($_SESSION['user_id'] ?? '')
                ]);
                $myExamRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($db instanceof mysqli) {
            if ($is_admin) {
                $result = $db->query("
                    SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                    FROM exam_header 
                    ORDER BY id DESC
                ");
                $myExamRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                $stmt = $db->prepare("
                    SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                    FROM exam_header 
                    WHERE teacher_id = ? OR teacher_id = ? 
                    ORDER BY id DESC
                ");
                $uIdStr = strval($_SESSION['user_id'] ?? '');
                $tIdStr = strval($logged_user_id);
                $stmt->bind_param("ss", $tIdStr, $uIdStr);
                $stmt->execute();
                $result = $stmt->get_result();
                $myExamRows = $result->fetch_all(MYSQLI_ASSOC);
            }
        }
    } catch (Exception $e) {
        error_log("Database Error in fetching exam header records: " . $e->getMessage());
        $myExamRows = [];
    }
}

// Group Exams by Batch
$groupedExamsByBatch = [];
$managedBatches = [];

foreach ($myExamRows as $examRow) {
    $b = trim($examRow['batch'] ?? 'General');
    if ($b === '') $b = 'General';
    $groupedExamsByBatch[$b][] = $examRow;
    $managedBatches[$b] = true;
}

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedExamsByBatch as $batchName => $exams) {
    if (in_array($batchName, $_SESSION['archived_admin_batches'])) {
        $archivedBatches[$batchName] = $exams;
    } else {
        $activeBatches[$batchName] = $exams;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_admin_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_admin_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_admin_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_admin_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
$archivedCount  = count($archivedBatches);

$successMsg = $_SESSION['success'] ?? null;
$errorMsg   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$examTimezone = new DateTimeZone('Asia/Dhaka');
$utcTimezone  = new DateTimeZone('UTC');
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
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Questions' : 'Exam Questions Management'; ?> - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-examTable-page.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-quesTable-page.css">
</head>

<body>
    <div class="main-layout">

        <!-- HEADER AND SIDEBAR (ADMIN / TEACHER) -->
        <?php 
            if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
                require_once __DIR__ . '/admin-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
            }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <?php if ($currentView === 'archived'): ?>
                        <h1><i class="fa-solid fa-box-archive" style="color: #d97706;"></i> Archived Batch Questions</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            Viewing archived exam question tables. You can pin priority tables or restore them back to your active questions.
                        </p>
                    <?php else: ?>
                        <h1><i class="fa-solid fa-clipboard-question" style="color: #0266cc;"></i> Exam Questions Management</h1>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            <?php echo $is_admin ? 'Administrator' : 'Teacher'; ?>: <strong><?php echo htmlspecialchars($logged_user_name); ?></strong> (<?php echo htmlspecialchars($logged_user_id ?: 'Authorized'); ?>) &bull; Managed Batches: <strong><?php echo !empty($managedBatches) ? htmlspecialchars(implode(', ', array_keys($managedBatches))) : 'None'; ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Header Actions (Archive Page Button + Create New Exam) -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($currentView === 'archived'): ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn-archive-page" style="background:#0266cc;">
                            <i class="fa-solid fa-arrow-left"></i> Back to Active Batches
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?view=archived'; ?>" class="btn-archive-page">
                            <i class="fa-solid fa-box-archive"></i> Archived Batch Questions
                            <?php if ($archivedCount > 0): ?>
                                <span class="badge-count"><?php echo $archivedCount; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                     
                </div>
            </div>

            <?php if (!empty($successMsg)): ?>
                <div class="alert-box alert-success">
                    <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMsg)): ?>
                <div class="alert-box alert-danger">
                    <i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <!-- CONTROLS ROW (STATUS TABS + SEARCH) - PROTOTYPED FROM admin-manageEnroll-page -->
            <div class="controls-card">
                <div class="filter-tabs-group" id="examStatusTabs">
                    <a href="javascript:void(0)" onclick="filterExamStatus('All', this)" class="tab-link active" data-status="all">
                        All
                    </a>
                    <a href="javascript:void(0)" onclick="filterExamStatus('Running', this)" class="tab-link" data-status="running">
                        <span class="pulse-dot" style="margin-right: 2px;"></span> Running
                    </a>
                    <a href="javascript:void(0)" onclick="filterExamStatus('Published', this)" class="tab-link" data-status="published">
                        Published
                    </a>
                    <a href="javascript:void(0)" onclick="filterExamStatus('Completed', this)" class="tab-link" data-status="completed">
                        Completed
                    </a>
                    <a href="javascript:void(0)" onclick="filterExamStatus('Cancelled/On Hold', this)" class="tab-link" data-status="cancelled/on hold">
                        On Hold
                    </a>
                    <a href="javascript:void(0)" onclick="filterExamStatus('Edited/Rescheduled', this)" class="tab-link" data-status="edited/rescheduled">
                        Rescheduled
                    </a>
                </div>

                <form method="GET" class="search-form" onsubmit="event.preventDefault(); filterTeacherExams();">
                    <input 
                        type="text" 
                        id="teacherExamSearch" 
                        class="search-input-field"
                        onkeyup="filterTeacherExams()" 
                        placeholder="<?php echo $currentView === 'archived' ? 'Search archived batches, course codes, subjects, or titles...' : 'Search exams by batch, course code, subject, title, or exam ID...'; ?>"
                    >
                    <button type="button" onclick="filterTeacherExams()" class="btn-search-submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </form>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or exam questions match your search query.</p>
            </div>

            <!-- Batches Container: Separate Table for each Batch -->
            <section class="table-section-container" id="batchesListContainer">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $exams): 
                    $isPinned = in_array($batchName, $_SESSION['pinned_admin_batches']);
                    $examsCount = count($exams);
                    $batchTeacher = !empty($exams[0]['teacher_name']) ? $exams[0]['teacher_name'] : $logged_user_name;
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
                                    <span><i class="fa-solid fa-user-tie" style="color: #0266cc;"></i> <strong>Teacher:</strong> <?php echo htmlspecialchars($batchTeacher); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-clipboard-list" style="color: #059669;"></i> <strong>Created Exams:</strong> <?php echo $examsCount; ?></span>
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
                                    <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Archive questions table for batch: <?php echo htmlspecialchars(addslashes($batchName)); ?>?');">
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
                                        <button type="submit" class="btn-restore-table" title="Restore back to active exams">
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
                                        <th>Course Code</th>
                                        <th>Subject/Course</th>
                                        <th>Course Title</th>
                                        <th>Exam Type</th>
                                        <th>Date & Time (Asia/Dhaka)</th>
                                        <th>Date & Time (UTC)</th>
                                        <th>Exam Starts in</th>
                                        <th>Duration</th>
                                        <th>Duration Left</th>
                                        <th>Total Mark</th>
                                        <th>Exam Actions</th>
                                        <th>Edit Actions</th>
                                        <th>Exam Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($exams)): ?>
                                        <?php foreach ($exams as $examRow):
                                            $currentStatus = $examRow['status'] ?? 'Published';
                                            $tName = !empty($examRow['teacher_name']) ? $examRow['teacher_name'] : $logged_teacher_name;
                                            $tId   = !empty($examRow['teacher_id']) ? $examRow['teacher_id'] : $logged_teacher_id;
                                            $raw_exam_date = trim($examRow['exam_date'] ?? '');
                                            $raw_exam_time = trim($examRow['exam_time'] ?? '');
                                            $dhakaToday = (new DateTimeImmutable('now', $examTimezone))->format('Y-m-d');
                                            $start_datetime = (!empty($raw_exam_date) && !empty($raw_exam_time))
                                                ? ($raw_exam_date . ' ' . $raw_exam_time)
                                                : (!empty($raw_exam_time) ? ($dhakaToday . ' ' . $raw_exam_time) : '');
                                            $scheduledStart = null;
                                            if (!empty($start_datetime)) {
                                                try {
                                                    $scheduledStart = new DateTimeImmutable($start_datetime, $examTimezone);
                                                } catch (Exception $e) {
                                                    $scheduledStart = null;
                                                }
                                            }
                                            $startTs = $scheduledStart ? $scheduledStart->getTimestamp() : 0;
                                            $startUtc = $scheduledStart
                                                ? $scheduledStart->setTimezone($utcTimezone)->format('d/m/Y h:i A') . ' UTC'
                                                : 'N/A';
                                            $hasWaitTime = ($startTs > 0 && time() < $startTs);
                                            $isCompleted = (strcasecmp($currentStatus, 'Completed') === 0);
                                            $isRunning   = (strcasecmp($currentStatus, 'Running') === 0);
                                            $remainingSeconds = max(0, (int)($examRow['remaining_seconds'] ?? ((int)($examRow['duration'] ?? 0) * 60)));
                                            $displayMarks = $examRow['marks'] ?? 0;
                                        ?>
                                            <tr class="exam-row teacher-exam-row"
                                                id="exam-row-<?php echo $examRow['id']; ?>"
                                                data-exam-id="<?php echo $examRow['id']; ?>"
                                                data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>"
                                                data-status="<?php echo htmlspecialchars($currentStatus); ?>"
                                                data-start-time="<?php echo htmlspecialchars($start_datetime); ?>"
                                                data-start-timestamp="<?php echo (int)$startTs; ?>"
                                                data-duration="<?php echo (int)($examRow['duration'] ?? 0); ?>"
                                                data-timer-updated-at-epoch="<?php echo (int)($examRow['timer_updated_at_epoch'] ?? 0); ?>"
                                                data-remaining-seconds="<?php echo $remainingSeconds; ?>"
                                                data-server-time-ms="<?php echo $pageServerTimeMs; ?>">

                                                <!-- Real Exam ID from exam_header -->
                                                <td><strong>#<?php echo htmlspecialchars($examRow['id']); ?></strong></td>

                                                <td>
                                                    <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                                    <?php echo htmlspecialchars($tName); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($tId); ?></td>
                                                <td><strong style="color: #4338ca;"><?php echo htmlspecialchars($examRow['batch'] ?? $batchName); ?></strong></td>
                                                
                                                <!-- Course Code (Inserted right after Batch as requested) -->
                                                <td><strong style="color: #0266cc;"><?php echo htmlspecialchars(!empty($examRow['course_code']) ? $examRow['course_code'] : 'N/A'); ?></strong></td>

                                                <td><?php echo htmlspecialchars($examRow['subject'] ?? 'N/A'); ?></td>
                                                <td><strong><?php echo htmlspecialchars($examRow['exam_title'] ?? 'N/A'); ?></strong></td>
                                                <td><span class="badge badge-type"><?php echo htmlspecialchars($examRow['exam_type'] ?? 'N/A'); ?></span></td>
                                                <td>
                                                    <?php echo $scheduledStart ? htmlspecialchars($scheduledStart->format('d/m/Y h:i A') . ' (Asia/Dhaka)') : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($startUtc); ?></small>
                                                </td>

                                                <!-- Start Countdown -->
                                                <td>
                                                    <?php if ($isRunning): ?>
                                                        <span class="timer-badge start-timer running">In Progress</span>
                                                    <?php elseif ($isCompleted): ?>
                                                        <span class="timer-badge start-timer finished">Completed</span>
                                                    <?php elseif (strcasecmp($currentStatus, 'Cancelled/On Hold') === 0): ?>
                                                        <span class="timer-badge start-timer on-hold">On Hold</span>
                                                    <?php elseif ($hasWaitTime): ?>
                                                        <span class="timer-badge start-timer active"><?php echo gmdate('H:i:s', $startTs - time()); ?></span>
                                                    <?php else: ?>
                                                        <span class="timer-badge start-timer ready">Ready to Start</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td><strong><?php echo htmlspecialchars($examRow['duration'] ?? '0'); ?> min</strong></td>

                                                <!-- Duration Left -->
                                                <td>
                                                    <span class="timer-badge duration-timer">--:--:--</span>
                                                </td>

                                                <td><strong><?php echo htmlspecialchars($displayMarks); ?></strong></td>

                                                <!-- Exam Actions -->
                                                <td>
                                                    <div class="action-btn-group">
                                                        <a href="admin-viewQues-page.php?exam_id=<?php echo $examRow['id']; ?>" class="btn btn-view" title="View all exam questions">
                                                            <i class="fa-solid fa-eye"></i> View Question
                                                        </a>

                                                        <a href="admin-inspectExam-page.php?exam_id=<?php echo $examRow['id']; ?>" class="btn btn-inspect <?php echo $isRunning ? 'is-live' : ''; ?>" title="Inspect live student exam participation">
                                                            <i class="fa-solid fa-users-viewfinder"></i> Inspect<?php if ($isRunning) echo ' <span class="live-badge-dot"></span>'; ?>
                                                        </a>

                                                        <?php 
                                                            $isOnHold = (strcasecmp($currentStatus, 'Cancelled/On Hold') === 0);
                                                        ?>
                                                        <?php if ($isCompleted): ?>
                                                            <button type="button" class="btn btn-start is-completed" disabled title="Exam has ended">
                                                                <i class="fa-solid fa-check"></i> Completed
                                                            </button>
                                                        <?php elseif ($isRunning): ?>
                                                            <button type="button" class="btn btn-start start-exam-btn is-running" data-exam-id="<?php echo $examRow['id']; ?>" data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>" title="Exam is currently running">
                                                                <i class="fa-solid fa-circle-dot fa-fade"></i> Exam Running
                                                            </button>
                                                        <?php elseif ($isOnHold): ?>
                                                            <button type="button" class="btn btn-start btn-resume start-exam-btn is-resume" data-exam-id="<?php echo $examRow['id']; ?>" data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>" data-is-resume="true" title="Click to resume this paused exam with left duration">
                                                                <i class="fa-solid fa-play"></i> Resume Exam
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-start start-exam-btn" data-exam-id="<?php echo $examRow['id']; ?>" data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>" <?php echo $hasWaitTime ? 'disabled title="Exam start time has not arrived yet (' . gmdate('H:i:s', $startTs - time()) . ' remaining)"' : 'title="Click to launch this exam immediately for students"'; ?>>
                                                                <i class="fa-solid fa-play"></i> Start Exam
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <!-- Edit Actions -->
                                                <td>
                                                    <div class="action-btn-group">
                                                         

                                                        <button type="button" 
                                                           class="btn btn-delete btn-delete-exam" 
                                                           data-exam-id="<?php echo $examRow['id']; ?>"
                                                           data-exam-title="<?php echo htmlspecialchars($examRow['exam_title'] ?? 'Exam'); ?>"
                                                           title="Delete this entire exam">
                                                            <i class="fa-solid fa-trash"></i> Delete
                                                        </button>
                                                    </div>
                                                </td>

                                                <!-- Exam Status Dropdown -->
                                                <td>
                                                    <div class="status-select-wrapper">
                                                        <select name="status" class="status-select" data-exam-id="<?php echo $examRow['id']; ?>">
                                                            <?php
                                                            $statusOptions = ['Published', 'Running', 'Completed', 'Cancelled/On Hold', 'Edited/Rescheduled'];

                                                            foreach ($statusOptions as $opt) {
                                                                $selected = (strcasecmp($currentStatus, $opt) === 0) ? 'selected' : '';
                                                                $isClickable = (strcasecmp($opt, 'Cancelled/On Hold') === 0);
                                                                $disabledAttr = $isClickable ? '' : 'disabled';
                                                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $selected $disabledAttr>" . htmlspecialchars($opt) . "</option>";
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="17" style="text-align: center; padding: 28px; color: #64748b;">
                                                <i class="fa-regular fa-folder-open" style="font-size: 1.8rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                                <strong>No exam questions found for Batch <?php echo htmlspecialchars($batchName); ?>.</strong><br>
                                                <span style="font-size: 0.85rem; color: #94a3b8;">Use the "Create New Exam" button above to add questions for this batch.</span>
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
                        <h3 style="color: #1e293b; margin-bottom: 6px;"><?php echo $currentView === 'archived' ? 'No archived batch question tables found.' : 'No exam questions found.'; ?></h3>
                        <p style="color: #64748b; margin-bottom: 16px;"><?php echo $currentView === 'archived' ? 'Tables you archive will appear here safely. You can restore them anytime.' : ($is_admin ? 'Exam question tables will appear here once exams are created or published by teachers.' : 'Click below to create your first exam questionnaire for students.'); ?></p>
                        <?php if (!$is_admin): ?>
                        <a href="teacher-makeQues-page1.php" class="btn" style="background: #0266cc; color: #fff; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                            <i class="fa-solid fa-plus"></i> Create New Exam
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>

    </div>

    <!-- Start & Resume Exam Confirmation Modal -->
    <div id="startExamModal" class="custom-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="custom-modal-box">
            <div class="custom-modal-header" id="startModalHeader">
                <div class="custom-modal-icon" id="startModalIcon">
                    <i class="fa-solid fa-play"></i>
                </div>
                <div>
                    <h3 class="custom-modal-title" id="startModalTitle">Confirm Exam Launch</h3>
                    <p style="margin: 2px 0 0; font-size: 0.82rem; color: #16a34a; font-weight: 600;" id="startModalSub">Live Exam Action</p>
                </div>
            </div>
            <div class="custom-modal-body">
                <p id="startModalDesc" style="margin-bottom: 8px;">Are you sure you want to start the exam? Students will immediately be allowed to enter the exam portal and begin their test.</p>
                <div class="modal-exam-pill" id="modalExamPill">
                    <span style="font-size: 0.8rem; color: #64748b; text-transform: uppercase; font-weight: 700;">Selected Exam</span>
                    <strong id="modalExamDetail">Exam #--</strong>
                    <div id="modalExamRemainingInfo" style="font-size: 0.82rem; color: #d97706; font-weight: 600; display: none; margin-top: 5px;">
                        <i class="fa-solid fa-clock"></i> Resuming with remaining duration: <span id="modalRemainingTime">00:00:00</span>
                    </div>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelStartExamBtn">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="confirmStartExamBtn">
                    <i class="fa-solid fa-play"></i> <span id="confirmStartBtnText">Yes, Start Exam</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Exam Confirmation Modal with 3s Disabled Button Countdown -->
    <div id="deleteExamModal" class="custom-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="custom-modal-box">
            <div class="custom-modal-header">
                <div class="custom-modal-icon danger">
                    <i class="fa-solid fa-trash-can"></i>
                </div>
                <div>
                    <h3 class="custom-modal-title">Confirm Exam Deletion</h3>
                    <p style="margin: 2px 0 0; font-size: 0.82rem; color: #dc2626; font-weight: 600;">Warning: Irreversible action</p>
                </div>
            </div>
            <div class="custom-modal-body">
                <p style="margin-bottom: 8px;">Are you sure you want to delete the entire exam from exam_header? All questions, options, and associated exam records will be permanently removed.</p>
                <div class="modal-exam-pill modal-exam-pill-danger" id="deleteModalExamPill">
                    <span style="font-size: 0.8rem; color: #dc2626; text-transform: uppercase; font-weight: 700;">Exam to be deleted</span>
                    <strong id="deleteModalExamDetail">Exam #--</strong>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelDeleteExamBtn">Cancel</button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmDeleteExamBtn" disabled>
                    <i class="fa-solid fa-trash"></i> <span id="deleteBtnLabel">Delete Exam (3s)</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Admin Scripts -->
    <script src="../assets/js/admin/admin-quesTable-page.js"></script>
     <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
