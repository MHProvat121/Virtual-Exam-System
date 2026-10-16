<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../src/config/config.php';

// Support both $pdo and $conn connection objects
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Authorization check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $session_teacher_id   = $_SESSION['teacher_id'] ?? '';
    $session_teacher_name = $_SESSION['teacher_name'] ?? '';
    $session_uid          = $_SESSION['user_id'] ?? 0;
    $session_email        = $_SESSION['email'] ?? '';

    // Guarantee reliable resolution of teacher_id and full_name from `teachers` table
    if (empty($session_teacher_id) || is_numeric($session_teacher_id) || empty($session_teacher_name)) {
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
                    ':tid'   => strval($session_teacher_id),
                    ':email' => strval($session_email)
                ]);
                $logged_teacher = $t_stmt->fetch(PDO::FETCH_ASSOC);

                if ($logged_teacher) {
                    $session_teacher_id   = $logged_teacher['teacher_id'] ?? $session_teacher_id;
                    $session_teacher_name = $logged_teacher['full_name'] ?? $session_teacher_name;
                    $_SESSION['teacher_id']   = $session_teacher_id;
                    $_SESSION['teacher_name'] = $session_teacher_name;
                }
            }
        } catch (Exception $e) {
            error_log("Teacher fetch error in logic: " . $e->getMessage());
        }
    }

    if (empty($session_teacher_id)) {
        $session_teacher_id = strval($session_uid);
    }

    $exam_id      = isset($_POST['exam_id']) ? intval($_POST['exam_id']) : 0;
    $teacher_name = trim($_POST['teacher_name'] ?? '');
    if (empty($teacher_name)) {
        $teacher_name = !empty($session_teacher_name) ? $session_teacher_name : 'Instructor';
    }

    $batch      = trim($_POST['batch'] ?? '');
    $subject    = trim($_POST['subject'] ?? '');
    $exam_title = trim($_POST['exam_title'] ?? '');
    $exam_type  = trim($_POST['exam_type'] ?? '');
    $exam_date  = trim($_POST['exam_date'] ?? '');
    $exam_time  = trim($_POST['exam_time'] ?? '');
    $duration   = intval($_POST['duration'] ?? 0);

    // Normalize exam_type enum to match schema: 'MCQ', 'Theory', 'Hybrid (MCQ+Theory)'
    $validTypes = ['MCQ', 'Theory', 'Hybrid (MCQ+Theory)'];
    if (!in_array($exam_type, $validTypes, true)) {
        if (stripos($exam_type, 'hybrid') !== false) {
            $exam_type = 'Hybrid (MCQ+Theory)';
        } elseif (stripos($exam_type, 'theory') !== false || stripos($exam_type, 'written') !== false) {
            $exam_type = 'Theory';
        } else {
            $exam_type = 'MCQ';
        }
    }

    // Form Validation
    if (empty($session_teacher_id) || empty($batch) || empty($subject) || empty($exam_title) || empty($exam_type) || empty($exam_date) || empty($exam_time) || $duration <= 0) {
        $_SESSION['error'] = "All required fields must be filled in correctly.";
        header("Location: teacher-makeQues-page1.php" . ($exam_id > 0 ? "?action=edit&exam_id=$exam_id" : ""));
        exit();
    }

    try {
        // Store the stable course code with the exam instead of making student pages
        // infer the course from subject/title text later.
        $courseCodeStmt = $dbConn->prepare("
            SELECT MIN(course_code)
            FROM batches
            WHERE TRIM(batch) = :batch
              AND TRIM(subject) = :subject
              AND TRIM(exam_title) = :exam_title
              AND course_code IS NOT NULL
              AND TRIM(course_code) <> ''
            HAVING COUNT(DISTINCT course_code) = 1
        ");
        $courseCodeStmt->execute([
            ':batch'      => $batch,
            ':subject'    => $subject,
            ':exam_title' => $exam_title
        ]);
        $course_code = $courseCodeStmt->fetchColumn();
        $course_code = ($course_code !== false && trim((string)$course_code) !== '')
            ? trim((string)$course_code)
            : null;

        if ($exam_id > 0) {
            // STEP 1 UPDATE: Update existing exam row in `exam_header` table using published exam algorithm
            $sql = "UPDATE exam_header SET 
                        teacher_id   = :teacher_id,
                        teacher_name = :teacher_name,
                        batch        = :batch,
                        course_code  = :course_code,
                        subject      = :subject,
                        exam_title   = :exam_title,
                        exam_type    = :exam_type,
                        exam_date    = :exam_date,
                        exam_time    = :exam_time,
                        duration     = :duration,
                        status       = 'Published',
                        updated_at   = CURRENT_TIMESTAMP()
                    WHERE id = :exam_id";

            $stmt = $dbConn->prepare($sql);
            $stmt->execute([
                ':teacher_id'   => $session_teacher_id,
                ':teacher_name' => $teacher_name,
                ':batch'        => $batch,
                ':course_code'  => $course_code,
                ':subject'      => $subject,
                ':exam_title'   => $exam_title,
                ':exam_type'    => $exam_type,
                ':exam_date'    => $exam_date,
                ':exam_time'    => $exam_time,
                ':duration'     => $duration,
                ':exam_id'      => $exam_id
            ]);

            $target_exam_id = $exam_id;
        } else {
            // STEP 1 INSERT: Insert into `exam_header` table (with marks initialized to 0.00)
            $sql = "INSERT INTO exam_header 
                        (teacher_id, teacher_name, batch, course_code, exam_title, exam_type, subject, exam_date, exam_time, duration, marks, status) 
                    VALUES 
                        (:teacher_id, :teacher_name, :batch, :course_code, :exam_title, :exam_type, :subject, :exam_date, :exam_time, :duration, 0.00, 'Published')";

            $stmt = $dbConn->prepare($sql);
            $stmt->execute([
                ':teacher_id'   => $session_teacher_id,
                ':teacher_name' => $teacher_name,
                ':batch'        => $batch,
                ':course_code'  => $course_code,
                ':exam_title'   => $exam_title,
                ':exam_type'    => $exam_type,
                ':subject'      => $subject,
                ':exam_date'    => $exam_date,
                ':exam_time'    => $exam_time,
                ':duration'     => $duration
            ]);

            $target_exam_id = intval($dbConn->lastInsertId());

            if ($target_exam_id <= 0) {
                // Fallback ID resolution
                $idStmt = $dbConn->prepare("SELECT id FROM exam_header WHERE teacher_id = :tid ORDER BY id DESC LIMIT 1");
                $idStmt->execute([':tid' => $session_teacher_id]);
                $target_exam_id = intval($idStmt->fetchColumn());
            }
        }

        // STEP 2 ROUTING: Proceed to Page 2 (Question builder) with the target exam ID
        header("Location: teacher-makeQues-page2.php?exam_id=" . $target_exam_id);
        exit();

    } catch (PDOException $e) {
        error_log("Database Error in Page 1 Logic: " . $e->getMessage());
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: teacher-makeQues-page1.php" . ($exam_id > 0 ? "?action=edit&exam_id=$exam_id" : ""));
        exit();
    }
} else {
    header("Location: teacher-makeQues-page1.php");
    exit();
}
