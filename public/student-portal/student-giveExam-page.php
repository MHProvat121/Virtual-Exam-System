<?php
// student-giveExam-page.php
// Live Student Examination Interface
// Features:
// 1. Accurate real duration retrieved from exam_details (`duration` column) with live countdown.
// 2. Interactive "Review All" modal showing answer status, attached images, and quick question jump navigation.
// 3. Robust autosave to schemas (`exam_submissions` and `student_answers`) on input, redirection, or exam finish.
// 4. Fixed camera & audio proctoring permissions with live dB meter and stream fallback.
// 5. Clean question HTML rendering without raw tag display (e.g. renders clean without raw "<p>what is laravel</p>").
// 6. Image & picture attachment support: students can submit photos/diagrams with an individual question or across multiple questions.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$db = $conn ?? $pdo ?? null;

$logged_student_id = $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? 'STD-2026-001';
$logged_student_name = $_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Alex Johnson';
$logged_batch = $_SESSION['student_batch'] ?? 'Batch 2026';

$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 102;
$is_revise_mode = isset($_GET['mode']) && $_GET['mode'] === 'revise';

if ($exam_id > 0) {
    $_SESSION['exam_entered_' . $exam_id] = 1;
    setcookie('exam_entered_' . $exam_id, '1', time() + 86400, '/');
}

$exam = null;
$questions = [];
$preloaded_answers = [];

// Fetch exam details and questions from DB
if ($db && $exam_id > 0) {
    try {
        if ($db instanceof PDO) {
            // STEP 1: Query exam_header (primary table for exam creation and view-exam-page)
            $stmt = $db->prepare("SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $exam_id]);
            $exam = $stmt->fetch(PDO::FETCH_ASSOC);

            // Fallback to exam_details if legacy
            if (!$exam) {
                try {
                    $stmt = $db->prepare("SELECT *, UNIX_TIMESTAMP(updated_at) AS timer_updated_at_epoch FROM exam_details WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $exam_id]);
                    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }

            // If still no exam for specified id, get latest from exam_header
            if (!$exam) {
                try {
                    $stmt = $db->query("SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header ORDER BY id DESC LIMIT 1");
                    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($exam) {
                        $exam_id = intval($exam['id']);
                    }
                } catch (Exception $e) {}
            }

            // Fetch questions
            $qStmt = $db->prepare("SELECT * FROM questions WHERE exam_id = :eid ORDER BY id ASC");
            $qStmt->execute([':eid' => $exam_id]);
            $rawQ = $qStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rawQ as $q) {
                $qId = intval($q['id']);
                $optStmt = $db->prepare("SELECT id, option_text FROM question_options WHERE question_id = :qid ORDER BY id ASC");
                $optStmt->execute([':qid' => $qId]);
                $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

                $questions[] = [
                    'id'            => $qId,
                    'question_text' => $q['question_text'],
                    'question_type' => $q['question_type'] ?? 'MCQ',
                    'marks'         => intval($q['marks'] ?? 1),
                    'image_url'     => $q['image_url'] ?? '',
                    'options'       => $options
                ];
            }

            // Preload previously saved answers from student_answers (including answer_image)
            $ansStmt = $db->prepare("SELECT * FROM student_answers WHERE exam_id = :eid AND student_id = :sid");
            $ansStmt->execute([':eid' => $exam_id, ':sid' => $logged_student_id]);
            $ansRows = $ansStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ansRows as $ar) {
                $rawImgs = $ar['answer_image'] ?? '';
                $parsedImgs = [];
                if (!empty($rawImgs)) {
                    $decoded = json_decode($rawImgs, true);
                    if (is_array($decoded)) {
                        $parsedImgs = $decoded;
                    } elseif (is_string($rawImgs)) {
                        $parsedImgs = [$rawImgs];
                    }
                }

                $preloaded_answers[$ar['question_id']] = [
                    'option' => $ar['selected_option_id'] ? strval($ar['selected_option_id']) : '',
                    'text'   => $ar['answer_text'] ?? '',
                    'images' => $parsedImgs
                ];
            }

        } elseif ($db instanceof mysqli) {
            // STEP 1: Query exam_header (primary table for exam creation and view-exam-page)
            $stmt = $db->prepare("SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $exam_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();

            if (!$exam) {
                $stmt = $db->prepare("SELECT *, UNIX_TIMESTAMP(updated_at) AS timer_updated_at_epoch FROM exam_details WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                $exam = $stmt->get_result()->fetch_assoc();
            }

            if (!$exam) {
                $resLatest = $db->query("SELECT *, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header ORDER BY id DESC LIMIT 1");
                if ($resLatest && $resLatest->num_rows > 0) {
                    $exam = $resLatest->fetch_assoc();
                    if ($exam) {
                        $exam_id = intval($exam['id']);
                    }
                }
            }

            $qStmt = $db->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY id ASC");
            $qStmt->bind_param("i", $exam_id);
            $qStmt->execute();
            $res = $qStmt->get_result();

            while ($q = $res->fetch_assoc()) {
                $qId = intval($q['id']);
                $optStmt = $db->prepare("SELECT id, option_text FROM question_options WHERE question_id = ? ORDER BY id ASC");
                $optStmt->bind_param("i", $qId);
                $optStmt->execute();
                $optRes = $optStmt->get_result();
                $options = $optRes->fetch_all(MYSQLI_ASSOC);

                $questions[] = [
                    'id'            => $qId,
                    'question_text' => $q['question_text'],
                    'question_type' => $q['question_type'] ?? 'MCQ',
                    'marks'         => intval($q['marks'] ?? 1),
                    'image_url'     => $q['image_url'] ?? '',
                    'options'       => $options
                ];
            }

            // Preload answers (including answer_image)
            $ansStmt = $db->prepare("SELECT * FROM student_answers WHERE exam_id = ? AND student_id = ?");
            $ansStmt->bind_param("is", $exam_id, $logged_student_id);
            $ansStmt->execute();
            $ansRes = $ansStmt->get_result();
            while ($ar = $ansRes->fetch_assoc()) {
                $rawImgs = $ar['answer_image'] ?? '';
                $parsedImgs = [];
                if (!empty($rawImgs)) {
                    $decoded = json_decode($rawImgs, true);
                    if (is_array($decoded)) {
                        $parsedImgs = $decoded;
                    } elseif (is_string($rawImgs)) {
                        $parsedImgs = [$rawImgs];
                    }
                }

                $preloaded_answers[$ar['question_id']] = [
                    'option' => $ar['selected_option_id'] ? strval($ar['selected_option_id']) : '',
                    'text'   => $ar['answer_text'] ?? '',
                    'images' => $parsedImgs
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Database error in giveExam: " . $e->getMessage());
    }
}

// Fallback questions for interactive sandbox testing
if (!$exam) {
    $exam = [
        'id'           => $exam_id,
        'exam_title'   => 'Final Semester Examination 2026',
        'subject'      => 'Web Systems & Frameworks',
        'teacher_name' => 'Dr. Sarah Connor',
        'teacher_id'   => 'T-101',
        'batch'        => $logged_batch,
        'exam_type'    => 'Hybrid (MCQ+Theory)',
        'exam_date'    => date('Y-m-d'),
        'exam_time'    => '10:00:00',
        'duration'     => 60,
        'marks'        => 80,
        'total_marks'  => 80,
        'status'       => 'Running',
        'remaining_seconds' => 3600,
        'timer_updated_at_epoch' => time()
    ];
}

// Extract view-exam-page header variables
$teacher_name = !empty($exam['teacher_name']) ? $exam['teacher_name'] : 'Dr. Sarah Connor';
$teacher_id_display = !empty($exam['teacher_id']) ? $exam['teacher_id'] : 'T-101';
$subject_display = !empty($exam['subject']) ? $exam['subject'] : 'Computer Science';
$batch_display = !empty($exam['batch']) ? $exam['batch'] : $logged_batch;
$exam_title_display = !empty($exam['exam_title']) ? $exam['exam_title'] : 'Midterm Examination 2026';
$exam_type_display = !empty($exam['exam_type']) ? $exam['exam_type'] : 'Hybrid (MCQ + Theory)';
$total_marks_display = !empty($exam['marks']) ? $exam['marks'] : (!empty($exam['total_marks']) ? $exam['total_marks'] : 80);
$exam_date_val = !empty($exam['exam_date']) ? $exam['exam_date'] : date('Y-m-d');
$exam_time_val = !empty($exam['exam_time']) ? substr($exam['exam_time'], 0, 5) : '10:00';
$examTimezone = new DateTimeZone('Asia/Dhaka');
$utcTimezone = new DateTimeZone('UTC');
$scheduledStart = null;
try {
    $scheduledStart = new DateTimeImmutable($exam_date_val . ' ' . $exam_time_val, $examTimezone);
} catch (Exception $e) {
    $scheduledStart = null;
}
$date_time_display = $scheduledStart
    ? $scheduledStart->format('d/m/Y h:i A') . ' (Asia/Dhaka) · UTC ' . $scheduledStart->setTimezone($utcTimezone)->format('d/m/Y h:i A')
    : ($exam_date_val . ' ' . $exam_time_val);
$exam['timer_updated_at_epoch'] = (int)($exam['timer_updated_at_epoch'] ?? 0);
$exam['remaining_seconds'] = max(0, (int)($exam['remaining_seconds'] ?? ($exam_duration_mins * 60)));

// Ensure duration is accurately retrieved (support both `duration` and `exam_duration`)
$exam_duration_mins = intval($exam['duration'] ?? $exam['exam_duration'] ?? 5);
if ($exam_duration_mins <= 0) {
    $exam_duration_mins = 5;
}

if (empty($questions)) {
    // Rich questions testing HTML rendering fix (specifically <p>what is laravel </p> and code snippets)
    $questions = [
        [
            'id'            => 1,
            'question_text' => '<p>What is Laravel, and what architectural pattern does it predominantly follow?</p>',
            'question_type' => 'MCQ',
            'marks'         => 2,
            'options'       => [
                ['id' => '1', 'option_text' => '<p>A PHP web framework built on the MVC (Model-View-Controller) architectural pattern.</p>'],
                ['id' => '2', 'option_text' => '<p>A client-side JavaScript compiler for single page apps.</p>'],
                ['id' => '3', 'option_text' => '<p>A relational database query optimizer for SQLite.</p>'],
                ['id' => '4', 'option_text' => '<p>An Apache HTTP web server reverse-proxy configuration tool.</p>']
            ]
        ],
        [
            'id'            => 2,
            'question_text' => '<p>Write down key modern programming languages used in full-stack web and systems software. You may attach handwritten notes or architecture diagrams if necessary.</p>',
            'question_type' => 'Descriptive',
            'marks'         => 5,
            'options'       => []
        ],
        [
            'id'            => 3,
            'question_text' => '<p>In PHP, which global superglobal array contains header, path, and script location information?</p>',
            'question_type' => 'MCQ',
            'marks'         => 2,
            'options'       => [
                ['id' => '5', 'option_text' => '<code>$_ENV</code>'],
                ['id' => '6', 'option_text' => '<code>$_SERVER</code>'],
                ['id' => '7', 'option_text' => '<code>$_GLOBALS</code>'],
                ['id' => '8', 'option_text' => '<code>$_SESSION</code>']
            ]
        ],
        [
            'id'            => 4,
            'question_text' => '<p>Explain the difference between <strong>PDO</strong> and <strong>MySQLi</strong> in PHP database access, emphasizing security and parameter binding. Attach any relevant schema diagrams or code screenshots.</p>',
            'question_type' => 'Descriptive',
            'marks'         => 5,
            'options'       => []
        ]
    ];
}

// Helper to clean and decode question text safely
function clean_question_text($text) {
    if (empty($text)) return '';
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return strip_tags($decoded, '<p><br><strong><b><em><i><code><pre><ul><ol><li><span><sub><sup>');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($exam['exam_title']); ?> - Exam Portal</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <!-- TinyMCE Rich Document Editor (Google Docs features for Theory & Writing) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <!-- Student Give Exam CSS -->
    <link rel="stylesheet" href="../assets/css/student/student-giveExam-page.css">
    <link rel="stylesheet" href="../assets/css/common/exam-proctoring.css">
</head>
<body data-exam-id="<?php echo $exam_id; ?>"
      data-student-id="<?php echo htmlspecialchars($logged_student_id); ?>"
      data-duration-mins="<?php echo $exam_duration_mins; ?>"
      data-is-revise="<?php echo $is_revise_mode ? '1' : '0'; ?>"
      data-status="<?php echo htmlspecialchars($exam['status'] ?? 'Published'); ?>"
      data-remaining-seconds="<?php echo $exam['remaining_seconds']; ?>"
      data-timer-updated-at-epoch="<?php echo $exam['timer_updated_at_epoch']; ?>"
      data-start-timestamp="<?php echo $scheduledStart ? $scheduledStart->getTimestamp() : 0; ?>"
      data-server-time-ms="<?php echo (int) round(microtime(true) * 1000); ?>"
      data-proctor-role="student"
      data-signal-url="../exam-proctoring-signal.php"
      data-ice-servers="<?php echo htmlspecialchars(json_encode([['urls' => 'stun:stun.l.google.com:19302']]), ENT_QUOTES); ?>">

    <div class="exam-viewport">
        <!-- Top Navigation & Live Timer Bar -->
        <header class="exam-navbar">
            <div class="exam-nav-left">
                <a href="student-examTable-page.php" class="back-to-table-btn" title="Back to Exam Table">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div class="exam-nav-title-block">
                    <div class="exam-nav-meta-badges">
                        <span class="badge-exam-type"><?php echo htmlspecialchars($exam_type_display); ?></span>
                        <span class="badge-exam-batch"><i class="fa-solid fa-users"></i> <?php echo htmlspecialchars($batch_display); ?></span>
                    </div>
                    <h1 class="exam-nav-title">
                        <i class="fa-solid fa-graduation-cap text-indigo-400"></i>
                        <?php echo htmlspecialchars($exam_title_display); ?>
                    </h1>
                </div>
            </div>

            <div class="exam-nav-right">
                <div class="countdown-box">
                    <span class="countdown-label"><i class="fa-regular fa-clock"></i> Time Left:</span>
                    <span class="countdown-digits" id="countdownDigits">--:--:--</span>
                </div>
                <button type="button" class="btn-action btn-submit-exam" id="submitExamBtn">
                    <i class="fa-solid fa-paper-plane"></i> Submit Exam
                </button>
            </div>
        </header>

        <!-- View-Exam-Page Standard Header Banner (Teacher Name, Teacher ID, Subject Name, Exam Title, Target Batch, Marks, Time) -->
        <section class="exam-header-banner">
            <div class="exam-header-meta-grid">
                <div class="header-meta-item">
                    <span class="meta-label"><i class="fa-solid fa-user-tie"></i> Teacher:</span>
                    <span class="meta-val"><strong><?php echo htmlspecialchars($teacher_name); ?></strong></span>
                </div>
                <div class="header-meta-item">
                    <span class="meta-label"><i class="fa-solid fa-id-badge"></i> Teacher ID:</span>
                    <span class="meta-val"><?php echo htmlspecialchars($teacher_id_display); ?></span>
                </div>
                <div class="header-meta-item">
                    <span class="meta-label"><i class="fa-solid fa-book-open"></i> Subject:</span>
                    <span class="meta-val"><strong><?php echo htmlspecialchars($subject_display); ?></strong></span>
                </div>
                <div class="header-meta-item">
                    <span class="meta-label"><i class="fa-solid fa-layer-group"></i> Batch:</span>
                    <span class="meta-val"><strong style="color: #6366f1;"><?php echo htmlspecialchars($batch_display); ?></strong></span>
                </div>
                <div class="header-meta-item">
                    <span class="meta-label"><i class="fa-regular fa-calendar-days"></i> Scheduled:</span>
                    <span class="meta-val"><?php echo htmlspecialchars($date_time_display); ?></span>
                </div>
                <div class="header-meta-item highlight">
                    <span class="meta-label"><i class="fa-regular fa-clock"></i> Time Allowed:</span>
                    <span class="meta-val"><strong><?php echo htmlspecialchars($exam_duration_mins); ?> Mins</strong></span>
                </div>
                <div class="header-meta-item highlight">
                    <span class="meta-label"><i class="fa-solid fa-award"></i> Total Marks:</span>
                    <span class="meta-val"><strong><?php echo htmlspecialchars($total_marks_display); ?> Marks</strong></span>
                </div>
            </div>
        </section>

        <!-- Revision Mode Alert Banner if Revising -->
        <?php if ($is_revise_mode): ?>
            <div class="revision-banner">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square fa-lg"></i>
                    <span><strong>Revision Mode Active:</strong> You have previously submitted this exam paper. You may revise or change your answers and click "Submit Exam" anytime while duration remains!</span>
                </div>
                <a href="student-examTable-page.php" style="color: #ffffff; text-decoration: underline; font-size: 0.82rem;">Return to Exam Table</a>
            </div>
        <?php endif; ?>

        <!-- Main Examination Grid -->
        <main class="exam-main-container">
            <!-- Left: Active Question Panel -->
            <section class="question-panel">
                <div class="question-header">
                    <span class="question-num-badge" id="questionNumBadge">Question 1 of <?php echo count($questions); ?></span>
                    <span class="question-marks-badge" id="questionMarksBadge">2 Marks</span>
                </div>

                <div class="question-body">
                    <!-- Clean Question Text (No raw tags like <p>what is laravel </p>) -->
                    <div class="question-text" id="questionText">
                        <?php echo clean_question_text($questions[0]['question_text'] ?? 'Question Text'); ?>
                    </div>

                    <!-- Question Attached Diagram / Figure (if question has an image) -->
                    <div class="question-image-box" id="questionImageBox" style="<?php echo !empty($questions[0]['image_url']) ? '' : 'display: none;'; ?> margin-bottom: 20px;">
                        <img id="questionImageEl" 
                             src="<?php echo !empty($questions[0]['image_url']) ? htmlspecialchars($questions[0]['image_url']) : ''; ?>" 
                             alt="Question Diagram" 
                             class="question-attached-img"
                             style="max-width: 100%; max-height: 420px; width: auto; height: auto; border-radius: 8px; border: 1px solid #e2e8f0; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: block; background: #ffffff;">
                    </div>

                    <!-- MCQ Options List -->
                    <div class="options-list" id="mcqOptionsContainer">
                        <!-- Populated dynamically by student-giveExam-page.js -->
                    </div>

                    <!-- Descriptive Answer Box (Google Docs Rich Document Editor) -->
                    <div id="descriptiveContainer" style="display: none;">
                        <div class="theory-editor-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                            <span style="font-weight: 600; font-size: 0.88rem; color: #334155; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-file-pen" style="color: #4f46e5;"></i> Written Theory & Solution (Google Docs Editor)
                            </span>
                            <span style="font-size: 0.76rem; color: #64748b;">
                                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Rich formatting, symbols & table tools enabled
                            </span>
                        </div>
                        <textarea class="descriptive-answer-box" id="descriptiveText" placeholder="Type your detailed written answer here..."></textarea>
                    </div>

                    <!-- Picture / Image Attachment Section for individual or multiple questions -->
                    <div class="answer-attachment-section" id="answerAttachmentSection">
                        <div class="attachment-header">
                            <span class="attachment-title">
                                <i class="fa-regular fa-image"></i> Attach Image / Picture Solution
                            </span>
                            <span class="attachment-subtitle">Optional (PNG, JPG, WEBP, GIF &bull; Max 10MB)</span>
                        </div>

                        <!-- Choose File Option Action Bar -->
                        <div class="choose-file-bar">
                            <label for="answerFileInput" class="btn-choose-file" id="chooseFileLabel">
                                <i class="fa-solid fa-folder-open"></i> Choose File
                            </label>
                            <span class="chosen-file-name" id="chosenFileNameText">No file chosen</span>
                            <input type="file" id="answerFileInput" accept="image/png, image/jpeg, image/jpg, image/webp, image/gif" multiple class="real-file-input">
                        </div>

                        <!-- Dropzone & Click-to-Upload Area -->
                        <div class="attachment-dropzone" id="attachmentDropzone">
                            <div class="dropzone-content">
                                <i class="fa-solid fa-cloud-arrow-up dropzone-icon"></i>
                                <div class="dropzone-text">
                                    <strong>Or drag & drop pictures here</strong> (or click to browse)
                                </div>
                                <span class="dropzone-hint">Attach photos of handwritten solutions, math work, sketches, or diagrams for this question</span>
                            </div>
                        </div>

                        <!-- Attached Images Grid Preview -->
                        <div class="attached-images-grid" id="attachedImagesGrid">
                            <!-- Populated dynamically by student-giveExam-page.js -->
                        </div>
                    </div>
                </div>

                <div class="question-footer">
                    <button type="button" class="btn-action btn-secondary" id="prevQuestionBtn" disabled>
                        <i class="fa-solid fa-chevron-left"></i> Previous
                    </button>
                    <div style="font-size: 0.82rem; color: #64748b;" id="autosaveStatusIndicator">
                        <i class="fa-solid fa-cloud-arrow-up text-emerald-600"></i> Answers auto-saved
                    </div>
                    <button type="button" class="btn-action btn-primary" id="nextQuestionBtn">
                        Next <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </section>

            <!-- Right Sidebar: Proctoring Video, Mic Meter, & Palette -->
            <aside class="sidebar-panel">
                <!-- Proctoring Card (Fixed Camera & Audio) -->
                <div class="proctoring-card">
                    <div class="proctoring-header">
                        <span><i class="fa-solid fa-shield-halved"></i> Proctoring Stream</span>
                        <span class="proctor-status-indicator" id="proctorStatus">
                            <span class="dot"></span> Connecting...
                        </span>
                    </div>

                    <div class="proctor-video-wrapper">
                        <!-- Video Feed -->
                        <video id="proctorVideo" autoplay playsinline muted></video>

                        <!-- Fallback / Permission Request Overlay -->
                        <div class="camera-fallback-overlay" id="cameraOverlay">
                            <i class="fa-solid fa-video-slash fa-2x" style="color: #f87171;"></i>
                            <p>Camera & microphone permission required for secure proctoring.</p>
                            <button type="button" class="btn-request-perms" id="requestPermsBtn">
                                <i class="fa-solid fa-camera"></i> Allow Camera & Mic
                            </button>
                        </div>
                    </div>

                    <div class="teacher-proctor-card">
                        <div class="teacher-proctor-header">
                            <span><i class="fa-solid fa-chalkboard-user"></i> Teacher Live Feed</span>
                            <span id="teacherConnectionStatus">Waiting for teacher</span>
                        </div>
                        <video id="teacherProctorVideo" autoplay playsinline></video>
                        <button type="button" id="enableTeacherAudioBtn">Enable teacher audio</button>
                    </div>
                    <!-- Real-time Microphone Decibel Visualizer -->
                    <div class="audio-meter-container">
                        <div class="audio-meter-label">
                            <span><i class="fa-solid fa-microphone-lines"></i> Mic Monitor:</span>
                            <span id="audioDbLabel" style="font-family: var(--font-mono); font-size: 0.72rem;">Listening...</span>
                        </div>
                        <div class="audio-meter-bar-track">
                            <div class="audio-meter-bar-fill" id="audioMeterFill"></div>
                        </div>
                    </div>
                </div>

                <!-- Question Palette Navigation -->
                <div class="palette-card">
                    <div class="palette-title">
                        <span>Question Palette</span>
                        <span style="font-size: 0.76rem; color: #16a34a; font-weight: 600;">
                            <i class="fa-solid fa-circle-check"></i> Green = Answered
                        </span>
                    </div>
                    <div class="palette-grid" id="paletteGrid">
                        <!-- Populated by student-giveExam-page.js -->
                    </div>
                    <button type="button" class="btn-action btn-secondary" id="paletteReviewBtn" style="width: 100%; margin-top: 14px; justify-content: center;">
                        <i class="fa-solid fa-list-check"></i> Review All Answers
                    </button>
                </div>
            </aside>
        </main>
    </div>

    <!-- Review All Answers Modal -->
    <div class="modal-backdrop" id="reviewModal" role="dialog" aria-modal="true">
        <div class="modal-card modal-card-wide">
            <div class="modal-header-custom">
                <div class="modal-icon modal-icon-info">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 class="modal-title" style="text-align: left; margin: 0;">Exam Review & Answer Summary</h3>
                    <p class="modal-desc" style="text-align: left; margin: 2px 0 0 0;">Check your responses and uploaded images before submitting your exam.</p>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="review-stats-bar">
                <div class="stat-pill stat-answered">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Answered: <strong id="reviewAnsweredCount">0</strong> / <strong id="reviewTotalCount">0</strong></span>
                </div>
                <div class="stat-pill stat-unanswered">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>Unanswered: <strong id="reviewUnansweredCount">0</strong></span>
                </div>
                <div class="stat-pill stat-timer-mini">
                    <i class="fa-regular fa-clock"></i>
                    <span id="reviewTimerDigits">--:--:--</span>
                </div>
            </div>

            <!-- Questions Review List -->
            <div class="review-questions-list" id="reviewQuestionsList">
                <!-- Populated dynamically by JS -->
            </div>

            <div class="modal-actions" style="margin-top: 20px; justify-content: space-between;">
                <button type="button" class="btn-action btn-secondary" id="closeReviewBtn">
                    <i class="fa-solid fa-arrow-left"></i> Back to Questions
                </button>
                <button type="button" class="btn-action btn-submit-exam" id="reviewProceedSubmitBtn">
                    <i class="fa-solid fa-paper-plane"></i> Submit Exam
                </button>
            </div>
        </div>
    </div>

    <!-- Submit Confirmation Modal -->
    <div class="modal-backdrop" id="submitModal" role="dialog" aria-modal="true">
        <div class="modal-card">
            <div class="modal-icon modal-icon-success">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
            <h3 class="modal-title">Submit Exam Paper?</h3>
            <p class="modal-desc">
                Are you sure you want to submit your exam? All your written answers and uploaded images are already securely auto-saved. If exam duration is still remaining, you can use the <strong>Revise</strong> button from your exam table to edit your answers anytime!
            </p>
            <div class="modal-actions">
                <button type="button" class="btn-action btn-secondary" id="cancelSubmitBtn">
                    Cancel & Continue
                </button>
                <button type="button" class="btn-action btn-submit-exam" id="confirmSubmitBtn">
                    <i class="fa-solid fa-check"></i> Yes, Submit Exam
                </button>
            </div>
        </div>
    </div>

    <!-- Image Lightbox Modal for Viewing Uploaded Pictures -->
    <div class="modal-backdrop" id="imageLightboxModal" role="dialog" aria-modal="true">
        <div class="modal-card modal-card-image">
            <div class="lightbox-header">
                <span id="lightboxImageTitle" style="font-weight: 600; font-size: 0.95rem; color: #1e293b;">Image Preview</span>
                <button type="button" class="btn-close-lightbox" id="closeLightboxBtn">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="lightbox-body">
                <img id="lightboxImageEl" src="" alt="Enlarged Answer Image">
            </div>
        </div>
    </div>

    <!-- Hold Alert Modal (When teacher pauses the exam) -->
    <div class="modal-backdrop" id="holdModal" role="dialog" aria-modal="true">
        <div class="modal-card">
            <div class="modal-icon modal-icon-warning">
                <i class="fa-solid fa-pause"></i>
            </div>
            <h3 class="modal-title">Exam Temporarily On Hold</h3>
            <p class="modal-desc">
                The supervising teacher has placed this exam session on hold. The countdown clock and answering have been paused. Please wait; the exam will automatically resume once the teacher continues.
            </p>
            <div style="font-size: 0.8rem; color: #92400e; font-weight: 600; background: #fef3c7; padding: 8px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-spinner fa-spin"></i> Awaiting teacher resume signal...
            </div>
        </div>
    </div>

    <!-- Questions Data JSON injection -->
    <script>
        window.EXAM_QUESTIONS = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        window.PRELOADED_ANSWERS = <?php echo json_encode($preloaded_answers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>
    <!-- Student Exam Engine JS -->
    <script src="../assets/js/common/exam-proctoring.js"></script>
    <script src="../assets/js/student/student-giveExam-page.js"></script>
    
</body>
</html>
