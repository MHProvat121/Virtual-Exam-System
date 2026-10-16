<?php
// =========================================================================
// teacher-courseSyllabus-page.php (WITH SEARCH, PIN & ARCHIVE FEATURES)
// Feature 1: Live search bar below the header to filter batches & courses
// Feature 2: Pin button on each batch header to pin tables to the top
// Feature 3: Archive button on each batch header to archive tables
// Feature 4: Archived Batch Courses button on top right with count badge
// Feature 5: In archive section: Restore and Pin buttons only
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
require_once __DIR__ . '/../../src/config/config.php';
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Initialize Session State for Pinned & Archived Batches
if (!isset($_SESSION['pinned_batches'])) {
    $_SESSION['pinned_batches'] = [];
}
if (!isset($_SESSION['archived_batches'])) {
    $_SESSION['archived_batches'] = [];
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

$teachersList = $dbConn->query("SELECT id, teacher_id, full_name FROM `teachers` ORDER BY full_name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

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
    $teacherCode = $teacherInfo['teacher_id'];
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
                $redirectUrl = ($_POST['current_view'] ?? '') === 'archived' ? $_SERVER['PHP_SELF'] . '?view=archived' : $_SERVER['PHP_SELF'];
                header("Location: " . $redirectUrl);
                exit();
            }
            break;

        // Feature 3: Archive Batch Action
        case 'archive_batch':
            if (!empty($batchName)) {
                if (!in_array($batchName, $_SESSION['archived_batches'])) {
                    $_SESSION['archived_batches'][] = $batchName;
                }
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            break;

        // Feature 5: Restore from Archive Action
        case 'restore_batch':
            if (!empty($batchName)) {
                $_SESSION['archived_batches'] = array_values(array_diff($_SESSION['archived_batches'], [$batchName]));
                header("Location: " . $_SERVER['PHP_SELF'] . "?view=archived");
                exit();
            }
            break;

        case 'create_course':
        case 'add_subject':
            $batch       = trim($_POST['batch'] ?? '');
            $courseCode  = trim($_POST['course_code'] ?? '');
            $credit      = trim($_POST['credit'] ?? '');
            $subject     = trim($_POST['subject'] ?? '');
            $examTitle   = trim($_POST['exam_title'] ?? '');
            $courseTeacherCode = $teacherCode;

            if ($action === 'create_course') {
                $courseTeacherCode = trim($_POST['teacher_id'] ?? $_POST['teacher_name'] ?? '');
                if ($courseTeacherCode === '') {
                    $courseTeacherCode = $teacherCode;
                }
            } elseif ($action === 'add_subject' && $batch !== '') {
                $advisorStmt = $dbConn->prepare("SELECT `teacher_id` FROM `batches` WHERE `batch` = ? ORDER BY `id` ASC LIMIT 1");
                $advisorStmt->execute([$batch]);
                $existingAdvisor = trim((string)$advisorStmt->fetchColumn());
                if ($existingAdvisor !== '') {
                    $courseTeacherCode = $existingAdvisor;
                }
            }

            if (!empty($batch)) {
                try {
                    $stmtIns = $dbConn->prepare("
                        INSERT INTO `batches` (`batch`, `course_code`, `credit`, `subject`, `exam_title`, `teacher_id`) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$batch, $courseCode, $credit, $subject, $examTitle, $courseTeacherCode]);
                } catch (PDOException $e) {
                    $stmtIns = $dbConn->prepare("
                        INSERT INTO `batches` (`batch`, `course_code`, `subject`, `exam_title`, `teacher_id`) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$batch, $courseCode, $subject, $examTitle, $courseTeacherCode]);
                }
            }
            break;

        case 'edit_batch':
            $oldBatch = trim($_POST['old_batch'] ?? '');
            $newBatch = trim($_POST['batch'] ?? '');
            $advisorCode = trim($_POST['teacher_id'] ?? '');
            if ($oldBatch !== '' && $newBatch !== '' && $advisorCode !== '') {
                try {
                    $dbConn->beginTransaction();
                    $dbConn->prepare("UPDATE `batches` SET `batch` = ?, `teacher_id` = ? WHERE `batch` = ?")
                        ->execute([$newBatch, $advisorCode, $oldBatch]);
                    $dbConn->prepare("UPDATE `student_enrollments` SET `batch` = ? WHERE `batch` = ?")
                        ->execute([$newBatch, $oldBatch]);
                    $dbConn->prepare("UPDATE `exam_header` SET `batch` = ?, `teacher_id` = ?, `teacher_name` = (SELECT `full_name` FROM `teachers` WHERE `teacher_id` = ? LIMIT 1) WHERE `batch` = ?")
                        ->execute([$newBatch, $advisorCode, $advisorCode, $oldBatch]);
                    $dbConn->prepare("UPDATE `exam_details` SET `batch` = ?, `teacher_id` = ?, `teacher_name` = (SELECT `full_name` FROM `teachers` WHERE `teacher_id` = ? LIMIT 1) WHERE `batch` = ?")
                        ->execute([$newBatch, $advisorCode, $advisorCode, $oldBatch]);
                    $dbConn->commit();
                } catch (PDOException $e) {
                    if ($dbConn->inTransaction()) $dbConn->rollBack();
                    error_log('Unable to edit teacher batch: ' . $e->getMessage());
                }
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

        case 'delete_table':
            $batch = trim($_POST['batch'] ?? '');
            if (!empty($batch)) {
                $dbConn->prepare("DELETE FROM `batches` WHERE `batch` = ?")->execute([$batch]);
                $dbConn->prepare("
                    INSERT INTO `batches` (`batch`, `course_code`, `subject`, `exam_title`, `teacher_id`) 
                    VALUES (?, '', '', '', ?)
                ")->execute([$batch, $teacherCode]);
            }
            break;

        case 'delete_batch':
            $batch = trim($_POST['batch'] ?? '');
            $deletedBy = $teacherInfo['teacher_id'];

            if (!empty($batch)) {
                $stmtLog = $dbConn->prepare("
                    INSERT INTO `batch_deletion_logs` (`batch_name`, `deleted_by`) 
                    VALUES (?, ?)
                ");
                $stmtLog->execute([$batch, $deletedBy]);

                $stmtDel = $dbConn->prepare("DELETE FROM `batches` WHERE `batch` = ?");
                $stmtDel->execute([$batch]);

                // Also clean up from pinned and archived sessions
                $_SESSION['pinned_batches'] = array_values(array_diff($_SESSION['pinned_batches'], [$batch]));
                $_SESSION['archived_batches'] = array_values(array_diff($_SESSION['archived_batches'], [$batch]));
            }
            break;
    }
    header("Location: " . $_SERVER['PHP_SELF']); 
    exit();
}

// Current View: Active or Archived
$currentView = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

// -------------------------------------------------------------------------
// 6. FETCH DATA FOR ALL BATCHES
// -------------------------------------------------------------------------
$groupedExams = [];
$stmt = $dbConn->prepare("
    SELECT 
        b.*, 
        COALESCE(t.full_name, 'Unknown Teacher') AS teacher_full_name, 
        COALESCE(t.teacher_id, b.teacher_id, 'N/A') AS display_teacher_id 
    FROM `batches` b 
    LEFT JOIN `teachers` t 
        ON (b.teacher_id COLLATE utf8mb4_unicode_ci = t.teacher_id COLLATE utf8mb4_unicode_ci 
         OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(t.id AS CHAR) COLLATE utf8mb4_unicode_ci) 
    ORDER BY b.batch ASC, b.id DESC
");
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
    $groupedExams[$row['batch']][] = $row; 
}

// Separate Batches into Active and Archived
$activeBatches   = [];
$archivedBatches = [];

foreach ($groupedExams as $batchName => $exams) {
    if (in_array($batchName, $_SESSION['archived_batches'])) {
        $archivedBatches[$batchName] = $exams;
    } else {
        $activeBatches[$batchName] = $exams;
    }
}

// Feature 2: Sort Active Batches with Pinned Batches placed on top
uksort($activeBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Feature 2: Sort Archived Batches with Pinned Batches placed on top as well
uksort($archivedBatches, function($a, $b) {
    $aPinned = in_array($a, $_SESSION['pinned_batches']);
    $bPinned = in_array($b, $_SESSION['pinned_batches']);
    if ($aPinned === $bPinned) return strcmp($a, $b);
    return $aPinned ? -1 : 1;
});

// Select which batch list to render
$displayBatches = ($currentView === 'archived') ? $archivedBatches : $activeBatches;
$archivedCount  = count($archivedBatches);

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
    <title><?php echo $currentView === 'archived' ? 'Archived Batch Courses' : 'Course Syllabus'; ?> - Virtual Exam System</title>
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
                    <h1><?php echo $currentView === 'archived' ? 'Archived Batch Courses' : 'Available Batches'; ?></h1>
                    <?php if ($currentView === 'archived'): ?>
                        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
                            <i class="fa-solid fa-box-archive"></i> Viewing archived batch tables. These tables are preserved and can be restored anytime.
                        </p>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($currentView === 'archived'): ?>
                        <!-- Back to Active Batches Button -->
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-archive-page">
                            <i class="fa-solid fa-arrow-left"></i> Back to Active Batches
                        </a>
                    <?php else: ?>
                        <!-- Feature 4: Archived Batch Courses Button with Badge -->
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?view=archived" class="btn-archive-page">
                            <i class="fa-solid fa-box-archive"></i> Archived Batch Courses
                            <?php if ($archivedCount > 0): ?>
                                <span class="badge-count"><?php echo $archivedCount; ?></span>
                            <?php endif; ?>
                        </a>

                        <button class="start-btn create-exam" onclick="openCreateCourseModal()">
                            <i class="fa-solid fa-plus"></i> Create New Course
                        </button>
                    <?php endif; ?>
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
                        placeholder="<?php echo $currentView === 'archived' ? 'Search archived batches, subjects, exams, or teachers...' : 'Search available batches, subjects, exams, or teachers...'; ?>"
                    >
                </div>
            </div>

            <div id="searchNoResultsMessage" style="display: none; text-align: center; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <p style="color: #64748b; font-size: 15px; margin: 0;">No batches match your search query.</p>
            </div>

            <section class="table-section-container">
                <?php if (!empty($displayBatches)): foreach ($displayBatches as $batchName => $exams): 
                    $earliestRecord = $exams[count($exams) - 1];
                    $batchCreatedBy = !empty($earliestRecord['teacher_full_name']) ? $earliestRecord['teacher_full_name'] : 'N/A';
                    $batchCreationDate = !empty($earliestRecord['created_at']) ? date('M d, Y', strtotime($earliestRecord['created_at'])) : 'N/A';
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

                            <div class="btn-group" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <?php if ($currentView === 'archived'): ?>
                                    <!-- Feature 5: In Archive Section: Pin & Restore Buttons Only -->
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <input type="hidden" name="current_view" value="archived">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table to top'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="restore_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-restore-table" title="Restore table to active batches">
                                            <i class="fa-solid fa-rotate-left"></i> Restore
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Feature 2: Pin Logo Button -->
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <input type="hidden" name="current_view" value="active">
                                        <button type="submit" class="btn-pin <?php echo $isPinned ? 'pinned' : ''; ?>" title="<?php echo $isPinned ? 'Unpin table' : 'Pin table to top'; ?>">
                                            <i class="fa-solid fa-thumbtack"></i> <?php echo $isPinned ? 'Pinned' : 'Pin'; ?>
                                        </button>
                                    </form>

                                    <!-- Feature 3: Archive Logo Button -->
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Archive batch table &quot;<?php echo htmlspecialchars(addslashes($batchName)); ?>&quot;?');">
                                        <input type="hidden" name="action" value="archive_batch">
                                        <input type="hidden" name="batch_name" value="<?php echo htmlspecialchars($batchName); ?>">
                                        <button type="submit" class="btn-archive-table" title="Move table into archives">
                                            <i class="fa-solid fa-box-archive"></i> Archive
                                        </button>
                                    </form>

                                    <button type="button" class="btn btn-edit" onclick='openEditBatchModal(<?php echo json_encode([
                                        "batch" => $batchName,
                                        "advisor_id" => $earliestRecord["display_teacher_id"] ?? "",
                                        "advisor_name" => $earliestRecord["teacher_full_name"] ?? ""
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
                                        <i class="fa-solid fa-pen"></i> Edit Batch
                                    </button>

                                    <!-- Existing Teacher Actions -->
                                    <button class="btn btn-add" onclick="openAddSubjectModal('<?php echo addslashes($batchName); ?>')">Add Subject</button>
                                    <button class="btn btn-delete-table" onclick="openDeleteTableModal('<?php echo addslashes($batchName); ?>')">Clear Table</button>
                                    <button class="btn btn-remove" onclick="openDeleteBatchModal('<?php echo addslashes($batchName); ?>')"><i class="fa-solid fa-trash"></i> Remove Batch</button>
                                <?php endif; ?>
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
                                        <th>Student Comments</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exams as $exam): if (empty($exam['subject']) && empty($exam['course_code'])) continue; 
                                        $cCount = $commentCounts[$exam['id']] ?? 0;
                                    ?>
                                    <tr>
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
                                            <a href="teacher-createSyllabus-page.php?id=<?php echo $exam['id']; ?>" class="start-btn" style="background:#059669;">Create</a>
                                        </td>

                                        <td>
                                            <a href="view-syllabusComment-page.php?id=<?php echo $exam['id']; ?>" class="btn-comments">
                                                <i class="fa-solid fa-comments"></i> View Comments
                                                <?php if ($cCount > 0): ?>
                                                    <span class="comment-badge"><?php echo $cCount; ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </td>

                                        <td class="action-cell">
                                            <button class="btn btn-edit" onclick='openEditModal(<?php echo json_encode($exam); ?>)'><i class="fa-solid fa-pen"></i></button>
                                            <form method="POST" class="inline-form" onsubmit="return confirm('Remove this row?');">
                                                <input type="hidden" name="action" value="delete_row">
                                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                                <button type="submit" class="btn btn-remove"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card" style="text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <h3><?php echo $currentView === 'archived' ? 'No archived batch courses found.' : 'No courses found.'; ?></h3>
                        <p style="color:#64748b;"><?php echo $currentView === 'archived' ? 'Batches you archive will appear here safely. You can restore them anytime.' : 'Click "Create New Course" above to add your first batch and syllabus.'; ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- MODALS SECTION -->
    <div id="createCourseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Course</h3>
                <button class="close-btn" onclick="closeModal('createCourseModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_course">
                <div class="form-group"><label>Batch Name</label><input type="text" name="batch" required placeholder="e.g. batch 51"></div>
                <div class="form-group">
                    <label>Course Advisor Name</label>
                    <select name="teacher_name" id="createTeacherName" onchange="syncTeacherSelect(this, 'createTeacherId')" required>
                        <option value="">-- Select Advisor Name --</option>
                        <?php foreach ($teachersList as $t):
                            $tid = !empty($t['teacher_id']) ? $t['teacher_id'] : $t['id'];
                        ?>
                            <option value="<?php echo htmlspecialchars($tid); ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Course Advisor ID</label>
                    <select name="teacher_id" id="createTeacherId" onchange="syncTeacherSelect(this, 'createTeacherName')" required>
                        <option value="">-- Select Advisor ID --</option>
                        <?php foreach ($teachersList as $t):
                            $tid = !empty($t['teacher_id']) ? $t['teacher_id'] : $t['id'];
                        ?>
                            <option value="<?php echo htmlspecialchars($tid); ?>"><?php echo htmlspecialchars($tid); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-add">Save</button></div>
            </form>
        </div>
    </div>

    <div id="editBatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Batch</h3>
                <button class="close-btn" onclick="closeModal('editBatchModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_batch">
                <input type="hidden" name="old_batch" id="editBatchOldName">
                <div class="form-group"><label>Batch Name</label><input type="text" name="batch" id="editBatchName" required></div>
                <div class="form-group">
                    <label>Course Advisor Name</label>
                    <select name="teacher_name" id="editBatchTeacherName" onchange="syncTeacherSelect(this, 'editBatchTeacherId')" required>
                        <option value="">-- Select Advisor Name --</option>
                        <?php foreach ($teachersList as $t): $tid = !empty($t['teacher_id']) ? $t['teacher_id'] : $t['id']; ?>
                            <option value="<?php echo htmlspecialchars($tid); ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Course Advisor ID</label>
                    <select name="teacher_id" id="editBatchTeacherId" onchange="syncTeacherSelect(this, 'editBatchTeacherName')" required>
                        <option value="">-- Select Advisor ID --</option>
                        <?php foreach ($teachersList as $t): $tid = !empty($t['teacher_id']) ? $t['teacher_id'] : $t['id']; ?>
                            <option value="<?php echo htmlspecialchars($tid); ?>"><?php echo htmlspecialchars($tid); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-edit">Update Batch</button></div>
            </form>
        </div>
    </div>

    <div id="addSubjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Subject</h3>
                <button class="close-btn" onclick="closeModal('addSubjectModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_subject">
                <input type="hidden" name="batch" id="addSubjectBatchInput">
                <div class="form-group"><label>Batch</label><input type="text" id="addSubjectBatchDisplay" disabled></div>
                <div class="form-group"><label>Course Code</label><input type="text" name="course_code" placeholder="e.g. CSE-101"></div>
                <div class="form-group"><label>Credit</label><input type="text" name="credit" placeholder="e.g. 3.0"></div>
                <div class="form-group"><label>Subject</label><input type="text" name="subject" required placeholder="e.g. Database Management System (DBMS)"></div>
                <div class="form-group"><label>Exam Title</label><input type="text" name="exam_title" required placeholder="e.g. Midterm Exam"></div>
                <div class="modal-footer"><button type="submit" class="btn btn-add">Add</button></div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Information</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_course">
                <input type="hidden" name="exam_id" id="editExamId">
                <div class="form-group"><label>Batch</label><input type="text" name="batch" id="editBatch" required></div>
                <div class="form-group"><label>Course Code</label><input type="text" name="course_code" id="editCourseCode" placeholder="e.g. CSE-101"></div>
                <div class="form-group"><label>Credit</label><input type="text" name="credit" id="editCredit" placeholder="e.g. 3.0"></div>
                <div class="form-group"><label>Subject</label><input type="text" name="subject" id="editSubject" required></div>
                <div class="form-group"><label>Exam Title</label><input type="text" name="exam_title" id="editExamTitle" required></div>
                <div class="modal-footer"><button type="submit" class="btn btn-edit">Update</button></div>
            </form>
        </div>
    </div>

    <div id="deleteTableModal" class="modal">
        <div class="modal-content">
            <div class="modal-header text-danger">
                <h3>Clear Table</h3>
                <button class="close-btn" onclick="closeModal('deleteTableModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_table">
                <input type="hidden" name="batch" id="deleteBatchInput">
                <p>Are you sure you want to clear <strong id="deleteBatchNameSpan"></strong>?</p>
                <div class="modal-footer">
                    <button type="submit" id="confirmDeleteTableBtn" class="btn btn-delete-table" disabled>Clear (3s)</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteBatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header text-danger">
                <h3>Delete Batch</h3>
                <button class="close-btn" onclick="closeModal('deleteBatchModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_batch">
                <input type="hidden" name="batch" id="deleteBatchNameInput">
                <p style="margin: 15px 0; font-size: 15px;">
                    Are you sure you want to delete batch <strong id="deleteBatchNameDisplay"></strong>?
                </p>
                <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" style="background:#64748b; color:#fff;" onclick="closeModal('deleteBatchModal')">Cancel</button>
                    <button type="submit" id="confirmDeleteBatchBtn" class="btn btn-remove" disabled>Delete (3s)</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    <script src="../assets/js/teacher/teacher-courseSyllabus-page.js"></script>
    <script src="teacher-courseSyllabus-page.js"></script>
</body>
</html>
