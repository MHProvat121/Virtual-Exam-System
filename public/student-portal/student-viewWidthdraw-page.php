<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    if (file_exists(__DIR__ . '/../../src/auth/login-page.php')) {
        header("Location: ../../src/auth/login-page.php");
        exit();
    }
}

// 3. Database Connection
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// 4. Fetch Logged-in Student Info
$student = [];
if ($dbConn) {
    // Ensure table migration for withdraw_requested_at and enroll_requested_at
    try {
        $colCheck = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'withdraw_requested_at'")->fetch();
        if (!$colCheck) {
            $dbConn->exec("ALTER TABLE `student_enrollments` ADD `withdraw_requested_at` timestamp NULL DEFAULT NULL AFTER `withdraw_reason`");
        }
        $colCheckReq = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'enroll_requested_at'")->fetch();
        if (!$colCheckReq) {
            $dbConn->exec("ALTER TABLE `student_enrollments` ADD `enroll_requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when student submitted enrollment request' AFTER `payment_slip`");
        }
    } catch (PDOException $e) {}

    // Drop unique_student_batch constraint if it exists so multiple requests can be stored
    try {
        $chkIdx = $dbConn->query("SHOW INDEX FROM `student_enrollments` WHERE Key_name = 'unique_student_batch'")->fetch();
        if ($chkIdx) {
            $dbConn->exec("ALTER TABLE `student_enrollments` DROP INDEX `unique_student_batch`");
            $dbConn->exec("ALTER TABLE `student_enrollments` ADD INDEX `idx_student_batch` (`student_id`, `batch`)");
        }
    } catch (PDOException $e) {}

    if (isset($_SESSION['email'])) {
        $stmt_student = $dbConn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
        $stmt_student->execute(['email' => $_SESSION['email']]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    if (empty($student) && isset($_SESSION['user_id'])) {
        $stmt_student = $dbConn->prepare("
            SELECT s.* FROM `students` s 
            JOIN `users-table` u ON s.email = u.email 
            WHERE u.id = :uid LIMIT 1
        ");
        $stmt_student->execute(['uid' => $_SESSION['user_id']]);
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

$studentId = $student['id'] ?? 0;
$studentCode = $student['student_id'] ?? '';
$selectedBatch = trim($_GET['batch'] ?? ($_POST['batch'] ?? ''));

// 5. FETCH EXISTING ENROLLMENT & WITHDRAWAL INFO FOR THIS BATCH
$mode = trim($_GET['mode'] ?? ($_POST['mode'] ?? ''));
$targetEnrollmentId = !empty($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : (!empty($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0);
$existingEnrollment = null;

// Read courses from GET query parameter or POST
$selectedCourseCodes = [];
if (!empty($_GET['courses'])) {
    $rawCodes = is_array($_GET['courses']) ? $_GET['courses'] : explode(',', $_GET['courses']);
    foreach ($rawCodes as $rc) {
        $c = trim(urldecode($rc));
        if ($c !== '') $selectedCourseCodes[] = $c;
    }
} elseif (!empty($_POST['selected_courses'])) {
    $rawCodes = is_array($_POST['selected_courses']) ? $_POST['selected_courses'] : explode(',', $_POST['selected_courses']);
    foreach ($rawCodes as $rc) {
        $c = trim(urldecode($rc));
        if ($c !== '') $selectedCourseCodes[] = $c;
    }
}

if ($dbConn && ($studentId > 0 || !empty($studentCode)) && !empty($selectedBatch)) {
    // If no mode is passed, check if there is an active pending withdrawal for this batch
    if (empty($mode)) {
        try {
            $stmtChkPending = $dbConn->prepare("
                SELECT id FROM `student_enrollments` 
                WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `withdraw_status` = 'Pending' 
                ORDER BY `id` DESC LIMIT 1
            ");
            $stmtChkPending->execute([$studentId, $studentCode ?: (string)$studentId, $selectedBatch]);
            $autoPendingId = (int)$stmtChkPending->fetchColumn();
            if ($autoPendingId > 0) {
                $mode = 'pending';
                $targetEnrollmentId = $autoPendingId;
            }
        } catch (PDOException $e) {}
    }

    // Only load pending record if in explicit 'pending' update mode
    if ($mode === 'pending') {
        if ($targetEnrollmentId > 0) {
            $stmtEx = $dbConn->prepare("
                SELECT * FROM `student_enrollments` 
                WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `withdraw_status` = 'Pending' 
                LIMIT 1
            ");
            $stmtEx->execute([$targetEnrollmentId, $studentId, $studentCode ?: (string)$studentId]);
            $existingEnrollment = $stmtEx->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$existingEnrollment) {
            $stmtExW = $dbConn->prepare("
                SELECT * FROM `student_enrollments` 
                WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `withdraw_status` = 'Pending'
                ORDER BY `id` DESC LIMIT 1
            ");
            $stmtExW->execute([$studentId, $studentCode ?: (string)$studentId, $selectedBatch]);
            $existingEnrollment = $stmtExW->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
}

$isWithdrawPending      = ($mode === 'pending' && $existingEnrollment !== null && ($existingEnrollment['withdraw_status'] ?? '') === 'Pending');
// Fresh form: empty reason unless in active pending update mode!
$existingWithdrawReason = $isWithdrawPending ? ($existingEnrollment['withdraw_reason'] ?? '') : '';
$isWithdrawn            = (($existingEnrollment['withdraw_status'] ?? '') === 'Withdrawn');

// 6. FETCH COURSES & CALCULATE TOTAL CREDIT TO WITHDRAW
$batchCourses = [];
$selectedCourseList = [];
$totalCredit = 0.0;

// Fetch available courses for this batch from `batches` table
if ($dbConn && !empty($selectedBatch)) {
    $stmtBatchCourses = $dbConn->prepare("
        SELECT id, batch, course_code, credit, subject, exam_title 
        FROM `batches` 
        WHERE `batch` = ? AND `course_code` IS NOT NULL AND `course_code` != ''
        ORDER BY id ASC
    ");
    $stmtBatchCourses->execute([$selectedBatch]);
    $batchCourses = $stmtBatchCourses->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// If no courses supplied in URL, fetch appropriate courses:
if ($dbConn && empty($selectedCourseCodes)) {
    try {
        if ($isWithdrawPending && !empty($existingEnrollment['id'])) {
            // Load courses from student_withdrawal_courses for this pending withdrawal request
            $stmtPendingCourses = $dbConn->prepare("
                SELECT course_code 
                FROM `student_withdrawal_courses` 
                WHERE `enrollment_id` = ?
            ");
            $stmtPendingCourses->execute([$existingEnrollment['id']]);
            $pCodes = $stmtPendingCourses->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!empty($pCodes)) {
                $selectedCourseCodes = $pCodes;
            }
        }

        if (empty($selectedCourseCodes)) {
            // Fresh withdrawal: fetch actively enrolled courses for this student in this batch
            $stmtActiveCourses = $dbConn->prepare("
                SELECT DISTINCT ec.course_code 
                FROM `student_enrollment_courses` ec
                JOIN `student_enrollments` e ON ec.enrollment_id = e.id
                WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                  AND e.batch = ? 
                  AND e.status = 'Enrolled'
            ");
            $stmtActiveCourses->execute([$studentId, $studentCode ?: (string)$studentId, $selectedBatch]);
            $activeCodes = $stmtActiveCourses->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!empty($activeCodes)) {
                $selectedCourseCodes = $activeCodes;
            }
        }
    } catch (PDOException $e) {
        // Table may not have records yet
    }
}

// Filter matching courses and calculate total credit
if (!empty($selectedCourseCodes)) {
    $normalizedSelected = array_map(function($c) {
        return strtoupper(trim(urldecode($c)));
    }, $selectedCourseCodes);

    foreach ($batchCourses as $bc) {
        $cCode = trim($bc['course_code'] ?? '');
        if ($cCode !== '' && in_array(strtoupper($cCode), $normalizedSelected, true)) {
            $cr = floatval($bc['credit'] ?? 0);
            $totalCredit += $cr;
            $selectedCourseList[] = [
                'code' => $cCode,
                'credit' => !empty($bc['credit']) ? $bc['credit'] : '0.0',
                'subject' => $bc['subject'] ?? 'N/A'
            ];
        }
    }

    // Safety fallback: If courses were specified in URL but didn't match $batchCourses exactly,
    // construct directly from $selectedCourseCodes so it NEVER displays the entire batch!
    if (empty($selectedCourseList)) {
        foreach ($selectedCourseCodes as $rawCode) {
            $c = trim(urldecode($rawCode));
            if ($c !== '') {
                $selectedCourseList[] = [
                    'code' => $c,
                    'credit' => '3.0',
                    'subject' => 'N/A'
                ];
                $totalCredit += 3.0;
            }
        }
    }
} else {
    // If no course-level records exist yet, default to all courses for this batch
    foreach ($batchCourses as $bc) {
        $cr = floatval($bc['credit'] ?? 0);
        $totalCredit += $cr;
        $selectedCourseList[] = [
            'code' => $bc['course_code'],
            'credit' => !empty($bc['credit']) ? $bc['credit'] : '0.0',
            'subject' => $bc['subject'] ?? 'N/A'
        ];
    }
}

$alertMessage = '';
$alertType = '';

// Handle Withdrawal Request Cancellation by Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_withdrawal'])) {
    $batchName = trim($_POST['batch'] ?? $selectedBatch);
    $cancelEnrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if (!empty($batchName) && $dbConn && ($studentId > 0 || !empty($studentCode))) {
        try {
            $enrollment = null;
            if ($cancelEnrollmentId > 0) {
                $stmtCheck = $dbConn->prepare("
                    SELECT id, status, enroll_requested_at, withdraw_status 
                    FROM `student_enrollments` 
                    WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) 
                    LIMIT 1
                ");
                $stmtCheck->execute([$cancelEnrollmentId, $studentId, $studentCode ?: (string)$studentId]);
                $enrollment = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            }
            if (!$enrollment) {
                $stmtCheck = $dbConn->prepare("
                    SELECT id, status, enroll_requested_at, withdraw_status 
                    FROM `student_enrollments` 
                    WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `withdraw_status` = 'Pending' 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtCheck->execute([$studentId, $studentCode ?: (string)$studentId, $batchName]);
                $enrollment = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            }

            if ($enrollment) {
                $targetId = (int)$enrollment['id'];

                // Delete associated courses in student_withdrawal_courses
                try {
                    $stmtDelWC = $dbConn->prepare("DELETE FROM `student_withdrawal_courses` WHERE `enrollment_id` = ?");
                    $stmtDelWC->execute([$targetId]);
                } catch (PDOException $e) {}

                // If record was created solely for withdrawal (enroll_requested_at is null or status is Withdrawn):
                if (empty($enrollment['enroll_requested_at']) || $enrollment['status'] === 'Withdrawn') {
                    $stmtDelE = $dbConn->prepare("DELETE FROM `student_enrollments` WHERE `id` = ?");
                    $stmtDelE->execute([$targetId]);
                } else {
                    $stmtCancelW = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `withdraw_status`         = 'None', 
                            `withdraw_reason`         = NULL, 
                            `withdraw_requested_at`   = NULL, 
                            `withdraw_action_at`      = NULL,
                            `withdraw_reviewed_by_id` = NULL,
                            `withdraw_reviewed_by_name` = NULL,
                            `withdraw_cancelled_by_id` = NULL,
                            `withdraw_cancelled_by_name` = NULL,
                            `withdraw_cancelled_at`   = NULL
                        WHERE `id` = ?
                    ");
                    $stmtCancelW->execute([$targetId]);
                }

                $_SESSION['flash_success'] = "Withdrawal request for Batch " . htmlspecialchars($batchName) . " has been cancelled successfully. Your enrollment remains active.";
                header("Location: student-enroll-page.php");
                exit();
            } else {
                $_SESSION['flash_warning'] = "No withdrawal request found to cancel.";
                header("Location: student-enroll-page.php");
                exit();
            }
        } catch (PDOException $e) {
            $alertMessage = "Database Error: " . $e->getMessage();
            $alertType = "danger";
        }
    }
}

// 7. Handle Withdrawal Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_withdrawal'])) {
    $batchName        = trim($_POST['batch'] ?? '');
    $withdrawReason   = trim($_POST['withdraw_reason'] ?? '');
    $submittedCourses = $_POST['selected_courses'] ?? [];
    $postEnrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $submitMode       = trim($_POST['mode'] ?? '');

    if (empty($batchName) || empty($withdrawReason)) {
        $alertMessage = "Please state your reason for requesting withdrawal.";
        $alertType = "danger";
    } elseif (empty($submittedCourses)) {
        $alertMessage = "Please select at least one course to withdraw.";
        $alertType = "danger";
    } else {
        if ($dbConn && ($studentId > 0 || !empty($studentCode))) {
            try {
                // Check if targeting an existing pending withdrawal record to update
                $existingPending = null;
                if ($postEnrollmentId > 0) {
                    $stmtCheckW = $dbConn->prepare("
                        SELECT * FROM `student_enrollments` 
                        WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `withdraw_status` = 'Pending' 
                        LIMIT 1
                    ");
                    $stmtCheckW->execute([$postEnrollmentId, $studentId, $studentCode ?: (string)$studentId]);
                    $existingPending = $stmtCheckW->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existingPending) {
                    $stmtCheckW2 = $dbConn->prepare("
                        SELECT * FROM `student_enrollments` 
                        WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `withdraw_status` = 'Pending' 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtCheckW2->execute([$studentId, $studentCode ?: (string)$studentId, $batchName]);
                    $existingPending = $stmtCheckW2->fetch(PDO::FETCH_ASSOC);
                }

                if ($existingPending && ($existingPending['withdraw_status'] ?? '') === 'Pending') {
                    // PENDING UPDATE TECHNOLOGY: Update existing pending withdrawal request (no new row created)
                    $targetWithdrawId = (int)$existingPending['id'];
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `withdraw_reason`          = ?, 
                            `withdraw_requested_at`    = NOW(),
                            `withdraw_status`          = 'Pending',
                            `withdraw_action_at`       = NULL,
                            `withdraw_reviewed_by_id`  = NULL,
                            `withdraw_reviewed_by_name`= NULL,
                            `withdraw_cancelled_by_id` = NULL,
                            `withdraw_cancelled_by_name` = NULL,
                            `withdraw_cancelled_at`    = NULL
                        WHERE `id` = ?
                    ");
                    $stmtUpd->execute([$withdrawReason, $targetWithdrawId]);

                    // Sync courses into student_withdrawal_courses
                    try {
                        $stmtDelOld = $dbConn->prepare("DELETE FROM `student_withdrawal_courses` WHERE `enrollment_id` = ?");
                        $stmtDelOld->execute([$targetWithdrawId]);

                        $stmtInsW = $dbConn->prepare("INSERT INTO `student_withdrawal_courses` (`enrollment_id`, `course_code`) VALUES (?, ?)");
                        foreach ($submittedCourses as $cCode) {
                            $cCode = trim($cCode);
                            if (!empty($cCode)) {
                                $stmtInsW->execute([$targetWithdrawId, $cCode]);
                            }
                        }
                    } catch (PDOException $e) {}

                    $_SESSION['flash_success'] = "Withdrawal request updated successfully! Awaiting administrator review.";
                    header("Location: student-enroll-page.php");
                    exit();
                } else {
                    // NEW WITHDRAWAL REQUEST TECHNOLOGY: Insert a brand new withdrawal-only record so a new row is created in admin withdrawal table
                    // enroll_requested_at is NULL and status is 'Unenrolled' so it creates tr strictly into the withdrawal table and NEVER into enroll table
                    $stmtIns = $dbConn->prepare("
                        INSERT INTO `student_enrollments` (
                            `student_id`, 
                            `batch`, 
                            `amount`, 
                            `payment_method`, 
                            `transaction_id`, 
                            `payment_slip`, 
                            `enroll_requested_at`, 
                            `status`, 
                            `withdraw_status`, 
                            `withdraw_reason`, 
                            `withdraw_requested_at`
                        ) VALUES (
                            ?, 
                            ?, 
                            0.00, 
                            'None', 
                            'N/A', 
                            NULL, 
                            NULL, 
                            'Unenrolled', 
                            'Pending', 
                            ?, 
                            NOW()
                        )
                    ");
                    $stmtIns->execute([$studentId, $batchName, $withdrawReason]);
                    $newWithdrawalId = (int)$dbConn->lastInsertId();

                    // Insert courses into student_withdrawal_courses for this new record
                    if ($newWithdrawalId > 0 && !empty($submittedCourses) && is_array($submittedCourses)) {
                        try {
                            $stmtInsW = $dbConn->prepare("INSERT INTO `student_withdrawal_courses` (`enrollment_id`, `course_code`) VALUES (?, ?)");
                            foreach ($submittedCourses as $cCode) {
                                $cCode = trim($cCode);
                                if (!empty($cCode)) {
                                    $stmtInsW->execute([$newWithdrawalId, $cCode]);
                                }
                            }
                        } catch (PDOException $e) {}
                    }

                    $_SESSION['flash_success'] = "Withdrawal request submitted successfully! Awaiting administrator review.";
                    header("Location: student-enroll-page.php");
                    exit();
                }
            } catch (PDOException $e) {
                $alertMessage = "Database Error: " . $e->getMessage();
                $alertType = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $isWithdrawPending ? 'Update Course Withdrawal' : 'Course Withdrawal Form'; ?> - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-viewWidthdraw-page.css">
</head>
<body>
    <div class="main-layout">
        <?php if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) { require_once __DIR__ . '/student-header-sidebar-section.php'; } ?>

        <main class="dashboard">
            <div class="enroll-form-container">
                <div class="form-title">
                    <span>
                        <i class="fa-solid fa-arrow-right-from-bracket" style="color:#dc2626;"></i> 
                        <?php echo $isWithdrawPending ? 'Update Withdrawal Request' : 'Course Withdrawal Form'; ?>
                    </span>
                    <a href="student-enroll-page.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Batches</a>
                </div>

                <?php if ($isWithdrawPending): ?>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-clock"></i> You currently have a <strong>Pending Withdrawal Request</strong> for <strong>Batch <?php echo htmlspecialchars($selectedBatch); ?></strong>. Submitting will update your withdrawal request.
                    </div>
                <?php endif; ?>

                <?php if (!empty($alertMessage)): ?>
                    <div class="alert alert-<?php echo $alertType; ?>">
                        <?php echo htmlspecialchars($alertMessage); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST">
                    <input type="hidden" name="mode" value="<?php echo htmlspecialchars($isWithdrawPending ? 'pending' : ''); ?>">
                    <?php if ($isWithdrawPending && !empty($existingEnrollment['id'])): ?>
                        <input type="hidden" name="enrollment_id" value="<?php echo (int)$existingEnrollment['id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Student Details (Readonly) -->
                    <div class="form-group">
                        <label>Student Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['full_name'] ?? 'N/A'); ?>" readonly style="background: #f1f5f9;">
                    </div>

                    <div class="form-group">
                        <label>Student ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?>" readonly style="background: #f1f5f9;">
                    </div>

                    <!-- Selected Batch -->
                    <div class="form-group">
                        <label for="batch">Selected Batch <span style="color:red;">*</span></label>
                        <input type="text" name="batch" id="batch" class="form-control" value="<?php echo htmlspecialchars($selectedBatch); ?>" readonly style="background: #f1f5f9;" required>
                    </div>

                    <!-- Selected Courses (To Withdraw) -->
                    <div class="form-group">
                        <label for="selected_courses">Selected Courses (To Withdraw)</label>
                        <select id="selected_courses" class="form-control" style="background: #f8fafc; cursor: pointer;" onfocus="this.defaultIndex=this.selectedIndex;" onchange="this.selectedIndex=this.defaultIndex;">
                            <?php if (!empty($selectedCourseList)): ?>
                                <?php foreach ($selectedCourseList as $index => $course): ?>
                                    <option value="<?php echo htmlspecialchars($course['code']); ?>" disabled <?php echo ($index === 0 ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($course['code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled selected>No courses selected</option>
                            <?php endif; ?>
                        </select>
                        <!-- Hidden inputs to submit the selected course codes to the backend -->
                        <?php if (!empty($selectedCourseList)): ?>
                            <?php foreach ($selectedCourseList as $course): ?>
                                <input type="hidden" name="selected_courses[]" value="<?php echo htmlspecialchars($course['code']); ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Total Credit -->
                    <div class="form-group">
                        <label for="total_credit">Total Credit</label>
                        <input type="text" name="total_credit" id="total_credit" class="form-control" 
                               value="<?php echo htmlspecialchars(number_format($totalCredit, 1)); ?>" 
                               readonly style="background: #f1f5f9; font-weight: 600; color: #1e293b;">
                    </div>

                    <!-- Reason for Withdrawal -->
                    <div class="form-group">
                        <label for="withdraw_reason">Reason for Withdrawal <span style="color:red;">*</span></label>
                        <textarea name="withdraw_reason" id="withdraw_reason" rows="4" class="form-control" required 
                                  placeholder="Please describe in detail why you want to withdraw from these courses / batch..."><?php echo htmlspecialchars($existingWithdrawReason); ?></textarea>
                    </div>

                    <!-- Actions: Cancel and Submit Buttons -->
                    <div class="btn-actions-group">
                        <?php if ($isWithdrawPending): ?>
                            <button type="submit" name="cancel_withdrawal" class="btn-cancel-danger" formnovalidate onclick="return confirm('Are you sure you want to cancel your pending withdrawal request for Batch <?php echo htmlspecialchars($selectedBatch, ENT_QUOTES); ?>? Your enrollment will remain active.');">
                                <i class="fa-solid fa-ban"></i> Cancel Withdrawal Request
                            </button>
                        <?php else: ?>
                            <a href="student-enroll-page.php" class="btn-cancel">
                                <i class="fa-solid fa-xmark"></i> Cancel
                            </a>
                        <?php endif; ?>
                        
                        <button type="submit" name="submit_withdrawal" class="btn-submit">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i> 
                            <?php echo $isWithdrawPending ? 'Update Withdrawal Request' : 'Submit Withdrawal Request'; ?>
                        </button>
                    </div>

                </form>
            </div>
        </main>
    </div>
    <script src="../assets/js/admin/student-viewWidthdraw-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
