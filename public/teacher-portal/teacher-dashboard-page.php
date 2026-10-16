<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration
require_once __DIR__ . '/../../src/config/config.php';

// Handle PDO variable standard safely
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if (!$dbConn) {
    die("Database connection failed. Please check your configuration.");
}

// -------------------------------------------------------------------------
// 4. FETCH LOGGED-IN TEACHER INFO
// -------------------------------------------------------------------------
$teacher = false;

// Step 1: Try fetching by session email first
if (!empty($_SESSION['email'])) {
    $stmt_teacher = $dbConn->prepare("SELECT * FROM `teachers` WHERE LOWER(TRIM(`email`)) = LOWER(TRIM(:email)) LIMIT 1");
    $stmt_teacher->execute(['email' => $_SESSION['email']]);
    $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC);
}

// Step 2: Fallback to user_id if email lookup didn't find teacher
if (!$teacher && !empty($_SESSION['user_id'])) {
    $stmt_teacher = $dbConn->prepare("
        SELECT t.*, u.id AS user_table_id 
        FROM `users-table` u
        LEFT JOIN `teachers` t 
               ON LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(t.email)) COLLATE utf8mb4_unicode_ci
        WHERE u.id = :uid LIMIT 1
    ");
    $stmt_teacher->execute(['uid' => $_SESSION['user_id']]);
    $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC);
}

// Ensure teacher_id is always string teacher_id (e.g., TCH-001)
$logged_teacher_code = !empty($teacher['teacher_id']) ? $teacher['teacher_id'] : ($_SESSION['teacher_id'] ?? 'N/A');
$logged_teacher_name = !empty($teacher['full_name'])  ? $teacher['full_name']  : ($_SESSION['teacher_name'] ?? 'N/A');

// SYNC ALL TEACHER DATA TO SESSION
if ($teacher) {
    $_SESSION['teacher_id']     = $teacher['teacher_id'];
    $_SESSION['teacher_name']   = $teacher['full_name'];
    $_SESSION['profile_image']  = $teacher['profile_image'];
}

// -------------------------------------------------------------------------
// PREPARE ALL POSSIBLE MATCHES FOR teacher_id IN QUERIES
// -------------------------------------------------------------------------
$teacher_ids = [];

if (!empty($teacher['teacher_id']))    $teacher_ids[] = (string)$teacher['teacher_id'];
if (!empty($teacher['id']))            $teacher_ids[] = (string)$teacher['id'];
if (!empty($teacher['user_table_id'])) $teacher_ids[] = (string)$teacher['user_table_id'];
if (!empty($_SESSION['teacher_id']))   $teacher_ids[] = (string)$_SESSION['teacher_id'];
if (!empty($_SESSION['user_id']))      $teacher_ids[] = (string)$_SESSION['user_id'];

// Filter out duplicates and empty values
$teacher_ids = array_values(array_unique(array_filter($teacher_ids)));

if (empty($teacher_ids)) {
    $teacher_ids = ['N/A'];
}

$in_placeholders = implode(',', array_fill(0, count($teacher_ids), '?'));

// -------------------------------------------------------------------------
// 5. INITIALIZE ALL DASHBOARD STAT VARIABLES
// -------------------------------------------------------------------------
$total_batches     = 0;
$total_courses     = 0;
$active_exams      = 0;
$rescheduled_exams = 0;
$results_published = 0;
$cancelled_exams   = 0;

// A. FETCH UNIQUE BATCHES & COURSES COUNT ACROSS batches, exam_details, AND exam_header
try {
    // 1. Primary counts from batches table
    $stmt_batch_stats = $dbConn->prepare("
        SELECT 
            COUNT(DISTINCT NULLIF(TRIM(`batch`), '')) AS total_batches,
            COUNT(DISTINCT COALESCE(NULLIF(TRIM(`course_code`), ''), NULLIF(TRIM(`subject`), ''))) AS total_courses
        FROM `batches`
        WHERE `teacher_id` IN ($in_placeholders)
    ");
    $stmt_batch_stats->execute($teacher_ids);
    $batch_counts = $stmt_batch_stats->fetch(PDO::FETCH_ASSOC);

    if ($batch_counts) {
        $total_batches = (int)($batch_counts['total_batches'] ?? 0);
        $total_courses = (int)($batch_counts['total_courses'] ?? 0);
    }

    // 2. Union unique batches across batches, exam_details, and exam_header
    $stmt_all_b = $dbConn->prepare("
        SELECT DISTINCT `batch` FROM `batches` WHERE `teacher_id` IN ($in_placeholders) AND NULLIF(TRIM(`batch`), '') IS NOT NULL
        UNION
        SELECT DISTINCT `batch` FROM `exam_details` WHERE `teacher_id` IN ($in_placeholders) AND NULLIF(TRIM(`batch`), '') IS NOT NULL
        UNION
        SELECT DISTINCT `batch` FROM `exam_header` WHERE `teacher_id` IN ($in_placeholders) AND NULLIF(TRIM(`batch`), '') IS NOT NULL
    ");
    $stmt_all_b->execute(array_merge($teacher_ids, $teacher_ids, $teacher_ids));
    $union_batches = $stmt_all_b->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($union_batches) > $total_batches) {
        $total_batches = count($union_batches);
    }

    // 3. Union unique courses/subjects across batches, exam_details, and exam_header
    $stmt_all_c = $dbConn->prepare("
        SELECT DISTINCT COALESCE(NULLIF(TRIM(`course_code`), ''), NULLIF(TRIM(`subject`), '')) AS course 
        FROM `batches` 
        WHERE `teacher_id` IN ($in_placeholders) AND (NULLIF(TRIM(`course_code`), '') IS NOT NULL OR NULLIF(TRIM(`subject`), '') IS NOT NULL)
        UNION
        SELECT DISTINCT NULLIF(TRIM(`subject`), '') AS course 
        FROM `exam_details` 
        WHERE `teacher_id` IN ($in_placeholders) AND NULLIF(TRIM(`subject`), '') IS NOT NULL
        UNION
        SELECT DISTINCT COALESCE(NULLIF(TRIM(`course_code`), ''), NULLIF(TRIM(`subject`), '')) AS course 
        FROM `exam_header` 
        WHERE `teacher_id` IN ($in_placeholders) AND (NULLIF(TRIM(`course_code`), '') IS NOT NULL OR NULLIF(TRIM(`subject`), '') IS NOT NULL)
    ");
    $stmt_all_c->execute(array_merge($teacher_ids, $teacher_ids, $teacher_ids));
    $union_courses = $stmt_all_c->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($union_courses) > $total_courses) {
        $total_courses = count($union_courses);
    }
} catch (Exception $e) {
    error_log("Error fetching batch & course stats: " . $e->getMessage());
}

// B. FETCH EXAM STATUS COUNTS FROM exam_details AND exam_header TABLES
try {
    $stmt_exam_stats = $dbConn->prepare("
        SELECT 
            SUM(CASE WHEN `status` IN ('Published', 'Running', 'Active') THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN `status` = 'Edited/Rescheduled' THEN 1 ELSE 0 END) AS rescheduled_cnt,
            SUM(CASE WHEN `status` = 'Cancelled/On Hold' THEN 1 ELSE 0 END) AS cancelled_cnt
        FROM `exam_details`
        WHERE `teacher_id` IN ($in_placeholders)
    ");
    $stmt_exam_stats->execute($teacher_ids);
    $counts = $stmt_exam_stats->fetch(PDO::FETCH_ASSOC);

    if ($counts) {
        $active_exams      = (int)($counts['active_cnt'] ?? 0);
        $rescheduled_exams = (int)($counts['rescheduled_cnt'] ?? 0);
        $cancelled_exams   = (int)($counts['cancelled_cnt'] ?? 0);
    }

    // Cross-check exam_header if present
    try {
        $stmt_eh_stats = $dbConn->prepare("
            SELECT 
                SUM(CASE WHEN `status` IN ('Published', 'Running', 'Active') THEN 1 ELSE 0 END) AS active_cnt,
                SUM(CASE WHEN `status` = 'Edited/Rescheduled' THEN 1 ELSE 0 END) AS rescheduled_cnt,
                SUM(CASE WHEN `status` = 'Cancelled/On Hold' THEN 1 ELSE 0 END) AS cancelled_cnt
            FROM `exam_header`
            WHERE `teacher_id` IN ($in_placeholders)
        ");
        $stmt_eh_stats->execute($teacher_ids);
        $eh_counts = $stmt_eh_stats->fetch(PDO::FETCH_ASSOC);
        if ($eh_counts) {
            $active_exams      = max($active_exams, (int)($eh_counts['active_cnt'] ?? 0));
            $rescheduled_exams = max($rescheduled_exams, (int)($eh_counts['rescheduled_cnt'] ?? 0));
            $cancelled_exams   = max($cancelled_exams, (int)($eh_counts['cancelled_cnt'] ?? 0));
        }
    } catch (Exception $eh_err) {}
} catch (Exception $e) {
    error_log("Error fetching exam stats: " . $e->getMessage());
}

// C. FETCH PUBLISHED RESULTS COUNT (Completed exams OR graded evaluations in exam_evaluations)
try {
    $stmt_r = $dbConn->prepare("
        SELECT COUNT(DISTINCT e.id) 
        FROM `exam_details` e
        LEFT JOIN `exam_submissions` s ON s.exam_id = e.id
        LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
        WHERE e.`teacher_id` IN ($in_placeholders) 
          AND (e.`status` = 'Completed' OR ev.`status` = 'Graded')
    ");
    $stmt_r->execute($teacher_ids);
    $results_published = (int)$stmt_r->fetchColumn();

    // Cross-check exam_header
    try {
        $stmt_rh = $dbConn->prepare("
            SELECT COUNT(DISTINCT eh.id) 
            FROM `exam_header` eh
            LEFT JOIN `exam_submissions` s ON s.exam_id = eh.id
            LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
            WHERE eh.`teacher_id` IN ($in_placeholders) 
              AND (eh.`status` = 'Completed' OR ev.`status` = 'Graded')
        ");
        $stmt_rh->execute($teacher_ids);
        $rh_cnt = (int)$stmt_rh->fetchColumn();
        if ($rh_cnt > $results_published) {
            $results_published = $rh_cnt;
        }
    } catch (Exception $rh_err) {}
} catch (Exception $e) {
    error_log("Error counting results: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 6. FETCH EXAMS RUNNING TODAY WITH JOINED TEACHER DETAILS
// -------------------------------------------------------------------------
$today_exams = [];
try {
    $today_date = date('Y-m-d');
    $stmt_today_params = array_merge($teacher_ids, [$today_date]);
    $stmt_today = $dbConn->prepare("
        SELECT 
            e.id,
            e.teacher_id,
            e.teacher_name,
            e.batch,
            e.subject,
            e.exam_title,
            e.exam_type,
            e.exam_date,
            e.exam_time,
            e.duration,
            e.total_marks,
            e.status,
            t.teacher_id AS real_teacher_id, 
            t.full_name AS real_teacher_name
        FROM `exam_details` e
        LEFT JOIN `teachers` t 
               ON (t.id = e.teacher_id OR t.teacher_id = e.teacher_id)
        WHERE e.`teacher_id` IN ($in_placeholders) 
          AND e.`exam_date` = ? 
        ORDER BY e.`exam_time` ASC
    ");
    $stmt_today->execute($stmt_today_params);
    $today_exams = $stmt_today->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Cross-check exam_header for today
    try {
        $stmt_th = $dbConn->prepare("
            SELECT 
                e.id,
                e.teacher_id,
                e.teacher_name,
                e.batch,
                e.subject,
                e.exam_title,
                e.exam_type,
                e.exam_date,
                e.exam_time,
                e.duration,
                e.marks AS total_marks,
                e.status,
                t.teacher_id AS real_teacher_id, 
                t.full_name AS real_teacher_name
            FROM `exam_header` e
            LEFT JOIN `teachers` t 
                   ON (t.id = e.teacher_id OR t.teacher_id = e.teacher_id)
            WHERE e.`teacher_id` IN ($in_placeholders) 
              AND e.`exam_date` = ? 
            ORDER BY e.`exam_time` ASC
        ");
        $stmt_th->execute($stmt_today_params);
        $header_today = $stmt_th->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $existing_today_ids = array_column($today_exams, 'id');
        foreach ($header_today as $hexam) {
            if (!in_array($hexam['id'], $existing_today_ids)) {
                $today_exams[] = $hexam;
            }
        }
    } catch (Exception $th_err) {}
} catch (PDOException $e) {
    error_log("Error fetching today's exams: " . $e->getMessage());
    $today_exams = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    
    <!-- CSS Stylesheets -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-dashboard-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-quesTable-page.css">

    <style>
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .status-active {
            background-color: #dcfce7;
            color: #15803d;
        }
        .status-cancelled {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .status-rescheduled {
            background-color: #fef3c7;
            color: #b45309;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stats .card {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stats .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        .stats .card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stats .card .icon.primary, .stats .card .icon.blue {
            background: #eff6ff;
            color: #2563eb;
        }
        .stats .card .icon.green, .stats .card .icon.success {
            background: #ecfdf5;
            color: #059669;
        }
        .stats .card .icon.orange, .stats .card .icon.warning {
            background: #fffbeb;
            color: #d97706;
        }
        .stats .card .icon.danger, .stats .card .icon.red {
            background: #fef2f2;
            color: #dc2626;
        }
        .stats .card .info p {
            margin: 0;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
        }
        .stats .card .info h2 {
            margin: 4px 0 0 0;
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }
        .empty-state-card {
            text-align: center;
            padding: 48px 24px;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .empty-state-card h3 {
            font-size: 1.25rem;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .empty-state-card p {
            color: #64748b;
            max-width: 540px;
            margin: 0 auto 20px;
            line-height: 1.6;
        }
        .empty-state-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>

<body>

    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- DASHBOARD CONTENT START -->
        <main class="dashboard">

            <div class="welcome-section" style="margin-bottom: 25px;">
                <h1>Teacher Dashboard</h1>
                <p style="color: #64748b;">Welcome back, <strong><?php echo htmlspecialchars($logged_teacher_name); ?></strong> (ID: <?php echo htmlspecialchars($logged_teacher_code); ?>)</p>
            </div>

            <!-- Stats Section -->
            <div class="stats">

                

                <div class="card">
                    <div class="icon primary">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>
                    <div class="info">
                        <p>Total Courses Assigned</p>
                        <h2><?php echo $total_courses; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon green">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="info">
                        <p>Active Exams</p>
                        <h2><?php echo $active_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon orange">
                        <i class="fa-solid fa-calendar-xmark"></i>
                    </div>
                    <div class="info">
                        <p>Rescheduled</p>
                        <h2><?php echo $rescheduled_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon success">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div class="info">
                        <p>Results Published</p>
                        <h2><?php echo $results_published; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon danger">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                    <div class="info">
                        <p>Cancelled Exams</p>
                        <h2><?php echo $cancelled_exams; ?></h2>
                    </div>
                </div>

            </div>

            <!-- Dashboard TABLE AREA -->
            <section class="table-section">

                <div class="table-header">
                    <h2>Available Exams Today (<?php echo date('d/m/Y'); ?>)</h2>
                </div>

                <?php if (!empty($today_exams)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Teacher Name</th>
                                    <th>Teacher ID</th>
                                    <th>Batch</th>
                                    <th>Exam Title</th>
                                    <th>Exam Type</th>
                                    <th>Subject</th>
                                    <th>Date & Time</th>
                                    <th>Exam Starts In</th>
                                    <th>Duration</th>
                                    <th>Total Mark</th>
                                    <th>Exam Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_exams as $exam): 
                                    $currentStatus = $exam['status'] ?? 'Published';

                                    // Class mapping for status badges
                                    $statusClass = 'status-active';
                                    if ($currentStatus === 'Cancelled/On Hold') {
                                        $statusClass = 'status-cancelled';
                                    } elseif ($currentStatus === 'Edited/Rescheduled') {
                                        $statusClass = 'status-rescheduled';
                                    }

                                    // Resolve Teacher Name
                                    $tName = !empty($exam['real_teacher_name']) ? $exam['real_teacher_name'] : $logged_teacher_name;
                                    if (empty($tName)) { $tName = 'N/A'; }

                                    // Resolve Teacher ID
                                    $tId = !empty($exam['real_teacher_id']) ? $exam['real_teacher_id'] : $logged_teacher_code;
                                    if (empty($tId)) { $tId = 'N/A'; }

                                    // Local standard datetime string for JS
                                    $start_datetime = ($exam['exam_date'] ?? '') . ' ' . ($exam['exam_time'] ?? '');
                                ?>
                                    <tr class="exam-list-row"
                                        data-start-time="<?php echo htmlspecialchars($start_datetime); ?>"
                                        data-status="<?php echo htmlspecialchars($currentStatus); ?>">

                                        <td><?php echo htmlspecialchars($tName); ?></td>
                                        <td><?php echo htmlspecialchars($tId); ?></td>
                                        <td><?php echo htmlspecialchars($exam['batch'] ?? 'N/A'); ?></td>
                                        <td><strong><?php echo htmlspecialchars($exam['exam_title'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($exam['exam_type'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($exam['subject'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                                $exam_date = !empty($exam['exam_date']) ? $exam['exam_date'] : '';
                                                $exam_time = !empty($exam['exam_time']) ? $exam['exam_time'] : '';
                                                echo !empty($exam_date) ? date('d/m/Y', strtotime($exam_date)) . ' ' . date('h:i A', strtotime($exam_time)) : 'N/A';
                                            ?>
                                        </td>
                                        <td>
                                            <span class="timer-badge start-timer">--:--:--</span>
                                        </td>
                                        <td><?php echo htmlspecialchars($exam['duration'] ?? '0'); ?> min</td>
                                        <td><?php echo htmlspecialchars($exam['total_marks'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars($currentStatus); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view-exam-page.php?exam_id=<?php echo (int)($exam['id'] ?? 0); ?>" class="start-btn" style="text-decoration:none; display:inline-block; text-align:center;">
                                                View Exam
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <!-- Empty State View -->
                    <?php if ($total_batches === 0 && $total_courses === 0): ?>
                        <div class="empty-state-card">
                            <div class="empty-state-icon" style="color: #94a3b8;">
                                <i class="fa-solid fa-chalkboard-user"></i>
                            </div>
                            <h3>No Batches or Courses Assigned Yet</h3>
                            <p>You currently do not have any academic batches or courses assigned to your teacher account. Contact the system administrator or create a course syllabus to get started.</p>
                            <div class="empty-state-actions">
                                <a href="create-syllabus-page.php" class="action-btn" style="display:inline-flex; align-items:center; gap:8px; background:#2563eb; color:#fff; padding:9px 18px; border-radius:8px; text-decoration:none; font-weight:500;">
                                    <i class="fa-solid fa-plus-circle"></i> Create Batch Syllabus
                                </a>
                                <a href="teacher-viewSyllabusList-page.php" class="action-btn" style="display:inline-flex; align-items:center; gap:8px; background:#f1f5f9; color:#334155; padding:9px 18px; border-radius:8px; text-decoration:none; font-weight:500; border:1px solid #cbd5e1;">
                                    <i class="fa-solid fa-book-open"></i> Browse Syllabuses
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <div class="empty-state-icon" style="color: #3b82f6;">
                                <i class="fa-regular fa-calendar-check"></i>
                            </div>
                            <h3>No Exams Scheduled Today</h3>
                            <p>There are no online exams running today (<?php echo date('d M, Y'); ?>) across your supervised batches.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </section>

            <div class="btn-flex" style="display: flex; gap: 15px; margin-top: 25px; flex-wrap: wrap;">
                <a href="teacher-viewExamList-page.php" class="action-btn btn-exam-list">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>View Exam List</span>
                </a>

                <a href="teacher-viewSyllabusList-page.php" class="action-btn btn-syllabus-list">
                    <i class="fa-solid fa-book-open"></i>
                    <span>View All Batch Syllabus</span>
                </a>

                <a href="teacher-viewResultList-page.php" class="action-btn btn-results-list">
                    <i class="fa-solid fa-award"></i>
                    <span>View Results List</span>
                </a>
            </div>

        </main>

    </div>

<!-- JavaScript Files -->
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>
<script src="../assets/js/teacher/teacher-dashboard-page.js"></script>
<script src="../assets/js/teacher/teacher-viewExamList-page.js"></script>

</body>
</html>