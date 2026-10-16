<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, ['admin', 'teacher', 'student'], true)) {
    if (!empty($_SESSION['admin_id'])) $role = 'admin';
    elseif (!empty($_SESSION['teacher_id'])) $role = 'teacher';
    elseif (!empty($_SESSION['student_id'])) $role = 'student';
}
if (!in_array($role, ['admin', 'teacher', 'student'], true)) {
    header('Location: ../../src/auth/login-page.php');
    exit();
}

require_once __DIR__ . '/../../src/config/config.php';
$dbConn = (isset($pdo) && $pdo instanceof PDO) ? $pdo : ((isset($conn) && $conn instanceof PDO) ? $conn : null);

function notificationEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function notificationTableExists(PDO $db, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return $cache[$table] = false;
    }
}

function notificationDate(string $value, string $timezone): ?DateTimeImmutable {
    if (trim($value) === '') return null;
    try { return new DateTimeImmutable($value, new DateTimeZone($timezone)); }
    catch (Throwable $error) { return null; }
}

function notificationMake(string $id, string $audience, string $category, string $severity, string $title, string $body, ?DateTimeImmutable $when, string $actionText, string $actionUrl, string $icon): array {
    return [
        'id' => $id,
        'audience' => $audience,
        'category' => $category,
        'severity' => $severity,
        'title' => $title,
        'body' => $body,
        'timestamp' => ($when ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'action_text' => $actionText,
        'action_url' => $actionUrl,
        'icon' => $icon
    ];
}

function notificationResolveActor(PDO $db, string $role): array {
    $userId = (string)($_SESSION['user_id'] ?? '');
    $email = trim((string)($_SESSION['email'] ?? ''));
    if ($role === 'student') {
        $sid = trim((string)($_SESSION['student_id'] ?? ''));
        $stmt = $db->prepare('SELECT id, student_id, full_name, email, batch FROM students WHERE student_id = ? OR id = ? OR email = ? LIMIT 1');
        $stmt->execute([$sid !== '' ? $sid : $userId, ctype_digit($userId) ? (int)$userId : 0, $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'teacher') {
        $tid = trim((string)($_SESSION['teacher_id'] ?? ''));
        $stmt = $db->prepare('SELECT id, teacher_id, full_name, email FROM teachers WHERE teacher_id = ? OR id = ? OR email = ? LIMIT 1');
        $stmt->execute([$tid !== '' ? $tid : $userId, ctype_digit($userId) ? (int)$userId : 0, $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'admin') {
        $aid = trim((string)($_SESSION['admin_id'] ?? ''));
        $stmt = $db->prepare('SELECT id, admin_id, full_name, email FROM admins WHERE admin_id = ? OR id = ? OR email = ? LIMIT 1');
        $stmt->execute([$aid !== '' ? $aid : $userId, ctype_digit($userId) ? (int)$userId : 0, $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return [];
}

function notificationIsoForSchedule(array $row): ?DateTimeImmutable {
    $dateTime = trim((string)($row['exam_date'] ?? '') . ' ' . (string)($row['exam_time'] ?? ''));
    return notificationDate($dateTime, 'Asia/Dhaka');
}

function notificationExamRemaining(array $exam, DateTimeImmutable $now): ?int {
    if (($exam['status'] ?? '') !== 'Running') return null;
    if (isset($exam['remaining_seconds']) && $exam['remaining_seconds'] !== null && isset($exam['timer_anchor_epoch']) && (int)$exam['timer_anchor_epoch'] > 0) {
        return max(0, (int)$exam['remaining_seconds'] - max(0, time() - (int)$exam['timer_anchor_epoch']));
    }
    $start = notificationIsoForSchedule($exam);
    if (!$start) return null;
    return max(0, (int)$start->modify('+' . max(0, (int)($exam['duration'] ?? 0)) . ' minutes')->getTimestamp() - $now->getTimestamp());
}

function notificationExamUrl(string $role, int $examId, bool $isRunning = false): string {
    if ($role === 'student') {
        return $isRunning
            ? '../student-portal/student-giveExam-page.php?exam_id=' . $examId
            : '../student-portal/student-examTable-page.php';
    }
    if ($role === 'teacher') return '../teacher-portal/teacher-inspectExam-page.php?exam_id=' . $examId;
    return 'notification-page.php?tab=system';
}

function notificationLoadExams(PDO $db, array $tables, ?string $teacherId = null, ?string $teacherDbId = null): array {
    if (!$tables['exams']) return [];
    try {
        $timerFields = '';
        if ($tables['timer_columns']) $timerFields = ', remaining_seconds, UNIX_TIMESTAMP(timer_updated_at) AS timer_anchor_epoch';
        $sql = 'SELECT id, teacher_id, teacher_name, batch, course_code, subject, exam_title, exam_type, exam_date, exam_time, duration, status, updated_at' . $timerFields . ' FROM exam_header';
        $params = [];
        if ($teacherId !== null) {
            $sql .= ' WHERE (teacher_id = ? OR teacher_id = ?)';
            $params[] = $teacherId;
            $params[] = $teacherDbId ?? '';
        }
        $sql .= ' ORDER BY exam_date DESC, exam_time DESC LIMIT 300';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) { return []; }
}

function notificationLoadSubmissions(PDO $db, array $tables, string $role, array $actor): array {
    if (!$tables['submissions'] || !$tables['exams']) return [];
    try {
        $evalJoin = $tables['evaluations'] ? 'LEFT JOIN exam_evaluations ev ON ev.submission_id = s.id' : '';
        $evalFields = $tables['evaluations'] ? "COALESCE(ev.status, 'Pending') AS evaluation_status, ev.evaluated_at, ev.total_marks_obtained" : "'Pending' AS evaluation_status, NULL AS evaluated_at, 0 AS total_marks_obtained";
        $sql = "SELECT s.id AS submission_id, s.exam_id, s.student_id, s.student_name, s.status AS submission_status,
                       s.submitted_at, e.teacher_id, e.teacher_name, e.batch, e.course_code, e.subject, e.exam_title,
                       e.status AS exam_status, {$evalFields}
                FROM exam_submissions s JOIN exam_header e ON e.id = s.exam_id {$evalJoin}";
        $params = [];
        if ($role === 'student') {
            $sql .= ' WHERE s.student_id = ?';
            $params[] = (string)($actor['student_id'] ?? '');
        } elseif ($role === 'teacher') {
            $sql .= ' WHERE (e.teacher_id = ? OR e.teacher_id = ?)';
            $params[] = (string)($actor['teacher_id'] ?? '');
            $params[] = (string)($actor['id'] ?? '');
        }
        $sql .= ' ORDER BY COALESCE(s.submitted_at, s.updated_at) DESC LIMIT 150';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) { return []; }
}

function notificationFeed(PDO $db, string $role, array $actor, array $tables): array {
    $events = [];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $scheduleNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));
    $teacherScope = $role === 'teacher' ? (string)($actor['teacher_id'] ?? '') : null;
    $exams = notificationLoadExams($db, $tables, $teacherScope, $role === 'teacher' ? (string)($actor['id'] ?? '') : null);

    if ($role === 'student' && $actor && $tables['enrollments']) {
        $enrollments = [];
        try {
            $stmt = $db->prepare("SELECT id, batch FROM student_enrollments WHERE student_id = ? AND status = 'Enrolled'");
            $stmt->execute([(int)$actor['id']]);
            $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {}

        $batchCodes = [];
        $enrollmentIds = array_values(array_filter(array_map(static fn($row) => (int)$row['id'], $enrollments)));
        if ($tables['enrollment_courses'] && $enrollmentIds) {
            $holders = implode(',', array_fill(0, count($enrollmentIds), '?'));
            try {
                $courseStmt = $db->prepare("SELECT enrollment_id, course_code FROM student_enrollment_courses WHERE enrollment_id IN ({$holders})");
                $courseStmt->execute($enrollmentIds);
                foreach ($courseStmt->fetchAll(PDO::FETCH_ASSOC) as $course) {
                    $batch = '';
                    foreach ($enrollments as $enrollment) if ((int)$enrollment['id'] === (int)$course['enrollment_id']) { $batch = (string)$enrollment['batch']; break; }
                    if ($batch !== '') $batchCodes[strtolower(trim($batch))][] = strtolower(trim((string)$course['course_code']));
                }
            } catch (Throwable $error) {}
        }

        $enrolledBatches = array_values(array_unique(array_map(static fn($row) => (string)$row['batch'], $enrollments)));
        $normalizedBatches = array_map(static fn($batch) => strtolower(trim((string)$batch)), $enrolledBatches);
        if (!$enrolledBatches && !empty($actor['batch'])) $enrolledBatches[] = (string)$actor['batch'];
        $allowedExams = [];
        foreach ($exams as $exam) {
            if (!in_array(strtolower(trim((string)$exam['batch'])), $normalizedBatches ?: array_map(static fn($batch) => strtolower(trim((string)$batch)), $enrolledBatches), true)) continue;
            $codes = $batchCodes[strtolower(trim((string)$exam['batch']))] ?? [];
            $examCode = strtolower(trim((string)($exam['course_code'] ?? '')));
            if ($codes && $examCode !== '' && !in_array($examCode, $codes, true)) continue;
            $allowedExams[(int)$exam['id']] = $exam;
        }
        $studentSubs = notificationLoadSubmissions($db, $tables, 'student', $actor);
        $subByExam = [];
        foreach ($studentSubs as $sub) $subByExam[(int)$sub['exam_id']] = $sub;

        foreach ($allowedExams as $examId => $exam) {
            $examStatus = (string)$exam['status'];
            $start = notificationIsoForSchedule($exam);
            $remaining = notificationExamRemaining($exam, $now);
            $sub = $subByExam[$examId] ?? null;
            if ($examStatus === 'Running' && $remaining === 0 && (!$sub || ($sub['submission_status'] ?? '') !== 'Submitted')) {
                $events[] = notificationMake('student-expired-' . $examId, 'student', 'critical', 'red', 'Exam time has expired',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. Open your exam list to check the final attempt status.', $now, 'View Exams', notificationExamUrl('student', $examId), 'fa-clock');
            } elseif ($examStatus === 'Running' && (!$sub || ($sub['submission_status'] ?? '') !== 'Submitted')) {
                $isUrgent = $remaining !== null && $remaining <= 300;
                $isCountdown = $remaining !== null && $remaining <= 900;
                $events[] = notificationMake('student-running-' . $examId, 'student', $isUrgent ? 'critical' : 'academic', $isUrgent ? 'red' : 'yellow', $isUrgent ? 'Exam window closing now' : ($isCountdown ? 'Exam window closes soon' : 'Exam is open'),
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ($remaining !== null ? ' · ' . ceil($remaining / 60) . ' min remaining' : '') . '. Open the exam page to continue.',
                    $remaining !== null ? $now->modify('+' . $remaining . ' seconds') : $now, 'Start Exam', notificationExamUrl('student', $examId, true), 'fa-file-pen');
            } elseif (in_array($examStatus, ['Published', 'Edited/Rescheduled'], true) && $start && $start >= $scheduleNow && $start <= $scheduleNow->modify('+30 days') && !$sub) {
                $secondsUntil = $start->getTimestamp() - $scheduleNow->getTimestamp();
                $critical = $secondsUntil <= 300;
                $events[] = notificationMake('student-upcoming-' . $examId, 'student', $critical ? 'critical' : 'academic', $critical ? 'red' : 'yellow', $critical ? 'Exam starts shortly' : 'Upcoming exam',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · ' . $start->format('d M Y, h:i A') . ' Asia/Dhaka.', $start, 'View Exam', notificationExamUrl('student', $examId), 'fa-calendar-days');
            } elseif (in_array($examStatus, ['Published', 'Edited/Rescheduled'], true) && $start && $start->modify('+' . max(0, (int)$exam['duration']) . ' minutes') < $scheduleNow && !$sub) {
                $events[] = notificationMake('student-missed-published-' . $examId, 'student', 'critical', 'red', 'Exam window passed without a submission',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. No submission record was found for your account.', $start->modify('+' . max(0, (int)$exam['duration']) . ' minutes'), 'View Exams', notificationExamUrl('student', $examId), 'fa-circle-exclamation');
            } elseif (in_array($examStatus, ['Cancelled/On Hold'], true)) {
                $events[] = notificationMake('student-hold-' . $examId, 'student', 'critical', 'red', 'Exam paused or cancelled',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. Check the exam page for the latest schedule.',
                    notificationDate((string)$exam['updated_at'], 'Asia/Dhaka'), 'View Exams', notificationExamUrl('student', $examId), 'fa-triangle-exclamation');
            } elseif ($examStatus === 'Completed' && !$sub) {
                $events[] = notificationMake('student-missed-' . $examId, 'student', 'critical', 'red', 'Exam window closed without a submission',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. No submission record was found for your account.',
                    notificationDate((string)$exam['updated_at'], 'Asia/Dhaka'), 'View Exams', notificationExamUrl('student', $examId), 'fa-circle-exclamation');
            }
        }

        foreach ($studentSubs as $sub) {
            if (($sub['submission_status'] ?? '') !== 'Submitted') continue;
            $submittedAt = notificationDate((string)($sub['submitted_at'] ?? ''), 'UTC');
            if (($sub['evaluation_status'] ?? '') === 'Graded') {
                $events[] = notificationMake('student-grade-' . $sub['submission_id'], 'student', 'academic', 'green', 'Grade published',
                    ($sub['exam_title'] ?: $sub['subject']) . ' · ' . number_format((float)$sub['total_marks_obtained'], 2) . ' marks. Your evaluated answer sheet is ready.',
                    notificationDate((string)($sub['evaluated_at'] ?? ''), 'UTC') ?? $submittedAt, 'View Result', '../student-portal/student-answerSheet-page.php?exam_id=' . (int)$sub['exam_id'] . '&submission_id=' . (int)$sub['submission_id'], 'fa-square-poll-vertical');
            } else {
                $events[] = notificationMake('student-submitted-' . $sub['submission_id'], 'student', 'academic', 'green', 'Exam submission received',
                    ($sub['exam_title'] ?: $sub['subject']) . ' · ' . $sub['batch'] . '. Evaluation status: ' . ($sub['evaluation_status'] ?: 'Pending') . '.',
                    $submittedAt, 'View Submission', '../student-portal/student-answerSheet-page.php?exam_id=' . (int)$sub['exam_id'] . '&submission_id=' . (int)$sub['submission_id'], 'fa-circle-check');
            }
        }
    }

    if ($role === 'teacher' && $actor) {
        $teacherSubs = notificationLoadSubmissions($db, $tables, 'teacher', $actor);
        foreach ($teacherSubs as $sub) {
            if (($sub['submission_status'] ?? '') !== 'Submitted' || ($sub['evaluation_status'] ?? 'Pending') === 'Graded') continue;
            $studentName = trim((string)($sub['student_name'] ?? '')) ?: 'Student ' . (string)$sub['student_id'];
            $events[] = notificationMake('teacher-grade-' . $sub['submission_id'], 'teacher', 'academic', 'yellow', 'Student submission needs grading',
                $studentName . ' submitted ' . ($sub['exam_title'] ?: $sub['subject']) . ' · ' . $sub['batch'] . '.', notificationDate((string)($sub['submitted_at'] ?? ''), 'UTC'), 'Grade Now',
                '../teacher-portal/teacher-answerSheet-page.php?exam_id=' . (int)$sub['exam_id'] . '&submission_id=' . (int)$sub['submission_id'] . '&student_id=' . rawurlencode((string)$sub['student_id']), 'fa-pen-to-square');
        }
        foreach ($exams as $exam) {
            $examId = (int)$exam['id'];
            $status = (string)$exam['status'];
            $start = notificationIsoForSchedule($exam);
            $remaining = notificationExamRemaining($exam, $now);
            if ($status === 'Running' && $remaining === 0) {
                $events[] = notificationMake('teacher-expired-' . $examId, 'teacher', 'critical', 'red', 'Exam timer expired',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. Verify that the exam has closed and review any pending attempts.', $now, 'Monitor Exam', notificationExamUrl('teacher', $examId, true), 'fa-clock');
            } elseif ($status === 'Running' && $remaining !== null && $remaining <= 900) {
                $critical = $remaining <= 300;
                $events[] = notificationMake('teacher-closing-' . $examId, 'teacher', $critical ? 'critical' : 'academic', $critical ? 'red' : 'yellow', $critical ? 'Exam window closing now' : 'Exam window closes soon',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · ' . ceil($remaining / 60) . ' min remaining.', $now->modify('+' . $remaining . ' seconds'), 'Monitor Exam', notificationExamUrl('teacher', $examId, true), 'fa-triangle-exclamation');
            } elseif (in_array($status, ['Published', 'Edited/Rescheduled'], true) && $start && $start >= $scheduleNow && $start <= $scheduleNow->modify('+30 days')) {
                $events[] = notificationMake('teacher-upcoming-' . $examId, 'teacher', 'academic', 'yellow', 'Upcoming exam',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · ' . $start->format('d M Y, h:i A') . ' Asia/Dhaka.', $start, 'Review Exam', notificationExamUrl('teacher', $examId), 'fa-calendar-days');
            } elseif ($status === 'Cancelled/On Hold') {
                $events[] = notificationMake('teacher-hold-' . $examId, 'teacher', 'critical', 'red', 'Exam is paused or cancelled',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . '. Check the schedule and update students if needed.', notificationDate((string)$exam['updated_at'], 'Asia/Dhaka'), 'Review Exam', notificationExamUrl('teacher', $examId), 'fa-triangle-exclamation');
            }
        }
    }

    if ($role === 'admin') {
        foreach ($exams as $exam) {
            $examId = (int)$exam['id'];
            $status = (string)$exam['status'];
            $start = notificationIsoForSchedule($exam);
            if ($status === 'Running') {
                $remaining = notificationExamRemaining($exam, $now);
                $expired = $remaining === 0;
                $urgent = $remaining !== null && $remaining <= 300;
                $countdown = $remaining !== null && $remaining <= 900;
                $events[] = notificationMake('admin-running-' . $examId, 'teacher', $urgent ? 'critical' : 'academic', $urgent ? 'red' : 'yellow', $expired ? 'Exam timer expired' : ($urgent ? 'Exam window closing now' : ($countdown ? 'Exam window closes soon' : 'Exam currently running')),
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · Teacher: ' . $exam['teacher_name'] . '.', $now, 'View Logs', 'notification-page.php?tab=system', 'fa-person-chalkboard');
            } elseif ($status === 'Cancelled/On Hold') {
                $events[] = notificationMake('admin-hold-' . $examId, 'student', 'critical', 'red', 'Exam paused or cancelled',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · Teacher: ' . $exam['teacher_name'] . '.', notificationDate((string)$exam['updated_at'], 'Asia/Dhaka'), 'View Logs', 'notification-page.php?tab=system', 'fa-triangle-exclamation');
            } elseif (in_array($status, ['Published', 'Edited/Rescheduled'], true) && $start && $start >= $scheduleNow && $start <= $scheduleNow->modify('+7 days')) {
                $events[] = notificationMake('admin-upcoming-' . $examId, 'student', 'academic', 'yellow', 'Upcoming exam scheduled',
                    ($exam['exam_title'] ?: $exam['subject']) . ' · ' . $exam['batch'] . ' · Teacher: ' . $exam['teacher_name'] . '.', $start, 'View Logs', 'notification-page.php?tab=system', 'fa-calendar-days');
            }
        }

        foreach (notificationLoadSubmissions($db, $tables, 'admin', $actor) as $sub) {
            if (($sub['submission_status'] ?? '') !== 'Submitted') continue;
            $studentName = trim((string)($sub['student_name'] ?? '')) ?: 'Student ' . (string)$sub['student_id'];
            if (($sub['evaluation_status'] ?? 'Pending') === 'Graded') {
                $events[] = notificationMake('admin-grade-' . $sub['submission_id'], 'student', 'academic', 'green', 'Grade published',
                    $studentName . ' · ' . ($sub['exam_title'] ?: $sub['subject']) . ' · ' . number_format((float)$sub['total_marks_obtained'], 2) . ' marks.', notificationDate((string)($sub['evaluated_at'] ?? ''), 'UTC'), 'View Logs', 'notification-page.php?tab=system', 'fa-square-poll-vertical');
            } else {
                $events[] = notificationMake('admin-pending-grade-' . $sub['submission_id'], 'teacher', 'academic', 'yellow', 'Submission pending evaluation',
                    $studentName . ' · ' . ($sub['exam_title'] ?: $sub['subject']) . ' · ' . $sub['teacher_name'] . '.', notificationDate((string)($sub['submitted_at'] ?? ''), 'UTC'), 'View Logs', 'notification-page.php?tab=system', 'fa-pen-to-square');
            }
        }

        if ($tables['deletion_logs']) {
            try {
                foreach ($db->query('SELECT batch_name, deleted_by, deleted_at FROM batch_deletion_logs ORDER BY deleted_at DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC) as $log) {
                    $events[] = notificationMake('log-batch-' . sha1((string)$log['batch_name'] . (string)$log['deleted_at']), 'admin', 'system', 'yellow', 'Batch record deleted',
                        'Batch ' . $log['batch_name'] . ' was deleted by ' . $log['deleted_by'] . '.', notificationDate((string)$log['deleted_at'], 'Asia/Dhaka'), 'View Logs', 'notification-page.php?tab=system', 'fa-box-archive');
                }
            } catch (Throwable $error) {}
        }
        if ($tables['users']) {
            try {
                foreach ($db->query("SELECT name, role, registration_date FROM `users-table` ORDER BY registration_date DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $user) {
                    $events[] = notificationMake('log-user-' . sha1((string)$user['name'] . (string)$user['registration_date']), 'admin', 'system', 'green', 'Account registered',
                        $user['name'] . ' registered with the ' . $user['role'] . ' role.', notificationDate((string)$user['registration_date'], 'Asia/Dhaka'), 'View Logs', 'notification-page.php?tab=system', 'fa-user-plus');
                }
            } catch (Throwable $error) {}
        }
        if (!$tables['proctor_participants'] || !$tables['proctor_signals']) {
            $events[] = notificationMake('log-proctor-schema', 'admin', 'system', 'yellow', 'Proctoring presence is not fully configured',
                'The current database is missing one or more live proctoring tables. Candidate webcam presence may not be available.', $now, 'View Logs', 'notification-page.php?tab=system', 'fa-video-slash');
        }
    }

    usort($events, static function (array $a, array $b): int {
        return (strtotime((string)$b['timestamp']) ?: 0) <=> (strtotime((string)$a['timestamp']) ?: 0);
    });
    return array_slice($events, 0, 150);
}

if (!$dbConn) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$tableMap = [
    'admins' => 'admins', 'students' => 'students', 'teachers' => 'teachers', 'users' => 'users-table',
    'exams' => 'exam_header', 'submissions' => 'exam_submissions', 'evaluations' => 'exam_evaluations',
    'enrollments' => 'student_enrollments', 'enrollment_courses' => 'student_enrollment_courses',
    'proctor_participants' => 'exam_proctor_participants', 'proctor_signals' => 'exam_proctor_signals',
    'deletion_logs' => 'batch_deletion_logs'
];
$tables = [];
foreach ($tableMap as $key => $name) $tables[$key] = notificationTableExists($dbConn, $name);
$tables['timer_columns'] = false;
if ($tables['exams']) {
    try {
        $columnStmt = $dbConn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'exam_header' AND column_name IN ('remaining_seconds','timer_updated_at')");
        $timerColumns = $columnStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tables['timer_columns'] = count($timerColumns) === 2;
    } catch (Throwable $error) {}
}

try { $actor = notificationResolveActor($dbConn, $role); }
catch (Throwable $error) { $actor = []; }
$actorName = trim((string)($actor['full_name'] ?? $_SESSION[$role . '_name'] ?? '')) ?: ucfirst($role);
$notifications = notificationFeed($dbConn, $role, $actor, $tables);

if (($_GET['ajax'] ?? '') === 'feed') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(['role' => $role, 'notifications' => $notifications], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit();
}

$roleLabels = ['student' => 'Student', 'teacher' => 'Teacher', 'admin' => 'Admin'];
$visibleTabs = ['all', 'critical'];
if ($role === 'student' || $role === 'teacher') $visibleTabs[] = 'academic';
if ($role === 'admin') $visibleTabs[] = 'system';
$requestedTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($requestedTab, $visibleTabs, true)) $requestedTab = 'all';
$bootstrap = json_encode(['role' => $role, 'tab' => $requestedTab, 'notifications' => $notifications], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/common/notification-page.css">
</head>
<body>
<main class="notification-app" data-feed-url="notification-page.php?ajax=feed" data-role="<?= notificationEscape($role) ?>">


     


    <header class="notification-header">
        <div class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></div>
        <div class="header-copy"><p class="eyebrow">Virtual Exam System</p><h1>Notifications</h1><p>Updates and actions for your <?= notificationEscape($roleLabels[$role]) ?> account.</p></div>
        <div class="header-user"><span class="role-pill role-<?= notificationEscape($role) ?>"><?= notificationEscape($roleLabels[$role]) ?></span><strong><?= notificationEscape($actorName) ?></strong><a href="../<?= notificationEscape($role) ?>-portal/<?= notificationEscape($role) ?>-dashboard-page.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a></div>
    </header>

    <nav class="notification-tabs" role="tablist" aria-label="Notification categories">
        <button type="button" class="notification-tab <?= $requestedTab === 'all' ? 'active' : '' ?>" data-tab="all" role="tab" aria-selected="<?= $requestedTab === 'all' ? 'true' : 'false' ?>"><i class="fa-solid fa-inbox"></i> All <span class="tab-count" data-count="all">0</span></button>
        <button type="button" class="notification-tab <?= $requestedTab === 'critical' ? 'active' : '' ?>" data-tab="critical" role="tab" aria-selected="<?= $requestedTab === 'critical' ? 'true' : 'false' ?>"><i class="fa-solid fa-triangle-exclamation"></i> Critical <span class="tab-count" data-count="critical">0</span></button>
        <?php if ($role === 'student' || $role === 'teacher'): ?><button type="button" class="notification-tab <?= $requestedTab === 'academic' ? 'active' : '' ?>" data-tab="academic" role="tab" aria-selected="<?= $requestedTab === 'academic' ? 'true' : 'false' ?>"><i class="fa-solid fa-book-open"></i> Academic <span class="tab-count" data-count="academic">0</span></button><?php endif; ?>
        <?php if ($role === 'admin'): ?><button type="button" class="notification-tab <?= $requestedTab === 'system' ? 'active' : '' ?>" data-tab="system" role="tab" aria-selected="<?= $requestedTab === 'system' ? 'true' : 'false' ?>"><i class="fa-solid fa-terminal"></i> System Logs <span class="tab-count" data-count="system">0</span></button><?php endif; ?>
    </nav>

    <?php if ($role === 'admin'): ?>
    <div class="audience-filter" role="group" aria-label="Filter notifications by audience">
        <span>Audience</span>
        <?php foreach (['all' => 'All roles', 'student' => 'Students', 'teacher' => 'Teachers', 'admin' => 'Admins'] as $audience => $label): ?><button type="button" class="audience-button <?= $audience === 'all' ? 'active' : '' ?>" data-audience-filter="<?= $audience ?>"><?= $label ?></button><?php endforeach; ?>
    </div>
    <p class="schema-note">System Logs are assembled from records the schema keeps, such as account registrations and batch deletions. Server crash logs and automated AI proctor flags are not stored by the current schema.</p>
    <?php endif; ?>

    <section class="feed-toolbar"><div><strong id="feedHeading">All notifications</strong><span id="feedDescription">Your latest exam and account activity.</span></div><span class="refresh-label"><i class="fa-solid fa-rotate"></i> Updates automatically</span></section>
    <section id="notificationFeed" class="notification-feed" aria-live="polite" aria-busy="false"></section>
    <footer class="notification-footer"><span id="lastUpdated">Loading latest updates…</span><span>Times are relative; exam schedules follow Asia/Dhaka. Feed items are derived from the current database state.</span></footer>
</main>
<script>window.notificationBootstrap = <?= $bootstrap ?: '{}' ?>;</script>
<script src="../assets/js/common/notification-page.js"  ></script>
</body>
</html>
