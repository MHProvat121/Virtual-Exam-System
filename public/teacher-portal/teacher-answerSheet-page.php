<?php
// ============================================================================
// Teacher Answer Sheet & Evaluation Page
// Allows teachers to review student submissions, grade theory answers,
// adjust MCQ marks, provide constructive feedback, and publish final marks.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// RBAC: Session gatekeeper for teachers
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

require_once __DIR__ . '/../../src/config/config.php';
 

// Resolve database connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// exam_submissions and exam_evaluations are created by the schema migration.
// Do not call ensureSubmissionsTables() here; no runtime helper is defined.

function formatTeacherAnswerUtc($value, $format) {
    if (empty($value)) return 'N/A';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format($format) . ' UTC';
    } catch (Throwable $e) {
        return 'N/A';
    }
}

function sanitizeTeacherAnswerRichTextNode($node) {
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
            $innerHtml .= sanitizeTeacherAnswerRichTextNode($child);
        }

        if (!in_array($tag, $safeTags, true)) {
            return $innerHtml;
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $scheme = $href !== '' ? parse_url($href, PHP_URL_SCHEME) : null;
            if ($href !== '' && ($scheme === null || (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true)))) {
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
        $innerHtml .= sanitizeTeacherAnswerRichTextNode($child);
    }
    return $innerHtml;
}

function renderTeacherAnswerRichText($value) {
    $decoded = (string)($value ?? '');
    for ($i = 0; $i < 5; $i++) {
        $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($next === $decoded) break;
        $decoded = $next;
    }

    if ($decoded === '') return '';
    if (!class_exists('DOMDocument')) {
        $plainText = preg_replace('/<(br|hr)\s*\/?>|<\/(p|div|li|tr|h[1-6]|blockquote)>/i', "\n", $decoded);
        $plainText = html_entity_decode(strip_tags($plainText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return nl2br(htmlspecialchars(trim($plainText), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="teacher-answer-rich-text-root">' . $decoded . '</div></body></html>'
        );
        $xpath = new DOMXPath($document);
        $rootNodes = $xpath->query('//*[@id="teacher-answer-rich-text-root"]');
        if ($rootNodes && $rootNodes->length > 0) {
            $root = $rootNodes->item(0);
            $safeHtml = '';
            foreach ($root->childNodes as $child) {
                $safeHtml .= sanitizeTeacherAnswerRichTextNode($child);
            }
            return $safeHtml;
        }
    } catch (Throwable $e) {
        // Fall through to escaped plain text if stored markup cannot be parsed.
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
        error_log("Teacher fetch error in answerSheet: " . $e->getMessage());
    }
}

$teacher_name = !empty($current_teacher_name) ? $current_teacher_name : 'Instructor';
$teacher_id_display = !empty($current_teacher_id) ? $current_teacher_id : strval($session_uid);

// URL parameters
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$selected_sub_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
$selected_student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';

$feedback_msg = null;
$feedback_type = 'success';

// Handle POST: Save Evaluation Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_evaluation') {
    $sub_id_to_save = intval($_POST['submission_id'] ?? 0);
    $marks_awarded  = $_POST['marks'] ?? [];
    $remarks_given  = $_POST['remarks'] ?? [];
    $overall_feedback = trim($_POST['overall_feedback'] ?? '');
    $eval_status = trim($_POST['status'] ?? 'Graded');
    if ($eval_status === 'Submitted') $eval_status = 'Pending';
    if (!in_array($eval_status, ['Pending', 'Under Review', 'Graded'], true)) $eval_status = 'Graded';

    if ($sub_id_to_save > 0 && $dbConn) {
        try {
            $dbConn->beginTransaction();

            $ownerStmt = $dbConn->prepare("
                SELECT s.id, s.exam_id, e.marks AS total_marks
                FROM exam_submissions s
                JOIN exam_header e ON e.id = s.exam_id
                WHERE s.id = :sid AND s.exam_id = :eid
                  AND (s.status = 'Submitted' OR s.submitted_at IS NOT NULL)
                  AND (e.teacher_id = :tid OR e.teacher_id = :uid)
                LIMIT 1 FOR UPDATE
            ");
            $ownerStmt->execute([
                ':sid' => $sub_id_to_save,
                ':eid' => $exam_id,
                ':tid' => strval($current_teacher_id),
                ':uid' => strval($session_uid)
            ]);
            $authorizedSubmission = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$authorizedSubmission) {
                throw new RuntimeException('Submission not found, not yet submitted, or not owned by this teacher.');
            }

            // Save only question marks belonging to this exam and cap each score at that question's maximum.
            foreach ($marks_awarded as $qid => $mark_val) {
                $qid = intval($qid);
                if ($qid <= 0) continue;

                $maxStmt = $dbConn->prepare("SELECT marks FROM questions WHERE id = :qid AND exam_id = :eid LIMIT 1");
                $maxStmt->execute([':qid' => $qid, ':eid' => $exam_id]);
                $questionMax = $maxStmt->fetchColumn();
                if ($questionMax === false) continue;

                $numeric_mark = min(floatval($questionMax), max(0.00, floatval($mark_val)));
                $remark = mb_substr(trim($remarks_given[$qid] ?? ''), 0, 255);

                $updAns = $dbConn->prepare("
                    UPDATE student_answers 
                    SET marks_obtained = :marks, teacher_remarks = :remarks, updated_at = UTC_TIMESTAMP()
                    WHERE submission_id = :sub_id AND exam_id = :eid AND question_id = :qid
                ");
                $updAns->execute([
                    ':marks'   => $numeric_mark,
                    ':remarks' => $remark,
                    ':sub_id'  => $sub_id_to_save,
                    ':eid'     => $exam_id,
                    ':qid'     => intval($qid)
                ]);
            }

            // Question marks are authoritative; keep the evaluation total in sync with their sum.
            $sumStmt = $dbConn->prepare("SELECT COALESCE(SUM(marks_obtained), 0) FROM student_answers WHERE submission_id = :sid AND exam_id = :eid");
            $sumStmt->execute([':sid' => $sub_id_to_save, ':eid' => $exam_id]);
            $total_calculated_marks = min(
                floatval($authorizedSubmission['total_marks']),
                max(0.00, floatval($sumStmt->fetchColumn()))
            );

            $nowUtc = gmdate('Y-m-d H:i:s');
            $evaluatedAtUtc = $eval_status === 'Pending' ? null : $nowUtc;
            $evaluator = $teacher_name . ' (' . $teacher_id_display . ')';
            $saveEval = $dbConn->prepare("
                INSERT INTO exam_evaluations
                    (submission_id, status, total_marks_obtained, teacher_feedback, evaluated_by, evaluated_at, created_at, updated_at)
                VALUES
                    (:sid, :status, :marks, :feedback, :eval_by, :evaluated_at, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    total_marks_obtained = VALUES(total_marks_obtained),
                    teacher_feedback = VALUES(teacher_feedback),
                    evaluated_by = VALUES(evaluated_by),
                    evaluated_at = VALUES(evaluated_at),
                    updated_at = VALUES(updated_at)
            ");
            $saveEval->execute([
                ':sid' => $sub_id_to_save,
                ':status' => $eval_status,
                ':marks' => $total_calculated_marks,
                ':feedback' => $overall_feedback,
                ':eval_by' => $evaluator,
                ':evaluated_at' => $evaluatedAtUtc,
                ':created_at' => $nowUtc,
                ':updated_at' => $nowUtc
            ]);

            $dbConn->commit();

            $feedback_msg = "Evaluation saved successfully! Total marks awarded: {$total_calculated_marks}.";
            $feedback_type = 'success';
            $selected_sub_id = $sub_id_to_save;
        } catch (Throwable $e) {
            if ($dbConn->inTransaction()) $dbConn->rollBack();
            $feedback_msg = "Failed to save evaluation: " . $e->getMessage();
            $feedback_type = 'danger';
        }
    }
}

// Fetch Exam Details
$exam = null;
if ($exam_id > 0 && $dbConn) {
    try {
        $eStmt = $dbConn->prepare("SELECT e.*, e.marks AS total_marks FROM exam_header e WHERE e.id = :id AND (e.teacher_id = :tid OR e.teacher_id = :uid) LIMIT 1");
        $eStmt->execute([
            ':id' => $exam_id,
            ':tid' => strval($current_teacher_id),
            ':uid' => strval($session_uid)
        ]);
        $exam = $eStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching exam in teacher answer sheet: " . $e->getMessage());
    }
}

// If no exam_id provided, fetch list of exams for this teacher
$teacher_exams = [];
if ($dbConn) {
    try {
        $teStmt = $dbConn->prepare("
            SELECT id, exam_title, batch, subject, marks AS total_marks, status, exam_date 
            FROM exam_header 
            WHERE (teacher_id = :tid OR teacher_id = :uid)
            ORDER BY id DESC
        ");
        $teStmt->execute([
            ':tid' => strval($current_teacher_id),
            ':uid' => strval($session_uid)
        ]);
        $teacher_exams = $teStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching teacher exams: " . $e->getMessage());
    }
}

// Fetch all submissions for this exam
$submissions_list = [];
if ($exam_id > 0 && $dbConn) {
    try {
        $subStmt = $dbConn->prepare("
            SELECT s.id, s.exam_id, s.student_id, s.student_name, s.submitted_at,
                   s.status AS attempt_status,
                   CASE
                       WHEN ev.status IS NULL OR ev.status = 'Pending' THEN 'Submitted'
                       ELSE ev.status
                   END AS status,
                   COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained,
                   ev.teacher_feedback, ev.evaluated_by, ev.evaluated_at,
                   COALESCE(NULLIF(s.student_name, ''), CONCAT('Student #', s.student_id)) AS resolved_student_name,
                   '' AS student_batch
            FROM exam_submissions s
            LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
            WHERE s.exam_id = :eid
              AND (s.status = 'Submitted' OR s.submitted_at IS NOT NULL)
            ORDER BY COALESCE(s.submitted_at, s.updated_at) DESC, s.id DESC
        ");
        $subStmt->execute([':eid' => $exam_id]);
        $submissions_list = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching submissions list: " . $e->getMessage());
    }
}

// If a submission is selected, fetch the detailed questions and student answers
$selected_submission = null;
$questions_with_answers = [];
$total_exam_marks = $exam ? floatval($exam['total_marks'] ?? 0) : 0;

if ($selected_sub_id > 0 && $dbConn) {
    foreach ($submissions_list as $sub) {
        if (intval($sub['id']) === $selected_sub_id) {
            $selected_submission = $sub;
            break;
        }
    }
} elseif (!empty($selected_student_id) && $dbConn) {
    foreach ($submissions_list as $sub) {
        if ($sub['student_id'] === $selected_student_id) {
            $selected_submission = $sub;
            $selected_sub_id = intval($sub['id']);
            break;
        }
    }
}

// If no specific submission selected but submissions exist, default to the first one if user came to evaluate
if (!$selected_submission && !empty($submissions_list) && isset($_GET['auto_open'])) {
    $selected_submission = $submissions_list[0];
    $selected_sub_id = intval($selected_submission['id']);
}

// Load Questions & Answers for the selected submission
if ($selected_submission && $dbConn) {
    try {
        // Fetch questions
        $qStmt = $dbConn->prepare("
            SELECT id, question_number, question_type, question_text, marks, image_url 
            FROM questions 
            WHERE exam_id = :eid 
            ORDER BY question_number ASC, id ASC
        ");
        $qStmt->execute([':eid' => $exam_id]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch student answers for this submission
        $ansStmt = $dbConn->prepare("
            SELECT * FROM student_answers 
            WHERE submission_id = :sub_id
        ");
        $ansStmt->execute([':sub_id' => $selected_sub_id]);
        $answers_raw = $ansStmt->fetchAll(PDO::FETCH_ASSOC);

        $answers_by_qid = [];
        foreach ($answers_raw as $ans) {
            $answers_by_qid[intval($ans['question_id'])] = $ans;
        }

        // Attach options and student answer to each question
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

        $questions_with_answers = $questions;
    } catch (Exception $e) {
        error_log("Error fetching questions with answers: " . $e->getMessage());
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
    <title><?= $exam ? htmlspecialchars($exam['exam_title']) . ' - Evaluation & Answer Sheets' : 'Teacher Evaluation Portal' ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-light: #eef2ff;
            --slate-800: #1e293b;
            --slate-600: #475569;
            --slate-100: #f1f5f9;
        }
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        .eval-header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .eval-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .eval-card-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .student-roster-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .student-roster-table td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-graded {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .status-submitted {
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .question-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
        }
        .question-box.mcq-box {
            border-left: 4px solid #3b82f6;
        }
        .question-box.theory-box {
            border-left: 4px solid #8b5cf6;
        }
        .student-response-panel {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }
        .teacher-written-answer p:first-child { margin-top: 0; }
        .teacher-written-answer p:last-child { margin-bottom: 0; }
        .teacher-written-answer table { width: 100%; border-collapse: collapse; margin: 0.5rem 0; }
        .teacher-written-answer th, .teacher-written-answer td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .teacher-written-answer ul, .teacher-written-answer ol { padding-left: 1.5rem; }
        .mcq-option-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.15s ease;
        }
        .mcq-option-row.is-selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            font-weight: 600;
        }
        .mcq-option-row.is-correct {
            border-color: #10b981;
            background-color: #ecfdf5;
        }
        .mcq-option-row.is-wrong-selection {
            border-color: #ef4444;
            background-color: #fef2f2;
        }
        .mark-badge {
            background: #f1f5f9;
            color: #334155;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .sticky-eval-footer {
            position: sticky;
            bottom: 0;
            background: #ffffff;
            border-top: 2px solid #e2e8f0;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.06);
            padding: 16px 24px;
            z-index: 100;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Header -->
    <header class="eval-header py-3">
        <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <a href="teacher-result-page.php" class="btn btn-outline-secondary btn-sm" title="Back to Exam Management Table">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Evaluation List
                </a>
                <div>
                    <h5 class="mb-0 fw-bold text-slate-800">
                        <i class="fa-solid fa-clipboard-check text-primary me-2"></i>Answer Sheet Evaluation
                    </h5>
                    <small class="text-muted">
                        <?= $exam ? htmlspecialchars($exam['exam_title']) . " (" . htmlspecialchars($exam['batch']) . " - " . htmlspecialchars($exam['subject']) . ")" : "Select an Examination" ?>
                    </small>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-light text-dark border px-3 py-2">
                    <i class="fa-solid fa-user-tie text-primary me-1"></i> <?= htmlspecialchars($teacher_name) ?> (<?= htmlspecialchars($teacher_id_display) ?>)
                </span>
                <?php if ($exam): ?>
                    <a href="teacher-viewQues-page.php?exam_id=<?= $exam_id ?><?= $selected_submission ? '&submission_id=' . $selected_submission['id'] : '' ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="View Master Question Paper">
                        <i class="fa-solid fa-eye me-1"></i> View Question Paper
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="container-fluid px-4 py-4">

        <!-- Flash Feedback Alert -->
        <?php if ($feedback_msg): ?>
            <div class="alert alert-<?= $feedback_type ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="fa-solid <?= $feedback_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
                <?= htmlspecialchars($feedback_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$exam): ?>
            <!-- Exam Selection Card if no exam selected -->
            <div class="row justify-content-center py-4">
                <div class="col-md-8">
                    <div class="eval-card p-4 text-center">
                        <i class="fa-solid fa-folder-open text-muted fa-3x mb-3"></i>
                        <h4>Select an Examination to Evaluate</h4>
                        <p class="text-muted">Choose from your created examinations below to evaluate student answer sheets:</p>
                        <div class="list-group mt-3 text-start">
                            <?php if (empty($teacher_exams)): ?>
                                <div class="p-3 text-center text-muted">No examinations found for your account.</div>
                            <?php else: ?>
                                <?php foreach ($teacher_exams as $te): ?>
                                    <a href="teacher-answerSheet-page.php?exam_id=<?= $te['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                                        <div>
                                            <div class="fw-bold text-primary"><?= htmlspecialchars($te['exam_title']) ?></div>
                                            <small class="text-muted">Batch: <?= htmlspecialchars($te['batch']) ?> | Subject: <?= htmlspecialchars($te['subject']) ?> | Total Marks: <?= htmlspecialchars($te['total_marks']) ?></small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-secondary"><?= htmlspecialchars($te['status']) ?></span>
                                            <span class="btn btn-sm btn-primary"><i class="fa-solid fa-arrow-right"></i> Open</span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <!-- Main Layout: Left Student Roster & Right Answer Sheet View -->
            <div class="row g-4">

                <!-- Left Column: Student Submissions Roster -->
                <div class="col-lg-4 col-xl-3">
                    <div class="eval-card">
                        <div class="eval-card-header">
                            <h6 class="mb-0 fw-bold">
                                <i class="fa-solid fa-users text-primary me-2"></i>Submissions (<?= count($submissions_list) ?>)
                            </h6>
                            <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-rotate"></i>
                            </a>
                        </div>
                        <div class="p-2">
                            <?php if (empty($submissions_list)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fa-regular fa-folder-open fa-2x mb-2 text-secondary"></i>
                                    <p class="small mb-0">No submissions recorded for this exam yet.</p>
                                    <small class="text-muted d-block mt-1">Once students submit from the Exam Hall, they will appear here instantly.</small>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($submissions_list as $subItem): 
                                        $is_active = $selected_submission && intval($selected_submission['id']) === intval($subItem['id']);
                                        $subStatus = $subItem['status'] ?? 'Submitted';
                                        $isGraded = strcasecmp($subStatus, 'Graded') === 0;
                                    ?>
                                        <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $subItem['id'] ?>" 
                                           class="list-group-item list-group-item-action p-3 rounded-2 mb-1 <?= $is_active ? 'border-primary bg-light' : 'border-0' ?>">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <span class="fw-bold text-dark" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($subItem['resolved_student_name'] ?: 'Student #' . $subItem['student_id']) ?>
                                                </span>
                                                <span class="status-pill <?= $isGraded ? 'status-graded' : 'status-submitted' ?>">
                                                    <i class="fa-solid <?= $isGraded ? 'fa-check' : 'fa-hourglass-half' ?>" style="font-size: 0.65rem;"></i>
                                                    <?= htmlspecialchars($subStatus) ?>
                                                </span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center text-muted small">
                                                <span>ID: <strong><?= htmlspecialchars($subItem['student_id']) ?></strong></span>
                                                <span class="fw-bold <?= $isGraded ? 'text-success' : 'text-primary' ?>">
                                                    <?= floatval($subItem['total_marks_obtained']) ?> / <?= $total_exam_marks ?>
                                                </span>
                                            </div>
                                            <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                                <i class="fa-regular fa-clock me-1"></i><?= htmlspecialchars(formatTeacherAnswerUtc($subItem['submitted_at'], 'M d, g:i A')) ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Interactive Evaluation Workspace -->
                <div class="col-lg-8 col-xl-9">

                    <?php if (!$selected_submission): ?>
                        <div class="eval-card p-5 text-center">
                            <i class="fa-solid fa-user-check text-primary fa-3x mb-3"></i>
                            <h4>Select a Student Paper to Evaluate</h4>
                            <p class="text-muted">Choose a student submission from the left panel to review answers, award marks, and provide feedback.</p>
                            <?php if (!empty($submissions_list)): ?>
                                <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $submissions_list[0]['id'] ?>" class="btn btn-primary mt-2">
                                    <i class="fa-solid fa-pen-nib me-1"></i> Evaluate First Submission
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>

                        <!-- Active Student Evaluation Form -->
                        <form method="POST" action="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $selected_sub_id ?>" id="evaluationForm">
                            <input type="hidden" name="action" value="save_evaluation">
                            <input type="hidden" name="submission_id" value="<?= $selected_sub_id ?>">

                            <!-- Student Info Card Banner -->
                            <div class="eval-card p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge bg-primary px-2.5 py-1">Student Paper</span>
                                            <h4 class="mb-0 fw-bold text-dark">
                                                <?= htmlspecialchars($selected_submission['resolved_student_name'] ?: 'Student ' . $selected_submission['student_id']) ?>
                                            </h4>
                                        </div>
                                        <div class="text-muted small">
                                            Student Roll/ID: <strong><?= htmlspecialchars($selected_submission['student_id']) ?></strong> &bull;
                                            Batch: <strong><?= htmlspecialchars($exam['batch'] ?? 'N/A') ?></strong> &bull;
                                            Submitted At: <strong><?= htmlspecialchars(formatTeacherAnswerUtc($selected_submission['submitted_at'], 'F j, Y, g:i A')) ?></strong>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-3">
                                        <div class="text-end">
                                            <span class="text-muted small d-block">Current Score</span>
                                            <span class="h4 fw-bold text-success mb-0" id="headerScoreDisplay">
                                                <?= floatval($selected_submission['total_marks_obtained']) ?> / <?= $total_exam_marks ?>
                                            </span>
                                        </div>
                                        <a href="teacher-viewStudentAnswerSheet-page.php?exam_id=<?= $exam_id ?>&submission_id=<?= $selected_submission['id'] ?>&student_id=<?= urlencode($selected_submission['student_id']) ?>" 
                                           class="btn btn-outline-primary btn-sm" target="_blank" title="Preview student answer sheet">
                                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Student View
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Question by Question Evaluation Section -->
                            <h5 class="fw-bold mb-3 text-slate-800">
                                <i class="fa-solid fa-list-check text-primary me-2"></i>Question Responses & Marking
                            </h5>

                            <?php if (empty($questions_with_answers)): ?>
                                <div class="alert alert-warning">No questions found for this exam.</div>
                            <?php else: ?>
                                <?php foreach ($questions_with_answers as $qIndex => $q): 
                                    $qid = intval($q['id']);
                                    $qtype = strtoupper($q['question_type']);
                                    $qmax = floatval($q['marks'] ?? 0);
                                    $studentAns = $q['student_answer'] ?? null;
                                    $currentMark = $studentAns ? floatval($studentAns['marks_obtained']) : 0.00;
                                    $currentRemark = $studentAns ? ($studentAns['teacher_remarks'] ?? '') : '';
                                ?>
                                    <div class="question-box <?= $qtype === 'MCQ' ? 'mcq-box' : 'theory-box' ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge <?= $qtype === 'MCQ' ? 'bg-primary' : 'bg-purple text-white' ?>" style="<?= $qtype !== 'MCQ' ? 'background-color: #8b5cf6;' : '' ?>">
                                                    Q<?= $q['question_number'] ?> &bull; <?= $qtype ?>
                                                </span>
                                                <span class="mark-badge">Max Marks: <?= $qmax ?></span>
                                            </div>

                                            <!-- Marks Award Input Box for this question -->
                                            <div class="d-flex align-items-center gap-2">
                                                <label class="small fw-bold text-muted mb-0">Award Marks:</label>
                                                <input type="number" 
                                                       step="0.01" 
                                                       min="0" 
                                                       max="<?= $qmax ?>" 
                                                       name="marks[<?= $qid ?>]" 
                                                       class="form-control form-control-sm text-center fw-bold mark-input" 
                                                       style="width: 85px; border-color: #cbd5e1;" 
                                                       value="<?= $currentMark ?>" 
                                                       data-max="<?= $qmax ?>"
                                                       required>
                                                <span class="text-muted small">/ <?= $qmax ?></span>
                                            </div>
                                        </div>

                                        <!-- Question Statement Text -->
                                        <div class="fs-6 fw-semibold text-dark mt-2 mb-3">
                                            <?= renderTeacherAnswerRichText($q['question_text'] ?? '') ?>
                                        </div>

                                        <!-- Optional Question Diagram / Image -->
                                        <?php if (!empty($q['image_url'])): ?>
                                            <div class="mb-3">
                                                <img src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question Diagram" class="img-fluid rounded border" style="max-height: 250px;">
                                            </div>
                                        <?php endif; ?>

                                        <!-- MCQ Options Rendering -->
                                        <?php if ($qtype === 'MCQ'): 
                                            $selectedOptId = $studentAns ? intval($studentAns['selected_option_id'] ?? 0) : 0;
                                        ?>
                                            <div class="mt-2">
                                                <div class="small fw-bold text-muted mb-2"><i class="fa-solid fa-list-ol me-1"></i>Options & Answer Key:</div>
                                                <div class="row g-2">
                                                    <?php foreach ($q['options'] as $opt): 
                                                        $optId = intval($opt['id']);
                                                        $isCorrectOpt = intval($opt['is_correct']) === 1;
                                                        $isSelectedByStudent = ($optId === $selectedOptId);

                                                        $optClass = 'mcq-option-row';
                                                        if ($isSelectedByStudent && $isCorrectOpt) {
                                                            $optClass .= ' is-correct is-selected';
                                                        } elseif ($isSelectedByStudent && !$isCorrectOpt) {
                                                            $optClass .= ' is-wrong-selection';
                                                        } elseif ($isCorrectOpt) {
                                                            $optClass .= ' is-correct';
                                                        }
                                                    ?>
                                                        <div class="col-md-6">
                                                            <div class="<?= $optClass ?>">
                                                                <div style="width: 20px;">
                                                                    <?php if ($isSelectedByStudent && $isCorrectOpt): ?>
                                                                        <i class="fa-solid fa-circle-check text-success"></i>
                                                                    <?php elseif ($isSelectedByStudent && !$isCorrectOpt): ?>
                                                                        <i class="fa-solid fa-circle-xmark text-danger"></i>
                                                                    <?php elseif ($isCorrectOpt): ?>
                                                                        <i class="fa-solid fa-check text-success"></i>
                                                                    <?php else: ?>
                                                                        <i class="fa-regular fa-circle text-muted"></i>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="flex-grow-1" style="font-size: 0.9rem;">
                                                                    <?= htmlspecialchars($opt['option_text']) ?>
                                                                </div>
                                                                <?php if ($isSelectedByStudent): ?>
                                                                    <span class="badge bg-primary px-2" style="font-size: 0.65rem;">Selected</span>
                                                                <?php endif; ?>
                                                                <?php if ($isCorrectOpt): ?>
                                                                    <span class="badge bg-success px-2" style="font-size: 0.65rem;">Correct Key</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <?php if ($selectedOptId <= 0): ?>
                                                    <div class="alert alert-light border small text-muted mt-2 py-1 px-3">
                                                        <i class="fa-solid fa-info-circle me-1"></i> Student did not select an option for this question.
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                        <?php else: 
                                            // Theory / Written Question
                                            $writtenAnswer = $studentAns ? trim($studentAns['answer_text'] ?? '') : '';
                                        ?>
                                            <div class="student-response-panel mt-3">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="small fw-bold text-slate-800">
                                                        <i class="fa-solid fa-pen text-purple me-1" style="color: #8b5cf6;"></i>Student's Written Answer:
                                                    </span>
                                                    <span class="badge bg-light text-muted border">
                                                        <?= mb_strlen($writtenAnswer) ?> characters
                                                    </span>
                                                </div>
                                                <?php if (empty($writtenAnswer)): ?>
                                                    <div class="text-muted fst-italic py-2">
                                                        <i class="fa-regular fa-comment-dots me-1"></i>(No written answer submitted by student for this question)
                                                    </div>
                                                <?php else: ?>
                                                    <div class="p-3 bg-white rounded border text-dark teacher-written-answer" style="white-space: pre-wrap; font-size: 0.92rem; line-height: 1.6;">
                                                        <?= renderTeacherAnswerRichText($writtenAnswer) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Question Remarks / Inline Critique -->
                                        <div class="mt-3">
                                            <input type="text" 
                                                   name="remarks[<?= $qid ?>]" 
                                                   class="form-control form-control-sm" 
                                                   placeholder="Add teacher remarks/critique for this question (optional)..." 
                                                   value="<?= htmlspecialchars($currentRemark) ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Overall Qualitative Feedback & Status -->
                            <div class="eval-card p-4">
                                <h6 class="fw-bold mb-3 text-slate-800">
                                    <i class="fa-solid fa-comment-dots text-primary me-2"></i>Overall Examiner Feedback & Assessment
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-semibold text-muted">Qualitative Feedback to Student:</label>
                                        <textarea name="overall_feedback" 
                                                  class="form-control" 
                                                  rows="3" 
                                                  placeholder="Provide constructive feedback, areas for improvement, or congratulations to the student..."><?= htmlspecialchars($selected_submission['teacher_feedback'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold text-muted">Evaluation Status:</label>
                                        <select name="status" class="form-select mb-3">
                                            <option value="Pending" <?= strcasecmp($selected_submission['status'] ?? '', 'Submitted') === 0 ? 'selected' : '' ?>>
                                                Pending (Submitted, Not Yet Evaluated)
                                            </option>
                                            <option value="Graded" <?= strcasecmp($selected_submission['status'] ?? '', 'Graded') === 0 ? 'selected' : '' ?>>
                                                Graded (Publish Marks to Student)
                                            </option>
                                            <option value="Under Review" <?= strcasecmp($selected_submission['status'] ?? '', 'Under Review') === 0 ? 'selected' : '' ?>>
                                                Under Review (Draft Evaluation)
                                            </option>
                                        </select>

                                        <div class="p-3 bg-light rounded border text-center">
                                            <span class="small text-muted d-block">Total Exam Marks</span>
                                            <h4 class="text-success fw-bold mb-0">
                                                <span id="computedTotalScore">0</span> / <?= $total_exam_marks ?>
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sticky Save Action Footer -->
                            <div class="sticky-eval-footer rounded-3 mt-4 d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <a href="teacher-answerSheet-page.php?exam_id=<?= $exam_id ?>" class="btn btn-outline-secondary">
                                        <i class="fa-solid fa-xmark me-1"></i> Close Sheet
                                    </a>
                                    <span class="text-muted small d-none d-md-inline">
                                        Evaluating: <strong><?= htmlspecialchars($selected_submission['resolved_student_name'] ?: $selected_submission['student_id']) ?></strong>
                                    </span>
                                </div>

                                <div class="d-flex align-items-center gap-3">
                                    <button type="submit" class="btn btn-success px-4 py-2 fw-semibold shadow-sm">
                                        <i class="fa-solid fa-cloud-arrow-up me-2"></i>Save Evaluation & Publish Marks
                                    </button>
                                </div>
                            </div>

                        </form>
                    <?php endif; ?>

                </div>
            </div>

        <?php endif; ?>

    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Dynamic Score Calculation Script -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const markInputs = document.querySelectorAll('.mark-input');
        const computedScoreSpan = document.getElementById('computedTotalScore');
        const headerScoreDisplay = document.getElementById('headerScoreDisplay');
        const totalExamMarks = <?= json_encode($total_exam_marks) ?>;

        function recalculateTotal() {
            let sum = 0;
            markInputs.forEach(input => {
                const val = parseFloat(input.value) || 0;
                sum += val;
            });
            // Round to 2 decimals
            const rounded = Math.round(sum * 100) / 100;
            if (computedScoreSpan) {
                computedScoreSpan.textContent = rounded;
            }
            if (headerScoreDisplay) {
                headerScoreDisplay.textContent = rounded + ' / ' + totalExamMarks;
            }
        }

        markInputs.forEach(input => {
            input.addEventListener('input', () => {
                const maxVal = parseFloat(input.dataset.max) || 0;
                let currentVal = parseFloat(input.value) || 0;
                if (currentVal > maxVal) {
                    input.value = maxVal;
                } else if (currentVal < 0) {
                    input.value = 0;
                }
                recalculateTotal();
            });
        });

        // Initialize sum on page load
        recalculateTotal();
    });
    </script>
</body>
</html>
