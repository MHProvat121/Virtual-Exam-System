<?php
// student-examTable-page-logic.php
// Backend API handler for student exam table, live countdown sync, and submission status checks

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$dbConn = $conn ?? $pdo ?? null;
$examTimezone = new DateTimeZone('Asia/Dhaka');

// Resolve logged-in student info
$session_uid       = $_SESSION['user_id'] ?? 0;
$logged_student_id = $_SESSION['student_id'] ?? ($session_uid ? strval($session_uid) : '');
$logged_batch      = $_SESSION['student_batch'] ?? $_SESSION['batch'] ?? '';

// If student ID or batch is missing in session, attempt to resolve from `students` table
if ($dbConn && (empty($logged_batch) || empty($logged_student_id))) {
    try {
        if ($dbConn instanceof PDO) {
            $s_stmt = $dbConn->prepare("
                SELECT id, student_id, batch 
                FROM students 
                WHERE id = :uid OR student_id = :sid OR email = :email 
                LIMIT 1
            ");
            $s_stmt->execute([
                ':uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
                ':sid'   => strval($logged_student_id),
                ':email' => strval($_SESSION['email'] ?? '')
            ]);
            $stu = $s_stmt->fetch(PDO::FETCH_ASSOC);
            if ($stu) {
                if (!empty($stu['student_id'])) $logged_student_id = $stu['student_id'];
                if (!empty($stu['batch']))      $logged_batch = $stu['batch'];
                $_SESSION['student_id'] = $logged_student_id;
                $_SESSION['batch']      = $logged_batch;
            }
        } elseif ($dbConn instanceof mysqli) {
            $s_stmt = $dbConn->prepare("
                SELECT id, student_id, batch 
                FROM students 
                WHERE id = ? OR student_id = ? OR email = ? 
                LIMIT 1
            ");
            $uidVal = is_numeric($session_uid) ? intval($session_uid) : 0;
            $sidVal = strval($logged_student_id);
            $emVal  = strval($_SESSION['email'] ?? '');
            $s_stmt->bind_param("iss", $uidVal, $sidVal, $emVal);
            $s_stmt->execute();
            $stu = $s_stmt->get_result()->fetch_assoc();
            if ($stu) {
                if (!empty($stu['student_id'])) $logged_student_id = $stu['student_id'];
                if (!empty($stu['batch']))      $logged_batch = $stu['batch'];
                $_SESSION['student_id'] = $logged_student_id;
                $_SESSION['batch']      = $logged_batch;
            }
        }
    } catch (Exception $e) {
        error_log("Student logic profile fetch error: " . $e->getMessage());
    }
}

// Fetch enrolled batches and course codes from student_enrollments where status = 'Enrolled'
$enrolledBatches = [];
$enrolledCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];
$studentEnrollments = [];

if ($dbConn) {
    try {
        if ($dbConn instanceof PDO) {
            $stmtEnr = $dbConn->prepare("
                SELECT se.* 
                FROM `student_enrollments` se 
                JOIN `students` st ON (se.student_id = st.id OR CAST(se.student_id AS CHAR) = CAST(st.id AS CHAR) OR CAST(se.student_id AS CHAR) = st.student_id)
                WHERE (st.id = :uid OR st.student_id = :sid OR st.email = :email) 
                ORDER BY se.id DESC
            ");
            $stmtEnr->execute([
                ':uid'   => is_numeric($session_uid) ? intval($session_uid) : 0,
                ':sid'   => strval($logged_student_id),
                ':email' => strval($_SESSION['email'] ?? '')
            ]);
            $enrIds = [];
            $enrollmentMapById = [];
            while ($row = $stmtEnr->fetch(PDO::FETCH_ASSOC)) {
                $b = $row['batch'];
                $enrollmentMapById[$row['id']] = $row;
                if (!isset($studentEnrollments[$b])) {
                    $studentEnrollments[$b] = $row;
                }
                if ($row['status'] === 'Enrolled' && ($row['withdraw_status'] ?? '') !== 'Withdrawn') {
                    $enrolledBatches[] = $b;
                }
                if (!empty($row['id'])) {
                    $enrIds[$row['id']] = $b;
                }
            }
            $enrolledBatches = array_values(array_unique(array_filter(array_map('trim', $enrolledBatches))));

            if (!empty($enrIds)) {
                $idList = implode(',', array_map('intval', array_keys($enrIds)));
                try {
                    $stmtEC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_enrollment_courses` WHERE `enrollment_id` IN ($idList)");
                    while ($eRow = $stmtEC->fetch(PDO::FETCH_ASSOC)) {
                        $eid = (int)$eRow['enrollment_id'];
                        $bName = $enrIds[$eid] ?? '';
                        $rec = $enrollmentMapById[$eid] ?? [];
                        if ($bName && ($rec['status'] ?? '') === 'Enrolled') {
                            $enrolledCourseCodesByBatch[$bName][] = $eRow['course_code'];
                        }
                    }
                } catch (PDOException $e) {}

                try {
                    $stmtWC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_withdrawal_courses` WHERE `enrollment_id` IN ($idList)");
                    while ($wRow = $stmtWC->fetch(PDO::FETCH_ASSOC)) {
                        $eid = (int)$wRow['enrollment_id'];
                        $bName = $enrIds[$eid] ?? '';
                        $rec = $enrollmentMapById[$eid] ?? [];
                        if ($bName && ($rec['withdraw_status'] ?? '') === 'Withdrawn') {
                            $withdrawnCourseCodesByBatch[$bName][] = $wRow['course_code'];
                        }
                    }
                } catch (PDOException $e) {}

                foreach ($withdrawnCourseCodesByBatch as $bKey => $wCodes) {
                    if (!empty($enrolledCourseCodesByBatch[$bKey])) {
                        $enrolledCourseCodesByBatch[$bKey] = array_values(array_diff($enrolledCourseCodesByBatch[$bKey], $wCodes));
                    }
                }
            }
        } elseif ($dbConn instanceof mysqli) {
            $stmtEnr = $dbConn->prepare("
                SELECT DISTINCT se.batch 
                FROM `student_enrollments` se 
                JOIN `students` st ON se.student_id = st.id 
                WHERE (st.id = ? OR st.student_id = ? OR st.email = ?) 
                  AND se.status = 'Enrolled'
            ");
            $uIdVal = is_numeric($session_uid) ? intval($session_uid) : 0;
            $sidVal = strval($logged_student_id);
            $emVal  = strval($_SESSION['email'] ?? '');
            $stmtEnr->bind_param("iss", $uIdVal, $sidVal, $emVal);
            $stmtEnr->execute();
            $res = $stmtEnr->get_result();
            while ($r = $res->fetch_assoc()) {
                $enrolledBatches[] = $r['batch'];
            }
        }
    } catch (Exception $e) {
        error_log("Enrolled batches fetch error: " . $e->getMessage());
    }
}

// Resolve batch names case-insensitively, matching the database's usual
// case-insensitive collation before using them as PHP array keys.
$enrolledBatchKeyByNormalized = [];
foreach ($enrolledBatches as $enrolledBatchName) {
    $enrolledBatchKeyByNormalized[strtolower(trim($enrolledBatchName))] = $enrolledBatchName;
}

// If requested via AJAX
$action = $_REQUEST['action'] ?? '';

if ($action === 'get_student_exams') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$dbConn || empty($enrolledBatches)) {
        echo json_encode([
            'success'          => true,
            'server_time'      => date('Y-m-d H:i:s'),
            'server_time_ms'   => (int) round(microtime(true) * 1000),
            'student_id'       => $logged_student_id,
            'enrolled_batches' => $enrolledBatches,
            'exams'            => []
        ]);
        exit();
    }

    try {
        $exams = [];
        if ($dbConn instanceof PDO) {
            $inClause = implode(',', array_fill(0, count($enrolledBatches), '?'));
            $sql = "
                SELECT e.id,
                       e.teacher_id,
                       e.teacher_name,
                       e.batch,
                       e.course_code,
                       e.subject,
                       e.exam_title,
                       e.exam_type,
                       e.exam_date,
                       e.exam_time,
                       e.duration,
                       COALESCE(e.marks, 0) AS total_marks,
                       e.marks,
                       e.status,
                       e.created_at,
                       e.updated_at,
                       UNIX_TIMESTAMP(e.updated_at) AS updated_at_epoch,
                       UNIX_TIMESTAMP(e.timer_updated_at) AS timer_updated_at_epoch,
                       e.remaining_seconds,
                       s.id AS submission_id,
                       s.status AS submission_status, 
                       s.submitted_at, 
                       COALESCE(ev.total_marks_obtained, 0) AS total_marks_obtained
                FROM exam_header e
                LEFT JOIN exam_submissions s 
                       ON e.id = s.exam_id AND (s.student_id = ? OR s.student_id = ?)
                LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id
                WHERE e.batch IN ($inClause)
                ORDER BY e.id DESC
            ";
            $params = array_merge([strval($logged_student_id), strval($session_uid)], $enrolledBatches);
            $stmt = $dbConn->prepare($sql);
            $stmt->execute($params);
            $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Filter exams to ONLY those whose course status is "Enrolled"
        $batchCoursesMap = [];
        if (!empty($enrolledBatches) && $dbConn) {
            try {
                $inBC = implode(',', array_fill(0, count($enrolledBatches), '?'));
                $stmtBC = $dbConn->prepare("
                    SELECT DISTINCT batch, course_code, subject, exam_title, credit, teacher_id 
                    FROM `batches` 
                    WHERE batch IN ($inBC)
                ");
                $stmtBC->execute($enrolledBatches);
                while ($bc = $stmtBC->fetch(PDO::FETCH_ASSOC)) {
                    $normalizedBatch = strtolower(trim($bc['batch'] ?? ''));
                    $batchKey = $enrolledBatchKeyByNormalized[$normalizedBatch] ?? ($bc['batch'] ?? '');
                    $batchCoursesMap[$batchKey][] = $bc;
                }
            } catch (Exception $e) {}
        }

        $filteredExams = [];
        foreach ($exams as $row) {
            $normalizedBatch = strtolower(trim($row['batch'] ?? ''));
            $b = $enrolledBatchKeyByNormalized[$normalizedBatch] ?? ($row['batch'] ?? '');
            $coursesInBatch = $batchCoursesMap[$b] ?? [];
            $matchedCourse = null;
            $exSub   = trim($row['subject'] ?? '');
            $exTitle = trim($row['exam_title'] ?? '');

            foreach ($coursesInBatch as $c) {
                $cCode  = trim($c['course_code'] ?? '');
                $cSub   = trim($c['subject'] ?? '');
                $cTitle = trim($c['exam_title'] ?? '');

                if ($cSub !== '' && strcasecmp($cSub, $exSub) === 0) {
                    $matchedCourse = $c;
                    break;
                }
                if ($cCode !== '' && (strcasecmp($cCode, $exSub) === 0 || strcasecmp($cCode, $exTitle) === 0)) {
                    $matchedCourse = $c;
                    break;
                }
                if ($cTitle !== '' && strcasecmp($cTitle, $exTitle) === 0) {
                    $matchedCourse = $c;
                    break;
                }
            }

            if (!$matchedCourse) {
                foreach ($coursesInBatch as $c) {
                    $cCode  = trim($c['course_code'] ?? '');
                    $cSub   = trim($c['subject'] ?? '');
                    $cTitle = trim($c['exam_title'] ?? '');

                    if ($cSub !== '' && (stripos($exSub, $cSub) !== false || stripos($exTitle, $cSub) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                    if ($cCode !== '' && (stripos($exSub, $cCode) !== false || stripos($exTitle, $cCode) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                    if ($cTitle !== '' && (stripos($exTitle, $cTitle) !== false || stripos($exSub, $cTitle) !== false)) {
                        $matchedCourse = $c;
                        break;
                    }
                }
            }

            if (!$matchedCourse && count($coursesInBatch) === 1) {
                $matchedCourse = $coursesInBatch[0];
            }

            // The saved exam course code is the authoritative link to the
            // student's enrolled course; older rows are backfilled by migration.
            $examCourseCode = trim($row['course_code'] ?? '');
            if ($examCourseCode !== '') {
                $matchedCourse = ['course_code' => $examCourseCode];
                foreach ($coursesInBatch as $courseCandidate) {
                    if (strcasecmp(trim($courseCandidate['course_code'] ?? ''), $examCourseCode) === 0) {
                        $matchedCourse = $courseCandidate;
                        break;
                    }
                }
            }

            $matchedCourseCode = trim($matchedCourse['course_code'] ?? '');
            $batchEnrolledList  = $enrolledCourseCodesByBatch[$b] ?? [];
            $batchWithdrawnList = $withdrawnCourseCodesByBatch[$b] ?? [];
            $hasEnrolledList    = !empty($batchEnrolledList);
            $enrDetails         = $studentEnrollments[$b] ?? [];

            $isThisCourseWithdrawn = false;
            if (!empty($batchWithdrawnList)) {
                if ($matchedCourseCode !== '') {
                    foreach ($batchWithdrawnList as $wc) {
                        if (strcasecmp(trim($wc), $matchedCourseCode) === 0) {
                            $isThisCourseWithdrawn = true;
                            break;
                        }
                    }
                }
                if (!$isThisCourseWithdrawn && $exSub !== '') {
                    foreach ($batchWithdrawnList as $wc) {
                        if (strcasecmp(trim($wc), $exSub) === 0) {
                            $isThisCourseWithdrawn = true;
                            break;
                        }
                    }
                }
            } else {
                $isThisCourseWithdrawn = (($enrDetails['withdraw_status'] ?? 'None') === 'Withdrawn');
            }

            $isThisCourseEnrolled = false;
            if ($isThisCourseWithdrawn) {
                $isThisCourseEnrolled = false;
            } elseif ($hasEnrolledList) {
                if ($matchedCourseCode !== '') {
                    foreach ($batchEnrolledList as $ec) {
                        if (strcasecmp(trim($ec), $matchedCourseCode) === 0) {
                            $isThisCourseEnrolled = true;
                            break;
                        }
                    }
                }
                if (!$isThisCourseEnrolled && $exSub !== '') {
                    foreach ($batchEnrolledList as $ec) {
                        if (strcasecmp(trim($ec), $exSub) === 0 || stripos($exSub, trim($ec)) !== false || stripos($exTitle, trim($ec)) !== false) {
                            $isThisCourseEnrolled = true;
                            break;
                        }
                    }
                }
            } else {
                $isThisCourseEnrolled = (($enrDetails['status'] ?? '') === 'Enrolled');
            }

            if ($isThisCourseEnrolled && !$isThisCourseWithdrawn) {
                $row['course_code']          = $matchedCourseCode;
                $row['course_enroll_status'] = 'Enrolled';
                if (empty($row['teacher_id']) && !empty($matchedCourse['teacher_id'])) {
                    $row['teacher_id'] = $matchedCourse['teacher_id'];
                }
                $filteredExams[] = $row;
            }
        }
        $exams = $filteredExams;

        // Process exams to respect rescheduled, active, and completed state
        $serverNowMs = (int) round(microtime(true) * 1000);
        $serverNow = intdiv($serverNowMs, 1000);
        foreach ($exams as &$ex) {
            $cStatus = $ex['status'] ?? 'Published';
            $isRescheduled = (strcasecmp($cStatus, 'Edited/Rescheduled') === 0 || stripos($cStatus, 'Rescheduled') !== false);
            $ex['is_rescheduled'] = $isRescheduled ? 1 : 0;

            $subStatus = $ex['submission_status'] ?? '';
            $ex['is_submitted'] = ($subStatus === 'Submitted' || $subStatus === 'Under Review' || $subStatus === 'Graded') ? 1 : 0;

            // Check timing
            $rawDate = trim($ex['exam_date'] ?? '');
            $rawTime = trim($ex['exam_time'] ?? '');
            $start_dt = ($rawDate !== '' ? $rawDate : (new DateTimeImmutable('now', $examTimezone))->format('Y-m-d'))
                . ($rawTime !== '' ? ' ' . $rawTime : '');
            try {
                $startTs = $rawTime !== '' ? (new DateTimeImmutable($start_dt, $examTimezone))->getTimestamp() : 0;
            } catch (Exception $e) {
                $startTs = 0;
            }
            $durSecs = intval($ex['duration'] ?? 0) * 60;
            $timerUpdatedAtEpoch = (int)($ex['timer_updated_at_epoch'] ?? 0);
            $remainingSeconds = max(0, (int)($ex['remaining_seconds'] ?? $durSecs));

            $isOnHold = (strcasecmp($cStatus, 'Cancelled/On Hold') === 0);
            $isCompletedStatus = (strcasecmp($cStatus, 'Completed') === 0);
            $isRunning = (strcasecmp($cStatus, 'Running') === 0);
            // Legacy teacher status updates changed updated_at but did not
            // set timer_updated_at. Use that timestamp as a compatibility anchor.
            if ($isRunning && $timerUpdatedAtEpoch <= 0) {
                $timerUpdatedAtEpoch = (int)($ex['updated_at_epoch'] ?? 0);
            }
            if ($isRunning) {
                $remainingSeconds = max(0, $remainingSeconds - max(0, $serverNow - $timerUpdatedAtEpoch));
            }
            $isCompleted = ($isCompletedStatus || (($isRunning || $isOnHold) && $remainingSeconds === 0));

            $ex['is_completed'] = $isCompleted ? 1 : 0;
            $ex['is_on_hold'] = $isOnHold ? 1 : 0;
            $ex['is_running'] = (!$isCompleted && !$isOnHold && $isRunning) ? 1 : 0;
            $ex['start_timestamp'] = $startTs;
            $ex['remaining_seconds'] = (int)($ex['remaining_seconds'] ?? $durSecs);
            $ex['timer_updated_at_epoch'] = $timerUpdatedAtEpoch;

            if ($isCompleted) {
                $ex['status'] = 'Completed';
            }
        }
        unset($ex);

        echo json_encode([
            'success'          => true,
            'server_time'      => date('Y-m-d H:i:s'),
            'server_time_ms'   => $serverNowMs,
            'student_id'       => $logged_student_id,
            'enrolled_batches' => $enrolledBatches,
            'exams'            => $exams
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}
