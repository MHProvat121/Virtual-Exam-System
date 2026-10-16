<?php
/* ====================================================
   MAKE QUESTIONS - PAGE 2 (STEP 2: exam_header INTEGRATION)
   ==================================================== */

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

// 2. Retrieve target exam_id from URL
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

if ($exam_id <= 0) {
    header("Location: teacher-makeQues-page1.php");
    exit();
}

// 3. Fetch logged-in teacher info
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

$teacher_name       = !empty($current_teacher_name) ? $current_teacher_name : 'Instructor';
$teacher_id_display = !empty($current_teacher_id)   ? $current_teacher_id   : strval($session_uid);

// 4. STEP 2: Fetch specific exam header details strictly from `exam_header` table
$exam = null;
if ($dbConn) {
    try {
        $stmt = $dbConn->prepare("
            SELECT * FROM exam_header 
            WHERE id = :id AND (teacher_id = :teacher_id OR teacher_id = :uid) 
            LIMIT 1
        ");
        $stmt->execute([
            ':id'         => $exam_id,
            ':teacher_id' => strval($current_teacher_id),
            ':uid'        => strval($session_uid)
        ]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            if (!empty($exam['teacher_name'])) $teacher_name       = $exam['teacher_name'];
            if (!empty($exam['teacher_id']))   $teacher_id_display = $exam['teacher_id'];
        }
    } catch (Exception $e) {
        error_log("Error fetching exam header in page2: " . $e->getMessage());
    }
}

if (!$exam) {
    header("Location: teacher-makeQues-page1.php");
    exit();
}

// Format Exam Date & Time from exam_header
$formatted_dateTime = 'N/A';
if (!empty($exam['exam_date'])) {
    $rawDate = $exam['exam_date'] . (!empty($exam['exam_time']) ? ' ' . $exam['exam_time'] : '');
    $formatted_dateTime = date('M d, Y h:i A', strtotime($rawDate));
}

// 5. Fetch existing questions strictly for THIS exam_id from questions table
$existing_questions = [];
if ($dbConn) {
    try {
        $q_stmt = $dbConn->prepare("
            SELECT id, question_number, question_type, question_text, marks, image_url 
            FROM questions 
            WHERE exam_id = :exam_id 
            ORDER BY question_number ASC
        ");
        $q_stmt->execute([':exam_id' => $exam_id]);
        $rawQuestions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawQuestions as $q) {
            $options = [];
            $correctIndex = 0;

            if (strtoupper($q['question_type']) === 'MCQ') {
                $opt_stmt = $dbConn->prepare("
                    SELECT option_text, option_order, is_correct 
                    FROM question_options 
                    WHERE question_id = :qid 
                    ORDER BY option_order ASC
                ");
                $opt_stmt->execute([':qid' => $q['id']]);
                $optsData = $opt_stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($optsData as $idx => $opt) {
                    $options[] = $opt['option_text'];
                    if (!empty($opt['is_correct'])) {
                        $correctIndex = $idx;
                    }
                }
            }

            $img = !empty($q['image_url']) ? $q['image_url'] : '';

            $existing_questions[] = [
                'ques_no'      => intval($q['question_number']),
                'text'         => $q['question_text'],
                'type'         => $q['question_type'],
                'marks'        => floatval($q['marks']),
                'options'      => $options,
                'correctIndex' => $correctIndex,
                'image'        => $img
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching questions for exam_id $exam_id: " . $e->getMessage());
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
    <title>Create Questions - <?= htmlspecialchars($exam['exam_title']); ?> (<?= htmlspecialchars($exam['batch']); ?>)</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-makeQues-page2.css">

    <!-- TinyMCE Cloud API Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="no-referrer"></script>

    <!-- Global Variables for JavaScript -->
    <script>
        window.EXAM_ID = <?php echo json_encode($exam_id); ?>;
        window.SELECTED_EXAM_TYPE = <?php echo json_encode($exam['exam_type'] ?? 'Hybrid (MCQ+Theory)'); ?>;
        window.EXISTING_QUESTIONS = <?php echo json_encode($existing_questions); ?>;
        window.SAVED_DRAFT_QUESTION = <?php echo json_encode($_SESSION['teacher_exam_draft_' . $exam_id] ?? null); ?>;
    </script>

    <style>
        .btn-cancel-exam {
            background-color: #dc3545;
            color: #ffffff;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s ease, opacity 0.2s ease;
        }
        .btn-cancel-exam:hover { background-color: #c82333; }
        .btn-cancel-exam:disabled { opacity: 0.7; cursor: not-allowed; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.5); display: flex; justify-content: center;
            align-items: center; z-index: 9999;
        }
        .modal-card {
            background: #ffffff; width: 90%; max-width: 450px;
            border-radius: 8px; padding: 20px 24px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .modal-header h3 { margin: 0; font-size: 1.15rem; color: #dc2626; display: flex; align-items: center; gap: 8px; }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; line-height: 1; }
        .modal-body { padding: 16px 0; font-size: 0.95rem; color: #334155; line-height: 1.5; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
        .btn-modal-dismiss { background: #e2e8f0; color: #334155; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-modal-dismiss:hover { background: #cbd5e1; }
        .btn-modal-danger { background: #dc2626; color: #ffffff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-modal-danger:hover { background: #b91c1c; }
        .btn-modal-danger:disabled { opacity: 0.7; cursor: not-allowed; }
    </style>
</head>

<body>

    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- STEP 2: EXAM DETAILS TABLE SECTION (FETCHED FROM exam_header) -->
        <section class="table-section">
            <div class="table-header">
                <h2>Target Exam Summary</h2>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Exam id</th>
                        <th>Teacher Name</th>
                        <th>Teacher ID</th>
                        <th>Target Batch</th>
                        <th>Course Title</th>
                        <th>Exam Type</th>
                        <th>Subject</th>
                        <th>Date & Time</th>
                        <th>Duration</th>
                        <th>Total Marks</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <!-- 1. Exam id (from exam_header) -->
                        <td>#<?php echo htmlspecialchars($exam_id); ?></td>

                        <!-- 2. Teacher Name (from exam_header) -->
                        <td><?php echo htmlspecialchars($teacher_name); ?></td>

                        <!-- 3. Teacher ID (from exam_header) -->
                        <td><?php echo htmlspecialchars($teacher_id_display); ?></td>

                        <!-- 4. Target Batch (from exam_header) -->
                        <td><strong style="color: #4338ca;"><?php echo htmlspecialchars($exam['batch'] ?? ''); ?></strong></td>

                        <!-- 5. Course Title (from exam_header) -->
                        <td><strong><?php echo htmlspecialchars($exam['exam_title'] ?? ''); ?></strong></td>

                        <!-- 6. Exam Type (from exam_header) -->
                        <td><span class="badge badge-type"><?php echo htmlspecialchars($exam['exam_type'] ?? ''); ?></span></td>

                        <!-- 7. Subject (from exam_header) -->
                        <td><?php echo htmlspecialchars($exam['subject'] ?? ''); ?></td>

                        <!-- 8. Date & Time (from exam_header) -->
                        <td><?php echo htmlspecialchars($formatted_dateTime); ?></td>

                        <!-- 9. Duration (from exam_header) -->
                        <td><?php echo htmlspecialchars($exam['duration'] ?? '0'); ?> min</td>

                        <!-- 10. Total Marks (counted from questions, stored in exam_header) -->
                        <td><strong id="totalMarksCell"><?php echo htmlspecialchars($exam['marks'] ?? '0'); ?></strong></td>

                        <!-- 11. Action (Edit details redirects to page 1) -->
                        <td style="text-align:center;">
                            <a href="teacher-makeQues-page1.php?exam_id=<?= urlencode($exam['id']); ?>&action=edit" class="start-btn" style="text-decoration: none; display: inline-block;">
                                Edit Details
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- ADD QUESTION SECTION -->
        <section class="question-builder-section">

            <div class="section-header">
                <h2>Create Questions for <?= htmlspecialchars($exam['batch'] ?? ''); ?> - <?= htmlspecialchars($exam['subject'] ?? ''); ?></h2>
                <button type="button" id="addQuesBtn" class="start-btn create-exam">
                    + Add Question
                </button>
            </div>

            <div id="questionContainer"></div>

            <div class="table-section summary-section" id="summaryTableWrapper" style="display: none; margin-top: 30px;">
                <div class="table-header">
                    <h2>Added Questions Summary</h2>
                </div>
                <table id="questionsSummaryTable">
                    <thead>
                        <tr>
                            <th>Ques No</th>
                            <th>Question / Text</th>
                            <th>Type</th>
                            <th>Marks</th>
                            <th>Options / Details</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="summaryTableBody">
                    </tbody>
                </table>
            </div>

            <!-- FORM ACTION BUTTON CONTAINER -->
            <div class="form-actions" style="margin-top: 30px; display: flex; justify-content: flex-end; align-items: center; gap: 15px;">
                <button type="button" id="cancelExamBtn" class="btn-cancel-exam" onclick="cancelExamCreation()">
                    <i class="fa-solid fa-xmark"></i> Remove All
                </button>

                <a href="view-exam-page.php?exam_id=<?= urlencode($exam_id); ?>" id="viewExamPageLink">
                    <button type="button" id="createExamBtn" class="start-btn create-exam exam-button" disabled style="opacity: 0.5; cursor: not-allowed;">
                        Create Exam
                    </button>
                </a>
            </div>

        </section>

    </div>

    <!-- CANCEL CONFIRMATION MODAL -->
    <div id="cancelConfirmModal" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Cancel Exam Creation</h3>
                <button type="button" class="modal-close-btn" id="modalCloseX" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel? All questions and details entered for this exam will be deleted.</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="modalDismissBtn" class="btn-modal-dismiss" onclick="closeCancelModal()">Go Back</button>
                <button type="button" id="confirmDeleteExamBtn" class="btn-modal-danger" onclick="proceedCancelExam()" disabled>
                    <i class="fa-solid fa-trash-can"></i> Yes, Cancel & Delete (3s)
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/teacher/teacher-makeQues-page2.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
     
</body>

</html>
