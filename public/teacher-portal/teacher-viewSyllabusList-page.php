<?php
// =========================================================================
// teacher-viewSyllabusList-page.php (WITH SEARCH & PIN FEATURES)
// Feature 1: Live search bar below the header to filter batches & courses
// Feature 2: Pin button on each batch header to pin tables to the top
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
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

// Initialize Session State for Pinned Batches
if (!isset($_SESSION['pinned_batches'])) {
    $_SESSION['pinned_batches'] = [];
}

// 4. SAFE LOGGED-IN TEACHER DETAILS FETCHING
$teacher = [];

if (isset($_SESSION['email'])) {
    $stmt_teacher = $dbConn->prepare("SELECT * FROM `teachers` WHERE `email` = :email LIMIT 1");
    $stmt_teacher->execute(['email' => $_SESSION['email']]);
    $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (empty($teacher) && isset($_SESSION['user_id'])) {
    $stmt_teacher = $dbConn->prepare("
        SELECT t.* FROM `teachers` t 
        JOIN `users-table` u ON t.email = u.email 
        WHERE u.id = :uid LIMIT 1
    ");
    $stmt_teacher->execute(['uid' => $_SESSION['user_id']]);
    $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Consolidate Logged-in Teacher Information
$teacherInfo = [
    'full_name'  => !empty($teacher['full_name'])  ? $teacher['full_name']  : ($_SESSION['teacher_name'] ?? 'N/A'),
    'teacher_id' => !empty($teacher['teacher_id']) ? $teacher['teacher_id'] : ($_SESSION['teacher_id']   ?? 'N/A')
];

// Update Session
if (!empty($teacherInfo['teacher_id']) && $teacherInfo['teacher_id'] !== 'N/A') {
    $_SESSION['teacher_id']   = $teacherInfo['teacher_id'];
    $_SESSION['teacher_name'] = $teacherInfo['full_name'];
}

// -------------------------------------------------------------------------
// 5. HANDLE FORM POST ACTIONS
// -------------------------------------------------------------------------
if ($dbConn) {
    try {
        $checkCredit = $dbConn->query("SHOW COLUMNS FROM `batches` LIKE 'credit'");
        if ($checkCredit && $checkCredit->rowCount() === 0) {
            $dbConn->exec("ALTER TABLE `batches` ADD COLUMN `credit` VARCHAR(50) NULL DEFAULT NULL AFTER `course_code`");
        }
    } catch (Exception $e) {
        // Continue safely if schema alteration is restricted
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $batchName = trim($_POST['batch_name'] ?? $_POST['batch'] ?? '');

    switch ($action) {
        // Feature 2: Toggle Pin Action
        case 'toggle_pin':
            if (!empty($batchName)) {
                if (in_array($batchName, $_SESSION['pinned_batches'])) {
                    $_SESSION['pinned_batches'] = array_values(array_diff($_SESSION['pinned_batches'], [$batchName]));
                } else {
                    $_SESSION['pinned_batches'][] = $batchName;
                }
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            break;

        case 'edit_course':
            $b = trim($_POST['batch'] ?? '');
            $cc = trim($_POST['course_code'] ?? '');
            $cr = trim($_POST['credit'] ?? '');
            $s = trim($_POST['subject'] ?? '');
            $et = trim($_POST['exam_title'] ?? '');
            $eId = (int)($_POST['exam_id'] ?? 0);

            try {
                $stmtUpd = $dbConn->prepare("
                    UPDATE `batches` 
                    SET `batch` = ?, `course_code` = ?, `credit` = ?, `subject` = ?, `exam_title` = ? 
                    WHERE `id` = ?
                ");
                $stmtUpd->execute([$b, $cc, $cr, $s, $et, $eId]);
            } catch (PDOException $e) {
                $stmtUpd = $dbConn->prepare("
                    UPDATE `batches` 
                    SET `batch` = ?, `course_code` = ?, `subject` = ?, `exam_title` = ? 
                    WHERE `id` = ?
                ");
                $stmtUpd->execute([$b, $cc, $s, $et, $eId]);
            }

            try {
                $dbConn->prepare("
                    UPDATE `exam_header` 
                    SET `batch` = ?, `course_code` = ?, `subject` = ?, `exam_title` = ? 
                    WHERE `batch` = ? AND (`course_code` = ? OR `subject` = ?)
                ")->execute([$b, $cc, $s, $et, $b, $cc, $s]);
                $dbConn->prepare("
                    UPDATE `exam_details` 
                    SET `batch` = ?, `subject` = ?, `exam_title` = ? 
                    WHERE `batch` = ? AND `subject` = ?
                ")->execute([$b, $s, $et, $b, $s]);
            } catch (Exception $e) {}
            break;

        case 'delete_row':
            $id = (int)($_POST['exam_id'] ?? 0);
            $stmt = $dbConn->prepare("SELECT `batch` FROM `batches` WHERE `id` = ?");
            $stmt->execute([$id]);
            $bName = $stmt->fetchColumn();

            if ($bName) {
                $count = $dbConn->prepare("SELECT COUNT(*) FROM `batches` WHERE `batch` = ?");
                $count->execute([$bName]);
                if ($count->fetchColumn() <= 1) {
                    $dbConn->prepare("UPDATE `batches` SET `course_code` = '', `subject` = '', `exam_title` = '' WHERE `id` = ?")->execute([$id]);
                } else {
                    $dbConn->prepare("DELETE FROM `batches` WHERE `id` = ?")->execute([$id]);
                }
            }
            break;
    }
    header("Location: " . $_SERVER['PHP_SELF']); 
    exit();
}

// -------------------------------------------------------------------------
// 6. FETCH DATA FOR ALL BATCHES
// -------------------------------------------------------------------------
$groupedExams = [];
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

// Feature 2: Sort Batches with Pinned Batches placed on top
$displayBatches = $groupedExams;
uksort($displayBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// 7. FETCH COMMENT COUNTS PER SYLLABUS RECORD
$commentCounts = [];
$stmtCounts = $dbConn->query("SELECT batch_id, COUNT(*) as c_count FROM `syllabus_comments` GROUP BY batch_id");
if ($stmtCounts) {
    while ($cnt = $stmtCounts->fetch(PDO::FETCH_ASSOC)) {
        $commentCounts[$cnt['batch_id']] = (int)$cnt['c_count'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course Syllabus - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-courseSyllabus-page.css">
</head>
<body>
    <div class="main-layout">
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>
        <main class="dashboard">
            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header">
                <div>
                    <h1>Available Batches</h1>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="teacher-dashboard-page.php" class="btn-archive-page" style="background:#0266cc;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Feature 1: Search bar -->
            <div class="search-container">
                <div class="search-input-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input 
                        type="text" 
                        id="courseBatchSearch" 
                        onkeyup="filterCourseBatches()" 
                        placeholder="Search available batches, subjects, exams, or teachers..."
                    >
                </div>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches match your search query.</p>
            </div>

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
                    $batchTeacherId = !empty($advisorRecord['teacher_id']) ? $advisorRecord['teacher_id'] : '';
                    $isPinned = in_array($batchName, $_SESSION['pinned_batches']);
                ?>
                    <div class="batch-card" style="background:#fff; border-radius:12px; border: 1px solid #e2e8f0; margin-bottom: 25px; overflow:hidden;">
                        <div class="batch-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            <div class="batch-title-info">
                                <h2 style="font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-users-rectangle"></i> Batch: <?php echo htmlspecialchars($batchName); ?>
                                    <?php if ($isPinned): ?>
                                        <span class="badge-pinned"><i class="fa-solid fa-thumbtack"></i> Pinned</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="batch-meta-info" style="font-size: 13px; color: #64748b; margin-top: 4px;">
                                    <span><i class="fa-solid fa-user" style="color: #0266cc;"></i> <strong>Created by:</strong> <?php echo htmlspecialchars($batchCreatedBy); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-calendar-days" style="color: #059669;"></i> <strong>Creation date:</strong> <?php echo htmlspecialchars($batchCreationDate); ?></span>
                                </div>
                            </div>

                            <!-- Part 3: in batch-header, only keep the pin button -->
                            <div class="btn-group" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <!-- Feature 2: Pin Logo Button -->
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                    <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table to top'; ?>">
                                        <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>

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

                                        <td>
                                            <a href="teacher-viewSyllabus-page.php?id=<?php echo $exam['id']; ?>" class="start-btn">View</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card" style="text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <h3>No courses found.</h3>
                        <p style="color:#64748b;">No batches or syllabus records currently available.</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

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