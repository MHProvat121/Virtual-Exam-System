<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache & JSON headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../src/config/config.php';

// Authorization Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// Handle question image upload directly (eliminates the need for a separate upload file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['question_image'])) {
    $file = $_FILES['question_image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error code: ' . $file['error']]);
        exit();
    }

    // Validate size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
        exit();
    }

    // Validate extension
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, or WEBP images are allowed.']);
        exit();
    }

    // Define target directory (supports standard and root asset directory structures)
    $uploadDir = __DIR__ . '/../../public/assets/images/question_images/';
    if (!is_dir(dirname(__DIR__ . '/../../public/assets/images/')) && is_dir(__DIR__ . '/../assets/')) {
        $uploadDir = __DIR__ . '/../assets/images/question_images/';
    }
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newFileName = 'qimg_' . time() . '_' . uniqid() . '.' . $ext;
    $targetPath  = rtrim($uploadDir, '/\\') . '/' . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $publicUrl = '../assets/images/question_images/' . $newFileName;
        echo json_encode([
            'success' => true,
            'url'     => $publicUrl
        ]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded image.']);
        exit();
    }
}

$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if (!$dbConn) {
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit();
}

$current_teacher_id = $_SESSION['teacher_id'] ?? '';
$session_uid        = $_SESSION['user_id'] ?? 0;

// Read JSON Payload
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid or empty request data.']);
    exit();
}

$exam_id = isset($data['exam_id']) ? intval($data['exam_id']) : 0;

if ($exam_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid exam ID.']);
    exit();
}

try {
    /* ====================================================
       ACTION 1: CANCEL & DELETE ENTIRE EXAM FROM `exam_header`
       ==================================================== */
    if (isset($data['action']) && $data['action'] === 'cancel') {
        unset($_SESSION['teacher_exam_draft_' . $exam_id]);
        $delExam = $dbConn->prepare("
            DELETE FROM exam_header 
            WHERE id = :exam_id AND (teacher_id = :teacher_id OR teacher_id = :uid)
        ");
        $delExam->execute([
            ':exam_id'    => $exam_id,
            ':teacher_id' => strval($current_teacher_id),
            ':uid'        => strval($session_uid)
        ]);

        echo json_encode([
            'success'  => true,
            'message'  => 'Exam and questions deleted successfully from exam_header.',
            'redirect' => 'teacher-quesTable-page.php'
        ]);
        exit();
    }

    /* ====================================================
       ACTION 1B: AUTOSAVE QUESTION DRAFT IN MEANTIME
       ==================================================== */
    if (isset($data['action']) && ($data['action'] === 'save_draft' || $data['action'] === 'autosave_draft')) {
        $_SESSION['teacher_exam_draft_' . $exam_id] = [
            'exam_id'       => $exam_id,
            'ques_no'       => intval($data['ques_no'] ?? 0),
            'type'          => trim($data['type'] ?? 'MCQ'),
            'text'          => trim($data['text'] ?? ''),
            'marks'         => floatval($data['marks'] ?? 1.00),
            'options'       => isset($data['options']) && is_array($data['options']) ? $data['options'] : [],
            'correct_index' => intval($data['correct_index'] ?? 0),
            'image'         => trim($data['image'] ?? ''),
            'updated_at'    => time()
        ];

        echo json_encode([
            'success' => true,
            'message' => 'Draft autosaved meantime.',
            'time'    => date('h:i:s A')
        ]);
        exit();
    }

    /* ====================================================
       ACTION 1C: CLEAR DRAFT ON COMPLETION / REMOVAL
       ==================================================== */
    if (isset($data['action']) && $data['action'] === 'clear_draft') {
        unset($_SESSION['teacher_exam_draft_' . $exam_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    /* ====================================================
       ACTION 2: DELETE INDIVIDUAL QUESTION FROM SUMMARY
       ==================================================== */
    if (isset($data['action']) && $data['action'] === 'delete_question') {
        $ques_no = isset($data['ques_no']) ? intval($data['ques_no']) : 0;

        if ($ques_no > 0) {
            $delQ = $dbConn->prepare("DELETE FROM questions WHERE exam_id = :exam_id AND question_number = :qnum");
            $delQ->execute([
                ':exam_id' => $exam_id,
                ':qnum'    => $ques_no
            ]);

            // STEP 2: Recalculate total marks from questions and store in exam_header(marks)
            $sumStmt = $dbConn->prepare("SELECT COALESCE(SUM(marks), 0) AS total FROM questions WHERE exam_id = :exam_id");
            $sumStmt->execute([':exam_id' => $exam_id]);
            $sumRow     = $sumStmt->fetch(PDO::FETCH_ASSOC);
            $totalMarks = floatval($sumRow['total'] ?? 0);

            $updateExamStmt = $dbConn->prepare("UPDATE exam_header SET marks = :marks WHERE id = :exam_id");
            $updateExamStmt->execute([
                ':marks'   => $totalMarks,
                ':exam_id' => $exam_id
            ]);

            echo json_encode([
                'success'     => true,
                'message'     => 'Question removed successfully.',
                'total_marks' => $totalMarks
            ]);
            exit();
        }
    }

    /* ====================================================
       ACTION 3: SAVE OR UPDATE QUESTION IN `questions`
       ==================================================== */
    $ques_no       = isset($data['ques_no']) ? intval($data['ques_no']) : 0;
    $type          = trim($data['type'] ?? 'MCQ');
    $text          = trim($data['text'] ?? '');
    $marks         = floatval($data['marks'] ?? 1.00);
    $optionsList   = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
    $correct_index = isset($data['correct_index']) ? intval($data['correct_index']) : 0;
    $image_url     = trim($data['image'] ?? '');

    // Validate ENUM ('MCQ' or 'Theory')
    $type = (strtoupper($type) === 'THEORY') ? 'Theory' : 'MCQ';

    if ($ques_no <= 0 || empty($text) || $marks <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please fill in Marks and Question statement.']);
        exit();
    }

    // Check if question already exists in `questions` table for THIS specific exam_id
    $checkStmt = $dbConn->prepare("SELECT id FROM questions WHERE exam_id = :exam_id AND question_number = :qnum LIMIT 1");
    $checkStmt->execute([
        ':exam_id' => $exam_id,
        ':qnum'    => $ques_no
    ]);
    $existingQues = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingQues) {
        $question_id = $existingQues['id'];

        // UPDATE existing question in `questions`
        $updateSql = "UPDATE questions SET 
                        question_type = :type,
                        question_text = :text,
                        image_url     = :image_url,
                        marks         = :marks
                      WHERE id = :qid";

        $stmt = $dbConn->prepare($updateSql);
        $stmt->execute([
            ':type'      => $type,
            ':text'      => $text,
            ':image_url' => $image_url,
            ':marks'     => $marks,
            ':qid'       => $question_id
        ]);
    } else {
        // INSERT new question into `questions`
        $insertSql = "INSERT INTO questions 
                        (exam_id, question_number, question_type, question_text, image_url, marks) 
                      VALUES 
                        (:exam_id, :qnum, :type, :text, :image_url, :marks)";

        $stmt = $dbConn->prepare($insertSql);
        $stmt->execute([
            ':exam_id'   => $exam_id,
            ':qnum'      => $ques_no,
            ':type'      => $type,
            ':text'      => $text,
            ':image_url' => $image_url,
            ':marks'     => $marks
        ]);
        $question_id = $dbConn->lastInsertId();
    }

    /* ====================================================
       ACTION 4: SAVE MCQ OPTIONS TO `question_options`
       ==================================================== */
    $delOptStmt = $dbConn->prepare("DELETE FROM question_options WHERE question_id = :qid");
    $delOptStmt->execute([':qid' => $question_id]);

    if ($type === 'MCQ' && !empty($optionsList)) {
        $optInsertSql = "INSERT INTO question_options (question_id, option_text, option_order, is_correct) 
                         VALUES (:qid, :opt_text, :opt_order, :is_correct)";
        $optStmt = $dbConn->prepare($optInsertSql);

        foreach ($optionsList as $order => $optText) {
            $isCorrect = ($order === $correct_index) ? 1 : 0;
            $optStmt->execute([
                ':qid'        => $question_id,
                ':opt_text'   => trim($optText),
                ':opt_order'  => $order,
                ':is_correct' => $isCorrect
            ]);
        }
    }

    /* ====================================================
       ACTION 5: RECALCULATE TOTAL MARKS AND UPDATE `exam_header` (marks)
       ==================================================== */
    $sumStmt = $dbConn->prepare("SELECT COALESCE(SUM(marks), 0) AS total FROM questions WHERE exam_id = :exam_id");
    $sumStmt->execute([':exam_id' => $exam_id]);
    $sumRow     = $sumStmt->fetch(PDO::FETCH_ASSOC);
    $totalMarks = floatval($sumRow['total'] ?? 0);

    // STEP 2: Store total marks counted after questions are made into exam_header
    $updateExamStmt = $dbConn->prepare("UPDATE exam_header SET marks = :marks WHERE id = :exam_id");
    $updateExamStmt->execute([
        ':marks'   => $totalMarks,
        ':exam_id' => $exam_id
    ]);

    // Clear active draft for this exam upon successful permanent save
    unset($_SESSION['teacher_exam_draft_' . $exam_id]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Question saved successfully.',
        'total_marks' => $totalMarks
    ]);
    exit();

} catch (PDOException $e) {
    error_log("Database error in page2-logic: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}
