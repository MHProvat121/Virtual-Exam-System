<?php
// ========================================================
// 1. SESSION & CONFIGURATION (STEP 3: exam_header FETCH)
// ========================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// RBAC Gatekeeper
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

require_once __DIR__ . '/../../src/config/config.php';

$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Retrieve target exam_id from URL
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Fetch logged-in teacher info
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

$exam = null;
$mcq_questions = [];
$theory_questions = [];
$mcq_total_marks = 0;
$theory_total_marks = 0;

if ($exam_id > 0 && $dbConn) {
    try {
        // STEP 3: Fetch Exam Details strictly from `exam_header` table
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

            // Fetch questions from `questions` table for THIS exact exam_id
            $q_stmt = $dbConn->prepare("
                SELECT id, question_number, question_type, question_text, marks, image_url 
                FROM questions 
                WHERE exam_id = :exam_id 
                ORDER BY question_number ASC
            ");
            $q_stmt->execute([':exam_id' => $exam_id]);
            $all_questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($all_questions as $q) {
                if (strtoupper($q['question_type']) === 'MCQ') {
                    // Fetch choices from `question_options` table
                    $opt_stmt = $dbConn->prepare("
                        SELECT option_text, option_order, is_correct 
                        FROM question_options 
                        WHERE question_id = :qid 
                        ORDER BY option_order ASC
                    ");
                    $opt_stmt->execute([':qid' => $q['id']]);
                    $q['options'] = $opt_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $mcq_questions[] = $q;
                    $mcq_total_marks += floatval($q['marks']);
                } else {
                    $theory_questions[] = $q;
                    $theory_total_marks += floatval($q['marks']);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error loading exam header details in view-exam-page: " . $e->getMessage());
    }
}

function getQuestionImageUrl($q) {
    return !empty($q['image_url']) ? $q['image_url'] : '';
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
    <title><?php echo $exam ? htmlspecialchars($exam['exam_title']) . " (" . htmlspecialchars($exam['batch']) . ") - Question Paper" : "Exam Not Found"; ?></title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/view-exam-page.css">
</head>

<body>
    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR FILE -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- QUESTION PAPER AREA START -->
        <main class="dashboard">

            <?php if (!$exam): ?>
                <!-- ERROR STATE: EXAM NOT FOUND -->
                <div class="question-paper-card" style="text-align: center; padding: 60px 20px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #ef4444; margin-bottom: 15px;"></i>
                    <h2>Exam Paper Not Found</h2>
                    <p style="color: #64748b; margin: 10px 0 25px;">The requested exam does not exist or has been removed from exam_header.</p>
                    <a href="teacher-quesTable-page.php" class="btn btn-primary">
                        <i class="fa-solid fa-arrow-left"></i> Return to Exam List
                    </a>
                </div>
            <?php else: ?>

                <!-- Top Control Action Bar -->
                <div class="action-bar no-print">
                    <a href="teacher-quesTable-page.php" class="btn btn-secondary">
                        <i class="fa-solid fa-arrow-left"></i> Back to Exams
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-danger" id="openCancelModalBtn">
                            <i class="fa-solid fa-circle-xmark"></i> Cancel Exam
                        </button>

                        <a href="teacher-makeQues-page2.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary">
                            <i class="fa-solid fa-pen-to-square"></i> Edit Questions
                        </a>
                        <button type="button" onclick="window.print()" class="btn btn-outline">
                            <i class="fa-solid fa-print"></i> Print / Save PDF
                        </button>
                    </div>
                </div>

                <!-- Printable Question Paper Sheet -->
                <section class="question-paper-card">
                    
                    <!-- Paper Header -->
                    <div class="paper-header">
                        <div class="institution-logo">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <h1 class="institution-name">VIRTUAL EXAM SYSTEM</h1>
                        <h3 class="department-title">Department of Computer Science & Engineering</h3>
                        <div class="exam-title-badge">
                            <span><?php echo htmlspecialchars($exam['exam_title']); ?></span>
                        </div>
                    </div>

                    <!-- Exam Metadata Information Grid (Fetched from exam_header) -->
                    <div class="paper-meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Course Teacher:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($teacher_name); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Teacher ID:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($teacher_id_display); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Course Subject:</span>
                            <span class="meta-value"><strong><?php echo htmlspecialchars($exam['subject'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Target Batch:</span>
                            <span class="meta-value"><strong style="color: #4338ca;"><?php echo htmlspecialchars($exam['batch'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Exam Type:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($exam['exam_type'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Date & Time:</span>
                            <span class="meta-value">
                                <?php 
                                    $e_date = !empty($exam['exam_date']) ? date('d/m/Y', strtotime($exam['exam_date'])) : 'N/A';
                                    $e_time = !empty($exam['exam_time']) ? date('h:i A', strtotime($exam['exam_time'])) : '';
                                    echo htmlspecialchars($e_date . ($e_time ? " ($e_time)" : ''));
                                ?>
                            </span>
                        </div>
                        <div class="meta-item highlight">
                            <span class="meta-label">Time Allowed:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($exam['duration'] ?? '0'); ?> Minutes</span>
                        </div>
                        <div class="meta-item highlight">
                            <span class="meta-label">Total Marks:</span>
                            <span class="meta-value"><?php echo htmlspecialchars($exam['marks'] ?? $exam['total_marks'] ?? '0'); ?> Marks</span>
                        </div>
                    </div>

                    <!-- Instructions Box -->
                    <div class="paper-instructions">
                        <p class="instructions-heading"><i class="fa-solid fa-circle-info"></i> Instructions to Candidates:</p>
                        <ul>
                            <li>Figures in the right margin indicate full marks for each question.</li>
                            <?php if (!empty($mcq_questions)): ?>
                                <li>Answer all questions in <strong>Section A (MCQs)</strong>.</li>
                            <?php endif; ?>
                            <?php if (!empty($theory_questions)): ?>
                                <li>Answer questions clearly in <strong>Section B (Theory)</strong>.</li>
                            <?php endif; ?>
                            <li>Ensure correct submission before the timer runs out.</li>
                        </ul>
                    </div>

                    <div class="paper-divider"></div>

                    <!-- SECTION A: MCQ QUESTIONS -->
                    <?php if (!empty($mcq_questions)): ?>
                        <div class="paper-section">
                            <div class="section-title">
                                <h3>SECTION A: Multiple Choice Questions</h3>
                                <span class="section-marks">[Total Marks: <?php echo $mcq_total_marks; ?>]</span>
                            </div>

                            <div class="questions-list">
                                <?php foreach ($mcq_questions as $index => $q): ?>
                                    <div class="question-block">
                                        <div class="question-head">
                                            <span class="q-number">Q<?php echo htmlspecialchars($q['question_number'] ?? ($index + 1)); ?>.</span>
                                            <div class="q-content">
                                                <div class="q-text"><?php echo $q['question_text']; ?></div>
                                                
                                                <?php 
                                                    $img_url = getQuestionImageUrl($q);
                                                    if (!empty($img_url)): 
                                                ?>
                                                    <div class="q-image-box">
                                                        <img src="<?php echo htmlspecialchars($img_url); ?>" alt="Question Diagram" class="q-attached-image">
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="q-marks">[<?php echo htmlspecialchars($q['marks']); ?>]</span>
                                        </div>

                                        <?php if (!empty($q['options'])): ?>
                                            <div class="mcq-options-grid">
                                                <?php 
                                                $opt_letters = ['A', 'B', 'C', 'D', 'E', 'F'];
                                                foreach ($q['options'] as $o_idx => $opt): 
                                                    $letter = isset($opt_letters[$opt['option_order']]) ? $opt_letters[$opt['option_order']] : chr(65 + $o_idx);
                                                ?>
                                                    <div class="option-item">
                                                        <span class="option-prefix">(<?php echo $letter; ?>)</span>
                                                        <span><?php echo htmlspecialchars($opt['option_text']); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SECTION B: THEORY / DESCRIPTIVE QUESTIONS -->
                    <?php if (!empty($theory_questions)): ?>
                        <div class="paper-section">
                            <div class="section-title">
                                <h3>SECTION B: Theory & Descriptive Questions</h3>
                                <span class="section-marks">[Total Marks: <?php echo $theory_total_marks; ?>]</span>
                            </div>

                            <div class="questions-list">
                                <?php foreach ($theory_questions as $index => $q): ?>
                                    <div class="question-block">
                                        <div class="question-head">
                                            <span class="q-number">Q<?php echo htmlspecialchars($q['question_number'] ?? ($index + 1)); ?>.</span>
                                            <div class="q-content">
                                                <div class="q-text"><?php echo $q['question_text']; ?></div>

                                                <?php 
                                                    $img_url = getQuestionImageUrl($q);
                                                    if (!empty($img_url)): 
                                                ?>
                                                    <div class="q-image-box">
                                                        <img src="<?php echo htmlspecialchars($img_url); ?>" alt="Question Diagram" class="q-attached-image">
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="q-marks">[<?php echo htmlspecialchars($q['marks']); ?>]</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- NO QUESTIONS ADDED YET -->
                    <?php if (empty($mcq_questions) && empty($theory_questions)): ?>
                        <div style="text-align: center; padding: 40px; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px;">
                            <i class="fa-regular fa-folder-open" style="font-size: 2rem; margin-bottom: 10px;"></i>
                            <p>No questions have been created for this exam paper yet.</p>
                            <a href="teacher-makeQues-page2.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="fa-solid fa-plus"></i> Add Questions Now
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Footer End Line -->
                    <div class="paper-footer">
                        <span>*** END OF QUESTION PAPER ***</span>
                    </div>

                </section>
            <?php endif; ?>

        </main>

    </div>

<!-- CANCEL EXAM WARNING MODAL -->
<?php if ($exam): ?>
<div class="cancel-modal-overlay" id="cancelExamModal" aria-hidden="true" role="dialog">
    <div class="cancel-modal-card">
        
        <div class="cancel-modal-header">
            <div class="warning-icon-wrapper">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <button type="button" class="modal-close-btn" id="closeCancelModalBtn" aria-label="Close modal">&times;</button>
        </div>

        <div class="cancel-modal-body">
            <h2>Cancel & Delete Exam?</h2>
            <p>Are you sure you want to cancel <strong>"<?php echo htmlspecialchars($exam['exam_title']); ?>"</strong>?</p>
            
            <div class="warning-alert-box">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>This action will permanently delete this exam from exam_header and all attached questions. This step cannot be undone.</span>
            </div>
        </div>

        <div class="cancel-modal-footer">
            <button type="button" class="btn-modal btn-modal-secondary" id="backCancelModalBtn">
                <i class="fa-solid fa-arrow-left"></i> Go Back
            </button>

            <form action="view-exam-page-logic.php" method="POST" id="cancelExamForm" style="margin: 0;">
                <input type="hidden" name="action" value="cancel_exam">
                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                
                <button type="submit" class="btn-modal btn-modal-danger" id="confirmDeleteBtn" disabled>
                    <i class="fa-solid fa-trash-can"></i> 
                    <span id="confirmDeleteText">Delete Exam (3s)</span>
                </button>
            </form>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- JavaScript Files -->
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>
<script src="../assets/js/teacher/view-exam-page.js"></script>

</body>
</html>
