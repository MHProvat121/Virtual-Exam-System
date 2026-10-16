<?php
// =========================================================================
// student-viewSyllabusList-page.php
// Prototype of Student Course Syllabus Lists grouped by Batches
// Replicated from teacher-viewSyllabusList-page.php
// Role: Student
// Feature 1: Live search bar below the header to filter batches & courses
// Feature 2: Pin button on each batch header to pin tables to the top
// Feature 3: Clean student view with View Syllabus action only
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

// 3. Database Connection
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
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null) ?? ($db instanceof PDO ? $db : null);

// Resolve logged-in student info
$logged_student_id   = $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? 'STU-2026-001';
$logged_student_name = $_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Alex Chen';
$studentBatch        = trim($_SESSION['batch'] ?? 'Batch 24');

// Initialize Session State for Pinned Batches
if (!isset($_SESSION['pinned_student_syllabus_batches'])) {
    $_SESSION['pinned_student_syllabus_batches'] = [];
}

// -------------------------------------------------------------------------
// 4. HANDLE FORM POST ACTIONS (PIN TOGGLE)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? $_POST['batch'] ?? '');

    // Feature 2: Toggle Pin Action
    if ($action === 'toggle_pin' && !empty($batchName)) {
        if (in_array($batchName, $_SESSION['pinned_student_syllabus_batches'])) {
            $_SESSION['pinned_student_syllabus_batches'] = array_values(array_diff($_SESSION['pinned_student_syllabus_batches'], [$batchName]));
        } else {
            $_SESSION['pinned_student_syllabus_batches'][] = $batchName;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// -------------------------------------------------------------------------
// 5. FETCH DATA FOR ALL BATCHES
// -------------------------------------------------------------------------
$groupedExams = [];

if ($dbConn) {
    try {
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
            ORDER BY b.batch ASC, b.id DESC
        ");
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
            $groupedExams[$row['batch']][] = $row; 
        }
    } catch (Exception $e) {
        $groupedExams = [];
    }
}

// Prototype Fallback Sample Records if database records are empty or not yet seeded
if (empty($groupedExams)) {
    $groupedExams = [
        'Batch 24' => [
            [
                'id' => 101,
                'batch' => 'Batch 24',
                'teacher_full_name' => 'Dr. Robert Miller',
                'display_teacher_id' => 'T-501',
                'teacher_id' => 'T-501',
                'course_code' => 'CS-302',
                'subject' => 'Database Systems & SQL Optimization',
                'credit' => '3.0',
                'exam_title' => 'Final Examination Syllabus',
                'created_at' => date('Y-m-d H:i:s', strtotime('-15 days')),
            ],
            [
                'id' => 102,
                'batch' => 'Batch 24',
                'teacher_full_name' => 'Prof. Sarah Jenkins',
                'display_teacher_id' => 'T-502',
                'teacher_id' => 'T-502',
                'course_code' => 'MATH-204',
                'subject' => 'Differential Equations & Linear Algebra',
                'credit' => '3.5',
                'exam_title' => 'Midterm Assessment Syllabus',
                'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            ],
            [
                'id' => 103,
                'batch' => 'Batch 24',
                'teacher_full_name' => 'Dr. Gregory Vance',
                'display_teacher_id' => 'T-503',
                'teacher_id' => 'T-503',
                'course_code' => 'PHYS-101',
                'subject' => 'Electromagnetism & Wave Mechanics',
                'credit' => '4.0',
                'exam_title' => 'Semester Syllabus & Reading List',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ],
        ],
        'Batch 25' => [
            [
                'id' => 104,
                'batch' => 'Batch 25',
                'teacher_full_name' => 'Dr. Emily Thornton',
                'display_teacher_id' => 'T-504',
                'teacher_id' => 'T-504',
                'course_code' => 'BIO-210',
                'subject' => 'Molecular Genetics & Cellular Biology',
                'credit' => '3.0',
                'exam_title' => 'Comprehensive Course Syllabus',
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            ],
            [
                'id' => 105,
                'batch' => 'Batch 25',
                'teacher_full_name' => 'Prof. David Lawson',
                'display_teacher_id' => 'T-505',
                'teacher_id' => 'T-505',
                'course_code' => 'ENG-301',
                'subject' => 'Academic Writing & Research Methods',
                'credit' => '2.5',
                'exam_title' => 'Course Modules & Research Guide',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
        ]
    ];
}

// Feature 2: Sort Batches with Pinned Batches placed on top
$displayBatches = $groupedExams;
uksort($displayBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_student_syllabus_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_student_syllabus_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Syllabus - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Portal CSS architecture -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-courseSyllabus-page.css">
</head>
<body>
    <div class="main-layout">
        <?php 
            if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
                require_once __DIR__ . '/student-header-sidebar-section.php'; 
            } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
                require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
            }
        ?>
        <main class="dashboard">
            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fa-solid fa-book-open-reader" style="color: #0266cc;"></i> Available Batches & Course Syllabus</h1>
                    <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                        Student: <strong><?php echo htmlspecialchars($logged_student_name); ?></strong> (<?php echo htmlspecialchars($logged_student_id); ?>) &bull; Enrolled: <strong><?php echo htmlspecialchars($studentBatch); ?></strong>
                    </p>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="student-dashboard-page.php" class="btn-action btn-back" style="text-decoration: none; padding: 9px 16px; background: #f1f5f9; color: #475569; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #cbd5e1;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Feature 1: Live Search Bar -->
            <div class="search-container">
                <div class="search-input-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input 
                        type="text" 
                        id="courseBatchSearch" 
                        onkeyup="filterCourseBatches()" 
                        placeholder="Search available batches, subjects, course codes, or instructors..."
                    >
                </div>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches or course syllabi match your search query.</p>
            </div>

            <!-- Batches Container -->
            <section class="table-section-container">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $exams): 
                    $advisorRecord = null;
                    foreach ($exams as $ex) {
                        if (empty($ex['subject']) && empty($ex['course_code'])) {
                            $advisorRecord = $ex;
                            break;
                        }
                    }
                    if (!$advisorRecord) {
                        $advisorRecord = $exams[count($exams) - 1];
                    }
                    $batchCreatedBy = !empty($advisorRecord['teacher_full_name']) && $advisorRecord['teacher_full_name'] !== 'Unknown Teacher' ? $advisorRecord['teacher_full_name'] : (!empty($advisorRecord['teacher_id']) ? $advisorRecord['teacher_id'] : 'N/A');
                    $batchCreationDate = !empty($advisorRecord['created_at']) ? date('M d, Y', strtotime($advisorRecord['created_at'])) : 'N/A';
                    $isPinned = in_array($batchName, $_SESSION['pinned_student_syllabus_batches']);
                ?>
                    <div class="batch-card" style="background:#fff; border-radius:12px; border: 1px solid #e2e8f0; margin-bottom: 25px; overflow:hidden;">
                        
                        <!-- Batch Header with Pin Button Only -->
                        <div class="batch-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            <div class="batch-title-info">
                                <h2 style="font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-users-rectangle"></i> Batch: <?php echo htmlspecialchars($batchName); ?>
                                    <?php if ($isPinned): ?>
                                        <span class="badge-pinned"><i class="fa-solid fa-thumbtack"></i> Pinned</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="batch-meta-info" style="font-size: 13px; color: #64748b; margin-top: 4px;">
                                    <span><i class="fa-solid fa-user-tie" style="color: #0266cc;"></i> <strong>Course Advisor:</strong> <?php echo htmlspecialchars($batchCreatedBy); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-calendar-days" style="color: #059669;"></i> <strong>Creation date:</strong> <?php echo htmlspecialchars($batchCreationDate); ?></span>
                                </div>
                            </div>

                            <div class="btn-group" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <!-- Feature 2: Pin Logo Button Only -->
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                    <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table to top'; ?>">
                                        <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Table of Courses for this Batch -->
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Teacher Name</th>
                                        <th>Teacher ID</th>
                                        <th>Course Code</th>
                                        <th>Subject</th>
                                        <th>Credit</th>
                                        <th>Exam Title</th>
                                        <th>Syllabus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exams as $exam): if (empty($exam['subject']) && empty($exam['course_code'])) continue; ?>
                                    <tr class="course-row">
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

                                        <!-- Syllabus Column: View Syllabus Only -->
                                        <td>
                                            <?php 
                                                $viewLink = file_exists(__DIR__ . '/student-viewSyllabus-page.php') 
                                                    ? 'student-viewSyllabus-page.php?id=' . $exam['id'] 
                                                    : (file_exists(__DIR__ . '/view-syllabusList-page2.php') 
                                                        ? 'view-syllabusList-page2.php?id=' . $exam['id'] 
                                                        : 'teacher-viewSyllabus-page.php?id=' . $exam['id']);
                                            ?>
                                            <a href="<?php echo htmlspecialchars($viewLink); ?>" class="start-btn" style="background:#0266cc; color:#fff; text-decoration:none; padding:6px 14px; border-radius:6px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:5px;">
                                                <i class="fa-solid fa-eye"></i> View
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
                        <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; color: #94a3b8; margin-bottom: 12px; display: block;"></i>
                        <h3 style="color:#1e293b; margin-bottom: 6px;">No courses found.</h3>
                        <p style="color:#64748b;">No batches or syllabus records are currently available for viewing.</p>
                        <a href="student-dashboard-page.php" class="btn" style="display:inline-block; margin-top:10px; background: #0266cc; color: #fff; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;">
                            <i class="fa-solid fa-arrow-left"></i> Return to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Scripts -->
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    <script src="../assets/js/teacher/teacher-courseSyllabus-page.js"></script>

    <!-- Client-Side Search Helper -->
    <script>
    function filterCourseBatches() {
        const input = document.getElementById('courseBatchSearch');
        if (!input) return;
        const query = input.value.toLowerCase().trim();
        const batchCards = document.querySelectorAll('.batch-card');
        let anyMatch = false;

        batchCards.forEach(card => {
            const batchTitle = (card.querySelector('.batch-title-info h2') ? card.querySelector('.batch-title-info h2').textContent : '').toLowerCase();
            const rows = card.querySelectorAll('tbody tr.course-row');
            let cardHasMatch = false;

            rows.forEach(row => {
                const rowText = row.textContent.toLowerCase();
                if (query === '' || rowText.includes(query) || batchTitle.includes(query)) {
                    row.style.display = '';
                    cardHasMatch = true;
                } else {
                    row.style.display = 'none';
                }
            });

            if (query === '' || cardHasMatch || batchTitle.includes(query)) {
                card.style.display = '';
                anyMatch = true;
            } else {
                card.style.display = 'none';
            }
        });

        const noResults = document.getElementById('searchNoResultsMessage');
        if (noResults) {
            noResults.style.display = (anyMatch || batchCards.length === 0) ? 'none' : 'block';
        }
    }
    </script>
</body>
</html>
