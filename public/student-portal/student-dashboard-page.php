<?php
// 1. Safe Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration
$configPaths = [
    __DIR__ . '/../../src/config/config.php',
    __DIR__ . '/../src/config/config.php',
    __DIR__ . '/../config/config.php'
];

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// 4. FETCH LOGGED-IN STUDENT DETAILS SAFELY
$studentData = [];

if ($dbConn && !empty($_SESSION['email'])) {
    try {
        $stmt_s = $dbConn->prepare("SELECT * FROM `students` WHERE LOWER(TRIM(`email`)) = LOWER(TRIM(:email)) LIMIT 1");
        $stmt_s->execute(['email' => $_SESSION['email']]);
        $studentData = $stmt_s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Student lookup error: " . $e->getMessage());
    }
}

if (empty($studentData) && $dbConn && !empty($_SESSION['user_id'])) {
    try {
        $stmt_s = $dbConn->prepare("
            SELECT s.* 
            FROM `users-table` u 
            LEFT JOIN `students` s ON LOWER(TRIM(s.email)) = LOWER(TRIM(u.email)) 
            WHERE u.id = :uid LIMIT 1
        ");
        $stmt_s->execute(['uid' => $_SESSION['user_id']]);
        $studentData = $stmt_s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("User lookup error: " . $e->getMessage());
    }
}

$student_db_id = !empty($studentData['id']) ? (int)$studentData['id'] : 0;
$logged_student_name    = !empty($studentData['full_name'])  ? $studentData['full_name']  : ($_SESSION['username'] ?? 'Student');
$logged_student_code    = !empty($studentData['student_id']) ? $studentData['student_id'] : ($_SESSION['student_id'] ?? 'N/A');
$logged_student_program = !empty($studentData['program'])    ? $studentData['program']    : 'B.Sc. Program';

// Collect all student batch enrollments from `students` and `student_enrollments`
$student_batches = [];
if (!empty($studentData['batch']) && trim($studentData['batch']) !== '' && trim($studentData['batch']) !== 'N/A') {
    $student_batches[] = trim($studentData['batch']);
}

$has_pending_enrollment = false;
$enrollment_request_status = 'None';
if ($dbConn && $student_db_id > 0) {
    try {
        $stmt_enr = $dbConn->prepare("
            SELECT `batch`, `status` 
            FROM `student_enrollments` 
            WHERE `student_id` = ? 
            ORDER BY `id` DESC
        ");
        $stmt_enr->execute([$student_db_id]);
        $enr_rows = $stmt_enr->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($enr_rows as $erow) {
            if ($erow['status'] === 'Enrolled' && !empty($erow['batch'])) {
                $student_batches[] = trim($erow['batch']);
            } elseif ($erow['status'] === 'Pending') {
                $has_pending_enrollment = true;
                if ($enrollment_request_status === 'None') {
                    $enrollment_request_status = 'Pending';
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching enrollments: " . $e->getMessage());
    }
}

$student_batches = array_values(array_unique(array_filter($student_batches)));
$logged_student_batch = !empty($student_batches) ? implode(', ', $student_batches) : (!empty($studentData['batch']) ? $studentData['batch'] : 'N/A');

$_SESSION['student_name']    = $logged_student_name;
$_SESSION['student_id']      = $logged_student_code;
$_SESSION['student_batch']   = $logged_student_batch;
$_SESSION['student_program'] = $logged_student_program;

// Collect student identifiers for matching in exam_submissions and student_answers
$student_ids = array_values(array_unique(array_filter([
    $logged_student_code !== 'N/A' ? (string)$logged_student_code : null,
    $student_db_id > 0 ? (string)$student_db_id : null,
    !empty($_SESSION['student_id']) && $_SESSION['student_id'] !== 'N/A' ? (string)$_SESSION['student_id'] : null,
    !empty($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null
])));
if (empty($student_ids)) {
    $student_ids = ['__NONE__'];
}
$stu_in = implode(',', array_fill(0, count($student_ids), '?'));

// 5. DASHBOARD STATS CALCULATION
$total_courses      = 0;
$total_exams        = 0;
$participated_exams = 0;
$missed_exams       = 0;
$passed_exams       = 0;
$failed_exams       = 0;

if ($dbConn && !empty($student_batches)) {
    $batch_in = implode(',', array_fill(0, count($student_batches), '?'));

    // A. Total Courses
    try {
        $stmt_c = $dbConn->prepare("
            SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(`course_code`), ''), NULLIF(TRIM(`subject`), ''))) 
            FROM `batches` 
            WHERE `batch` IN ($batch_in)
        ");
        $stmt_c->execute($student_batches);
        $total_courses = (int)$stmt_c->fetchColumn();

        if ($student_db_id > 0) {
            $stmt_sec = $dbConn->prepare("
                SELECT COUNT(DISTINCT sec.course_code) 
                FROM `student_enrollment_courses` sec
                JOIN `student_enrollments` se ON se.id = sec.enrollment_id
                WHERE se.student_id = ? AND se.status = 'Enrolled'
            ");
            $stmt_sec->execute([$student_db_id]);
            $enr_courses_cnt = (int)$stmt_sec->fetchColumn();
            if ($enr_courses_cnt > $total_courses) {
                $total_courses = $enr_courses_cnt;
            }
        }
    } catch (Exception $e) {
        error_log("Error counting courses: " . $e->getMessage());
    }

    // B. Total Exams Scheduled for Student's Batches
    try {
        $stmt_e = $dbConn->prepare("
            SELECT COUNT(DISTINCT id) 
            FROM `exam_details` 
            WHERE `batch` IN ($batch_in)
        ");
        $stmt_e->execute($student_batches);
        $total_exams = (int)$stmt_e->fetchColumn();

        try {
            $stmt_eh = $dbConn->prepare("
                SELECT COUNT(DISTINCT id) 
                FROM `exam_header` 
                WHERE `batch` IN ($batch_in)
            ");
            $stmt_eh->execute($student_batches);
            $header_exams = (int)$stmt_eh->fetchColumn();
            if ($header_exams > $total_exams) {
                $total_exams = $header_exams;
            }
        } catch (Exception $eh_err) {}
    } catch (Exception $e) {
        error_log("Error counting exams: " . $e->getMessage());
    }
}

// C. Participated Exams
if ($dbConn) {
    try {
        $stmt_p = $dbConn->prepare("
            SELECT COUNT(DISTINCT exam_id) 
            FROM `exam_submissions` 
            WHERE `student_id` IN ($stu_in)
        ");
        $stmt_p->execute($student_ids);
        $participated_exams = (int)$stmt_p->fetchColumn();

        try {
            $stmt_ans = $dbConn->prepare("
                SELECT COUNT(DISTINCT exam_id) 
                FROM `student_answers` 
                WHERE `student_id` IN ($stu_in)
            ");
            $stmt_ans->execute($student_ids);
            $ans_participated = (int)$stmt_ans->fetchColumn();
            if ($ans_participated > $participated_exams) {
                $participated_exams = $ans_participated;
            }
        } catch (Exception $ans_err) {}
    } catch (Exception $e) {
        error_log("Error counting participated exams: " . $e->getMessage());
    }
}

// D. Missed Exams
if ($dbConn && !empty($student_batches)) {
    try {
        $batch_in = implode(',', array_fill(0, count($student_batches), '?'));
        $missed_params = array_merge($student_batches, $student_ids);
        $stmt_m = $dbConn->prepare("
            SELECT COUNT(DISTINCT e.id) 
            FROM `exam_details` e
            WHERE e.`batch` IN ($batch_in)
              AND e.`status` NOT IN ('Cancelled/On Hold')
              AND (
                  e.`status` = 'Completed' 
                  OR e.`exam_date` < CURDATE()
                  OR (e.`exam_date` = CURDATE() AND ADDTIME(e.`exam_time`, SEC_TO_TIME(COALESCE(e.`duration`, 0) * 60)) < CURTIME())
              )
              AND e.`id` NOT IN (
                  SELECT DISTINCT s.exam_id 
                  FROM `exam_submissions` s 
                  WHERE s.`student_id` IN ($stu_in)
              )
        ");
        $stmt_m->execute($missed_params);
        $missed_exams = (int)$stmt_m->fetchColumn();
    } catch (Exception $e) {
        error_log("Error counting missed exams: " . $e->getMessage());
    }
}

// E. Passed and Failed Exams (Calculated from exam_evaluations and exam_submissions)
if ($dbConn) {
    try {
        $stmt_eval = $dbConn->prepare("
            SELECT 
                s.id AS submission_id,
                s.exam_id,
                ev.status AS eval_status,
                ev.total_marks_obtained,
                COALESCE(ed.total_marks, eh.marks, 100) AS exam_total_marks
            FROM `exam_submissions` s
            LEFT JOIN `exam_evaluations` ev ON ev.submission_id = s.id
            LEFT JOIN `exam_details` ed ON ed.id = s.exam_id
            LEFT JOIN `exam_header` eh ON eh.id = s.exam_id
            WHERE s.`student_id` IN ($stu_in)
        ");
        $stmt_eval->execute($student_ids);
        $eval_rows = $stmt_eval->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($eval_rows as $row) {
            $max_mark = (float)($row['exam_total_marks'] ?? 100);
            if ($max_mark <= 0) $max_mark = 100;
            $pass_threshold = $max_mark * 0.40; // 40% standard passing cutoff

            if (!empty($row['eval_status']) && $row['eval_status'] === 'Graded') {
                $obtained = (float)($row['total_marks_obtained'] ?? 0);
                if ($obtained >= $pass_threshold) {
                    $passed_exams++;
                } else {
                    $failed_exams++;
                }
            } else {
                // If not in exam_evaluations yet, check if student_answers sum exists
                $stmt_ans_sum = $dbConn->prepare("
                    SELECT COALESCE(SUM(marks_obtained), 0) AS total_ans_marks 
                    FROM `student_answers` 
                    WHERE `submission_id` = ?
                ");
                $stmt_ans_sum->execute([$row['submission_id']]);
                $ans_obtained = (float)$stmt_ans_sum->fetchColumn();
                if ($ans_obtained > 0) {
                    if ($ans_obtained >= $pass_threshold) {
                        $passed_exams++;
                    } else {
                        $failed_exams++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating pass/fail exams: " . $e->getMessage());
    }
}

// 6. TODAY'S EXAMS FOR STUDENT BATCH
$today_exams = [];
$submitted_exam_ids = [];

if ($dbConn) {
    try {
        $stmt_subs = $dbConn->prepare("
            SELECT DISTINCT `exam_id` 
            FROM `exam_submissions` 
            WHERE `student_id` IN ($stu_in) AND `status` = 'Submitted'
        ");
        $stmt_subs->execute($student_ids);
        $submitted_exam_ids = $stmt_subs->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {}

    if (!empty($student_batches)) {
        try {
            $today_date = date('Y-m-d');
            $batch_in = implode(',', array_fill(0, count($student_batches), '?'));
            $today_params = array_merge($student_batches, [$today_date]);

            // Query from exam_details
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
                LEFT JOIN `teachers` t ON (t.id = e.teacher_id OR t.teacher_id = e.teacher_id)
                WHERE e.`batch` IN ($batch_in)
                  AND e.`exam_date` = ? 
                ORDER BY e.`exam_time` ASC
            ");
            $stmt_today->execute($today_params);
            $today_exams = $stmt_today->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Cross-check exam_header for any today exams
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
                    LEFT JOIN `teachers` t ON (t.id = e.teacher_id OR t.teacher_id = e.teacher_id)
                    WHERE e.`batch` IN ($batch_in)
                      AND e.`exam_date` = ? 
                    ORDER BY e.`exam_time` ASC
                ");
                $stmt_th->execute($today_params);
                $header_today = $stmt_th->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $existing_ids = array_column($today_exams, 'id');
                foreach ($header_today as $hexam) {
                    if (!in_array($hexam['id'], $existing_ids)) {
                        $today_exams[] = $hexam;
                    }
                }
            } catch (Exception $th_err) {}
        } catch (Exception $e) {
            error_log("Error fetching today exams: " . $e->getMessage());
            $today_exams = [];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Virtual Exam System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-dashboard-page.css">
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

        <!-- STUDENT HEADER AND SIDEBAR (Opens .content-wrapper) -->
        <?php require_once __DIR__ . '/student-header-sidebar-section.php'; ?>

        <!-- DASHBOARD CONTENT INSIDE CONTENT WRAPPER -->
        <main class="dashboard">

            <div class="welcome-section" style="margin-bottom: 25px;">
                <h1>Student Dashboard</h1>
                <p style="color: #64748b;">Welcome back, <strong><?php echo htmlspecialchars($logged_student_name); ?></strong> (ID: <?php echo htmlspecialchars($logged_student_code); ?> | Batch: <?php echo htmlspecialchars($logged_student_batch); ?>)</p>
            </div>

            <!-- Stats Section (6 Cards Grid) -->
            <div class="stats">

                <div class="card">
                    <div class="icon primary">
                        <i class="fa-solid fa-book"></i>
                    </div>
                    <div class="info">
                        <p>Total Courses</p>
                        <h2><?php echo $total_courses; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon primary">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>
                    <div class="info">
                        <p>Total Exams</p>
                        <h2><?php echo $total_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon green">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="info">
                        <p>Participated</p>
                        <h2><?php echo $participated_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon orange">
                        <i class="fa-solid fa-calendar-xmark"></i>
                    </div>
                    <div class="info">
                        <p>Missed</p>
                        <h2><?php echo $missed_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon success">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div class="info">
                        <p>Passed</p>
                        <h2><?php echo $passed_exams; ?></h2>
                    </div>
                </div>

                <div class="card">
                    <div class="icon danger">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                    <div class="info">
                        <p>Failed</p>
                        <h2><?php echo $failed_exams; ?></h2>
                    </div>
                </div>

            </div>

            <!-- Exam Table Section -->
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
                                    $examId = (int)($exam['id'] ?? 0);
                                    $hasSubmitted = in_array($examId, $submitted_exam_ids);

                                    $statusClass = 'status-active';
                                    if ($currentStatus === 'Cancelled/On Hold') {
                                        $statusClass = 'status-cancelled';
                                    } elseif ($currentStatus === 'Edited/Rescheduled') {
                                        $statusClass = 'status-rescheduled';
                                    }

                                    $tName = !empty($exam['real_teacher_name']) ? $exam['real_teacher_name'] : (!empty($exam['teacher_name']) ? $exam['teacher_name'] : 'N/A');
                                    $tId   = !empty($exam['real_teacher_id'])   ? $exam['real_teacher_id']   : (!empty($exam['teacher_id']) ? $exam['teacher_id'] : 'N/A');
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
                                            <?php if ($hasSubmitted): ?>
                                                <span class="status-badge" style="background-color: #dcfce7; color: #15803d;">
                                                    <i class="fa-solid fa-check-circle"></i> Submitted
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge <?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($currentStatus); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasSubmitted): ?>
                                                <a href="student-viewResultList-page.php" class="start-btn" style="text-decoration:none; display:inline-block; text-align:center; background:#059669;">
                                                    View Result
                                                </a>
                                            <?php elseif ($currentStatus === 'Cancelled/On Hold'): ?>
                                                <button class="start-btn" disabled style="opacity:0.6; cursor:not-allowed; background:#94a3b8; border:none; color:#fff; padding:6px 14px; border-radius:6px;">
                                                    Cancelled
                                                </button>
                                            <?php else: ?>
                                                <a href="start-exam-page.php?exam_id=<?php echo $examId; ?>" class="start-btn" style="text-decoration:none; display:inline-block; text-align:center;">
                                                    Start Exam
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <?php if (empty($student_batches) || $logged_student_batch === 'N/A'): ?>
                        <?php if ($has_pending_enrollment): ?>
                            <div class="empty-state-card">
                                <div class="empty-state-icon" style="color: #f59e0b;">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                </div>
                                <h3>Batch Enrollment Pending Approval</h3>
                                <p>Your batch enrollment request is currently under review by the administration. Once verified and approved, your assigned batch courses, syllabus, and live exam schedules will appear here automatically.</p>
                                <div class="empty-state-actions">
                                    <a href="Student-viewSyllabusList-page.php" class="action-btn" style="display:inline-flex; align-items:center; gap:8px; background:#f1f5f9; color:#334155; padding:9px 18px; border-radius:8px; text-decoration:none; font-weight:500; border:1px solid #cbd5e1;">
                                        <i class="fa-solid fa-book-open"></i> Browse Syllabuses
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state-card">
                                <div class="empty-state-icon" style="color: #94a3b8;">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <h3>No Academic Batch Enrolled</h3>
                                <p>You are not currently enrolled in any academic batch. Please complete your batch enrollment to view scheduled examinations, access course materials, and participate in online assessments.</p>
                                <div class="empty-state-actions">
                                    <a href="student-enrollment-page.php" class="action-btn" style="display:inline-flex; align-items:center; gap:8px; background:#2563eb; color:#fff; padding:9px 18px; border-radius:8px; text-decoration:none; font-weight:500;">
                                        <i class="fa-solid fa-plus-circle"></i> Enroll in a Batch
                                    </a>
                                    <a href="view-syllabusList-page.php" class="action-btn" style="display:inline-flex; align-items:center; gap:8px; background:#f1f5f9; color:#334155; padding:9px 18px; border-radius:8px; text-decoration:none; font-weight:500; border:1px solid #cbd5e1;">
                                        <i class="fa-solid fa-book-open"></i> Browse Syllabuses
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state-card">
                            <div class="empty-state-icon" style="color: #3b82f6;">
                                <i class="fa-regular fa-calendar-check"></i>
                            </div>
                            <h3>No Exams Scheduled Today</h3>
                            <p>There are no online exams scheduled for your batch (<strong><?php echo htmlspecialchars($logged_student_batch); ?></strong>) today (<?php echo date('d M, Y'); ?>).</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </section>

            <!-- Bottom Action Buttons -->
            <div class="btn-flex" style="display: flex; gap: 15px; margin-top: 25px; flex-wrap: wrap;">
                <a href="student-viewExamList-page.php" class="action-btn btn-exam-list">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>View Exam List</span>
                </a>

                <a href="student-viewSyllabusList-page.php" class="action-btn btn-syllabus-list">
                    <i class="fa-solid fa-book-open"></i>
                    <span>View All Batch Syllabus</span>
                </a>

                <a href="student-viewResultList-page.php" class="action-btn btn-results-list">
                    <i class="fa-solid fa-award"></i>
                    <span>View Results List</span>
                </a>
            </div>

        </main>

    </div><!-- CLOSE CONTENT WRAPPER -->

</div><!-- CLOSE MAIN LAYOUT -->

<!-- JavaScript Files -->
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>
<script src="../assets/js/student/student-dashboard-page.js"></script>
<script src="../assets/js/teacher/view-examList-page.js"></script>

</body>

</html>