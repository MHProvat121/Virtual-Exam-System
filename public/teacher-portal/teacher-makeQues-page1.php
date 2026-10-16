<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../src/config/config.php';

// 1. Session Authorization Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// 2. Resolve logged-in teacher info accurately from DB (`teachers` table)
$current_teacher_id   = $_SESSION['teacher_id'] ?? '';
$current_teacher_name = $_SESSION['teacher_name'] ?? '';
$session_uid          = $_SESSION['user_id'] ?? 0;
$session_email        = $_SESSION['email'] ?? '';

if (empty($current_teacher_id) || is_numeric($current_teacher_id) || empty($current_teacher_name)) {
    try {
        if ($dbConn) {
            $t_stmt = $dbConn->prepare("
                SELECT `teacher_id`, `full_name` 
                FROM `teachers` 
                WHERE `id` = :uid 
                   OR `teacher_id` = :tid 
                   OR (`email` = :email AND :email != '')
                LIMIT 1
            ");
            $t_stmt->execute([
                ':uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
                ':tid'   => strval($current_teacher_id),
                ':email' => strval($session_email)
            ]);
            $logged_teacher = $t_stmt->fetch(PDO::FETCH_ASSOC);

            if ($logged_teacher) {
                $current_teacher_id   = $logged_teacher['teacher_id'] ?? $current_teacher_id;
                $current_teacher_name = $logged_teacher['full_name'] ?? $current_teacher_name;
                $_SESSION['teacher_id']   = $current_teacher_id;
                $_SESSION['teacher_name'] = $current_teacher_name;
            }
        }
    } catch (Exception $e) {
        error_log("Teacher fetch error: " . $e->getMessage());
    }
}

if (empty($current_teacher_name)) $current_teacher_name = 'Instructor';
if (empty($current_teacher_id))   $current_teacher_id   = strval($session_uid);

// 3. Strict Routing Rule:
// Edit mode is ONLY active when action=edit and a valid exam_id is given.
// Creating a new exam MUST ALWAYS have examId = 0 so it never overwrites previous exams.
$action  = strtolower(trim($_GET['action'] ?? ''));
$isEdit  = ($action === 'edit');
$examId  = 0;
$batchId = 0;

if ($isEdit) {
    $examId = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : intval($_GET['id'] ?? 0);
} else {
    // New exam mode: id or batch_id refers to syllabus batch for pre-filling
    $examId  = 0;
    $batchId = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : intval($_GET['id'] ?? 0);
}

$examRow  = null;
$batchRow = null;

// A. EDIT MODE: Fetch target exam from `exam_header` (Updated for Step 1)
if ($isEdit && $examId > 0 && $dbConn) {
    try {
        $stmt = $dbConn->prepare("
            SELECT * FROM exam_header 
            WHERE id = :id AND (teacher_id = :teacher_id OR teacher_id = :user_id) 
            LIMIT 1
        ");
        $stmt->execute([
            ':id'         => $examId,
            ':teacher_id' => strval($current_teacher_id),
            ':user_id'    => strval($session_uid)
        ]);
        $examRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching exam header for edit: " . $e->getMessage());
    }
}

// B. NEW EXAM MODE: Fetch pre-fill data from `batches` syllabus if batch_id provided
if (!$examRow && $batchId > 0 && $dbConn) {
    try {
        $b_stmt = $dbConn->prepare("SELECT * FROM batches WHERE id = :bid LIMIT 1");
        $b_stmt->execute([':bid' => $batchId]);
        $batchRow = $b_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching batch details from syllabus: " . $e->getMessage());
    }
}

// C. Fetch all batches records to establish dynamic Batch -> Subject -> Exam Title mapping
$rawBatchesData = [];
if ($dbConn) {
    try {
        // Match teacher-courseSyllabus-page: it exposes all syllabus batches to
        // teachers, including batches assigned by an admin to another advisor.
        $stmtB = $dbConn->prepare("
            SELECT id, batch, subject, exam_title
            FROM batches
            ORDER BY batch ASC, subject ASC
        ");
        $stmtB->execute();
        $rawBatchesData = $stmtB->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading batches mapping: " . $e->getMessage());
    }
}

// Build structured map: batch -> list of subjects -> list of titles
$batchMap = [];
$allDistinctBatches = [];

foreach ($rawBatchesData as $row) {
    $bName = trim($row['batch'] ?? '');
    $sName = trim($row['subject'] ?? '');
    $tName = trim($row['exam_title'] ?? '');

    if (!empty($bName)) {
        if (!isset($batchMap[$bName])) {
            $batchMap[$bName] = [];
            $allDistinctBatches[] = $bName;
        }
        if (!empty($sName)) {
            if (!isset($batchMap[$bName][$sName])) {
                $batchMap[$bName][$sName] = [];
            }
            if (!empty($tName) && !in_array($tName, $batchMap[$bName][$sName])) {
                $batchMap[$bName][$sName][] = $tName;
            }
        }
    }
}

// Pre-selected values
$selectedBatch     = trim($examRow['batch']      ?? $batchRow['batch']      ?? $_GET['batch']      ?? '');
$selectedSubject   = trim($examRow['subject']    ?? $batchRow['subject']    ?? $_GET['subject']    ?? '');
$selectedExamTitle = trim($examRow['exam_title'] ?? $batchRow['exam_title'] ?? $_GET['exam_title'] ?? '');

// If selected batch is given but not in list, add it
if (!empty($selectedBatch) && !in_array($selectedBatch, $allDistinctBatches)) {
    array_unshift($allDistinctBatches, $selectedBatch);
}

// Available subjects for the selected batch
$availableSubjects = [];
if (!empty($selectedBatch) && isset($batchMap[$selectedBatch])) {
    $availableSubjects = array_keys($batchMap[$selectedBatch]);
}
if (!empty($selectedSubject) && !in_array($selectedSubject, $availableSubjects)) {
    $availableSubjects[] = $selectedSubject;
}

// Available titles for selected batch and subject
$availableTitles = [];
if (!empty($selectedBatch) && !empty($selectedSubject) && isset($batchMap[$selectedBatch][$selectedSubject])) {
    $availableTitles = $batchMap[$selectedBatch][$selectedSubject];
}
if (!empty($selectedExamTitle) && !in_array($selectedExamTitle, $availableTitles)) {
    $availableTitles[] = $selectedExamTitle;
}

$errorMessage = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

// Protect form scope before sidebar include
$pageData = [
    'is_edit'            => (bool) $examRow,
    'exam_id'            => $examRow ? intval($examRow['id']) : 0,
    'teacher_name'       => $examRow['teacher_name'] ?? $current_teacher_name,
    'teacher_id'         => $examRow['teacher_id'] ?? $current_teacher_id,
    'exam_type'          => $examRow['exam_type'] ?? '',
    'exam_date'          => $examRow['exam_date'] ?? '',
    'exam_time'          => $examRow['exam_time'] ?? '',
    'duration'           => $examRow['duration'] ?? '',
    'selected_batch'     => $selectedBatch,
    'selected_subject'   => $selectedSubject,
    'selected_title'     => $selectedExamTitle,
    'all_batches'        => $allDistinctBatches,
    'available_subjects' => $availableSubjects,
    'available_titles'   => $availableTitles,
    'batch_map_json'     => json_encode($batchMap),
    'error_message'      => $errorMessage,
];

unset($examRow, $batchRow, $current_teacher_id, $current_teacher_name, $rawBatchesData, $batchMap, $allDistinctBatches);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= $pageData['is_edit'] ? 'Edit Examination' : 'Create New Examination'; ?> - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-makeQues-page1.css">
</head>

<body>

    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- EXAM DETAILS FORM AREA -->
        <div class="form-container">
            <div class="form-header">
                <h2><?= $pageData['is_edit'] ? 'Edit Examination' : 'Create New Examination'; ?></h2>
                <p><?= $pageData['is_edit'] ? 'Update examination schedule & details in exam_header.' : 'Fill in the basic exam details for the target batch to create exam header before adding questions.'; ?></p>
            </div>

            <?php if (!empty($pageData['error_message'])): ?>
                <div class="alert alert-danger" style="color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px 20px; border-radius: 5px; margin-bottom: 20px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($pageData['error_message']); ?>
                </div>
            <?php endif; ?>

            <form id="examForm" method="POST" action="teacher-makeQues-page1-logic.php" class="exam-form" autocomplete="off">

                <!-- Hidden input: Strictly 0 for new exams to prevent overwriting previous exams -->
                <input type="hidden" name="exam_id" id="exam_id" value="<?= htmlspecialchars($pageData['exam_id']); ?>">

                <!-- Teacher Name Field (from teachers table/session) -->
                <div class="form-group">
                    <label for="teacher_name">Teacher Name</label>
                    <input type="text" id="teacher_name" name="teacher_name" value="<?= htmlspecialchars($pageData['teacher_name']); ?>" readonly style="background-color: #e9ecef; cursor: not-allowed;">
                </div>

                <!-- Teacher ID Field (from teachers table/session) -->
                <div class="form-group">
                    <label for="teacher_id">Teacher ID <span class="required">*</span></label>
                    <input type="text" id="teacher_id" name="teacher_id" value="<?= htmlspecialchars($pageData['teacher_id']); ?>" readonly required style="background-color: #e9ecef; cursor: not-allowed;">
                </div>

                <!-- Batch Selection (from batches table) -->
                <div class="form-group">
                    <label for="batch">Select Batch <span class="required">*</span></label>
                    <select id="batch" name="batch" required autocomplete="off">
                        <option value="" <?= empty($pageData['selected_batch']) ? 'selected' : ''; ?> disabled hidden>-- Select Target Batch --</option>
                        <?php
                        foreach ($pageData['all_batches'] as $b) {
                            $selected = (strcasecmp(trim($pageData['selected_batch']), trim($b)) === 0) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($b) . "\" $selected>" . htmlspecialchars($b) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Subject Selection (from batches table for selected batch) -->
                <div class="form-group col">
                    <label for="subject">Subject <span class="required">*</span></label>
                    <select id="subject" name="subject" required autocomplete="off">
                        <option value="" <?= empty($pageData['selected_subject']) ? 'selected' : ''; ?> disabled hidden>-- Select Subject --</option>
                        <?php
                        foreach ($pageData['available_subjects'] as $sub) {
                            $selected = (strcasecmp(trim($pageData['selected_subject']), trim($sub)) === 0) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($sub) . "\" $selected>" . htmlspecialchars($sub) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Exam Title Selection (from batches table for selected subject) -->
                <div class="form-group">
                    <label for="exam_title">Course / Exam Title <span class="required">*</span></label>
                    <select id="exam_title" name="exam_title" required autocomplete="off">
                        <option value="" <?= empty($pageData['selected_title']) ? 'selected' : ''; ?> disabled hidden>-- Select Exam Title --</option>
                        <?php
                        foreach ($pageData['available_titles'] as $title) {
                            $selected = (strcasecmp(trim($pageData['selected_title']), trim($title)) === 0) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($title) . "\" $selected>" . htmlspecialchars($title) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Exam Type Row: enum('MCQ','Theory','Hybrid (MCQ+Theory)') -->
                <div class="form-row">
                    <div class="form-group col">
                        <label for="exam_type">Exam Type <span class="required">*</span></label>
                        <select id="exam_type" name="exam_type" required autocomplete="off">
                            <option value="" <?= empty($pageData['exam_type']) ? 'selected' : ''; ?> disabled hidden>-- Select Type --</option>
                            <?php
                            $types = [
                                "MCQ"                 => "MCQ",
                                "Theory"              => "Theory",
                                "Hybrid (MCQ+Theory)" => "Hybrid (MCQ+Theory)"
                            ];
                            foreach ($types as $val => $label) {
                                $selected = (strcasecmp(trim($pageData['exam_type']), trim($val)) === 0) ? 'selected' : '';
                                echo "<option value=\"$val\" $selected>$label</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <!-- Exam Date & Time Row -->
                <div class="form-row">
                    <div class="form-group col">
                        <label for="exam_date">Set Exam Date <span class="required">*</span></label>
                        <input type="date" id="exam_date" name="exam_date" value="<?= htmlspecialchars($pageData['exam_date']); ?>" required autocomplete="off">
                    </div>

                    <div class="form-group col">
                        <label for="exam_time">Set Exam Time <span class="required">*</span></label>
                        <input type="time" id="exam_time" name="exam_time" value="<?= htmlspecialchars($pageData['exam_time']); ?>" required autocomplete="off">
                    </div>
                </div>

                <!-- Duration -->
                <div class="form-group">
                    <label for="duration">Duration (in minutes) <span class="required">*</span></label>
                    <input type="number" id="duration" name="duration" value="<?= htmlspecialchars($pageData['duration']); ?>" min="5" max="300" placeholder="e.g., 60" required autocomplete="off">
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" id="submitBtn" name="save_exam" class="btn-primary">
                        <?= $pageData['is_edit'] ? 'Update &amp; Proceed &rarr;' : 'Proceed to Questions &rarr;'; ?>
                    </button>
                </div>

            </form>
        </div>

    </div>

    <!-- Client-side Chained Dropdown Script to guarantee Batch -> Subject -> Exam Title consistency -->
    <script>
        const BATCH_MAP = <?= $pageData['batch_map_json']; ?>;
        const batchSelect = document.getElementById('batch');
        const subjectSelect = document.getElementById('subject');
        const titleSelect = document.getElementById('exam_title');

        function updateSubjects() {
            const selectedBatch = batchSelect.value.trim();
            const currentSub = subjectSelect.value;
            
            subjectSelect.innerHTML = '<option value="" disabled selected hidden>-- Select Subject --</option>';
            titleSelect.innerHTML = '<option value="" disabled selected hidden>-- Select Exam Title --</option>';

            if (selectedBatch && BATCH_MAP[selectedBatch]) {
                const subjects = Object.keys(BATCH_MAP[selectedBatch]);
                subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub;
                    opt.textContent = sub;
                    if (sub === currentSub) opt.selected = true;
                    subjectSelect.appendChild(opt);
                });

                if (subjects.length === 1) {
                    subjectSelect.value = subjects[0];
                    updateTitles();
                }
            }
        }

        function updateTitles() {
            const selectedBatch = batchSelect.value.trim();
            const selectedSub = subjectSelect.value.trim();
            const currentTitle = titleSelect.value;

            titleSelect.innerHTML = '<option value="" disabled selected hidden>-- Select Exam Title --</option>';

            if (selectedBatch && selectedSub && BATCH_MAP[selectedBatch] && BATCH_MAP[selectedBatch][selectedSub]) {
                const titles = BATCH_MAP[selectedBatch][selectedSub];
                titles.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = t;
                    opt.textContent = t;
                    if (t === currentTitle) opt.selected = true;
                    titleSelect.appendChild(opt);
                });

                if (titles.length === 1) {
                    titleSelect.value = titles[0];
                }
            }
        }

        batchSelect.addEventListener('change', updateSubjects);
        subjectSelect.addEventListener('change', updateTitles);
    </script>

    <!-- JavaScript Files -->
    <script src="../assets/js/teacher/teacher-makeQues-page1.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>

</body>

</html>
