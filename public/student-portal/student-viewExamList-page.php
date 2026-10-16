<?php
// =========================================================================
// student-viewExamList-page.php
// Prototype of Student Exam Questions & Assessment Lists grouped by Batches
// 100% Prototype replicated from teacher-viewExamList-page.php
// Role: Student
// Designed with the identical architecture and visual standards of student-examTable-page.php & teacher-quesTable-page.php
// Feature 1: Controls-card technology (Live status filter tabs + real-time search)
// Feature 2: Pin button on each batch header to pin tables to the top
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

// 2. RBAC Session Gatekeeper: Restrict access to authorized students only (with prototype preview support)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id'])) {
    // Initialize default student session for prototype preview if not logged in
    $_SESSION['student_id']   = 'STU-2026-001';
    $_SESSION['student_name'] = 'Alex Chen';
    $_SESSION['user_role']    = 'student';
    $_SESSION['batch']        = 'Batch 24';
}

if (isset($_SESSION['user_role']) && !in_array(strtolower($_SESSION['user_role']), ['student', 'learner', 'candidate', 'admin', 'superadmin', 'administrator', 'teacher', 'instructor'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration & Logic
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
} elseif (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
} elseif (file_exists(__DIR__ . '/../../config/database.php')) {
    require_once __DIR__ . '/../../config/database.php';
}

// Resolve database connection
$db = $conn ?? $pdo ?? null;

// Resolve logged-in student info
$logged_student_id   = $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? 'STU-2026-001';
$logged_student_name = $_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Alex Chen';
$studentBatch        = trim($_SESSION['batch'] ?? 'Batch 24');

// Initialize Session State for Pinned Batches
if (!isset($_SESSION['pinned_student_batches'])) {
    $_SESSION['pinned_student_batches'] = [];
}

// -------------------------------------------------------------------------
// 4. HANDLE ACTIONS (PIN)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_student_batches'])) {
            $_SESSION['pinned_student_batches'] = array_values(array_diff($_SESSION['pinned_student_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_student_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// -------------------------------------------------------------------------
// 5. FETCH EXAM HEADERS FOR STUDENT
// -------------------------------------------------------------------------
$myExamRows = [];

if ($db) {
    try {
        if ($db instanceof PDO) {
            $stmt = $db->query("
                SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                FROM exam_header 
                ORDER BY id DESC
            ");
            $myExamRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } elseif ($db instanceof mysqli) {
            $result = $db->query("
                SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch 
                FROM exam_header 
                ORDER BY id DESC
            ");
            $myExamRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
    } catch (Exception $e) {
        error_log("Database Error in fetching exam header records: " . $e->getMessage());
        $myExamRows = [];
    }
}

// Prototype Fallback Sample Records if database is empty or not yet connected
if (empty($myExamRows)) {
    $myExamRows = [
        [
            'id' => 101,
            'teacher_name' => 'Dr. Robert Miller',
            'teacher_id' => 'T-501',
            'batch' => 'Batch 24',
            'course_code' => 'CS-302',
            'subject' => 'Computer Science',
            'exam_title' => 'Database Systems & SQL Optimization',
            'exam_type' => 'Written & MCQ',
            'exam_date' => date('Y-m-d'),
            'exam_time' => '10:00:00',
            'duration' => 90,
            'remaining_seconds' => 5400,
            'marks' => 100,
            'status' => 'Published',
            'timer_updated_at_epoch' => time(),
        ],
        [
            'id' => 102,
            'teacher_name' => 'Prof. Sarah Jenkins',
            'teacher_id' => 'T-502',
            'batch' => 'Batch 24',
            'course_code' => 'MATH-204',
            'subject' => 'Mathematics',
            'exam_title' => 'Differential Equations & Linear Algebra',
            'exam_type' => 'Written',
            'exam_date' => date('Y-m-d', strtotime('+3 days')),
            'exam_time' => '13:00:00',
            'duration' => 120,
            'remaining_seconds' => 7200,
            'marks' => 100,
            'status' => 'Published',
            'timer_updated_at_epoch' => time(),
        ],
        [
            'id' => 103,
            'teacher_name' => 'Dr. Gregory Vance',
            'teacher_id' => 'T-503',
            'batch' => 'Batch 24',
            'course_code' => 'PHYS-101',
            'subject' => 'Physics',
            'exam_title' => 'Electromagnetism & Wave Mechanics',
            'exam_type' => 'MCQ',
            'exam_date' => date('Y-m-d'),
            'exam_time' => date('H:i:s', strtotime('-15 minutes')),
            'duration' => 105,
            'remaining_seconds' => 4500,
            'marks' => 80,
            'status' => 'Running',
            'timer_updated_at_epoch' => time(),
        ],
        [
            'id' => 104,
            'teacher_name' => 'Dr. Emily Thornton',
            'teacher_id' => 'T-504',
            'batch' => 'Batch 25',
            'course_code' => 'BIO-210',
            'subject' => 'Biology',
            'exam_title' => 'Molecular Genetics & Cellular Biology',
            'exam_type' => 'Written',
            'exam_date' => date('Y-m-d', strtotime('-10 days')),
            'exam_time' => '14:00:00',
            'duration' => 90,
            'remaining_seconds' => 0,
            'marks' => 100,
            'status' => 'Completed',
            'timer_updated_at_epoch' => time(),
        ],
        [
            'id' => 105,
            'teacher_name' => 'Prof. David Lawson',
            'teacher_id' => 'T-505',
            'batch' => 'Batch 25',
            'course_code' => 'ENG-301',
            'subject' => 'English',
            'exam_title' => 'Academic Writing & Research Methods',
            'exam_type' => 'Essay',
            'exam_date' => date('Y-m-d', strtotime('+5 days')),
            'exam_time' => '11:00:00',
            'duration' => 90,
            'remaining_seconds' => 5400,
            'marks' => 50,
            'status' => 'Cancelled/On Hold',
            'timer_updated_at_epoch' => time(),
        ]
    ];
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

// Feature 2: Sort Batches with Pinned Batches placed on top
$displayBatches = $groupedExamsByBatch;
uksort($displayBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_student_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_student_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

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
    <title>Exam Questions Management - Virtual Exam System</title>

    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files consistent with portal architecture -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-examTable-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-quesTable-page.css">
</head>

<body>
    <div class="main-layout">

        <!-- STUDENT HEADER AND SIDEBAR -->
        <?php 
            if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
                require_once __DIR__ . '/student-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
            }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fa-solid fa-clipboard-question" style="color: #0266cc;"></i> Exam Questions Management</h1>
                    <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                        Student: <strong><?php echo htmlspecialchars($logged_student_name); ?></strong> (<?php echo htmlspecialchars($logged_student_id ?: 'N/A'); ?>) &bull; Enrolled Batches: <strong><?php echo !empty($managedBatches) ? htmlspecialchars(implode(', ', array_keys($managedBatches))) : 'None'; ?></strong>
                    </p>
                </div>
                <!-- Back to Dashboard Action Button -->
                <div class="header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="student-dashboard-page.php" class="btn-archive-page" style="background:#0266cc;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
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

                <form method="GET" class="search-form" onsubmit="event.preventDefault(); filterStudentExams();">
                    <input 
                        type="text" 
                        id="studentExamSearch" 
                        class="search-input-field"
                        onkeyup="filterStudentExams()" 
                        placeholder="Search exams by batch, course code, subject, title, or exam ID..."
                    >
                    <button type="button" onclick="filterStudentExams()" class="btn-search-submit">
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
                    $isPinned = in_array($batchName, $_SESSION['pinned_student_batches']);
                    $examsCount = count($exams);
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
                                    <span><i class="fa-solid fa-user-graduate" style="color: #0266cc;"></i> <strong>Student:</strong> <?php echo htmlspecialchars($logged_student_name); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-clipboard-list" style="color: #059669;"></i> <strong>Available Exams:</strong> <?php echo $examsCount; ?></span>
                                </div>
                            </div>

                            <!-- Right Side Action Buttons: Pin Control -->
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <!-- Feature 2: Pin Logo Button -->
                                <form method="POST" style="display: inline; margin: 0;">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                    <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table on top'; ?>">
                                        <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                    </button>
                                </form>
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
                                        <th>Exam Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($exams)): ?>
                                        <?php foreach ($exams as $examRow):
                                            $currentStatus = $examRow['status'] ?? 'Published';
                                            $tName = !empty($examRow['teacher_name']) ? $examRow['teacher_name'] : 'Instructor';
                                            $tId   = !empty($examRow['teacher_id']) ? $examRow['teacher_id'] : 'T-001';
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
                                            <tr class="exam-row student-exam-row"
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

                                                <!-- Exam Status -->
                                                <td>
                                                    <div class="status-select-wrapper">
                                                        <select name="status" class="status-select" data-exam-id="<?php echo $examRow['id']; ?>" disabled>
                                                            <?php
                                                            $statusOptions = ['Published', 'Running', 'Completed', 'Cancelled/On Hold', 'Edited/Rescheduled'];

                                                            foreach ($statusOptions as $opt) {
                                                                $selected = (strcasecmp($currentStatus, $opt) === 0) ? 'selected' : '';
                                                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $selected disabled>" . htmlspecialchars($opt) . "</option>";
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
                                                <span style="font-size: 0.85rem; color: #94a3b8;">Assessment questions will appear here once published by your instructors.</span>
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
                        <h3 style="color: #1e293b; margin-bottom: 6px;">No exam questions found.</h3>
                        <p style="color: #64748b; margin-bottom: 16px;">Exam schedules and questionnaires will appear here once published.</p>
                        <a href="student-dashboard-page.php" class="btn" style="background: #0266cc; color: #fff; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                            <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
                        </a>
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
                    <h3 class="custom-modal-title" id="startModalTitle">Confirm Exam Entry</h3>
                    <p style="margin: 2px 0 0; font-size: 0.82rem; color: #16a34a; font-weight: 600;" id="startModalSub">Live Exam Action</p>
                </div>
            </div>
            <div class="custom-modal-body">
                <p id="startModalDesc" style="margin-bottom: 8px;">Are you ready to enter the examination? The test timer will begin immediately once you enter.</p>
                <div class="modal-exam-pill" id="modalExamPill">
                    <span style="font-size: 0.8rem; color: #64748b; text-transform: uppercase; font-weight: 700;">Selected Exam</span>
                    <strong id="modalExamDetail">Exam #--</strong>
                    <div id="modalExamRemainingInfo" style="font-size: 0.82rem; color: #d97706; font-weight: 600; display: none; margin-top: 5px;">
                        <i class="fa-solid fa-clock"></i> Remaining duration: <span id="modalRemainingTime">00:00:00</span>
                    </div>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelStartExamBtn">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="confirmStartExamBtn">
                    <i class="fa-solid fa-play"></i> <span id="confirmStartBtnText">Enter Exam</span>
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript for Dynamic Filter Tabs & Search -->
    <script>
        var currentActiveStatus = 'all';

        function filterExamStatus(status, tabElement) {
            currentActiveStatus = status.toLowerCase().trim();

            var tabs = document.querySelectorAll('#examStatusTabs .tab-link');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            if (tabElement) {
                tabElement.classList.add('active');
            }

            filterStudentExams();
        }

        function filterStudentExams() {
            var searchInput = document.getElementById('studentExamSearch');
            var query = searchInput ? searchInput.value.toLowerCase().trim() : '';

            var batchCards = document.querySelectorAll('.batch-card');
            var anyMatchFound = false;

            batchCards.forEach(function(card) {
                var rows = card.querySelectorAll('.exam-row');
                var visibleRowCount = 0;

                rows.forEach(function(row) {
                    var rowStatus = (row.getAttribute('data-status') || '').toLowerCase().trim();
                    var examId = (row.getAttribute('data-exam-id') || '').toLowerCase();
                    var title = (row.getAttribute('data-exam-title') || '').toLowerCase();
                    var rowText = row.textContent.toLowerCase();

                    // Status Matching
                    var statusMatch = false;
                    if (currentActiveStatus === 'all') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'running' && rowStatus === 'running') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'published' && rowStatus === 'published') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'completed' && rowStatus === 'completed') {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'cancelled/on hold' && (rowStatus === 'cancelled/on hold' || rowStatus === 'on hold' || rowStatus === 'cancelled')) {
                        statusMatch = true;
                    } else if (currentActiveStatus === 'edited/rescheduled' && (rowStatus === 'edited/rescheduled' || rowStatus === 'rescheduled')) {
                        statusMatch = true;
                    }

                    // Query Matching
                    var queryMatch = false;
                    if (query === '') {
                        queryMatch = true;
                    } else if (
                        rowText.indexOf(query) !== -1 ||
                        examId.indexOf(query) !== -1 ||
                        title.indexOf(query) !== -1
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

    <!-- Student Scripts -->
    <script src="../assets/js/teacher/teacher-quesTable-page.js"></script>
    <script src="../assets/js/student/student-examTable-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
