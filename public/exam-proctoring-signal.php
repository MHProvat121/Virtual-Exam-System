<?php
// Authenticated WebRTC signaling. Audio/video media stays peer-to-peer and is never stored here.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: '', true);
$body = is_array($body) ? $body : [];
$action = trim((string)($body['action'] ?? $_REQUEST['action'] ?? ''));
$examId = (int)($body['exam_id'] ?? $_REQUEST['exam_id'] ?? 0);
$role = trim((string)($body['role'] ?? $_REQUEST['role'] ?? ''));
$peerId = trim((string)($body['peer_id'] ?? $_REQUEST['peer_id'] ?? ''));

$userId = (string)($_SESSION['user_id'] ?? '');
$teacherSessionId = (string)($_SESSION['teacher_id'] ?? '');
$studentSessionId = (string)($_SESSION['student_id'] ?? '');
if ($userId === '' && $teacherSessionId === '' && $studentSessionId === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to use exam proctoring.']);
    exit;
}
// Release the PHP session lock so the polling and heartbeat requests can run side-by-side.
session_write_close();

if (file_exists(__DIR__ . '/../src/config/config.php')) {
    require_once __DIR__ . '/../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$db = $conn ?? $pdo ?? null;
if (!$db || $examId <= 0 || !in_array($role, ['teacher', 'student'], true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $peerId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid exam proctoring request.']);
    exit;
}

function proctorStatement($db, string $sql, array $params = []) {
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Database query could not be prepared.');
        if ($params) {
            $values = array_values($params);
            $types = str_repeat('s', count($values));
            $refs = [&$types];
            foreach ($values as $index => $value) $refs[] = &$values[$index];
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        return $stmt;
    }
    throw new RuntimeException('Unsupported database connection.');
}
function proctorRows($stmt): array {
    if ($stmt instanceof PDOStatement) return $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
function proctorRow($stmt): ?array {
    $rows = proctorRows($stmt);
    return $rows[0] ?? null;
}
function proctorExec($db, string $sql, array $params = []): void {
    proctorStatement($db, $sql, $params);
}
function proctorJson(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // Resolve the exam and make sure its owner is the authenticated teacher when acting as teacher.
    $exam = proctorRow(proctorStatement($db, 'SELECT id, teacher_id, batch, course_code, status FROM exam_header WHERE id = ? LIMIT 1', [$examId]));
    if (!$exam) proctorJson(['success' => false, 'message' => 'Exam not found.'], 404);

    $teacherId = (string)$exam['teacher_id'];
    $isOwner = ($teacherSessionId !== '' && hash_equals($teacherId, $teacherSessionId))
        || ($userId !== '' && hash_equals($teacherId, $userId));
    if (!$isOwner && $userId !== '' && ctype_digit($userId)) {
        $teacherRow = proctorRow(proctorStatement($db, 'SELECT teacher_id FROM teachers WHERE id = ? LIMIT 1', [$userId]));
        $isOwner = $teacherRow && hash_equals($teacherId, (string)$teacherRow['teacher_id']);
    }

    $studentId = '';
    $studentName = 'Student';
    $studentRow = null;
    if ($role === 'student') {
        $studentCandidates = array_values(array_unique(array_filter([$studentSessionId, $userId], static fn($value) => $value !== '')));
        foreach ($studentCandidates as $candidate) {
            $studentRow = proctorRow(proctorStatement($db, 'SELECT id, student_id, full_name FROM students WHERE student_id = ? LIMIT 1', [$candidate]));
            if ($studentRow) break;
        }
        if (!$studentRow && $userId !== '' && ctype_digit($userId)) {
            $studentRow = proctorRow(proctorStatement($db, 'SELECT id, student_id, full_name FROM students WHERE id = ? LIMIT 1', [$userId]));
        }
        if (!$studentRow) proctorJson(['success' => false, 'message' => 'Student account was not found.'], 403);
        $studentId = (string)$studentRow['student_id'];
        $studentName = (string)$studentRow['full_name'];

        $courseCode = trim((string)($exam['course_code'] ?? ''));
        $batch = trim((string)$exam['batch']);
        if ($courseCode !== '') {
            $eligible = proctorRow(proctorStatement($db, "
                SELECT 1 AS allowed
                FROM student_enrollments se
                INNER JOIN student_enrollment_courses sec ON sec.enrollment_id = se.id
                WHERE se.student_id = ? AND se.status = 'Enrolled' AND (? = 'All' OR se.batch = ?)
                  AND UPPER(TRIM(sec.course_code)) = UPPER(TRIM(?))
                LIMIT 1
            ", [$studentRow['id'], $batch, $batch, $courseCode]));
        } elseif ($batch === 'All') {
            $eligible = ['allowed' => 1];
        } else {
            $eligible = proctorRow(proctorStatement($db, "
                SELECT 1 AS allowed
                WHERE EXISTS (
                    SELECT 1 FROM student_enrollments se
                    WHERE se.student_id = ? AND se.status = 'Enrolled' AND se.batch = ?
                ) OR EXISTS (
                    SELECT 1 FROM students s WHERE s.id = ? AND s.batch = ?
                )
                LIMIT 1
            ", [$studentRow['id'], $batch, $studentRow['id'], $batch]));
        }
        if (!$eligible) proctorJson(['success' => false, 'message' => 'You are not enrolled in this exam course and batch.'], 403);
    } elseif (!$isOwner) {
        proctorJson(['success' => false, 'message' => 'Only the teacher who owns this exam can access its proctoring.'], 403);
    }

    if (strcasecmp((string)$exam['status'], 'Completed') === 0) {
        proctorJson(['success' => false, 'message' => 'Proctoring has ended for this exam.'], 409);
    }

    if ($action === 'heartbeat') {
        // Let only the teacher heartbeat clean old rows, roughly once per minute.
        if ($role === 'teacher' && (int)gmdate('s') < 6) {
            proctorExec($db, 'DELETE FROM exam_proctor_signals WHERE exam_id = ? AND created_at < DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 10 MINUTE)', [$examId]);
            proctorExec($db, 'DELETE FROM exam_proctor_participants WHERE exam_id = ? AND connected = 0 AND last_seen < DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)', [$examId]);
        }

        if ($role === 'student') {
            proctorExec($db, "
                INSERT INTO exam_proctor_participants (exam_id, student_id, peer_id, student_name, connected, last_seen)
                VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP())
                ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), student_name = VALUES(student_name), connected = 1, last_seen = CURRENT_TIMESTAMP()
            ", [$examId, $studentId, $peerId, $studentName]);
        } else {
            proctorExec($db, "
                INSERT INTO exam_proctor_participants (exam_id, student_id, peer_id, student_name, connected, last_seen)
                VALUES (?, '__teacher__', ?, 'Teacher', 1, CURRENT_TIMESTAMP())
                ON DUPLICATE KEY UPDATE connected = 1, last_seen = CURRENT_TIMESTAMP()
            ", [$examId, $peerId]);
        }
        proctorJson(['success' => true, 'server_time_ms' => (int)round(microtime(true) * 1000)]);
    }

    if ($action === 'leave') {
        if ($role === 'student') {
            proctorExec($db, 'UPDATE exam_proctor_participants SET connected = 0, last_seen = CURRENT_TIMESTAMP() WHERE exam_id = ? AND student_id = ? AND peer_id = ?', [$examId, $studentId, $peerId]);
        } else {
            proctorExec($db, 'UPDATE exam_proctor_participants SET connected = 0, last_seen = CURRENT_TIMESTAMP() WHERE exam_id = ? AND student_id = ? AND peer_id = ?', [$examId, '__teacher__', $peerId]);
        }
        proctorJson(['success' => true]);
    }

    if ($action === 'send') {
        $type = trim((string)($body['type'] ?? ''));
        $recipientRole = trim((string)($body['recipient_role'] ?? ''));
        $recipientId = trim((string)($body['recipient_id'] ?? ''));
        $recipientPeerId = trim((string)($body['recipient_peer_id'] ?? ''));
        if (!in_array($type, ['offer', 'answer', 'ice', 'leave'], true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $recipientPeerId)) {
            proctorJson(['success' => false, 'message' => 'Invalid WebRTC signal.'], 400);
        }
        $signalPayload = json_encode($body['payload'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($signalPayload === false || strlen($signalPayload) > 131072) proctorJson(['success' => false, 'message' => 'WebRTC signal is too large.'], 413);

        if ($role === 'teacher') {
            if ($recipientRole !== 'student' || $recipientId === '') proctorJson(['success' => false, 'message' => 'Invalid student destination.'], 400);
            $activeStudent = proctorRow(proctorStatement($db, "SELECT peer_id FROM exam_proctor_participants WHERE exam_id = ? AND student_id = ? AND peer_id = ? AND connected = 1 AND last_seen >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 25 SECOND) LIMIT 1", [$examId, $recipientId, $recipientPeerId]));
            if (!$activeStudent) proctorJson(['success' => false, 'message' => 'Student proctoring session is no longer connected.'], 409);
            $targetRole = 'student';
            $targetId = $recipientId;
        } else {
            if ($recipientRole !== 'teacher' || $recipientId !== '' && $recipientId !== $teacherId) proctorJson(['success' => false, 'message' => 'Invalid teacher destination.'], 400);
            $activeTeacher = proctorRow(proctorStatement($db, "SELECT peer_id FROM exam_proctor_participants WHERE exam_id = ? AND student_id = '__teacher__' AND peer_id = ? AND connected = 1 AND last_seen >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 25 SECOND) LIMIT 1", [$examId, $recipientPeerId]));
            if (!$activeTeacher) proctorJson(['success' => false, 'message' => 'Teacher proctoring session is no longer connected.'], 409);
            $targetRole = 'teacher';
            $targetId = $teacherId;
        }

        proctorExec($db, 'INSERT INTO exam_proctor_signals (exam_id, sender_role, sender_id, sender_peer_id, recipient_role, recipient_id, recipient_peer_id, signal_type, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP())', [$examId, $role, $role === 'teacher' ? $teacherId : $studentId, $peerId, $targetRole, $targetId, $recipientPeerId, $type, $signalPayload]);
        proctorJson(['success' => true]);
    }

    if ($action === 'poll') {
        $sinceId = max(0, (int)($body['since_id'] ?? $_REQUEST['since_id'] ?? 0));
        $recipientId = $role === 'teacher' ? $teacherId : $studentId;
        $signals = proctorRows(proctorStatement($db, 'SELECT id, sender_role, sender_id, sender_peer_id, signal_type AS type, payload FROM exam_proctor_signals WHERE exam_id = ? AND recipient_role = ? AND recipient_id = ? AND recipient_peer_id = ? AND id > ? AND created_at >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 10 MINUTE) ORDER BY id ASC LIMIT 100', [$examId, $role, $recipientId, $peerId, $sinceId]));
        foreach ($signals as &$signal) {
            $decoded = json_decode((string)$signal['payload'], true);
            $signal['payload'] = $decoded ?? new stdClass();
            $signal['id'] = (int)$signal['id'];
        }
        unset($signal);
        $data = ['success' => true, 'signals' => $signals, 'server_time_ms' => (int)round(microtime(true) * 1000)];
        if ($role === 'teacher') {
            $data['participants'] = proctorRows(proctorStatement($db, "SELECT student_id, student_name, peer_id FROM exam_proctor_participants WHERE exam_id = ? AND student_id <> '__teacher__' AND connected = 1 AND last_seen >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 18 SECOND) ORDER BY student_name, student_id", [$examId]));
        }
        proctorJson($data);
    }

    proctorJson(['success' => false, 'message' => 'Unknown proctoring action.'], 400);
} catch (Throwable $error) {
    error_log('Exam proctoring signal error: ' . $error->getMessage());
    proctorJson(['success' => false, 'message' => 'Proctoring service error. Run the supplied migration and try again.'], 500);
}
