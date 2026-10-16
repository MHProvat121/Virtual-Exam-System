<?php
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

// Get target exam_id
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$exam = null;
$total_questions = 0;
$students_data = [];
$stats = [
    'total_enrolled' => 0,
    'in_progress'    => 0,
    'submitted'      => 0,
    'not_started'    => 0,
];

if ($db && $exam_id > 0) {
    try {
        // Fetch exam header details from `exam_header` table
        if ($db instanceof PDO) {
            $stmt = $db->prepare("
                SELECT id, teacher_id, teacher_name, batch, subject, exam_title, 
                       exam_type, exam_date, exam_time, duration, marks, 
                       marks AS total_marks, duration AS exam_duration, status, created_at, updated_at,
                       COALESCE(remaining_seconds, duration * 60) AS remaining_seconds,
                       UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch,
                       UNIX_TIMESTAMP(updated_at) AS updated_at_epoch
                FROM exam_header 
                WHERE id = :id 
                LIMIT 1
            ");
            $stmt->execute([':id' => $exam_id]);
            $exam = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exam) {
                // Count total questions from `questions` table
                $qStmt = $db->prepare("SELECT COUNT(*) AS total_q FROM questions WHERE exam_id = :exam_id");
                $qStmt->execute([':exam_id' => $exam_id]);
                $qRow = $qStmt->fetch(PDO::FETCH_ASSOC);
                $total_questions = intval($qRow['total_q'] ?? 0);
            }
        } elseif ($db instanceof mysqli) {
            $stmt = $db->prepare("
                SELECT id, teacher_id, teacher_name, batch, subject, exam_title, 
                       exam_type, exam_date, exam_time, duration, marks, 
                       marks AS total_marks, duration AS exam_duration, status, created_at, updated_at,
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

            if ($exam) {
                $qStmt = $db->prepare("SELECT COUNT(*) AS total_q FROM questions WHERE exam_id = ?");
                $qStmt->bind_param("i", $exam_id);
                $qStmt->execute();
                $qRow = $qStmt->get_result()->fetch_assoc();
                $total_questions = intval($qRow['total_q'] ?? 0);
            }
        }

        if ($exam) {
            $batch = $exam['batch'] ?? '';
            $exam['total_questions'] = $total_questions;

            // Fetch enrolled students using `program` (not `department`)
            $students_list = [];
            if ($db instanceof PDO) {
                $sQuery = "
                    SELECT s.id, s.student_id, s.full_name, s.email, s.program, s.program AS department, s.batch, s.profile_image
                    FROM students s
                    WHERE s.batch = :batch 
                       OR :batch = 'All' 
                       OR s.id IN (
                           SELECT student_id 
                           FROM student_enrollments 
                           WHERE batch = :batch AND status = 'Enrolled'
                       )
                    ORDER BY s.student_id ASC
                ";
                $sStmt = $db->prepare($sQuery);
                $sStmt->execute([':batch' => $batch]);
                $students_list = $sStmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch submissions using `status` column
                $subQuery = "
                    SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at, s.status,
                           s.status AS submission_status, COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained
                    FROM exam_submissions s
                    LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
                    WHERE s.exam_id = :exam_id
                ";
                $subStmt = $db->prepare($subQuery);
                $subStmt->execute([':exam_id' => $exam_id]);
                $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            } elseif ($db instanceof mysqli) {
                $sStmt = $db->prepare("
                    SELECT s.id, s.student_id, s.full_name, s.email, s.program, s.program AS department, s.batch, s.profile_image
                    FROM students s
                    WHERE s.batch = ? 
                       OR ? = 'All' 
                       OR s.id IN (
                           SELECT student_id 
                           FROM student_enrollments 
                           WHERE batch = ? AND status = 'Enrolled'
                       )
                    ORDER BY s.student_id ASC
                ");
                $sStmt->bind_param("sss", $batch, $batch, $batch);
                $sStmt->execute();
                $students_list = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $subStmt = $db->prepare("
                    SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at, s.status,
                           s.status AS submission_status, COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained
                    FROM exam_submissions s
                    LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
                    WHERE s.exam_id = ?
                ");
                $subStmt->bind_param("i", $exam_id);
                $subStmt->execute();
                $submissions = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }

            // Index submissions by student_id
            $subMap = [];
            foreach ($submissions as $sub) {
                $subMap[strval($sub['student_id'])] = $sub;
            }

            // Aggregate student live state
            foreach ($students_list as $student) {
                $sId = strval($student['student_id']);
                $hasSub = isset($subMap[$sId]);

                $subRecord = $hasSub ? $subMap[$sId] : null;
                $subStatus = trim((string)($subRecord['status'] ?? 'Not Started'));
                $normalizedSubStatus = strtolower(preg_replace('/\s+/', ' ', $subStatus));
                $marks = $subRecord['total_marks_obtained'] ?? null;
                $isSubmitted = in_array($normalizedSubStatus, ['submitted', 'under review', 'graded'], true);
                $isAttemptInProgress = in_array($normalizedSubStatus, ['in progress', 'in-progress', 'started'], true);

                // Determine live status
                if ($isSubmitted) {
                    $statusType = 'submitted';
                    $stats['submitted']++;
                } elseif ($isAttemptInProgress) {
                    $statusType = 'in_progress';
                    $stats['in_progress']++;
                } else {
                    $statusType = 'not_started';
                    $stats['not_started']++;
                }

                $students_data[] = [
                    'student_id'        => $sId,
                    'full_name'         => $student['full_name'] ?? 'Student',
                    'email'             => $student['email'] ?? '',
                    'department'        => $student['program'] ?? $student['department'] ?? '',
                    'batch'             => $student['batch'] ?? $batch,
                    'profile_image'     => !empty($student['profile_image']) ? $student['profile_image'] : 'assets/images/user-avatar.jpg',
                    'status_type'       => $statusType,
                    'submission_status' => $subStatus,
                    'marks_obtained'    => $marks,
                    'submitted_at'      => $subRecord['submitted_at'] ?? null,
                ];
            }

            $stats['total_enrolled'] = count($students_data);
        }
    } catch (Exception $e) {
        error_log("Error loading exam inspection: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Exam Inspection - <?php echo htmlspecialchars($exam['exam_title'] ?? 'Exam'); ?></title>

    <!-- Google Fonts & Font Awesome Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Stylesheets -->
    <link rel="stylesheet" href="../assets/css/common/header.css">
    <link rel="stylesheet" href="../assets/css/common/sidebar.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-inspectExam-page.css">
    <link rel="stylesheet" href="../assets/css/common/exam-proctoring.css">
</head>
<body>

    <!-- Header Navigation -->
    <?php 
        if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
            require_once __DIR__ . '/admin-header-sidebar-section.php';
        } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
            require_once __DIR__ . '/teacher-header-sidebar-section.php';
        } elseif (file_exists(__DIR__ . '/../../src/views/common/header.php')) {
            include_once __DIR__ . '/../../src/views/common/header.php';
        }
    ?>

    <div class="main-container">

        <!-- Sidebar Navigation -->
        <?php 
            if (!file_exists(__DIR__ . '/admin-header-sidebar-section.php') && !file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                $sidebarPath = __DIR__ . '/../../src/views/common/sidebar.php';
                if (file_exists($sidebarPath)) {
                    include_once $sidebarPath;
                }
            }
        ?>

        <!-- Primary Page Content -->
        <main class="content-area inspect-content-area" id="inspectPageApp" 
              data-exam-id="<?php echo $exam_id; ?>"
              data-exam-status="<?php echo htmlspecialchars($exam['status'] ?? 'Published'); ?>"
              data-duration="<?php echo htmlspecialchars($exam['duration'] ?? '60'); ?>"
              data-remaining-seconds="<?php echo htmlspecialchars($exam['remaining_seconds'] ?? (($exam['duration'] ?? 60) * 60)); ?>"
              data-timer-updated-at-epoch="<?php echo htmlspecialchars($exam['timer_updated_at_epoch'] ?? 0); ?>"
              data-updated-at-epoch="<?php echo htmlspecialchars($exam['updated_at_epoch'] ?? 0); ?>"
              data-server-time-ms="<?php echo (int) round(microtime(true) * 1000); ?>"
              data-proctor-role="<?php echo $is_admin ? 'admin' : 'teacher'; ?>"
              data-signal-url="../exam-proctoring-signal.php"
              data-ice-servers="<?php echo htmlspecialchars(json_encode([['urls' => 'stun:stun.l.google.com:19302']]), ENT_QUOTES); ?>"
              data-start-time="<?php echo htmlspecialchars(($exam['exam_date'] ?? '') . ' ' . ($exam['exam_time'] ?? '')); ?>"
              data-total-questions="<?php echo htmlspecialchars($total_questions); ?>">

            <!-- Breadcrumb Navigation Bar -->
            <div class="inspect-top-nav">
                <a href="admin-quesTable-page.php" class="btn-back-link">
                    <i class="fa-solid fa-arrow-left"></i> Back to Exam Questions Table
                </a>
                <div class="nav-actions">
                    <div class="live-indicator-pill" id="livePollingPill">
                        <span class="live-dot-pulse"></span>
                        <span id="liveStatusText">Live Feed Active</span>
                    </div>
                    <button type="button" class="btn btn-secondary-refresh" id="refreshInspectBtn" title="Refresh Live Data">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                </div>
            </div>

            <?php if (!$exam): ?>
                <div class="empty-state-card" style="text-align: center; padding: 50px 20px; background: #fff; border-radius: 12px; margin-top: 20px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #f59e0b; margin-bottom: 12px; display: block;"></i>
                    <h2>Exam Not Found</h2>
                    <p style="color: #64748b; margin-top: 8px;">The requested exam #<?php echo $exam_id; ?> could not be found in <code>exam_header</code> or you do not have permission to inspect it.</p>
                    <a href="admin-quesTable-page.php" class="btn btn-primary" style="margin-top: 18px; display: inline-block; padding: 10px 20px; background: #4338ca; color: #fff; border-radius: 8px; text-decoration: none;">Return to Exam List</a>
                </div>
            <?php else: ?>

                <!-- Exam Overview Hero Header -->
                <div class="inspect-hero-card">
                    <div class="hero-main-details">
                        <div class="hero-badges">
                            <span class="badge-exam-type"><?php echo htmlspecialchars($exam['exam_type'] ?? 'MCQ'); ?></span>
                            <span class="badge-batch"><i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars($exam['batch'] ?? 'All Batches'); ?></span>
                            <span class="badge-exam-status status-<?php echo strtolower(str_replace(['/', ' '], '-', $exam['status'] ?? 'published')); ?>" id="heroStatusBadge">
                                <?php echo htmlspecialchars($exam['status'] ?? 'Published'); ?>
                            </span>
                        </div>
                        <h1 class="hero-title"><?php echo htmlspecialchars($exam['exam_title'] ?? 'Live Exam Inspection'); ?></h1>
                        <p class="hero-subtitle">
                            <span><i class="fa-regular fa-hashtag"></i> Exam ID: #<?php echo $exam['id']; ?></span>
                            <span><i class="fa-regular fa-file-lines"></i> Questions: <?php echo htmlspecialchars($total_questions); ?></span>
                            <span><i class="fa-solid fa-award"></i> Total Marks: <?php echo htmlspecialchars($exam['marks'] ?? '0.00'); ?></span>
                            <span><i class="fa-regular fa-clock"></i> Duration: <?php echo htmlspecialchars($exam['duration'] ?? '0'); ?> Mins</span>
                        </p>
                    </div>

                    <!-- Live Synchronized Duration Badge -->
                    <div class="hero-timer-box">
                        <span class="timer-label">EXAM DURATION REMAINING</span>
                        <div class="timer-display" id="inspectDurationTimer">--:--:--</div>
                        <div class="hero-actions">
                            <?php if (strcasecmp($exam['status'] ?? '', 'Running') === 0): ?>
                                <button type="button" class="btn btn-warning-hold" id="btnHoldFromInspect" title="Put Exam On Hold">
                                    <i class="fa-solid fa-pause"></i> Hold Exam
                                </button>
                            <?php elseif (strcasecmp($exam['status'] ?? '', 'Cancelled/On Hold') === 0): ?>
                                <button type="button" class="btn btn-resume-exam" id="btnResumeFromInspect" title="Resume Exam">
                                    <i class="fa-solid fa-play"></i> Resume Exam
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <section class="exam-proctoring-board" aria-label="Live audio and video proctoring">
                    <div class="teacher-proctor-controls">
                        <div>
                            <h2>Live Audio &amp; Video Proctoring</h2>
                            <p id="teacherProctorStatus" role="status">Start proctoring to connect with students who are taking this exam.</p>
                        </div>
                        <button type="button" id="btnStartProctoring" class="btn btn-primary">
                            <i class="fa-solid fa-video"></i> Start Proctoring
                        </button>
                        <button type="button" id="enableStudentAudioBtn" class="btn btn-secondary-refresh">
                            <i class="fa-solid fa-volume-high"></i> Enable Student Audio
                        </button>
                    </div>
                    <div class="teacher-proctor-self">
                        <video id="teacherLocalPreview" autoplay playsinline muted></video>
                        <span>Your camera and microphone</span>
                    </div>
                    <div id="teacherStudentVideoGrid" class="teacher-student-video-grid" aria-live="polite">
                        <p class="proctor-empty-state">Student camera and microphone feeds appear here when students open the exam.</p>
                    </div>
                </section>

                <!-- KPI Metric Grid -->
                <div class="kpi-metric-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon icon-blue"><i class="fa-solid fa-users"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-title">Total Enrolled</span>
                            <span class="kpi-value" id="kpiTotalEnrolled"><?php echo $stats['total_enrolled']; ?></span>
                        </div>
                    </div>
                    <div class="kpi-card live-card">
                        <div class="kpi-icon icon-green"><i class="fa-solid fa-laptop-code"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-title">Currently Giving Exam</span>
                            <span class="kpi-value text-green" id="kpiInProgress">
                                <?php echo $stats['in_progress']; ?>
                                <span class="live-dot-pulse-inline"></span>
                            </span>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon icon-indigo"><i class="fa-solid fa-file-circle-check"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-title">Submitted Papers</span>
                            <span class="kpi-value text-indigo" id="kpiSubmitted"><?php echo $stats['submitted']; ?></span>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon icon-amber"><i class="fa-solid fa-user-clock"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-title">Not Started Yet</span>
                            <span class="kpi-value text-muted" id="kpiNotStarted"><?php echo $stats['not_started']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Live Student Inspection Table Section -->
                <div class="inspect-table-card">
                    <div class="inspect-toolbar">
                        <div class="filter-tabs">
                            <button type="button" class="tab-btn active" data-filter="all">All Students (<span id="countFilterAll"><?php echo count($students_data); ?></span>)</button>
                            <button type="button" class="tab-btn" data-filter="in_progress">
                                <span class="live-badge-dot"></span> Giving Exam (<span id="countFilterProgress"><?php echo $stats['in_progress']; ?></span>)
                            </button>
                            <button type="button" class="tab-btn" data-filter="submitted">Submitted (<span id="countFilterSubmitted"><?php echo $stats['submitted']; ?></span>)</button>
                            <button type="button" class="tab-btn" data-filter="not_started">Not Started (<span id="countFilterNotStarted"><?php echo $stats['not_started']; ?></span>)</button>
                        </div>

                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="studentSearchInput" placeholder="Search by student name or ID..." class="search-input">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="inspect-table" id="studentInspectTable">
                            <thead>
                                <tr>
                                    <th># Roll / ID</th>
                                    <th>Student Details</th>
                                    <th>Current Status</th>
                                    <th>Live Proctoring</th>
                                    <th>Score / Result</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentTableBody">
                                <?php if (empty($students_data)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted" style="text-align: center; padding: 40px; color: #64748b;">
                                            <i class="fa-solid fa-user-slash" style="font-size: 2rem; margin-bottom: 10px; display: block; color: #9ca3af;"></i>
                                            No enrolled students found for batch "<?php echo htmlspecialchars($exam['batch'] ?? ''); ?>".
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($students_data as $st): ?>
                                        <tr class="student-row"
                                            data-status-type="<?php echo $st['status_type']; ?>"
                                            data-student-id="<?php echo htmlspecialchars($st['student_id']); ?>"
                                            data-student-name="<?php echo htmlspecialchars($st['full_name']); ?>">
                                            <td><div class="student-id-badge"><strong><?php echo htmlspecialchars($st['student_id']); ?></strong></div></td>
                                            <td>
                                                <div class="student-meta" style="display: flex; align-items: center; gap: 10px;">
                                                    <img src="<?php echo htmlspecialchars($st['profile_image']); ?>" alt="Avatar" class="student-avatar" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($st['full_name']); ?>&background=4338ca&color=fff'">
                                                    <div>
                                                        <div class="student-name" style="font-weight: 600;"><?php echo htmlspecialchars($st['full_name']); ?></div>
                                                        <div class="student-email" style="font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($st['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($st['status_type'] === 'submitted'): ?>
                                                    <span class="status-pill pill-submitted"><i class="fa-solid fa-check"></i> Submitted</span>
                                                <?php elseif ($st['status_type'] === 'in_progress'): ?>
                                                    <span class="status-pill pill-progress"><span class="pulse-dot"></span> Giving Exam</span>
                                                <?php else: ?>
                                                    <span class="status-pill pill-not-started"><i class="fa-regular fa-circle"></i> Not Connected</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="proctor-connection" data-proctor-status="disconnected">Not connected</span></td>
                                            <td>
                                                <?php if ($st['marks_obtained'] !== null && $st['marks_obtained'] !== ''): ?>
                                                    <span class="marks-badge" style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.85rem;"><?php echo htmlspecialchars($st['marks_obtained']); ?> Marks</span>
                                                <?php elseif ($st['status_type'] === 'submitted'): ?>
                                                    <span class="eval-badge" style="background: #f1f5f9; color: #475569; padding: 4px 8px; border-radius: 6px; font-size: 0.82rem;">Pending Review</span>
                                                <?php else: ?>
                                                    <span class="text-muted" style="color: #94a3b8;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div class="table-action-buttons">
                                                    <button type="button" class="btn-force-submit btn-sm" data-student-id="<?php echo htmlspecialchars($st['student_id']); ?>" data-student-name="<?php echo htmlspecialchars($st['full_name']); ?>" title="Force submit this student's exam" hidden>
                                                        <i class="fa-solid fa-upload"></i> Force Submit
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <!-- Modal: Force Submit Confirmation -->
    <div id="forceSubmitModal" class="custom-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="custom-modal-box">
            <div class="custom-modal-header">
                <div class="custom-modal-icon danger">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 class="custom-modal-title">Confirm Force Submit</h3>
                    <p style="margin: 2px 0 0; font-size: 0.82rem; color: #dc2626; font-weight: 600;">Student Examination Action</p>
                </div>
            </div>
            <div class="custom-modal-body">
                <p>Are you sure you want to force submit the exam paper for this student? Their answers will be submitted immediately and they will not be able to continue writing.</p>
                <div class="modal-exam-pill modal-exam-pill-danger" id="forceSubmitPill">
                    <strong id="forceSubmitStudentTarget">Student #--</strong>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelForceSubmitBtn">Cancel</button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmForceSubmitBtn">
                    <i class="fa-solid fa-upload"></i> Yes, Force Submit
                </button>
            </div>
        </div>
    </div>

    <!-- External JavaScript -->
    <script src="../assets/js/common/exam-proctoring.js"></script>
    <script src="../assets/js/teacher/teacher-inspectExam-page.js"></script>

</body>
</html>
