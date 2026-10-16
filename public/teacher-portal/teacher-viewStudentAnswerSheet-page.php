<?php
// ============================================================================
// Teacher View Student Answer Sheet & Audit Page
// Role: Teacher / Examiner / Administrator
// Displays an individual student's submitted examination paper with full
// question-by-question responses, correct answer keys, student options,
// written theory answers, handwritten image attachments, marks awarded,
// and examiner remarks with rapid student switching and evaluation shortcuts.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// RBAC: Session gatekeeper for Teacher role (or Administrator)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// Require system config
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/src/config/config.php')) {
    require_once __DIR__ . '/src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Resolve database connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

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
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">' . $innerHtml . '</a>';
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
        // Fallback
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
    }

    return nl2br(htmlspecialchars(strip_tags($decoded), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
}

// Resolve teacher identity
$current_teacher_id   = $_SESSION['teacher_id'] ?? '';
$current_teacher_name = $_SESSION['teacher_name'] ?? '';
$session_uid          = $_SESSION['user_id'] ?? 0;
$session_email        = $_SESSION['email'] ?? '';
$is_admin             = isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin';

if ($dbConn && (empty($current_teacher_id) || is_numeric($current_teacher_id) || empty($current_teacher_name))) {
    try {
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
    } catch (Exception $e) {
        error_log("Teacher fetch error in view student answer sheet: " . $e->getMessage());
    }
}

$teacher_display_name = !empty($current_teacher_name) ? $current_teacher_name : 'Instructor';

// URL Parameters
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$url_student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';
$url_sub_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : (isset($_GET['sub_id']) ? intval($_GET['sub_id']) : 0);

// Fetch Exam Details
$exam = null;
if ($exam_id > 0 && $dbConn) {
    try {
        // Teachers see exams they own, or admins can see all exams
        if ($is_admin) {
            $eStmt = $dbConn->prepare("SELECT e.*, e.marks AS total_marks FROM exam_header e WHERE e.id = :id LIMIT 1");
            $eStmt->execute([':id' => $exam_id]);
        } else {
            $eStmt = $dbConn->prepare("
                SELECT e.*, e.marks AS total_marks 
                FROM exam_header e 
                WHERE e.id = :id AND (e.teacher_id = :tid OR e.teacher_id = :uid) 
                LIMIT 1
            ");
            $eStmt->execute([
                ':id'  => $exam_id,
                ':tid' => strval($current_teacher_id),
                ':uid' => strval($session_uid)
            ]);
        }
        $exam = $eStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading exam details: " . $e->getMessage());
    }
}

// Fetch All Submissions for this Exam (for teacher navigation & dropdown selector)
$all_submissions = [];
if ($exam_id > 0 && $dbConn) {
    try {
        $subListStmt = $dbConn->prepare("
            SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at,
                   s.status AS attempt_status,
                   CASE
                       WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                       ELSE ev.status
                   END AS eval_status,
                   COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained,
                   ev.teacher_feedback, ev.evaluated_by, ev.evaluated_at,
                   COALESCE(NULLIF(s.student_name, ''), CONCAT('Student #', s.student_id)) AS resolved_student_name
            FROM exam_submissions s
            LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
            WHERE s.exam_id = :eid
              AND (s.status = 'Submitted' OR s.submitted_at IS NOT NULL)
            ORDER BY COALESCE(s.submitted_at, s.updated_at) ASC, s.id ASC
        ");
        $subListStmt->execute([':eid' => $exam_id]);
        $all_submissions = $subListStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading submission roster: " . $e->getMessage());
    }
}

// Resolve Target Submission and Student
$selected_submission = null;
$selected_sub_index = -1;

if ($url_sub_id > 0) {
    foreach ($all_submissions as $idx => $sRow) {
        if (intval($sRow['id']) === $url_sub_id) {
            $selected_submission = $sRow;
            $selected_sub_index = $idx;
            break;
        }
    }
} elseif (!empty($url_student_id)) {
    foreach ($all_submissions as $idx => $sRow) {
        if (strval($sRow['student_id']) === strval($url_student_id)) {
            $selected_submission = $sRow;
            $selected_sub_index = $idx;
            break;
        }
    }
}

// Default to first submission if not specified but list is non-empty
if (!$selected_submission && !empty($all_submissions)) {
    $selected_submission = $all_submissions[0];
    $selected_sub_index = 0;
}

$prev_sub = ($selected_sub_index > 0) ? $all_submissions[$selected_sub_index - 1] : null;
$next_sub = ($selected_sub_index >= 0 && $selected_sub_index < count($all_submissions) - 1) ? $all_submissions[$selected_sub_index + 1] : null;

$submission = $selected_submission;
$target_student_id = $submission['student_id'] ?? $url_student_id;

// Fetch Student Profile Information from students table
$student_info = null;
if ($dbConn && !empty($target_student_id)) {
    try {
        $sStmt = $dbConn->prepare("
            SELECT id, student_id, full_name, email, program, batch, contact_number, profile_image 
            FROM students 
            WHERE student_id = :sid OR id = :uid 
            LIMIT 1
        ");
        $sStmt->execute([
            ':sid' => strval($target_student_id),
            ':uid' => is_numeric($target_student_id) ? intval($target_student_id) : 0
        ]);
        $student_info = $sStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Student fetch error: " . $e->getMessage());
    }
}

$display_student_name = $student_info['full_name'] ?? $submission['resolved_student_name'] ?? $submission['student_name'] ?? 'Student ' . $target_student_id;
$display_student_id   = $student_info['student_id'] ?? $target_student_id;
$display_student_batch = $student_info['batch'] ?? $exam['batch'] ?? 'N/A';
$display_student_prog  = $student_info['program'] ?? '';
$display_student_email = $student_info['email'] ?? '';

// Fetch Questions and Student Answers
$questions_data = [];
$total_exam_marks = $exam ? floatval($exam['total_marks'] ?? 0) : 0;
$total_obtained_marks = $submission ? floatval($submission['total_marks_obtained'] ?? 0) : 0;
$eval_status = $submission ? ($submission['eval_status'] ?? 'Submitted') : 'Not Submitted';
$is_graded = strcasecmp($eval_status, 'Graded') === 0;

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
        error_log("Error fetching questions & answers for teacher view: " . $e->getMessage());
    }
}

// Calculate percentage & metrics
$percentage = ($total_exam_marks > 0) ? round(($total_obtained_marks / $total_exam_marks) * 100, 1) : 0;
$total_submissions_count = count($all_submissions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= $exam ? htmlspecialchars($exam['exam_title']) . " - Student Answer Sheet (Teacher Audit View)" : "Teacher View - Student Answer Sheet" ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-light: #eef2ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
        }
        body {
            background-color: var(--slate-50);
            color: var(--slate-800);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            min-height: 100vh;
        }

        /* Sticky Teacher Navigation Bar */
        .teacher-nav-bar {
            background: #ffffff;
            border-bottom: 1px solid var(--slate-200);
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        /* Teacher Submissions Switcher Sub-header */
        .roster-toolbar {
            background: #f8fafc;
            border-bottom: 1px solid var(--slate-200);
            padding: 10px 0;
        }

        .paper-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--slate-200);
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            margin-bottom: 24px;
            overflow: hidden;
        }

        /* Examiner Hero Banner */
        .score-banner {
            background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #4f46e5 100%);
            color: #ffffff;
            border-radius: 14px;
            padding: 26px 30px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.15);
            margin-bottom: 25px;
            position: relative;
        }

        .score-circle {
            width: 116px;
            height: 116px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            border: 3px solid rgba(255, 255, 255, 0.35);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            backdrop-filter: blur(4px);
        }

        /* Question Cards */
        .question-sheet-card {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: 10px;
            padding: 22px;
            margin-bottom: 20px;
            position: relative;
            transition: border-color 0.2s ease;
        }
        .question-sheet-card.is-mcq {
            border-left: 5px solid #3b82f6;
        }
        .question-sheet-card.is-theory {
            border-left: 5px solid #8b5cf6;
        }

        /* MCQ Options Item */
        .option-item {
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid var(--slate-200);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            transition: all 0.15s ease;
        }
        .option-item.student-correct {
            border-color: #10b981;
            background-color: #ecfdf5;
            font-weight: 600;
        }
        .option-item.student-wrong {
            border-color: #ef4444;
            background-color: #fef2f2;
        }
        .option-item.is-answer-key {
            border-color: #10b981;
            background-color: #f0fdf4;
        }

        /* Theory Answer Display */
        .student-written-box {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 16px;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #0f172a;
        }
        .student-written-box p:first-child { margin-top: 0; }
        .student-written-box p:last-child { margin-bottom: 0; }
        .student-written-box table { width: 100%; border-collapse: collapse; margin: 0.5rem 0; }
        .student-written-box th, .student-written-box td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .student-written-box ul, .student-written-box ol { padding-left: 1.5rem; }

        /* Handwritten Image Attachment Gallery */
        .attachment-thumbnail {
            width: 140px;
            height: 140px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .attachment-thumbnail:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: var(--primary);
        }

        /* Teacher Feedback & Remarks Callouts */
        .teacher-remark-callout {
            background-color: #fefce8;
            border-left: 4px solid #eab308;
            border-radius: 0 8px 8px 0;
            padding: 12px 16px;
            margin-top: 14px;
            font-size: 0.9rem;
            color: #713f12;
        }
        .overall-feedback-card {
            background-color: #f0f4ff;
            border: 1px solid #c7d2fe;
            border-left: 5px solid #4f46e5;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .badge-awarded {
            font-size: 0.88rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 30px;
        }

        .audit-tag {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 4px;
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
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
            }
            .score-circle {
                border-color: #475569 !important;
                color: #000000 !important;
            }
            .paper-card, .question-sheet-card {
                border: 1px solid #ccc !important;
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            .attachment-thumbnail {
                max-width: 250px;
                max-height: 250px;
                height: auto;
            }
        }
    </style>
</head>
<body>

    <!-- Top Teacher Navigation Bar -->
    <header class="teacher-nav-bar py-2.5 no-print">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?><?= $submission ? '&submission_id=' . $submission['id'] : '' ?>" 
                   class="btn btn-outline-secondary btn-sm" title="Return to Evaluation & Grading Workbench">
                    <i class="fa-solid fa-arrow-left me-1"></i> Evaluation Workbench
                </a>
                <span class="border-start ps-3 fw-bold text-slate-800 d-none d-md-inline">
                    <i class="fa-solid fa-chalkboard-user text-primary me-1.5"></i>Student Answer Sheet Review
                </span>
                <span class="badge bg-indigo-100 text-primary audit-tag d-none d-lg-inline">Teacher Audit View</span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if ($exam_id > 0): ?>
                    <a href="teacher-viewQues-page.php?exam_id=<?= $exam_id ?><?= $submission ? '&submission_id=' . $submission['id'] : '' ?>" 
                       class="btn btn-outline-secondary btn-sm" target="_blank" title="Inspect Master Question Paper">
                        <i class="fa-solid fa-file-circle-question me-1"></i> Question Paper
                    </a>
                <?php endif; ?>
                <?php if ($submission): ?>
                    <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>" 
                       class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Grade / Edit Evaluation
                    </a>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-outline-dark btn-sm">
                    <i class="fa-solid fa-print me-1"></i> Print / Save PDF
                </button>
            </div>
        </div>
    </header>

    <!-- Student Roster Quick Switcher Bar (For teacher convenience) -->
    <?php if ($exam && !empty($all_submissions)): ?>
        <section class="roster-toolbar no-print">
            <div class="container d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 520px;">
                    <span class="small fw-semibold text-muted text-nowrap">
                        <i class="fa-solid fa-users me-1"></i> Student:
                    </span>
                    <select class="form-select form-select-sm" onchange="if(this.value) window.location.href=this.value;">
                        <?php foreach ($all_submissions as $i => $s): 
                            $isCurrent = ($submission && intval($s['id']) === intval($submission['id']));
                            $statusLabel = ($s['eval_status'] === 'Graded') ? ' [Graded: ' . floatval($s['total_marks_obtained']) . ' marks]' : ' [' . $s['eval_status'] . ']';
                        ?>
                            <option value="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $s['id'] ?>" <?= $isCurrent ? 'selected' : '' ?>>
                                <?= ($i + 1) ?>. <?= htmlspecialchars($s['resolved_student_name']) ?> (ID: <?= htmlspecialchars($s['student_id']) ?>)<?= $statusLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small d-none d-sm-inline">
                        Submission <strong><?= ($selected_sub_index + 1) ?></strong> of <strong><?= $total_submissions_count ?></strong>
                    </span>

                    <?php if ($prev_sub): ?>
                        <a href="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $prev_sub['id'] ?>" 
                           class="btn btn-outline-secondary btn-sm" title="Previous Student: <?= htmlspecialchars($prev_sub['resolved_student_name']) ?>">
                            <i class="fa-solid fa-chevron-left me-1"></i> Prev
                        </a>
                    <?php else: ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled><i class="fa-solid fa-chevron-left me-1"></i> Prev</button>
                    <?php endif; ?>

                    <?php if ($next_sub): ?>
                        <a href="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $next_sub['id'] ?>" 
                           class="btn btn-outline-secondary btn-sm" title="Next Student: <?= htmlspecialchars($next_sub['resolved_student_name']) ?>">
                            Next <i class="fa-solid fa-chevron-right ms-1"></i>
                        </a>
                    <?php else: ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled>Next <i class="fa-solid fa-chevron-right ms-1"></i></button>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <main class="container py-4">

        <?php if (!$exam): ?>
            <!-- Error State: Exam Not Found -->
            <div class="paper-card p-5 text-center">
                <i class="fa-solid fa-triangle-exclamation text-danger fa-3x mb-3"></i>
                <h3 class="fw-bold">Examination Record Not Found</h3>
                <p class="text-muted" style="max-width: 500px; margin: 0 auto;">
                    The requested examination could not be loaded or you do not have permission to inspect this exam.
                </p>
                <div class="mt-4">
                    <a href="teacher-answerSheet-page.php" class="btn btn-primary">
                        <i class="fa-solid fa-arrow-left me-1"></i> Return to Teacher Portal
                    </a>
                </div>
            </div>

        <?php elseif (!$submission): ?>
            <!-- Error State: No Submission Found -->
            <div class="paper-card p-5 text-center">
                <i class="fa-regular fa-clipboard text-warning fa-3x mb-3"></i>
                <h3 class="fw-bold">No Student Submissions Available</h3>
                <p class="text-muted" style="max-width: 550px; margin: 0 auto;">
                    No completed submissions were found for <strong><?= htmlspecialchars($exam['exam_title']) ?></strong>.
                    Students will appear here once they finalize and submit their answer sheets.
                </p>
                <div class="mt-4">
                    <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>" class="btn btn-outline-secondary me-2">
                        <i class="fa-solid fa-table-list me-1"></i> Evaluation Workbench
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- Printable Institution Header (Shows in Print & On-Screen) -->
            <div class="paper-card p-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary text-white px-2.5 py-1">
                                Batch: <?= htmlspecialchars($exam['batch'] ?? 'N/A') ?>
                            </span>
                            <?php if (!empty($exam['course_code'])): ?>
                                <span class="badge bg-secondary px-2.5 py-1">
                                    <?= htmlspecialchars($exam['course_code']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge bg-light text-dark border px-2.5 py-1">
                                <?= htmlspecialchars($exam['exam_type'] ?? 'Standard') ?> Format
                            </span>
                        </div>
                        <h3 class="fw-bold mb-1 text-slate-800"><?= htmlspecialchars($exam['exam_title']) ?></h3>
                        <div class="text-muted small">
                            Subject: <strong><?= htmlspecialchars($exam['subject']) ?></strong> &bull;
                            Instructor: <strong><?= htmlspecialchars($exam['teacher_name'] ?? $teacher_display_name) ?></strong> &bull;
                            Total Exam Marks: <strong><?= $total_exam_marks ?></strong> &bull;
                            Duration: <strong><?= intval($exam['duration'] ?? 0) ?> mins</strong>
                        </div>
                    </div>

                    <div class="border-start ps-md-4 py-1">
                        <div class="small text-muted mb-0.5">Student Particulars:</div>
                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($display_student_name) ?></div>
                        <div class="small text-muted">
                            Student ID: <strong><?= htmlspecialchars($display_student_id) ?></strong>
                            <?php if (!empty($display_student_prog)): ?>
                                &bull; <?= htmlspecialchars($display_student_prog) ?>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            Submitted: <strong><?= htmlspecialchars(formatAnswerSheetUtc($submission['submitted_at'], 'M d, Y - h:i A')) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scorecard Hero Banner (Teacher Perspective) -->
            <div class="score-banner">
                <div class="row align-items-center gy-4">
                    <div class="col-md-8">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <?php if ($is_graded): ?>
                                <span class="badge bg-success px-3 py-1.5 rounded-pill shadow-xs">
                                    <i class="fa-solid fa-circle-check me-1"></i> Evaluated & Marks Finalized
                                </span>
                            <?php elseif ($eval_status === 'Under Review'): ?>
                                <span class="badge bg-info text-dark px-3 py-1.5 rounded-pill shadow-xs">
                                    <i class="fa-solid fa-pen-nib me-1"></i> Evaluation Under Review
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark px-3 py-1.5 rounded-pill shadow-xs">
                                    <i class="fa-solid fa-hourglass-half me-1"></i> Awaiting Examiner Evaluation
                                </span>
                            <?php endif; ?>

                            <span class="badge bg-light text-dark px-2.5 py-1 rounded-pill opacity-90">
                                <i class="fa-regular fa-clock me-1"></i> Submitted <?= htmlspecialchars(formatAnswerSheetUtc($submission['submitted_at'], 'M d, Y - h:i A')) ?>
                            </span>
                        </div>

                        <h2 class="fw-bold mb-2">Student Evaluation & Performance</h2>
                        <p class="mb-3 opacity-90" style="max-width: 620px; font-size: 0.95rem;">
                            Reviewing submitted script for <strong><?= htmlspecialchars($display_student_name) ?></strong>.
                            Automated scoring keys for objective MCQs and subjective responses evaluated by the course instructor are summarized below.
                        </p>

                        <div class="d-flex flex-wrap align-items-center gap-3 no-print">
                            <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>" class="btn btn-sm btn-light text-primary fw-semibold shadow-xs">
                                <i class="fa-solid fa-sliders me-1"></i> Modify Marks / Evaluation
                            </a>
                            <?php if (!empty($submission['evaluated_by'])): ?>
                                <span class="small opacity-75">
                                    <i class="fa-solid fa-user-check me-1"></i> Evaluated by: <?= htmlspecialchars($submission['evaluated_by']) ?>
                                    <?php if (!empty($submission['evaluated_at'])): ?>
                                        (<?= htmlspecialchars(formatAnswerSheetUtc($submission['evaluated_at'], 'M d, Y')) ?>)
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-4 d-flex justify-content-md-end justify-content-center">
                        <div class="score-circle">
                            <span class="fs-6 opacity-75">TOTAL</span>
                            <div class="fs-3 fw-bold lh-1 my-1">
                                <?= $total_obtained_marks ?>
                            </div>
                            <span class="small opacity-75">out of <?= $total_exam_marks ?></span>
                            <div class="mt-1 badge bg-white text-dark py-0.5 px-2 font-monospace" style="font-size: 0.72rem;">
                                <?= $percentage ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Examiner Feedback Card -->
            <?php if (!empty($submission['teacher_feedback'])): ?>
                <div class="overall-feedback-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="d-flex align-items-center gap-2 text-primary fw-bold fs-6">
                            <i class="fa-solid fa-comment-dots"></i> Examiner's Overall Assessment & Feedback:
                        </div>
                        <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>" class="btn btn-outline-primary btn-sm py-0.5 px-2 no-print" style="font-size: 0.78rem;">
                            <i class="fa-solid fa-pencil me-1"></i> Edit Feedback
                        </a>
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
            <?php else: ?>
                <div class="paper-card p-3 mb-4 bg-light border text-muted small d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa-solid fa-circle-info text-primary me-1.5"></i>
                        No overall examiner remarks written yet for this submission.
                    </div>
                    <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>" class="btn btn-outline-primary btn-sm py-0.5 px-2 no-print" style="font-size: 0.78rem;">
                        <i class="fa-solid fa-plus me-1"></i> Add Feedback
                    </a>
                </div>
            <?php endif; ?>

            <!-- Question by Question Breakdown Header -->
            <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                <h5 class="fw-bold mb-0 text-slate-800">
                    <i class="fa-solid fa-list-check text-primary me-2"></i>Question-by-Question Script & Marking
                </h5>
                <span class="text-muted small">Total Questions: <strong><?= count($questions_data) ?></strong></span>
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

                    // Process handwritten images if uploaded
                    $handwrittenImages = [];
                    if (!empty($studentAns['answer_image'])) {
                        $rawImgData = $studentAns['answer_image'];
                        $jsonDecoded = json_decode($rawImgData, true);
                        if (is_array($jsonDecoded)) {
                            $handwrittenImages = $jsonDecoded;
                        } else {
                            $handwrittenImages = array_filter(array_map('trim', explode(',', $rawImgData)));
                        }
                    }
                ?>
                    <div class="question-sheet-card <?= $qtype === 'MCQ' ? 'is-mcq' : 'is-theory' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $qtype === 'MCQ' ? 'bg-primary' : 'bg-purple text-white' ?>" style="<?= $qtype !== 'MCQ' ? 'background-color: #8b5cf6;' : '' ?>">
                                    Q<?= $q['question_number'] ?> &bull; <?= $qtype ?>
                                </span>
                                <span class="text-muted small">Max Marks: <strong><?= $qmax ?></strong></span>
                            </div>

                            <!-- Score Badge for this question -->
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-awarded <?= $badgeClass ?>">
                                    Awarded: <?= $awardedMark ?> / <?= $qmax ?> Marks
                                </span>
                                <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>#question-<?= $qid ?>" 
                                   class="btn btn-outline-secondary btn-sm py-0 px-1.5 no-print" title="Edit this question mark in evaluation page">
                                    <i class="fa-solid fa-pen" style="font-size: 0.72rem;"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Question Statement -->
                        <div class="fs-6 fw-semibold text-slate-800 mt-2 mb-3">
                            <?= renderAnswerSheetRichText($q['question_text'] ?? '') ?>
                        </div>

                        <!-- Question Diagram / Image -->
                        <?php if (!empty($q['image_url'])): ?>
                            <div class="mb-3">
                                <span class="small text-muted d-block mb-1">
                                    <i class="fa-regular fa-image me-1"></i> Question Diagram / Attachment:
                                </span>
                                <img src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question Diagram" 
                                     class="img-fluid rounded border attachment-thumbnail" 
                                     onclick="openImagePreview('<?= htmlspecialchars($q['image_url'], ENT_QUOTES) ?>', 'Question <?= $q['question_number'] ?> Diagram')">
                            </div>
                        <?php endif; ?>

                        <!-- MCQ Evaluation Display for Teacher -->
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
                                            $optCardClass .= ' student-correct';
                                        } elseif ($isSelectedByStudent && !$isCorrect) {
                                            $optCardClass .= ' student-wrong';
                                        } elseif ($isCorrect) {
                                            $optCardClass .= ' is-answer-key';
                                        }
                                    ?>
                                        <div class="col-md-6">
                                            <div class="<?= $optCardClass ?>">
                                                <div style="width: 20px;">
                                                    <?php if ($isSelectedByStudent && $isCorrect): ?>
                                                        <i class="fa-solid fa-circle-check text-success" title="Student picked correct option"></i>
                                                    <?php elseif ($isSelectedByStudent && !$isCorrect): ?>
                                                        <i class="fa-solid fa-circle-xmark text-danger" title="Student picked incorrect option"></i>
                                                    <?php elseif ($isCorrect): ?>
                                                        <i class="fa-solid fa-check text-success" title="Correct answer key"></i>
                                                    <?php else: ?>
                                                        <i class="fa-regular fa-circle text-muted"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-grow-1" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($opt['option_text']) ?>
                                                </div>

                                                <!-- Status Badges -->
                                                <?php if ($isSelectedByStudent): ?>
                                                    <span class="badge <?= $isCorrect ? 'bg-success' : 'bg-danger' ?>" style="font-size: 0.7rem;">
                                                        <?= $isCorrect ? 'Student Pick (Correct)' : 'Student Pick (Incorrect)' ?>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($isCorrect && !$isSelectedByStudent): ?>
                                                    <span class="badge bg-success" style="font-size: 0.7rem;">Official Answer Key</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($selectedOptId <= 0): ?>
                                    <div class="alert alert-light border small text-muted mt-2 py-1.5 px-3">
                                        <i class="fa-solid fa-circle-exclamation text-warning me-1"></i> Student did not submit an option for this question (Unanswered).
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php else: 
                            // Theory Question Display
                            $writtenAns = $studentAns ? trim($studentAns['answer_text'] ?? '') : '';
                        ?>
                            <div class="mt-3">
                                <div class="small fw-bold text-muted mb-1 d-flex justify-content-between align-items-center">
                                    <span><i class="fa-solid fa-align-left me-1"></i>Student's Submitted Theory Response:</span>
                                    <?php if (!empty($writtenAns)): ?>
                                        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.72rem;">
                                            <?= str_word_count(strip_tags($writtenAns)) ?> words
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="student-written-box">
                                    <?= !empty($writtenAns) ? renderAnswerSheetRichText($writtenAns) : '<em class="text-muted">(No written response submitted by student for this question)</em>' ?>
                                </div>

                                <!-- Handwritten Answer Attachments (If student uploaded images) -->
                                <?php if (!empty($handwrittenImages)): ?>
                                    <div class="mt-3 pt-2 border-top">
                                        <div class="small fw-bold text-muted mb-2">
                                            <i class="fa-solid fa-paperclip me-1 text-primary"></i>Handwritten Uploads / Attached Work (<?= count($handwrittenImages) ?> file<?= count($handwrittenImages) > 1 ? 's' : '' ?>):
                                        </div>
                                        <div class="d-flex flex-wrap gap-3">
                                            <?php foreach ($handwrittenImages as $imgIndex => $imgPath): ?>
                                                <div class="text-center">
                                                    <img src="<?= htmlspecialchars($imgPath) ?>" alt="Student Work <?= ($imgIndex + 1) ?>" 
                                                         class="attachment-thumbnail" 
                                                         onclick="openImagePreview('<?= htmlspecialchars($imgPath, ENT_QUOTES) ?>', 'Student Work for Q<?= $q['question_number'] ?> (File #<?= ($imgIndex + 1) ?>)')">
                                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Page <?= ($imgIndex + 1) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>

                        <!-- Teacher's Inline Question Remark -->
                        <?php if (!empty($teacherRemarks)): ?>
                            <div class="teacher-remark-callout">
                                <div class="fw-bold mb-0.5 d-flex justify-content-between align-items-center">
                                    <span><i class="fa-solid fa-pen-nib me-1"></i>Examiner's Feedback on Q<?= $q['question_number'] ?>:</span>
                                </div>
                                <div><?= htmlspecialchars($teacherRemarks) ?></div>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Printable Certification / Signature Section (Appears at bottom for official archive) -->
            <div class="paper-card p-4 mt-5 text-muted small">
                <div class="row text-center pt-4">
                    <div class="col-4">
                        <div class="border-top pt-2">Student Signature / Roll</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top pt-2">Course Instructor Signature</div>
                    </div>
                    <div class="col-4">
                        <div class="border-top pt-2">Exam Controller / Verification Date</div>
                    </div>
                </div>
            </div>

            <!-- Bottom Teacher Navigation Actions -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-5 no-print gap-3">
                <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?><?= $submission ? '&submission_id=' . $submission['id'] : '' ?>" 
                   class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Evaluation Workbench
                </a>

                <div class="d-flex align-items-center gap-2">
                    <?php if ($prev_sub): ?>
                        <a href="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $prev_sub['id'] ?>" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-chevron-left me-1"></i> Previous Student
                        </a>
                    <?php endif; ?>

                    <?php if ($submission): ?>
                        <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submission['id'] ?>" class="btn btn-primary">
                            <i class="fa-solid fa-pen-to-square me-1"></i> Grade This Student
                        </a>
                    <?php endif; ?>

                    <?php if ($next_sub): ?>
                        <a href="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $next_sub['id'] ?>" class="btn btn-outline-secondary">
                            Next Student <i class="fa-solid fa-chevron-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>

    </main>

    <!-- Image Lightbox Modal -->
    <div class="modal fade no-print" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2.5">
                    <h6 class="modal-title fw-bold" id="imagePreviewTitle">Attachment Preview</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-3" style="background-color: #0f172a;">
                    <img id="imagePreviewSrc" src="" alt="Enlarged view" class="img-fluid rounded" style="max-height: 80vh;">
                </div>
                <div class="modal-footer py-2 d-flex justify-content-between">
                    <a id="imageDownloadBtn" href="" target="_blank" download class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-download me-1"></i> Open Full Image
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function openImagePreview(src, title) {
        document.getElementById('imagePreviewSrc').src = src;
        document.getElementById('imagePreviewTitle').textContent = title || 'Image Preview';
        document.getElementById('imageDownloadBtn').href = src;
        var modal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
        modal.show();
    }
    </script>
</body>
</html>
