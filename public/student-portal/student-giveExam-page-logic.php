<?php
// student-giveExam-page-logic.php
// Backend API handler for saving student answers, image uploads, submitting exam, and checking hold status
// Strictly mapped to MySQL database schema:
// - exam_details / exam_header
// - exam_submissions (attempt state and UTC submission timestamps)
// - exam_evaluations (grading state, marks, feedback, and UTC evaluation timestamps)
// - student_answers (id, submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, ...)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header('Content-Type: application/json; charset=utf-8');

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$dbConn = $conn ?? $pdo ?? null;
require_once __DIR__ . '/../common-portal/exam-submission-finalizer.php';

$logged_student_id   = $_SESSION['student_id'] ?? $_SESSION['user_id'] ?? 'STD-2026-001';
$logged_student_name = $_SESSION['student_name'] ?? $_SESSION['user_name'] ?? 'Alex Johnson';

$action  = strtolower(trim($_POST['action'] ?? $_GET['action'] ?? $_REQUEST['action'] ?? ''));
$exam_id = intval($_POST['exam_id'] ?? $_GET['exam_id'] ?? $_REQUEST['exam_id'] ?? 0);

if ($exam_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid exam ID.']);
    exit();
}

/**
 * Automatically ensures the `answer_image` column exists in `student_answers`
 */
function ensureAnswerImageColumnExists($db) {
    if (!$db) return;
    try {
        if ($db instanceof PDO) {
            $check = $db->query("SHOW COLUMNS FROM `student_answers` LIKE 'answer_image'");
            if ($check && $check->rowCount() === 0) {
                $db->exec("ALTER TABLE `student_answers` ADD COLUMN `answer_image` TEXT DEFAULT NULL COMMENT 'Path or JSON array of uploaded answer image(s)' AFTER `answer_text`");
            }
        } elseif ($db instanceof mysqli) {
            $check = $db->query("SHOW COLUMNS FROM `student_answers` LIKE 'answer_image'");
            if ($check && $check->num_rows === 0) {
                $db->query("ALTER TABLE `student_answers` ADD COLUMN `answer_image` TEXT DEFAULT NULL COMMENT 'Path or JSON array of uploaded answer image(s)' AFTER `answer_text`");
            }
        }
    } catch (Exception $e) {
        // Suppress if already exists or permission denied
    }
}

/**
 * Helper to ensure a row exists in exam_submissions for (exam_id, student_id)
 * and return the submission_id.
 */
function getOrCreateSubmissionId($db, $examId, $studentId, $studentName) {
    if (!$db) return 1;
    $now = gmdate('Y-m-d H:i:s');

    if ($db instanceof PDO) {
        // Find existing submission
        $stmt = $db->prepare("SELECT id FROM exam_submissions WHERE exam_id = :eid AND student_id = :sid LIMIT 1");
        $stmt->execute([':eid' => $examId, ':sid' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return intval($row['id']);
        }

        // Insert a non-final attempt row. submit_exam records final submission.
        $ins = $db->prepare("
            INSERT INTO exam_submissions (exam_id, student_id, student_name, submitted_at, status, created_at, updated_at)
            VALUES (:eid, :sid, :sname, NULL, 'In Progress', :cre, :upd)
        ");
        $ins->execute([
            ':eid'   => $examId,
            ':sid'   => $studentId,
            ':sname' => $studentName,
            ':cre'   => $now,
            ':upd'   => $now
        ]);
        return intval($db->lastInsertId());
    } elseif ($db instanceof mysqli) {
        $stmt = $db->prepare("SELECT id FROM exam_submissions WHERE exam_id = ? AND student_id = ? LIMIT 1");
        $stmt->bind_param("is", $examId, $studentId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            return intval($row['id']);
        }

        $ins = $db->prepare("INSERT INTO exam_submissions (exam_id, student_id, student_name, submitted_at, status, created_at, updated_at) VALUES (?, ?, ?, NULL, 'In Progress', ?, ?)");
        $ins->bind_param("issss", $examId, $studentId, $studentName, $now, $now);
        $ins->execute();
        return intval($db->insert_id);
    }

    return 1;
}

try {
    if ($dbConn) {
        ensureAnswerImageColumnExists($dbConn);
    }

    // -------------------------------------------------------------
    // ACTION: start_attempt
    // Mark an open exam attempt as in progress, not as a final submission.
    // -------------------------------------------------------------
    if ($action === 'start_attempt') {
        $examStatus = '';
        if ($dbConn instanceof PDO) {
            $statusStmt = $dbConn->prepare("SELECT status FROM exam_header WHERE id = :eid LIMIT 1");
            $statusStmt->execute([':eid' => $exam_id]);
            $examStatus = (string)($statusStmt->fetchColumn() ?: '');
        } elseif ($dbConn instanceof mysqli) {
            $statusStmt = $dbConn->prepare("SELECT status FROM exam_header WHERE id = ? LIMIT 1");
            $statusStmt->bind_param("i", $exam_id);
            $statusStmt->execute();
            $statusRow = $statusStmt->get_result()->fetch_assoc();
            $examStatus = (string)($statusRow['status'] ?? '');
        }

        if (strcasecmp($examStatus, 'Running') !== 0) {
            echo json_encode(['success' => false, 'message' => 'This exam is not currently running.']);
            exit();
        }

        $submissionId = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);
        $now = gmdate('Y-m-d H:i:s');
        if ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("UPDATE exam_submissions SET status = 'In Progress', submitted_at = NULL, updated_at = :upd WHERE id = :id");
            $stmt->execute([':upd' => $now, ':id' => $submissionId]);
        } elseif ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("UPDATE exam_submissions SET status = 'In Progress', submitted_at = NULL, updated_at = ? WHERE id = ?");
            $stmt->bind_param("si", $now, $submissionId);
            $stmt->execute();
        }

        echo json_encode(['success' => true, 'status' => 'In Progress']);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: upload_answer_image (or upload_image, upload, etc.)
    // Uploads a picture/image file for an individual question or many questions
    // -------------------------------------------------------------
    $isUploadAction = in_array($action, [
        'upload_answer_image', 
        'upload_image', 
        'upload', 
        'upload_file', 
        'image_upload', 
        'upload_attachment', 
        'uploadanswerimage'
    ]) || (!empty($_FILES) && (empty($action) || strpos($action, 'upload') !== false));

    if ($isUploadAction) {
        $question_id = intval($_POST['question_id'] ?? $_GET['question_id'] ?? $_REQUEST['question_id'] ?? 0);
        if ($question_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid question ID for image attachment.']);
            exit();
        }

        $uploadedFile = $_FILES['image'] ?? $_FILES['file'] ?? $_FILES['answer_image'] ?? (count($_FILES) > 0 ? reset($_FILES) : null);

        if (!$uploadedFile || !isset($uploadedFile['tmp_name']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errCode = is_array($uploadedFile) ? ($uploadedFile['error'] ?? 'missing') : 'empty';
            echo json_encode(['success' => false, 'message' => 'No image file uploaded or upload error occurred (code: ' . $errCode . ').']);
            exit();
        }

        $fileTmp = $uploadedFile['tmp_name'];
        $fileName = basename($uploadedFile['name']);
        $fileSize = $uploadedFile['size'];

        // Size check (max 10MB)
        if ($fileSize > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Image file exceeds maximum allowed size (10MB).']);
            exit();
        }

        // Extension & mime check
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image format (' . htmlspecialchars($ext) . '). Allowed: PNG, JPG, JPEG, WEBP, GIF.']);
            exit();
        }

        // Verify with getimagesize
        $imgInfo = @getimagesize($fileTmp);
        if (!$imgInfo) {
            echo json_encode(['success' => false, 'message' => 'The uploaded file is not a valid image.']);
            exit();
        }

        // Target upload directory: php/assets/images/student_answers/
        $uploadSubdir = '/../assets/images/student_answers/';
        $targetDir = __DIR__ . $uploadSubdir;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        // Generate sanitized unique filename
        $safeStudentId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $logged_student_id);
        $newFileName = 'ans_exam' . $exam_id . '_q' . $question_id . '_' . $safeStudentId . '_' . time() . '_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
        $targetPath = $targetDir . $newFileName;

        if (!move_uploaded_file($fileTmp, $targetPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded image to server storage.']);
            exit();
        }

        // Relative URL stored in DB and accessible by browser
        $relativeUrl = '../assets/images/student_answers/' . $newFileName;

        // Auto-persist into student_answers table so image is securely saved immediately
        if ($dbConn) {
            $submission_id = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);
            $now = gmdate('Y-m-d H:i:s');

            // Retrieve current answer_image list if any
            $currentImages = [];
            if ($dbConn instanceof PDO) {
                $sel = $dbConn->prepare("SELECT answer_image FROM student_answers WHERE submission_id = :sub_id AND question_id = :qid LIMIT 1");
                $sel->execute([':sub_id' => $submission_id, ':qid' => $question_id]);
                $exRow = $sel->fetch(PDO::FETCH_ASSOC);
                if ($exRow && !empty($exRow['answer_image'])) {
                    $dec = json_decode($exRow['answer_image'], true);
                    $currentImages = is_array($dec) ? $dec : [$exRow['answer_image']];
                }
            } elseif ($dbConn instanceof mysqli) {
                $sel = $dbConn->prepare("SELECT answer_image FROM student_answers WHERE submission_id = ? AND question_id = ? LIMIT 1");
                $sel->bind_param("ii", $submission_id, $question_id);
                $sel->execute();
                $exRow = $sel->get_result()->fetch_assoc();
                if ($exRow && !empty($exRow['answer_image'])) {
                    $dec = json_decode($exRow['answer_image'], true);
                    $currentImages = is_array($dec) ? $dec : [$exRow['answer_image']];
                }
            }

            if (!in_array($relativeUrl, $currentImages)) {
                $currentImages[] = $relativeUrl;
            }
            $imagesJson = json_encode(array_values($currentImages), JSON_UNESCAPED_SLASHES);

            if ($dbConn instanceof PDO) {
                $upsert = $dbConn->prepare("
                    INSERT INTO student_answers 
                        (submission_id, exam_id, question_id, student_id, answer_image, created_at, updated_at)
                    VALUES 
                        (:sub_id, :eid, :qid, :sid, :ans_img, :cre, :upd)
                    ON DUPLICATE KEY UPDATE 
                        answer_image = VALUES(answer_image),
                        updated_at = VALUES(updated_at)
                ");
                $upsert->execute([
                    ':sub_id'  => $submission_id,
                    ':eid'     => $exam_id,
                    ':qid'     => $question_id,
                    ':sid'     => $logged_student_id,
                    ':ans_img' => $imagesJson,
                    ':cre'     => $now,
                    ':upd'     => $now
                ]);
            } elseif ($dbConn instanceof mysqli) {
                $upsert = $dbConn->prepare("
                    INSERT INTO student_answers 
                        (submission_id, exam_id, question_id, student_id, answer_image, created_at, updated_at)
                    VALUES 
                        (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        answer_image = VALUES(answer_image),
                        updated_at = VALUES(updated_at)
                ");
                $upsert->bind_param("iiissss", $submission_id, $exam_id, $question_id, $logged_student_id, $imagesJson, $now, $now);
                $upsert->execute();
            }
        }

        echo json_encode([
            'success'     => true,
            'question_id' => $question_id,
            'file_url'    => $relativeUrl,
            'file_name'   => $fileName,
            'all_images'  => $currentImages ?? [$relativeUrl],
            'uploaded_at' => date('H:i:s')
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: delete_answer_image
    // Deletes or detaches an image attached to a question
    // -------------------------------------------------------------
    if ($action === 'delete_answer_image' || $action === 'delete_image') {
        $question_id = intval($_POST['question_id'] ?? $_GET['question_id'] ?? $_REQUEST['question_id'] ?? 0);
        $file_url = trim($_POST['file_url'] ?? $_GET['file_url'] ?? $_REQUEST['file_url'] ?? '');

        if ($question_id > 0 && !empty($file_url) && $dbConn) {
            $submission_id = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);
            $now = gmdate('Y-m-d H:i:s');

            $currentImages = [];
            if ($dbConn instanceof PDO) {
                $sel = $dbConn->prepare("SELECT answer_image FROM student_answers WHERE submission_id = :sub_id AND question_id = :qid LIMIT 1");
                $sel->execute([':sub_id' => $submission_id, ':qid' => $question_id]);
                $exRow = $sel->fetch(PDO::FETCH_ASSOC);
                if ($exRow && !empty($exRow['answer_image'])) {
                    $dec = json_decode($exRow['answer_image'], true);
                    $currentImages = is_array($dec) ? $dec : [$exRow['answer_image']];
                }
            } elseif ($dbConn instanceof mysqli) {
                $sel = $dbConn->prepare("SELECT answer_image FROM student_answers WHERE submission_id = ? AND question_id = ? LIMIT 1");
                $sel->bind_param("ii", $submission_id, $question_id);
                $sel->execute();
                $exRow = $sel->get_result()->fetch_assoc();
                if ($exRow && !empty($exRow['answer_image'])) {
                    $dec = json_decode($exRow['answer_image'], true);
                    $currentImages = is_array($dec) ? $dec : [$exRow['answer_image']];
                }
            }

            // Remove target image
            $currentImages = array_filter($currentImages, function($img) use ($file_url) {
                return trim($img) !== trim($file_url);
            });
            $imagesJson = !empty($currentImages) ? json_encode(array_values($currentImages), JSON_UNESCAPED_SLASHES) : null;

            if ($dbConn instanceof PDO) {
                $upd = $dbConn->prepare("UPDATE student_answers SET answer_image = :ans_img, updated_at = :upd WHERE submission_id = :sub_id AND question_id = :qid");
                $upd->execute([
                    ':ans_img' => $imagesJson,
                    ':upd'     => $now,
                    ':sub_id'  => $submission_id,
                    ':qid'     => $question_id
                ]);
            } elseif ($dbConn instanceof mysqli) {
                $upd = $dbConn->prepare("UPDATE student_answers SET answer_image = ?, updated_at = ? WHERE submission_id = ? AND question_id = ?");
                $upd->bind_param("ssii", $imagesJson, $now, $submission_id, $question_id);
                $upd->execute();
            }

            // Clean up file if within our student_answers folder
            $fileName = basename($file_url);
            $localFile = __DIR__ . '/../assets/images/student_answers/' . $fileName;
            if (file_exists($localFile) && is_file($localFile)) {
                @unlink($localFile);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Image removed successfully.']);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: save_answer
    // Autosaves a single question response (MCQ, written text, and attached image(s))
    // -------------------------------------------------------------
    if ($action === 'save_answer') {
        $question_id = intval($_POST['question_id'] ?? 0);
        $selected_option = trim($_POST['selected_option'] ?? '');
        $answer_text = trim($_POST['answer_text'] ?? '');
        $rawImages = $_POST['answer_image'] ?? '';

        if ($question_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid question ID.']);
            exit();
        }

        // Format images JSON
        $imagesJson = null;
        if (!empty($rawImages)) {
            if (is_array($rawImages)) {
                $imagesJson = json_encode(array_values($rawImages), JSON_UNESCAPED_SLASHES);
            } else {
                $trimmed = trim($rawImages);
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $imagesJson = !empty($decoded) ? json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES) : null;
                } elseif (!empty($trimmed)) {
                    $imagesJson = json_encode([$trimmed], JSON_UNESCAPED_SLASHES);
                }
            }
        }

        if ($dbConn) {
            $submission_id = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);
            $now = gmdate('Y-m-d H:i:s');
            
            // Map selected_option to integer or null
            $optIdVal = !empty($selected_option) && is_numeric($selected_option) ? intval($selected_option) : null;
            $txtVal = !empty($answer_text) ? $answer_text : null;

            if ($dbConn instanceof PDO) {
                // Upsert into student_answers (unique on submission_id, question_id)
                $upsert = $dbConn->prepare("
                    INSERT INTO student_answers 
                        (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                    VALUES 
                        (:sub_id, :eid, :qid, :sid, :opt_id, :ans_txt, :ans_img, :cre, :upd)
                    ON DUPLICATE KEY UPDATE 
                        selected_option_id = VALUES(selected_option_id),
                        answer_text = VALUES(answer_text),
                        answer_image = VALUES(answer_image),
                        updated_at = VALUES(updated_at)
                ");
                $upsert->execute([
                    ':sub_id'  => $submission_id,
                    ':eid'     => $exam_id,
                    ':qid'     => $question_id,
                    ':sid'     => $logged_student_id,
                    ':opt_id'  => $optIdVal,
                    ':ans_txt' => $txtVal,
                    ':ans_img' => $imagesJson,
                    ':cre'     => $now,
                    ':upd'     => $now
                ]);
            } elseif ($dbConn instanceof mysqli) {
                $upsert = $dbConn->prepare("
                    INSERT INTO student_answers 
                        (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                    VALUES 
                        (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        selected_option_id = VALUES(selected_option_id),
                        answer_text = VALUES(answer_text),
                        answer_image = VALUES(answer_image),
                        updated_at = VALUES(updated_at)
                ");
                $upsert->bind_param("iiisissss", $submission_id, $exam_id, $question_id, $logged_student_id, $optIdVal, $txtVal, $imagesJson, $now, $now);
                $upsert->execute();
            }
        }

        echo json_encode([
            'success'     => true,
            'question_id' => $question_id,
            'saved_at'    => date('H:i:s')
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: save_all_answers
    // Autosaves entire answer palette (used on blur, periodic sync, beforeunload/redirect)
    // -------------------------------------------------------------
    if ($action === 'save_all_answers') {
        $answersJson = $_POST['answers_json'] ?? '';
        if (empty($answersJson)) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded) && isset($decoded['answers_json'])) {
                    $answersJson = is_string($decoded['answers_json']) ? $decoded['answers_json'] : json_encode($decoded['answers_json']);
                }
            }
        }

        $answers = json_decode($answersJson, true);
        $savedCount = 0;

        if ($dbConn && is_array($answers)) {
            $submission_id = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);
            $now = gmdate('Y-m-d H:i:s');

            foreach ($answers as $qId => $data) {
                $qIdInt = intval($qId);
                if ($qIdInt <= 0) continue;

                $optId = !empty($data['option']) && is_numeric($data['option']) ? intval($data['option']) : null;
                $txt   = !empty($data['text']) ? trim($data['text']) : null;
                $rawImg = $data['images'] ?? $data['answer_image'] ?? null;
                
                $imgJson = null;
                if (!empty($rawImg)) {
                    if (is_array($rawImg)) {
                        $imgJson = !empty($rawImg) ? json_encode(array_values($rawImg), JSON_UNESCAPED_SLASHES) : null;
                    } elseif (is_string($rawImg)) {
                        $dec = json_decode($rawImg, true);
                        $imgJson = is_array($dec) ? json_encode(array_values($dec), JSON_UNESCAPED_SLASHES) : json_encode([$rawImg], JSON_UNESCAPED_SLASHES);
                    }
                }

                if ($optId === null && $txt === null && $imgJson === null) continue;

                if ($dbConn instanceof PDO) {
                    $stmt = $dbConn->prepare("
                        INSERT INTO student_answers 
                            (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                        VALUES 
                            (:sub_id, :eid, :qid, :sid, :opt_id, :ans_txt, :ans_img, :cre, :upd)
                        ON DUPLICATE KEY UPDATE 
                            selected_option_id = VALUES(selected_option_id),
                            answer_text = VALUES(answer_text),
                            answer_image = VALUES(answer_image),
                            updated_at = VALUES(updated_at)
                    ");
                    $stmt->execute([
                        ':sub_id'  => $submission_id,
                        ':eid'     => $exam_id,
                        ':qid'     => $qIdInt,
                        ':sid'     => $logged_student_id,
                        ':opt_id'  => $optId,
                        ':ans_txt' => $txt,
                        ':ans_img' => $imgJson,
                        ':cre'     => $now,
                        ':upd'     => $now
                    ]);
                    $savedCount++;
                } elseif ($dbConn instanceof mysqli) {
                    $stmt = $dbConn->prepare("
                        INSERT INTO student_answers 
                            (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                        VALUES 
                            (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            selected_option_id = VALUES(selected_option_id),
                            answer_text = VALUES(answer_text),
                            answer_image = VALUES(answer_image),
                            updated_at = VALUES(updated_at)
                    ");
                    $stmt->bind_param("iiisissss", $submission_id, $exam_id, $qIdInt, $logged_student_id, $optId, $txt, $imgJson, $now, $now);
                    $stmt->execute();
                    $savedCount++;
                }
            }
        }

        echo json_encode([
            'success'     => true,
            'saved_count' => $savedCount,
            'timestamp'   => date('H:i:s')
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: submit_exam
    // Saves any incoming answers and marks student submission as Submitted (allows Revise if duration left)
    // -------------------------------------------------------------
    if ($action === 'submit_exam') {
        $now = gmdate('Y-m-d H:i:s');
        if ($dbConn) {
            $submission_id = getOrCreateSubmissionId($dbConn, $exam_id, $logged_student_id, $logged_student_name);

            // Also persist any answers payload passed during submit (e.g. from auto-submit or confirmation modal)
            $answersJson = $_POST['answers_json'] ?? '';
            if (empty($answersJson)) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput)) {
                    $decoded = json_decode($rawInput, true);
                    if (is_array($decoded) && isset($decoded['answers_json'])) {
                        $answersJson = is_string($decoded['answers_json']) ? $decoded['answers_json'] : json_encode($decoded['answers_json']);
                    }
                }
            }

            if (!empty($answersJson)) {
                $answers = json_decode($answersJson, true);
                if (is_array($answers)) {
                    foreach ($answers as $qId => $data) {
                        $qIdInt = intval($qId);
                        if ($qIdInt <= 0) continue;

                        $optId = !empty($data['option']) && is_numeric($data['option']) ? intval($data['option']) : null;
                        $txt   = !empty($data['text']) ? trim($data['text']) : null;
                        $rawImg = $data['images'] ?? $data['answer_image'] ?? null;
                        
                        $imgJson = null;
                        if (!empty($rawImg)) {
                            if (is_array($rawImg)) {
                                $imgJson = !empty($rawImg) ? json_encode(array_values($rawImg), JSON_UNESCAPED_SLASHES) : null;
                            } elseif (is_string($rawImg)) {
                                $dec = json_decode($rawImg, true);
                                $imgJson = is_array($dec) ? json_encode(array_values($dec), JSON_UNESCAPED_SLASHES) : json_encode([$rawImg], JSON_UNESCAPED_SLASHES);
                            }
                        }

                        if ($optId === null && $txt === null && $imgJson === null) continue;

                        if ($dbConn instanceof PDO) {
                            $stmt = $dbConn->prepare("
                                INSERT INTO student_answers 
                                    (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                                VALUES 
                                    (:sub_id, :eid, :qid, :sid, :opt_id, :ans_txt, :ans_img, :cre, :upd)
                                ON DUPLICATE KEY UPDATE 
                                    selected_option_id = VALUES(selected_option_id),
                                    answer_text = VALUES(answer_text),
                                    answer_image = VALUES(answer_image),
                                    updated_at = VALUES(updated_at)
                            ");
                            $stmt->execute([
                                ':sub_id'  => $submission_id,
                                ':eid'     => $exam_id,
                                ':qid'     => $qIdInt,
                                ':sid'     => $logged_student_id,
                                ':opt_id'  => $optId,
                                ':ans_txt' => $txt,
                                ':ans_img' => $imgJson,
                                ':cre'     => $now,
                                ':upd'     => $now
                            ]);
                        } elseif ($dbConn instanceof mysqli) {
                            $stmt = $dbConn->prepare("
                                INSERT INTO student_answers 
                                    (submission_id, exam_id, question_id, student_id, selected_option_id, answer_text, answer_image, created_at, updated_at)
                                VALUES 
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    selected_option_id = VALUES(selected_option_id),
                                    answer_text = VALUES(answer_text),
                                    answer_image = VALUES(answer_image),
                                    updated_at = VALUES(updated_at)
                            ");
                            $stmt->bind_param("iiisissss", $submission_id, $exam_id, $qIdInt, $logged_student_id, $optId, $txt, $imgJson, $now, $now);
                            $stmt->execute();
                        }
                    }
                }
            }

            if ($dbConn instanceof PDO) {
                $stmt = $dbConn->prepare("
                    UPDATE exam_submissions 
                    SET status = 'Submitted', submitted_at = :sub, updated_at = :upd
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':sub' => $now,
                    ':upd' => $now,
                    ':id'  => $submission_id
                ]);
            } elseif ($dbConn instanceof mysqli) {
                $stmt = $dbConn->prepare("UPDATE exam_submissions SET status = 'Submitted', submitted_at = ?, updated_at = ? WHERE id = ?");
                $stmt->bind_param("ssi", $now, $now, $submission_id);
                $stmt->execute();
            }
        }

        echo json_encode([
            'success'      => true,
            'message'      => 'Exam submitted successfully.',
            'exam_id'      => $exam_id,
            'submitted_at' => $now
        ]);
        exit();
    }

    // -------------------------------------------------------------
    // ACTION: check_exam_live_status
    // Checks if teacher held, resumed, or ended the exam
    // Note: column in exam_details is `duration`, NOT `exam_duration`!
    // -------------------------------------------------------------
    if ($action === 'check_exam_live_status') {
        $status = 'Running';
        $updatedAt = gmdate('Y-m-d H:i:s');
        $duration = 5;
        $remainingSeconds = $duration * 60;
        $updatedAtEpoch = 0;

        if ($dbConn) {
            if ($dbConn instanceof PDO) {
                // Check exam_header first (primary source of truth)
                $stmt = $dbConn->prepare("SELECT status, updated_at, duration, COALESCE(remaining_seconds, duration * 60) AS remaining_seconds, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = :eid LIMIT 1");
                $stmt->execute([':eid' => $exam_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    try {
                        $stmt = $dbConn->prepare("SELECT status, updated_at, duration, duration * 60 AS remaining_seconds, UNIX_TIMESTAMP(updated_at) AS timer_updated_at_epoch FROM exam_details WHERE id = :eid LIMIT 1");
                        $stmt->execute([':eid' => $exam_id]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {}
                }
                if ($row) {
                    $status    = $row['status'];
                    $updatedAt = $row['updated_at'];
                    $duration  = intval($row['duration']);
                    $remainingSeconds = intval($row['remaining_seconds'] ?? ($duration * 60));
                    $updatedAtEpoch = intval($row['timer_updated_at_epoch'] ?? 0);
                }
            } elseif ($dbConn instanceof mysqli) {
                $stmt = $dbConn->prepare("SELECT status, updated_at, duration, COALESCE(remaining_seconds, duration * 60) AS remaining_seconds, UNIX_TIMESTAMP(timer_updated_at) AS timer_updated_at_epoch FROM exam_header WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if (!$row) {
                    try {
                        $stmt = $dbConn->prepare("SELECT status, updated_at, duration, duration * 60 AS remaining_seconds, UNIX_TIMESTAMP(updated_at) AS timer_updated_at_epoch FROM exam_details WHERE id = ? LIMIT 1");
                        $stmt->bind_param("i", $exam_id);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                    } catch (Exception $e) {}
                }
                if ($row) {
                    $status    = $row['status'];
                    $updatedAt = $row['updated_at'];
                    $duration  = intval($row['duration']);
                    $remainingSeconds = intval($row['remaining_seconds'] ?? ($duration * 60));
                    $updatedAtEpoch = intval($row['timer_updated_at_epoch'] ?? 0);
                }
            }
        }

        $serverTimeMs = (int) round(microtime(true) * 1000);
        $effectiveRemaining = $remainingSeconds;
        if ($status === 'Running') {
            $effectiveRemaining = max(0, $remainingSeconds - max(0, intdiv($serverTimeMs, 1000) - $updatedAtEpoch));
        }
        if (($status === 'Running' || $status === 'Cancelled/On Hold') && $effectiveRemaining === 0) {
            $status = 'Completed';
        }

        if ($status === 'Completed' && $effectiveRemaining === 0) {
            finalizeExpiredExamSubmissions($dbConn, $exam_id);
        }

        echo json_encode([
            'success'     => true,
            'exam_id'     => $exam_id,
            'status'      => $status,
            'duration'    => $duration,
            'remaining_seconds' => $remainingSeconds,
            'updated_at'  => $updatedAt,
            'timer_updated_at_epoch' => $updatedAtEpoch,
            'server_time' => gmdate('Y-m-d H:i:s'),
            'server_time_ms' => $serverTimeMs
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . ($action ?: 'none')]);
    exit();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
