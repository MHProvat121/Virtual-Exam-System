<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../../src/auth/login-page.php');
    exit();
}

require_once __DIR__ . '/../../src/config/config.php';
$dbConn = (isset($pdo) && $pdo instanceof PDO) ? $pdo : ((isset($conn) && $conn instanceof PDO) ? $conn : null);

function adminDashEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminDashTableExists(PDO $db, string $table): bool {
    static $known = [];
    if (array_key_exists($table, $known)) return $known[$table];
    try {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->execute([$table]);
        return $known[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return $known[$table] = false;
    }
}

function adminDashCount(PDO $db, string $sql, array $params = []): int {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return 0;
    }
}

function adminDashSnapshot(PDO $db, array $tables): array {
    $snapshot = [
        'metrics' => [
            'students' => 0, 'teachers' => 0, 'admins' => 0, 'exams' => 0,
            'active_attempts' => 0, 'completed_exams' => 0, 'pending_starts' => 0,
            'pending_enrollments' => 0, 'questions' => 0, 'online_candidates' => 0
        ],
        'exams' => [], 'participants' => [],
        'health' => ['database' => 'Unavailable', 'db_latency_ms' => null, 'db_connections' => null, 'host_load' => null, 'php_memory_mb' => 0],
        'features' => ['proctor_presence' => false, 'proctor_flags' => false, 'admin_video' => false]
    ];

    $snapshot['metrics']['students'] = $tables['students'] ? adminDashCount($db, 'SELECT COUNT(*) FROM students') : 0;
    $snapshot['metrics']['teachers'] = $tables['teachers'] ? adminDashCount($db, 'SELECT COUNT(*) FROM teachers') : 0;
    $snapshot['metrics']['admins'] = $tables['users'] ? adminDashCount($db, "SELECT COUNT(*) FROM `users-table` WHERE role = 'admin'") : 0;
    $snapshot['metrics']['exams'] = $tables['exams'] ? adminDashCount($db, 'SELECT COUNT(*) FROM exam_header') : 0;
    $snapshot['metrics']['completed_exams'] = $tables['exams'] ? adminDashCount($db, "SELECT COUNT(*) FROM exam_header WHERE status = 'Completed'") : 0;
    $snapshot['metrics']['questions'] = $tables['questions'] ? adminDashCount($db, 'SELECT COUNT(*) FROM questions') : 0;
    $snapshot['metrics']['pending_enrollments'] = $tables['enrollments'] ? adminDashCount($db, "SELECT COUNT(*) FROM student_enrollments WHERE status = 'Pending'") : 0;

    $dhakaNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    if ($tables['exams']) {
        $snapshot['metrics']['pending_starts'] = adminDashCount(
            $db,
            "SELECT COUNT(*) FROM exam_header WHERE status IN ('Published','Edited/Rescheduled') AND CONCAT(exam_date, ' ', exam_time) > ?",
            [$dhakaNow]
        );
    }
    if ($tables['exams'] && $tables['submissions']) {
        $snapshot['metrics']['active_attempts'] = adminDashCount(
            $db,
            "SELECT COUNT(*) FROM exam_submissions s JOIN exam_header e ON e.id = s.exam_id WHERE s.status = 'In Progress' AND e.status = 'Running'"
        );
    }

    if ($tables['proctor_participants'] && $tables['exams']) {
        $snapshot['features']['proctor_presence'] = true;
        try {
            $stmt = $db->query("SELECT p.exam_id, p.peer_id, p.student_id, p.student_name, p.last_seen,
                                       e.exam_title, e.batch, e.course_code, e.status AS exam_status,
                                       e.teacher_name, e.exam_date, e.exam_time
                                FROM exam_proctor_participants p
                                JOIN exam_header e ON e.id = p.exam_id
                                WHERE p.student_id <> '__teacher__' AND p.connected = 1
                                  AND p.last_seen >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 18 SECOND)
                                ORDER BY p.last_seen DESC LIMIT 30");
            $snapshot['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $snapshot['metrics']['online_candidates'] = adminDashCount(
                $db,
                "SELECT COUNT(*) FROM exam_proctor_participants WHERE student_id <> '__teacher__' AND connected = 1 AND last_seen >= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 18 SECOND)"
            );
        } catch (Throwable $error) {
            $snapshot['features']['proctor_presence'] = false;
        }
    }
    $snapshot['features']['proctor_flags'] = $tables['proctor_flags'];

    if ($tables['exams']) {
        try {
            $sql = "SELECT e.id, e.teacher_name, e.batch, e.course_code, e.subject, e.exam_title,
                           e.exam_type, e.exam_date, e.exam_time, e.duration, e.status,
                           COUNT(DISTINCT s.id) AS attempt_count,
                           COUNT(DISTINCT CASE WHEN s.status = 'In Progress' THEN s.id END) AS active_count,
                           COUNT(DISTINCT CASE WHEN s.status = 'Submitted' THEN s.id END) AS submitted_count
                    FROM exam_header e";
            if ($tables['submissions']) $sql .= " LEFT JOIN exam_submissions s ON s.exam_id = e.id";
            else $sql .= " LEFT JOIN (SELECT NULL AS id, NULL AS exam_id, NULL AS status) s ON 1 = 0";
            $sql .= " GROUP BY e.id ORDER BY CASE e.status WHEN 'Running' THEN 0 WHEN 'Published' THEN 1 ELSE 2 END,
                     e.exam_date DESC, e.exam_time DESC LIMIT 12";
            $snapshot['exams'] = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $error) {
            $snapshot['exams'] = [];
        }
    }

    $started = microtime(true);
    try {
        $db->query('SELECT 1')->fetchColumn();
        $snapshot['health']['database'] = 'Online';
        $snapshot['health']['db_latency_ms'] = round((microtime(true) - $started) * 1000, 1);
    } catch (Throwable $error) {
        $snapshot['health']['database'] = 'Unavailable';
    }
    try {
        $connectionStatus = $db->query("SHOW GLOBAL STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_NUM);
        if ($connectionStatus && isset($connectionStatus[1])) $snapshot['health']['db_connections'] = (int)$connectionStatus[1];
    } catch (Throwable $error) {
        $snapshot['health']['db_connections'] = null;
    }
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if (is_array($load) && isset($load[0])) $snapshot['health']['host_load'] = round((float)$load[0], 2);
    }
    $snapshot['health']['php_memory_mb'] = round(memory_get_usage(true) / 1048576, 1);

    return $snapshot;
}

if (!$dbConn) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$tableNames = [
    'admins' => 'admins', 'students' => 'students', 'teachers' => 'teachers', 'users' => 'users-table',
    'exams' => 'exam_header', 'submissions' => 'exam_submissions', 'questions' => 'questions',
    'options' => 'question_options', 'enrollments' => 'student_enrollments',
    'proctor_participants' => 'exam_proctor_participants', 'proctor_flags' => 'exam_proctor_flags'
];
$tables = [];
foreach ($tableNames as $key => $name) $tables[$key] = adminDashTableExists($dbConn, $name);

if (($_GET['ajax'] ?? '') === 'dashboard') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(adminDashSnapshot($dbConn, $tables), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

$snapshot = adminDashSnapshot($dbConn, $tables);
$adminName = trim((string)($_SESSION['admin_name'] ?? $_SESSION['name'] ?? ''));
try {
    if ($tables['admins'] && $tables['users']) {
        $adminStmt = $dbConn->prepare("SELECT a.full_name FROM admins a LEFT JOIN `users-table` u ON u.email = a.email WHERE a.email = ? OR u.id = ? LIMIT 1");
        $adminStmt->execute([(string)($_SESSION['email'] ?? ''), (int)$_SESSION['user_id']]);
        $adminName = (string)($adminStmt->fetchColumn() ?: $adminName);
    } elseif ($tables['admins'] && !empty($_SESSION['email'])) {
        $adminStmt = $dbConn->prepare('SELECT full_name FROM admins WHERE email = ? LIMIT 1');
        $adminStmt->execute([(string)$_SESSION['email']]);
        $adminName = (string)($adminStmt->fetchColumn() ?: $adminName);
    }
} catch (Throwable $error) {}
if ($adminName === '') $adminName = 'Administrator';

$candidateQuery = trim((string)($_GET['candidate_q'] ?? ''));
$candidateProgram = trim((string)($_GET['candidate_program'] ?? ''));
$candidateBatch = trim((string)($_GET['candidate_batch'] ?? ''));
$candidateStatus = trim((string)($_GET['candidate_status'] ?? ''));
$directoryRows = [];
$candidatePrograms = [];
$candidateBatches = [];
if ($tables['students']) {
    try {
        $candidatePrograms = $dbConn->query("SELECT DISTINCT program FROM students WHERE program IS NOT NULL AND program <> '' ORDER BY program")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($tables['enrollments']) {
            $candidateBatches = $dbConn->query("SELECT DISTINCT batch FROM student_enrollments WHERE batch <> '' ORDER BY batch")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } else {
            $candidateBatches = $dbConn->query("SELECT DISTINCT batch FROM students WHERE batch IS NOT NULL AND batch <> '' ORDER BY batch")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $statusSql = $tables['enrollments']
            ? "CASE WHEN EXISTS (SELECT 1 FROM student_enrollments se WHERE se.student_id = st.id AND se.status = 'Enrolled') THEN 'Enrolled'
                    WHEN EXISTS (SELECT 1 FROM student_enrollments se WHERE se.student_id = st.id AND se.status = 'Pending') THEN 'Pending'
                    ELSE 'Registered' END"
            : "'Registered'";
        $batchSql = $tables['enrollments']
            ? "COALESCE((SELECT GROUP_CONCAT(DISTINCT se.batch ORDER BY se.batch SEPARATOR ', ') FROM student_enrollments se WHERE se.student_id = st.id AND se.status = 'Enrolled'), st.batch, '—')"
            : "COALESCE(st.batch, '—')";
        $where = [];
        $params = [];
        if ($candidateQuery !== '') {
            $where[] = '(st.student_id LIKE ? OR st.full_name LIKE ? OR st.email LIKE ?)';
            $like = '%' . $candidateQuery . '%';
            array_push($params, $like, $like, $like);
        }
        if ($candidateProgram !== '') { $where[] = 'st.program = ?'; $params[] = $candidateProgram; }
        if ($candidateBatch !== '') {
            if ($tables['enrollments']) {
                $where[] = '(st.batch = ? OR EXISTS (SELECT 1 FROM student_enrollments se WHERE se.student_id = st.id AND se.batch = ?))';
                array_push($params, $candidateBatch, $candidateBatch);
            } else { $where[] = 'st.batch = ?'; $params[] = $candidateBatch; }
        }
        if (in_array($candidateStatus, ['Enrolled', 'Pending', 'Registered'], true)) {
            $where[] = $statusSql . ' = ?';
            $params[] = $candidateStatus;
        }
        $directorySql = "SELECT st.student_id, st.full_name, st.email, st.program, st.batch,
                                {$batchSql} AS enrolled_batches, {$statusSql} AS registration_status
                         FROM students st";
        if ($where) $directorySql .= ' WHERE ' . implode(' AND ', $where);
        $directorySql .= ' ORDER BY st.full_name LIMIT 25';
        $directoryStmt = $dbConn->prepare($directorySql);
        $directoryStmt->execute($params);
        $directoryRows = $directoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) {
        $directoryRows = [];
    }
}

$teacherRows = [];
if ($tables['teachers']) {
    try {
        $teacherSql = "SELECT t.teacher_id, t.full_name, t.email,
                              COUNT(DISTINCT e.id) AS assigned_exams,
                              COUNT(DISTINCT CASE WHEN e.status = 'Running' THEN e.id END) AS active_exams
                       FROM teachers t LEFT JOIN exam_header e ON e.teacher_id = t.teacher_id
                       GROUP BY t.id ORDER BY t.full_name LIMIT 12";
        $teacherRows = $dbConn->query($teacherSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) { $teacherRows = []; }
}

$bankSubject = trim((string)($_GET['bank_subject'] ?? ''));
$bankType = trim((string)($_GET['bank_type'] ?? ''));
$bankSearch = trim((string)($_GET['bank_q'] ?? ''));
$bankRows = [];
$bankSubjects = [];
if ($tables['questions'] && $tables['exams']) {
    try {
        $bankSubjects = $dbConn->query("SELECT DISTINCT subject FROM exam_header WHERE subject <> '' ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $where = [];
        $params = [];
        if ($bankSubject !== '') { $where[] = 'e.subject = ?'; $params[] = $bankSubject; }
        if (in_array($bankType, ['MCQ', 'Theory'], true)) { $where[] = 'q.question_type = ?'; $params[] = $bankType; }
        if ($bankSearch !== '') { $where[] = '(q.question_text LIKE ? OR e.exam_title LIKE ? OR e.course_code LIKE ?)'; $like = '%' . $bankSearch . '%'; array_push($params, $like, $like, $like); }
        $optionCountSql = $tables['options'] ? '(SELECT COUNT(*) FROM question_options qo WHERE qo.question_id = q.id)' : '0';
        $bankSql = "SELECT q.id, q.exam_id, q.question_number, q.question_type, q.question_text, q.marks,
                           e.subject, e.course_code, e.exam_title, {$optionCountSql} AS option_count
                    FROM questions q JOIN exam_header e ON e.id = q.exam_id";
        if ($where) $bankSql .= ' WHERE ' . implode(' AND ', $where);
        $bankSql .= ' ORDER BY q.created_at DESC LIMIT 12';
        $bankStmt = $dbConn->prepare($bankSql);
        $bankStmt->execute($params);
        $bankRows = $bankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) { $bankRows = []; }
}

$dhakaNowLabel = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('d M Y, h:i:s A') . ' (Asia/Dhaka)';
$initialDashboardJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-dashboard-page.css">
</head>
<body>
<div class="main-layout">
    <?php require_once __DIR__ . '/admin-header-sidebar-section.php'; ?>
    <main class="dashboard admin-dashboard-page" data-dashboard-url="admin-dashboard-page.php?ajax=dashboard">
        <div class="dashboard-topline">
            <div>
                <p class="eyebrow"><i class="fa-solid fa-shield-halved"></i> Administration</p>
                <h1>Exam System Dashboard</h1>
                <p class="welcome-copy">Welcome, <?= adminDashEscape($adminName) ?>. Live operational data from the current project database.</p>
            </div>
            <div class="clock-card"><i class="fa-regular fa-clock"></i><span id="dhakaClock"><?= adminDashEscape($dhakaNowLabel) ?></span></div>
        </div>

        <section class="metric-grid" aria-label="Exam metrics">
            <article class="metric-card metric-blue"><span class="metric-icon"><i class="fa-solid fa-person-chalkboard"></i></span><div><small>Active exam attempts</small><strong id="metricActive"><?= number_format($snapshot['metrics']['active_attempts']) ?></strong><span>Students marked In Progress in Running exams</span></div></article>
            <article class="metric-card metric-green"><span class="metric-icon"><i class="fa-solid fa-circle-check"></i></span><div><small>Completed exams</small><strong id="metricCompleted"><?= number_format($snapshot['metrics']['completed_exams']) ?></strong><span>Exam headers marked Completed</span></div></article>
            <article class="metric-card metric-amber"><span class="metric-icon"><i class="fa-solid fa-hourglass-start"></i></span><div><small>Pending starts</small><strong id="metricPending"><?= number_format($snapshot['metrics']['pending_starts']) ?></strong><span>Published exams scheduled for later</span></div></article>
            <article class="metric-card metric-violet"><span class="metric-icon"><i class="fa-solid fa-users"></i></span><div><small>Registered candidates</small><strong id="metricStudents"><?= number_format($snapshot['metrics']['students']) ?></strong><span><b id="metricPendingEnrollments"><?= number_format($snapshot['metrics']['pending_enrollments']) ?></b> enrollment requests pending</span></div></article>
        </section>

        <section class="panel health-panel">
            <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-heart-pulse"></i></span><div><h2>System health</h2><p>Database latency, connection load, and this PHP request's resource use.</p></div></div><span class="live-pill"><i></i> Auto-refresh 10 sec</span></div>
            <div class="health-grid">
                <div class="health-item"><small>Database</small><strong id="healthDatabase"><?= adminDashEscape($snapshot['health']['database']) ?></strong></div>
                <div class="health-item"><small>Database round-trip</small><strong><span id="healthLatency"><?= $snapshot['health']['db_latency_ms'] === null ? '—' : adminDashEscape($snapshot['health']['db_latency_ms']) ?></span> ms</strong></div>
                <div class="health-item"><small>Concurrent DB connections</small><strong id="healthConnections"><?= $snapshot['health']['db_connections'] === null ? 'Unavailable' : number_format((int)$snapshot['health']['db_connections']) ?></strong></div>
                <div class="health-item"><small>Host load (1 min)</small><strong id="healthLoad"><?= $snapshot['health']['host_load'] === null ? 'Unavailable on this host' : adminDashEscape($snapshot['health']['host_load']) ?></strong></div>
                <div class="health-item"><small>PHP memory used</small><strong id="healthMemory"><?= adminDashEscape($snapshot['health']['php_memory_mb']) ?> MB</strong></div>
                <div class="health-item"><small>Online proctor peers</small><strong id="metricOnline"><?= number_format($snapshot['metrics']['online_candidates']) ?></strong></div>
            </div>
            <p class="health-note">Host CPU load is unavailable when PHP does not expose it. Database connections are the MySQL server-wide count; proctor peers use the live heartbeat table.</p>
        </section>

        <section class="panel monitoring-panel" id="live-monitoring">
            <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-video"></i></span><div><h2>Real-time exam monitoring</h2><p>Running exams, attempt status, and current proctoring presence.</p></div></div><span class="schema-chip">Schema-backed</span></div>
            <div class="monitor-grid">
                <div class="monitor-card">
                    <div class="subsection-heading"><h3>Active and upcoming exams</h3><a href="admin-courseSyllabus-page.php">Manage courses <i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
                    <div class="table-wrap"><table><thead><tr><th>Exam</th><th>Batch / Course</th><th>Teacher</th><th>Schedule (Dhaka)</th><th>Status</th><th>Attempts</th></tr></thead><tbody id="liveExamRows">
                    <?php if ($snapshot['exams']): foreach ($snapshot['exams'] as $exam): ?>
                        <tr><td><strong>#<?= (int)$exam['id'] ?> <?= adminDashEscape($exam['exam_title']) ?></strong><small><?= adminDashEscape($exam['subject']) ?></small></td><td><?= adminDashEscape($exam['batch']) ?><small><?= adminDashEscape($exam['course_code'] ?: 'No course code') ?></small></td><td><?= adminDashEscape($exam['teacher_name']) ?></td><td><?= adminDashEscape($exam['exam_date']) ?><small><?= adminDashEscape(substr((string)$exam['exam_time'], 0, 5)) ?></small></td><td><span class="status-pill status-<?= strtolower(str_replace([' ', '/', '\\'], '-', (string)$exam['status'])) ?>"><?= adminDashEscape($exam['status']) ?></span></td><td><?= (int)$exam['active_count'] ?> active <small><?= (int)$exam['submitted_count'] ?> submitted</small></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="6" class="empty-cell">No exam records found in exam_header.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </div>
                <div class="monitor-card presence-card">
                    <div class="subsection-heading"><h3>Live candidate presence</h3><span class="live-pill"><i></i> <span id="presenceCount"><?= count($snapshot['participants']) ?></span> online</span></div>
                    <div class="presence-list" id="presenceList">
                    <?php if ($snapshot['participants']): foreach ($snapshot['participants'] as $participant): ?>
                        <article class="presence-row"><span class="presence-avatar"><i class="fa-solid fa-user-graduate"></i></span><div><strong><?= adminDashEscape($participant['student_name'] ?: 'Student') ?></strong><small><?= adminDashEscape($participant['student_id']) ?> · <?= adminDashEscape($participant['exam_title']) ?> (#<?= (int)$participant['exam_id'] ?>)</small></div><span class="connected-dot" title="Heartbeat received recently"></span></article>
                    <?php endforeach; else: ?><p class="empty-state" id="presenceEmpty"><i class="fa-solid fa-video-slash"></i><strong>No live candidate peers detected</strong><span><?= $tables['proctor_participants'] ? 'A candidate appears after their browser establishes a proctoring heartbeat.' : 'Run exam-proctoring-migration.sql to enable presence tracking.' ?></span></p><?php endif; ?>
                    </div>
                    <div class="capability-note"><i class="fa-solid fa-circle-info"></i><span>The current WebRTC signaling authorizes teacher/student peers only. Admin webcam viewing and screen sharing need a separate authorized media path; this page does not claim to display streams.</span></div>
                </div>
            </div>
            <div class="flags-panel"><div class="flags-heading"><span class="flag-icon"><i class="fa-solid fa-flag"></i></span><div><h3>Proctoring flags</h3><p>Automated violations such as face missing or tab switching.</p></div></div><div class="flags-state"><strong><?= $tables['proctor_flags'] ? 'Flag table detected' : 'Not available in current schema' ?></strong><span><?= $tables['proctor_flags'] ? 'A flag data adapter is needed to render these records.' : 'The current schema stores WebRTC presence and signaling only; it has no proctoring-alert table or AI event records.' ?></span></div></div>
        </section>

        <section class="panel directory-panel" id="candidate-directory">
            <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-address-book"></i></span><div><h2>Candidate directory</h2><p>Search students by registration ID, name, email, program, batch, or enrollment status.</p></div></div><span class="count-chip"><?= number_format($snapshot['metrics']['students']) ?> candidates</span></div>
            <form method="get" class="filter-bar">
                <label class="search-field"><i class="fa-solid fa-magnifying-glass"></i><input name="candidate_q" value="<?= adminDashEscape($candidateQuery) ?>" placeholder="Name, ID, or email"></label>
                <select name="candidate_program"><option value="">All programs</option><?php foreach ($candidatePrograms as $program): ?><option value="<?= adminDashEscape($program) ?>" <?= $candidateProgram === $program ? 'selected' : '' ?>><?= adminDashEscape($program) ?></option><?php endforeach; ?></select>
                <select name="candidate_batch"><option value="">All batches</option><?php foreach ($candidateBatches as $batch): ?><option value="<?= adminDashEscape($batch) ?>" <?= $candidateBatch === $batch ? 'selected' : '' ?>><?= adminDashEscape($batch) ?></option><?php endforeach; ?></select>
                <select name="candidate_status"><option value="">All registration states</option><?php foreach (['Enrolled','Pending','Registered'] as $status): ?><option value="<?= $status ?>" <?= $candidateStatus === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
                <button class="button-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                <a class="button-quiet" href="admin-dashboard-page.php#candidate-directory">Reset</a>
            </form>
            <div class="table-wrap"><table><thead><tr><th>Candidate</th><th>Registration ID</th><th>Program</th><th>Batch</th><th>Enrollment</th></tr></thead><tbody>
            <?php if ($directoryRows): foreach ($directoryRows as $candidate): ?><tr><td><strong><?= adminDashEscape($candidate['full_name']) ?></strong><small><?= adminDashEscape($candidate['email']) ?></small></td><td><?= adminDashEscape($candidate['student_id']) ?></td><td><?= adminDashEscape($candidate['program'] ?: '—') ?></td><td><?= adminDashEscape($candidate['enrolled_batches']) ?></td><td><span class="status-pill status-<?= strtolower((string)$candidate['registration_status']) ?>"><?= adminDashEscape($candidate['registration_status']) ?></span></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="empty-cell">No candidates match these filters.</td></tr><?php endif; ?>
            </tbody></table></div>
            <p class="table-footnote">Showing up to 25 records. Account activation and candidate status are not separate fields in the current schema; enrollment states are sourced from student_enrollments.</p>
        </section>

        <section class="dashboard-columns">
            <section class="panel roster-panel">
                <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-user-tie"></i></span><div><h2>Proctor / examiner roster</h2><p>Teachers assigned to exams in exam_header.</p></div></div></div>
                <div class="table-wrap"><table><thead><tr><th>Teacher</th><th>Teacher ID</th><th>Assigned exams</th><th>Running</th></tr></thead><tbody>
                <?php if ($teacherRows): foreach ($teacherRows as $teacher): ?><tr><td><strong><?= adminDashEscape($teacher['full_name']) ?></strong><small><?= adminDashEscape($teacher['email']) ?></small></td><td><?= adminDashEscape($teacher['teacher_id']) ?></td><td><?= (int)$teacher['assigned_exams'] ?></td><td><?= (int)$teacher['active_exams'] ?></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="empty-cell">No teacher roster records found.</td></tr><?php endif; ?>
                </tbody></table></div>
                <div class="capability-note"><i class="fa-solid fa-circle-info"></i><span>Exam assignments are visible from exam_header. There is no separate invigilator-to-room assignment table in the current schema.</span></div>
            </section>

            <section class="panel access-panel">
                <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-user-lock"></i></span><div><h2>Roles & access control</h2><p>Current account-role distribution.</p></div></div></div>
                <div class="role-grid">
                    <?php foreach (['admin' => 'Administrators', 'teacher' => 'Teachers', 'student' => 'Students'] as $role => $label): ?>
                    <div class="role-stat"><span><?= adminDashEscape($label) ?></span><strong><?= $tables['users'] ? number_format(adminDashCount($dbConn, 'SELECT COUNT(*) FROM `users-table` WHERE role = ?', [$role])) : '—' ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <div class="capability-note"><i class="fa-solid fa-lock"></i><span>Current users-table supports admin, teacher, and student roles. Granular proctor, examiner, and support permissions have no permission matrix in the schema, so this dashboard leaves role editing disabled.</span></div>
            </section>
        </section>

        <section class="panel question-bank-panel" id="question-bank">
            <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-database"></i></span><div><h2>Question repository</h2><p>Questions are linked to exams and categorized by course, subject, and question type.</p></div></div><div class="section-actions"><span class="count-chip"><?= number_format($snapshot['metrics']['questions']) ?> questions</span><button class="button-disabled" type="button" disabled title="The current schema does not include a reusable question bank or import target"><i class="fa-solid fa-file-csv"></i> CSV import unavailable</button></div></div>
            <form method="get" class="filter-bar">
                <label class="search-field"><i class="fa-solid fa-magnifying-glass"></i><input name="bank_q" value="<?= adminDashEscape($bankSearch) ?>" placeholder="Search question, title, or course code"></label>
                <select name="bank_subject"><option value="">All subjects</option><?php foreach ($bankSubjects as $subject): ?><option value="<?= adminDashEscape($subject) ?>" <?= $bankSubject === $subject ? 'selected' : '' ?>><?= adminDashEscape($subject) ?></option><?php endforeach; ?></select>
                <select name="bank_type"><option value="">All types</option><?php foreach (['MCQ','Theory'] as $type): ?><option value="<?= $type ?>" <?= $bankType === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
                <button class="button-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                <a class="button-quiet" href="admin-dashboard-page.php#question-bank">Reset</a>
            </form>
            <div class="table-wrap"><table><thead><tr><th>Question</th><th>Course / Subject</th><th>Exam</th><th>Type</th><th>Marks</th><th>Difficulty</th></tr></thead><tbody>
            <?php if ($bankRows): foreach ($bankRows as $question): $plainQuestion = html_entity_decode(strip_tags((string)$question['question_text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?><tr><td><strong>Q<?= (int)$question['question_number'] ?> · <?= adminDashEscape(mb_strimwidth($plainQuestion, 0, 110, '…')) ?></strong><small><?= (int)$question['option_count'] ?> answer options</small></td><td><?= adminDashEscape($question['course_code'] ?: '—') ?><small><?= adminDashEscape($question['subject']) ?></small></td><td>#<?= (int)$question['exam_id'] ?> · <?= adminDashEscape($question['exam_title']) ?></td><td><span class="type-chip"><?= adminDashEscape($question['question_type']) ?></span></td><td><?= number_format((float)$question['marks'], 2) ?></td><td><span class="muted-value">Not stored</span></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="empty-cell">No question records match this filter.</td></tr><?php endif; ?>
            </tbody></table></div>
            <div class="capability-note"><i class="fa-solid fa-circle-info"></i><span>The schema stores questions under an exam; it has no reusable bank, difficulty field, or import log. CSV import is disabled here so a bulk upload cannot silently attach questions to the wrong exam.</span></div>
        </section>

        <section class="dashboard-columns">
            <section class="panel workflow-panel">
                <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div><h2>Exam setup workflow</h2><p>Existing exam configuration path from this project schema.</p></div></div><a class="button-primary" href="admin-courseSyllabus-page.php"><i class="fa-solid fa-book-open"></i> Course setup</a></div>
                <ol class="workflow-list"><li><span>1</span><div><strong>Set up course and batch</strong><small>Course code, subject, title, teacher, and syllabus are stored in batches.</small></div></li><li><span>2</span><div><strong>Create the exam schedule</strong><small>exam_header stores exam date/time, duration, type, teacher, and marks.</small></div></li><li><span>3</span><div><strong>Add questions and grading</strong><small>questions and question_options are linked to the exam header.</small></div></li></ol>
                <div class="setting-row"><div><strong>Question / answer shuffling</strong><small>Randomization fields are not present in exam_header.</small></div><span class="setting-off">Not configured</span></div>
            </section>
            <section class="panel security-panel">
                <div class="section-heading"><div><span class="section-icon"><i class="fa-solid fa-shield"></i></span><div><h2>Security controls</h2><p>Configuration status based on available schema fields.</p></div></div></div>
                <div class="setting-row"><div><strong>Force full-screen</strong><small>Requires client-side enforcement and stored policy.</small></div><span class="setting-off">Not configured</span></div>
                <div class="setting-row"><div><strong>Disable copy / paste</strong><small>No exam lockdown policy is stored.</small></div><span class="setting-off">Not configured</span></div>
                <div class="setting-row"><div><strong>IP allowlist</strong><small>No trusted-network table or request enforcement exists.</small></div><span class="setting-off">Not configured</span></div>
                <div class="setting-row"><div><strong>Geo-fencing</strong><small>No region policy or location-verification record exists.</small></div><span class="setting-off">Not configured</span></div>
            </section>
        </section>
        <footer class="dashboard-footer">Dashboard values refresh from the project database. Server-wide metrics and protections are only shown when the application has an implementation for them.</footer>
    </main>
</div>
<script>window.adminDashboardInitial = <?= $initialDashboardJson ?: '{}' ?>;</script>
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>
<script src="../assets/js/admin/admin-dashboard-page.js"></script>
</body>
</html>
