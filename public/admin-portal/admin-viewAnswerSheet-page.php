<?php
// ============================================================================
// Student Answer Sheet & Result Page
// Displays the student's submitted examination paper with question-by-question
// marks, correct answer keys for MCQs, written theory responses, and examiner remarks.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// RBAC: Session gatekeeper (Admin, Student, or Teacher preview)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && !isset($_SESSION['student_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
 

// Resolve database connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// exam_submissions and exam_evaluations are created by the schema migration.
// Do not call ensureSubmissionsTables() here; no runtime helper is defined.

function formatAnswerSheetUtc($value, $format) {
    if (empty($value)) return 'N/A';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format($format) . ' UTC';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function sanitizeAnswerSheetRichTextNode($node) {
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
        return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    if ($node->nodeType === XML_ELEMENT_NODE) {
        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template'], true)) {
            return '';
        }

        $safeTags = [
            'p', 'div', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'sup', 'sub', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'table', 'caption',
            'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a'
        ];

        $innerHtml = '';
        foreach ($node->childNodes as $child) {
            $innerHtml .= sanitizeAnswerSheetRichTextNode($child);
        }

        if (!in_array($tag, $safeTags, true)) {
            return $innerHtml;
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $scheme = $href !== '' ? parse_url($href, PHP_URL_SCHEME) : null;
            if ($href !== '' && ($scheme === null || in_array(strtolower($scheme), ['http', 'https', 'mailto'], true))) {
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '" rel="noopener noreferrer">' . $innerHtml . '</a>';
            }
            return $innerHtml;
        }

        if ($tag === 'br' || $tag === 'hr') {
            return '<' . $tag . '>';
        }

        return '<' . $tag . '>' . $innerHtml . '</' . $tag . '>';
    }

    $innerHtml = '';
    foreach ($node->childNodes as $child) {
        $innerHtml .= sanitizeAnswerSheetRichTextNode($child);
    }
    return $innerHtml;
}

function renderAnswerSheetRichText($value) {
    $decoded = (string)($value ?? '');
    for ($i = 0; $i < 5; $i++) {
        $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($next === $decoded) break;
        $decoded = $next;
    }

    if ($decoded === '') return '';

    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(strip_tags($decoded), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="answer-sheet-rich-text-root">' . $decoded . '</div></body></html>'
        );
        $xpath = new DOMXPath($document);
        $rootNodes = $xpath->query('//*[@id="answer-sheet-rich-text-root"]');
        if ($rootNodes && $rootNodes->length > 0) {
            $root = $rootNodes->item(0);
            $safeHtml = '';
            foreach ($root->childNodes as $child) {
                $safeHtml .= sanitizeAnswerSheetRichTextNode($child);
            }
            return $safeHtml;
        }
    } catch (Throwable $e) {
        // Fall through to escaped plain text if the stored markup cannot be parsed.
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
    }

    return nl2br(htmlspecialchars(strip_tags($decoded), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
}

// Identify student & user role
$is_admin = (isset($_SESSION['user_role']) && in_array(strtolower($_SESSION['user_role']), ['admin', 'superadmin', 'administrator'])) || isset($_SESSION['admin_id']) || (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'superadmin', 'administrator']));
$is_teacher_preview   = isset($_SESSION['teacher_id']) || (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'teacher');
$is_staff_preview     = $is_admin || $is_teacher_preview;

$session_student_id   = $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? '';
$session_student_name = $_SESSION['student_name'] ?? $_SESSION['user_name'] ?? ($is_admin ? 'Administrator' : 'Student');

// URL Parameters
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$url_submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : (isset($_GET['sub_id']) ? intval($_GET['sub_id']) : 0);
$url_student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';

// Resolve Submission if submission_id is provided directly in URL (e.g. from admin-result-page)
$submission = null;
if ($url_submission_id > 0 && $dbConn) {
    try {
        $subStmt = $dbConn->prepare("
            SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at,
                   s.status AS attempt_status,
                   CASE
                       WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                       ELSE ev.status
                   END AS status,
                   COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained,
                   ev.teacher_feedback, ev.evaluated_by, ev.evaluated_at
            FROM exam_submissions s
            LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
            WHERE s.id = :sub_id" . ($exam_id > 0 ? " AND s.exam_id = :eid" : "") . "
            LIMIT 1
        ");
        $params = [':sub_id' => $url_submission_id];
        if ($exam_id > 0) $params[':eid'] = $exam_id;
        $subStmt->execute($params);
        $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($submission) {
            $target_student_id = $submission['student_id'];
            if ($exam_id <= 0) $exam_id = intval($submission['exam_id']);
        }
    } catch (Exception $e) {
        error_log("Error loading submission by ID: " . $e->getMessage());
    }
}

// Target student selection
if (empty($target_student_id)) {
    $target_student_id = ($is_staff_preview && !empty($url_student_id)) ? $url_student_id : $session_student_id;
}

// Fetch Student Profile Information
$student_info = null;
if ($dbConn && !empty($target_student_id)) {
    try {
        $sStmt = $dbConn->prepare("
            SELECT * FROM students 
            WHERE student_id = :sid OR id = :uid OR email = :email 
            LIMIT 1
        ");
        $sStmt->execute([
            ':sid'   => strval($target_student_id),
            ':uid'   => is_numeric($target_student_id) ? intval($target_student_id) : 0,
            ':email' => strval($_SESSION['email'] ?? '')
        ]);
        $student_info = $sStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Student fetch error: " . $e->getMessage());
    }
}

$display_student_name = !empty($student_info['full_name']) ? $student_info['full_name'] : (!empty($submission['student_name']) ? $submission['student_name'] : $session_student_name);
$display_student_id   = !empty($student_info['student_id']) ? $student_info['student_id'] : (!empty($target_student_id) ? $target_student_id : 'N/A');
$display_student_batch = $student_info['batch'] ?? '';

// Fetch Exam Details
$exam = null;
if ($exam_id > 0 && $dbConn) {
    try {
        $eStmt = $dbConn->prepare("SELECT e.*, e.marks AS total_marks FROM exam_header e WHERE e.id = :id LIMIT 1");
        $eStmt->execute([':id' => $exam_id]);
        $exam = $eStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading exam details in student answer sheet: " . $e->getMessage());
    }
}

// Fetch Submission Record if not already fetched via submission_id
if (!$submission && $exam_id > 0 && !empty($target_student_id) && $dbConn) {
    try {
        $subStmt = $dbConn->prepare("
            SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at,
                   s.status AS attempt_status,
                   CASE
                       WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                       ELSE ev.status
                   END AS status,
                   COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained,
                   ev.teacher_feedback, ev.evaluated_by, ev.evaluated_at
            FROM exam_submissions s
            LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
            WHERE s.exam_id = :eid
              AND (s.student_id = :sid OR s.student_id = :alt_sid)
              AND s.status = 'Submitted'
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([
            ':eid'     => $exam_id,
            ':sid'     => strval($display_student_id),
            ':alt_sid' => strval($target_student_id)
        ]);
        $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading student submission: " . $e->getMessage());
    }
}

// Fetch Questions and Student Answers
$questions_data = [];
$total_exam_marks = $exam ? floatval($exam['total_marks'] ?? 0) : 0;
$total_obtained_marks = $submission ? floatval($submission['total_marks_obtained'] ?? 0) : 0;
$submission_status = $submission ? ($submission['status'] ?? 'Submitted') : 'Not Submitted';
$is_graded = strcasecmp($submission_status, 'Graded') === 0;

if ($exam_id > 0 && $dbConn) {
    try {
        // Fetch Questions
        $qStmt = $dbConn->prepare("
            SELECT id, question_number, question_type, question_text, marks, image_url 
            FROM questions 
            WHERE exam_id = :eid 
            ORDER BY question_number ASC, id ASC
        ");
        $qStmt->execute([':eid' => $exam_id]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Student Answers if submission exists
        $answers_by_qid = [];
        if ($submission) {
            $ansStmt = $dbConn->prepare("SELECT * FROM student_answers WHERE submission_id = :sub_id");
            $ansStmt->execute([':sub_id' => $submission['id']]);
            $answers_raw = $ansStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($answers_raw as $a) {
                $answers_by_qid[intval($a['question_id'])] = $a;
            }
        }

        // Attach MCQ options & student response
        foreach ($questions as &$q) {
            $qid = intval($q['id']);
            $q['student_answer'] = $answers_by_qid[$qid] ?? null;

            if (strtoupper($q['question_type']) === 'MCQ') {
                $optStmt = $dbConn->prepare("
                    SELECT id, option_text, option_order, is_correct 
                    FROM question_options 
                    WHERE question_id = :qid 
                    ORDER BY option_order ASC, id ASC
                ");
                $optStmt->execute([':qid' => $qid]);
                $q['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $q['options'] = [];
            }
        }
        unset($q);

        $questions_data = $questions;
    } catch (Exception $e) {
        error_log("Error fetching questions & answers for student: " . $e->getMessage());
    }
}

// Calculate percentage
$percentage = ($total_exam_marks > 0) ? round(($total_obtained_marks / $total_exam_marks) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= $exam ? htmlspecialchars($exam['exam_title']) . " - Answer Sheet & Marks" : "Student Answer Sheet" ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #eef2ff;
            --success: #10b981;
            --slate-800: #1e293b;
            --slate-600: #475569;
            --slate-100: #f1f5f9;
        }
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        .header-bar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .paper-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .score-banner {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.15);
            margin-bottom: 25px;
        }
        .score-circle {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            border: 3px solid rgba(255, 255, 255, 0.4);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            backdrop-filter: blur(4px);
        }
        .question-sheet-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 22px;
            margin-bottom: 20px;
            position: relative;
        }
        .question-sheet-card.is-mcq {
            border-left: 5px solid #3b82f6;
        }
        .question-sheet-card.is-theory {
            border-left: 5px solid #8b5cf6;
        }
        .option-item {
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            transition: all 0.15s ease;
        }
        .option-item.user-correct {
            border-color: #10b981;
            background-color: #ecfdf5;
            font-weight: 600;
        }
        .option-item.user-wrong {
            border-color: #ef4444;
            background-color: #fef2f2;
        }
        .option-item.is-answer-key {
            border-color: #10b981;
            background-color: #f0fdf4;
        }
        .student-written-box {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 16px;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            color: #0f172a;
        }
        .student-written-box p:first-child { margin-top: 0; }
        .student-written-box p:last-child { margin-bottom: 0; }
        .student-written-box table { width: 100%; border-collapse: collapse; margin: 0.5rem 0; }
        .student-written-box th, .student-written-box td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .student-written-box ul, .student-written-box ol { padding-left: 1.5rem; }
        .teacher-remark-callout {
            background-color: #fefce8;
            border-left: 4px solid #eab308;
            border-radius: 0 8px 8px 0;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 0.88rem;
            color: #713f12;
        }
        .badge-awarded {
            font-size: 0.9rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 30px;
        }

        /* Print Specific Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .score-banner {
                background: #f1f5f9 !important;
                color: #000000 !important;
                border: 1px solid #ccc !important;
                box-shadow: none !important;
            }
            .score-circle {
                border-color: #000 !important;
                color: #000 !important;
            }
            .paper-card, .question-sheet-card {
                border: 1px solid #ccc !important;
                box-shadow: none !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Top Action Navigation Bar -->
    <header class="header-bar py-3 no-print">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <a href="<?php echo $is_admin ? 'admin-result-page.php' : 'student-result-page.php'; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to All Results
                </a>
                <span class="fw-bold text-slate-800 d-none d-sm-inline">
                    <i class="fa-solid fa-graduation-cap text-primary me-2"></i>Official Student Answer Sheet
                </span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-print me-1"></i> Print / Save PDF
                </button>
            </div>
        </div>
    </header>

    <div class="container py-4">

        <?php if (!$exam): ?>
            <!-- Error State: Exam Not Found -->
            <div class="paper-card p-5 text-center">
                <i class="fa-solid fa-triangle-exclamation text-danger fa-3x mb-3"></i>
                <h3>Examination Not Found</h3>
                <p class="text-muted">The requested examination could not be loaded.</p>
                <a href="<?php echo $is_admin ? 'admin-quesTable-page.php' : 'student-examTable-page.php'; ?>" class="btn btn-primary mt-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> Return to Exams
                </a>
            </div>

        <?php elseif (!$submission): ?>
            <!-- Error State: No Submission Found -->
            <div class="paper-card p-5 text-center">
                <i class="fa-regular fa-clipboard text-warning fa-3x mb-3"></i>
                <h3>No Submission Recorded</h3>
                <p class="text-muted">
                    No submitted answer sheet was found for student 
                    <strong><?= htmlspecialchars($display_student_name) ?></strong> (<?= htmlspecialchars($display_student_id) ?>) 
                    for <strong><?= htmlspecialchars($exam['exam_title']) ?></strong>.
                </p>
                <div class="mt-3">
                    <?php if (strcasecmp($exam['status'] ?? '', 'Running') === 0): ?>
                        <a href="give-exam-page.php?exam_id=<?= $exam_id ?>" class="btn btn-success me-2">
                            <i class="fa-solid fa-pen-to-square me-1"></i> Enter Exam Hall Now
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo $is_admin ? 'admin-result-page.php' : 'admin-quesTable-page.php'; ?>" class="btn btn-outline-secondary">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Results
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- Printable Institution Header (Shows in Print & On-Screen) -->
            <div class="paper-card p-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <span class="badge bg-indigo-50 text-indigo-700 border px-2.5 py-1 mb-2" style="background:blue">
                            Batch: <?= htmlspecialchars($exam['batch'] ?? 'N/A') ?>
                        </span>
                        <h3 class="fw-bold mb-1 text-slate-800"><?= htmlspecialchars($exam['exam_title']) ?></h3>
                        <div class="text-muted small">
                            Subject: <strong><?= htmlspecialchars($exam['subject']) ?></strong> &bull;
                            Instructor: <strong><?= htmlspecialchars($exam['teacher_name'] ?? 'Instructor') ?></strong> &bull;
                            Exam Type: <strong><?= htmlspecialchars($exam['exam_type'] ?? 'Hybrid') ?></strong>
                        </div>
                    </div>

                    <div class="border-start ps-md-4 py-1">
                        <div class="small text-muted">Student Name:</div>
                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($display_student_name) ?></div>
                        <div class="small text-muted">Student ID: <strong><?= htmlspecialchars($display_student_id) ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Scorecard Hero Banner -->
            <div class="score-banner">
                <div class="row align-items-center gy-4">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($is_graded): ?>
                                <span class="badge bg-success px-3 py-1.5 rounded-pill shadow-xs">
                                    <i class="fa-solid fa-circle-check me-1"></i> Final Marks Graded & Verified
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark px-3 py-1.5 rounded-pill shadow-xs">
                                    <i class="fa-solid fa-hourglass-half me-1"></i> Paper Submitted - Evaluation in Progress
                                </span>
                            <?php endif; ?>

                            <span class="badge bg-light text-dark px-2.5 py-1 rounded-pill opacity-75">
                                Submitted <?= htmlspecialchars(formatAnswerSheetUtc($submission['submitted_at'], 'M d, Y - h:i A')) ?>
                            </span>
                        </div>

                        <h2 class="fw-bold mb-2">Examination Result</h2>
                        <p class="mb-0 opacity-90" style="max-width: 600px; font-size: 0.95rem;">
                            <?php if ($is_graded): ?>
                                Your examination has been officially evaluated and scored by your instructor. Review each question below for marks, options key, and feedback.
                            <?php else: ?>
                                Your answers have been successfully submitted to the server. Objective MCQs may reflect automated scoring; final marks will finalize once subjective questions are evaluated.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="col-md-4 d-flex justify-content-md-end justify-content-center">
                        <div class="score-circle">
                            <span class="fs-6 opacity-75">SCORE</span>
                            <div class="fs-3 fw-bold lh-1 my-1">
                                <?= $total_obtained_marks ?>
                            </div>
                            <span class="small opacity-75">out of <?= $total_exam_marks ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Examiner Feedback Card (If provided by Teacher) -->
            <?php if (!empty($submission['teacher_feedback'])): ?>
                <div class="paper-card p-4 border-primary" style="background-color: #f0f4ff; border-left: 5px solid #4f46e5;">
                    <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-bold">
                        <i class="fa-solid fa-comment-dots"></i> Examiner's Overall Assessment:
                    </div>
                    <div class="text-dark" style="font-size: 0.95rem; line-height: 1.6;">
                        <?= nl2br(htmlspecialchars($submission['teacher_feedback'])) ?>
                    </div>
                    <?php if (!empty($submission['evaluated_at'])): ?>
                        <div class="text-muted small mt-2">
                            <i class="fa-solid fa-check-double me-1"></i> Evaluated on <?= htmlspecialchars(formatAnswerSheetUtc($submission['evaluated_at'], 'F j, Y - g:i A')) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Question by Question Breakdown -->
            <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                <h5 class="fw-bold mb-0 text-slate-800">
                    <i class="fa-solid fa-list-ol text-primary me-2"></i>Question-by-Question Evaluation
                </h5>
                <span class="text-muted small">Total Questions: <?= count($questions_data) ?></span>
            </div>

            <?php if (empty($questions_data)): ?>
                <div class="alert alert-info">No questions recorded for this examination.</div>
            <?php else: ?>
                <?php foreach ($questions_data as $qIndex => $q): 
                    $qid = intval($q['id']);
                    $qtype = strtoupper($q['question_type']);
                    $qmax = floatval($q['marks'] ?? 0);
                    $studentAns = $q['student_answer'] ?? null;
                    $awardedMark = $studentAns ? floatval($studentAns['marks_obtained']) : 0.00;
                    $teacherRemarks = $studentAns ? trim($studentAns['teacher_remarks'] ?? '') : '';

                    // Badge color based on marks awarded
                    $badgeClass = 'bg-secondary';
                    if ($is_graded || $qtype === 'MCQ') {
                        if ($awardedMark >= $qmax && $qmax > 0) {
                            $badgeClass = 'bg-success';
                        } elseif ($awardedMark > 0) {
                            $badgeClass = 'bg-warning text-dark';
                        } else {
                            $badgeClass = 'bg-danger';
                        }
                    }
                ?>
                    <div class="question-sheet-card <?= $qtype === 'MCQ' ? 'is-mcq' : 'is-theory' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $qtype === 'MCQ' ? 'bg-primary' : 'bg-purple text-white' ?>" style="<?= $qtype !== 'MCQ' ? 'background-color: #8b5cf6;' : '' ?>">
                                    Q<?= $q['question_number'] ?> &bull; <?= $qtype ?>
                                </span>
                                <span class="text-muted small">Max Marks: <?= $qmax ?></span>
                            </div>

                            <!-- Score Badge for this question -->
                            <div>
                                <span class="badge badge-awarded <?= $badgeClass ?>">
                                    <?= $awardedMark ?> / <?= $qmax ?> Marks
                                </span>
                            </div>
                        </div>

                        <!-- Question Statement -->
                        <div class="fs-6 fw-semibold text-slate-800 mt-2 mb-3">
                            <?= renderAnswerSheetRichText($q['question_text'] ?? '') ?>
                        </div>

                        <!-- Optional Question Diagram / Image -->
                        <?php if (!empty($q['image_url'])): ?>
                            <div class="mb-3">
                                <img src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question Diagram" class="img-fluid rounded border" style="max-height: 250px;">
                            </div>
                        <?php endif; ?>

                        <!-- MCQ Evaluation Display -->
                        <?php if ($qtype === 'MCQ'): 
                            $selectedOptId = $studentAns ? intval($studentAns['selected_option_id'] ?? 0) : 0;
                        ?>
                            <div class="mt-3">
                                <div class="row g-2">
                                    <?php foreach ($q['options'] as $opt): 
                                        $optId = intval($opt['id']);
                                        $isCorrect = intval($opt['is_correct']) === 1;
                                        $isSelectedByStudent = ($optId === $selectedOptId);

                                        $optCardClass = 'option-item';
                                        if ($isSelectedByStudent && $isCorrect) {
                                            $optCardClass .= ' user-correct';
                                        } elseif ($isSelectedByStudent && !$isCorrect) {
                                            $optCardClass .= ' user-wrong';
                                        } elseif ($isCorrect) {
                                            $optCardClass .= ' is-answer-key';
                                        }
                                    ?>
                                        <div class="col-md-6">
                                            <div class="<?= $optCardClass ?>">
                                                <div style="width: 20px;">
                                                    <?php if ($isSelectedByStudent && $isCorrect): ?>
                                                        <i class="fa-solid fa-circle-check text-success"></i>
                                                    <?php elseif ($isSelectedByStudent && !$isCorrect): ?>
                                                        <i class="fa-solid fa-circle-xmark text-danger"></i>
                                                    <?php elseif ($isCorrect): ?>
                                                        <i class="fa-solid fa-check text-success"></i>
                                                    <?php else: ?>
                                                        <i class="fa-regular fa-circle text-muted"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-grow-1" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($opt['option_text']) ?>
                                                </div>
                                                <?php if ($isSelectedByStudent): ?>
                                                    <span class="badge <?= $isCorrect ? 'bg-success' : 'bg-danger' ?>" style="font-size: 0.68rem;">
                                                        <?= $isCorrect ? 'Your Correct Pick' : 'Your Pick' ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($isCorrect && !$isSelectedByStudent): ?>
                                                    <span class="badge bg-success" style="font-size: 0.68rem;">Correct Key</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($selectedOptId <= 0): ?>
                                    <div class="alert alert-light border small text-muted mt-2 py-1 px-3">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i> You did not submit an option for this question.
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php else: 
                            // Theory Question
                            $writtenAns = $studentAns ? trim($studentAns['answer_text'] ?? '') : '';
                        ?>
                            <div class="mt-3">
                                <div class="small fw-bold text-muted mb-1">
                                    <i class="fa-solid fa-align-left me-1"></i>Your Submitted Response:
                                </div>
                                <div class="student-written-box">
                                    <?= !empty($writtenAns) ? renderAnswerSheetRichText($writtenAns) : '<em class="text-muted">(No response written for this question)</em>' ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Teacher's Inline Question Remark -->
                        <?php if (!empty($teacherRemarks)): ?>
                            <div class="teacher-remark-callout">
                                <div class="fw-bold mb-0.5">
                                    <i class="fa-solid fa-pen-nib me-1"></i>Examiner Feedback on Q<?= $q['question_number'] ?>:
                                </div>
                                <div><?= htmlspecialchars($teacherRemarks) ?></div>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Printable Signature Section (Appears at bottom for official certification) -->
            <div class="paper-card p-4 mt-5 text-muted small">
                <div class="row text-center pt-4">
                    <div class="col-4">
                        <div class="border-top pt-2">Student Signature</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top pt-2">Course Instructor</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top pt-2">Exam Controller / Date</div>
                    </div>
                </div>
            </div>

            <!-- Back to Results Action -->
            <div class="text-center mt-4 mb-5 no-print">
                <a href="<?php echo $is_admin ? 'admin-result-page.php' : 'student-result-page.php'; ?>" class="btn btn-primary px-4 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> Return to <?php echo $is_admin ? 'Examination Results' : 'Exam Dashboard'; ?>
                </a>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
