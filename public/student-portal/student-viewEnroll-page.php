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

// 5. Individual batch from the table clicked (options part removed)

// 6. FETCH EXISTING ENROLLMENT INFO (Only if in explicit Pending Update Info mode)
$mode = trim($_GET['mode'] ?? ($_POST['mode'] ?? ''));
$targetEnrollmentId = !empty($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : (!empty($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0);
$existingEnrollment = null;

if ($dbConn && ($studentId > 0 || !empty($studentCode)) && !empty($selectedBatch)) {
    // Ensure unique constraint is dropped to allow multiple separate requests per batch
    try {
        $chk = $dbConn->query("SHOW INDEX FROM `student_enrollments` WHERE Key_name = 'unique_student_batch'")->fetch();
        if ($chk) {
            $dbConn->exec("ALTER TABLE `student_enrollments` DROP INDEX `unique_student_batch`");
            $dbConn->exec("ALTER TABLE `student_enrollments` ADD INDEX `idx_student_batch` (`student_id`, `batch`)");
        }
    } catch (PDOException $e) {}

    // Ensure enroll_requested_at column exists
    try {
        $colCheckReq = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'enroll_requested_at'")->fetch();
        if (!$colCheckReq) {
            $dbConn->exec("ALTER TABLE `student_enrollments` ADD `enroll_requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when student submitted enrollment request' AFTER `payment_slip`");
        }
    } catch (PDOException $e) {}

    // Load existing pending record if in 'pending' update mode or if student already has a pending enrollment for this batch
    if ($mode === 'pending' || $targetEnrollmentId > 0) {
        if ($targetEnrollmentId > 0) {
            $stmtEx = $dbConn->prepare("
                SELECT * FROM `student_enrollments` 
                WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `status` = 'Pending' 
                LIMIT 1
            ");
            $stmtEx->execute([$targetEnrollmentId, $studentId, $studentCode ?: (string)$studentId]);
            $existingEnrollment = $stmtEx->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$existingEnrollment) {
            $stmtEx = $dbConn->prepare("
                SELECT * FROM `student_enrollments` 
                WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `status` = 'Pending'
                ORDER BY `id` DESC LIMIT 1
            ");
            $stmtEx->execute([$studentId, $studentCode ?: (string)$studentId, $selectedBatch]);
            $existingEnrollment = $stmtEx->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } else {
        // Auto-check if there is already a pending enrollment for this student and batch
        $stmtEx = $dbConn->prepare("
            SELECT * FROM `student_enrollments` 
            WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `status` = 'Pending' AND `enroll_requested_at` IS NOT NULL
            ORDER BY `id` DESC LIMIT 1
        ");
        $stmtEx->execute([$studentId, $studentCode ?: (string)$studentId, $selectedBatch]);
        $existingEnrollment = $stmtEx->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingEnrollment) {
            $mode = 'pending';
            $targetEnrollmentId = (int)$existingEnrollment['id'];
        }
    }
}

$isUpdateMode          = ($existingEnrollment !== null && ($existingEnrollment['status'] ?? '') === 'Pending');
$existingAmount        = $isUpdateMode ? ($existingEnrollment['amount'] ?? '') : '';
$existingPaymentMethod = $isUpdateMode ? ($existingEnrollment['payment_method'] ?? '') : '';
$existingTransactionId = $isUpdateMode ? ($existingEnrollment['transaction_id'] ?? '') : '';
$existingPaymentSlip   = $isUpdateMode ? ($existingEnrollment['payment_slip'] ?? '') : '';
$existingStatus        = $isUpdateMode ? ($existingEnrollment['status'] ?? '') : '';

// 6.1 FETCH COURSES & CALCULATE TOTAL CREDIT
$batchCourses = [];
$selectedCourseCodes = [];
$selectedCourseList = [];
$totalCredit = 0.0;

// Read courses from GET query parameter or POST
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

// If update mode and no courses supplied in URL, fetch from `student_enrollment_courses`
if ($isUpdateMode && empty($selectedCourseCodes) && !empty($existingEnrollment['id'])) {
    try {
        $stmtSavedCourses = $dbConn->prepare("
            SELECT course_code 
            FROM `student_enrollment_courses` 
            WHERE `enrollment_id` = ?
        ");
        $stmtSavedCourses->execute([$existingEnrollment['id']]);
        $selectedCourseCodes = $stmtSavedCourses->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        // student_enrollment_courses may not have records
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
    // If no specific checkboxes were filtered, default to all courses for this batch
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

// Handle Enrollment Request Cancellation by Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_enrollment'])) {
    $batchName = trim($_POST['batch'] ?? $selectedBatch);
    $cancelId  = (int)($_POST['enrollment_id'] ?? 0);
    if (!empty($batchName) && $dbConn && ($studentId > 0 || !empty($studentCode))) {
        try {
            if ($cancelId > 0) {
                $stmtCheck = $dbConn->prepare("
                    SELECT id, status, payment_slip 
                    FROM `student_enrollments` 
                    WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) 
                    LIMIT 1
                ");
                $stmtCheck->execute([$cancelId, $studentId, $studentCode ?: (string)$studentId]);
            } else {
                $stmtCheck = $dbConn->prepare("
                    SELECT id, status, payment_slip 
                    FROM `student_enrollments` 
                    WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `batch` = ? AND `status` = 'Pending' 
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtCheck->execute([$studentId, $studentCode ?: (string)$studentId, $batchName]);
            }
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $enrollmentId = (int)$existing['id'];

                // Remove uploaded slip file if it exists
                if (!empty($existing['payment_slip']) && file_exists(__DIR__ . '/' . $existing['payment_slip'])) {
                    @unlink(__DIR__ . '/' . $existing['payment_slip']);
                }

                // Delete child records in student_enrollment_courses
                try {
                    $stmtDelC = $dbConn->prepare("DELETE FROM `student_enrollment_courses` WHERE `enrollment_id` = ?");
                    $stmtDelC->execute([$enrollmentId]);
                } catch (PDOException $e) {}

                // Delete the enrollment record so it leaves no impact on admin-manageEnroll-page and restores the status on student-enroll-page to normal Enroll
                $stmtDel = $dbConn->prepare("DELETE FROM `student_enrollments` WHERE `id` = ?");
                $stmtDel->execute([$enrollmentId]);

                $_SESSION['flash_success'] = "Enrollment request for Batch " . htmlspecialchars($batchName) . " has been cancelled.";
                header("Location: student-enroll-page.php");
                exit();
            } else {
                $_SESSION['flash_warning'] = "No enrollment request found to cancel.";
                header("Location: student-enroll-page.php");
                exit();
            }
        } catch (PDOException $e) {
            $alertMessage = "Database Error: " . $e->getMessage();
            $alertType = "danger";
        }
    }
}

// 7. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_enrollment'])) {
    $batchName        = trim($_POST['batch'] ?? '');
    $amount           = floatval($_POST['amount'] ?? 0);
    $paymentMethod    = trim($_POST['payment_method'] ?? '');
    $transactionId    = trim($_POST['transaction_id'] ?? '');
    $submittedCourses = $_POST['selected_courses'] ?? [];
    $postEnrollmentId = (int)($_POST['enrollment_id'] ?? 0);

    if (empty($batchName) || $amount <= 0 || empty($paymentMethod) || empty($transactionId) || empty($submittedCourses)) {
        $alertMessage = "Please select at least one course and fill in all required payment details.";
        $alertType = "danger";
    } else {
        // Handle File Upload
        $filePath = null;
        if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath   = $_FILES['payment_slip']['tmp_name'];
            $fileName      = $_FILES['payment_slip']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = __DIR__ . '/../../uploads/slips/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFileName = 'slip_' . $studentId . '_' . time() . '.' . $fileExtension;
                $destPath    = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $filePath = 'uploads/slips/' . $newFileName;
                }
            } else {
                $alertMessage = "Invalid file format. Only JPG, PNG, and PDF allowed.";
                $alertType = "danger";
            }
        }

        if (empty($alertMessage) && $dbConn) {
            try {
                // Check if targeting an existing pending record or creating a new separate request
                $existing = null;
                if ($postEnrollmentId > 0) {
                    $stmtCheck = $dbConn->prepare("
                        SELECT id, payment_slip, status 
                        FROM `student_enrollments` 
                        WHERE `id` = ? AND (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) AND `status` = 'Pending' 
                        LIMIT 1
                    ");
                    $stmtCheck->execute([$postEnrollmentId, $studentId, $studentCode ?: (string)$studentId]);
                    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existing) {
                    // Check if there is already an existing pending enrollment for this student and batch
                    $stmtCheck = $dbConn->prepare("
                        SELECT id, payment_slip, status 
                        FROM `student_enrollments` 
                        WHERE (`student_id` = ? OR CAST(`student_id` AS CHAR) = ?) 
                          AND `batch` = ? 
                          AND `status` = 'Pending' 
                          AND `enroll_requested_at` IS NOT NULL
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtCheck->execute([$studentId, $studentCode ?: (string)$studentId, $batchName]);
                    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                }

                $enrollmentId = null;

                if ($existing && $existing['status'] === 'Pending') {
                    $enrollmentId = (int)$existing['id'];
                    $finalSlip = ($filePath !== null) ? $filePath : $existing['payment_slip'];

                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `amount`                  = ?, 
                            `payment_method`          = ?, 
                            `transaction_id`          = ?, 
                            `payment_slip`            = ?, 
                            `enroll_requested_at`     = NOW(),
                            `status`                  = 'Pending',
                            `withdraw_status`         = 'None',
                            `withdraw_reason`         = NULL,
                            `withdraw_requested_at`   = NULL,
                            `withdraw_action_at`      = NULL,
                            `withdraw_reviewed_by_id` = NULL,
                            `withdraw_reviewed_by_name` = NULL,
                            `cancellation_reason`     = NULL,
                            `action_at`               = NULL,
                            `reviewed_by_id`          = NULL,
                            `reviewed_by_name`        = NULL
                        WHERE `id` = ?
                    ");
                    $stmtUpd->execute([$amount, $paymentMethod, $transactionId, $finalSlip, $enrollmentId]);

                    $_SESSION['flash_success'] = "Enrollment information updated successfully! Awaiting administrator review.";
                } else {
                    // Create separate enrollment request
                    $stmtIns = $dbConn->prepare("
                        INSERT INTO `student_enrollments` (`student_id`, `batch`, `amount`, `payment_method`, `transaction_id`, `payment_slip`, `enroll_requested_at`, `status`, `withdraw_status`) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Pending', 'None')
                    ");
                    $stmtIns->execute([$studentId, $batchName, $amount, $paymentMethod, $transactionId, $filePath]);
                    $enrollmentId = (int)$dbConn->lastInsertId();
                    $_SESSION['flash_success'] = "Enrollment request submitted successfully! Awaiting administrator review.";
                }

                // Save selected courses into `student_enrollment_courses` strictly for this enrollment
                if ($enrollmentId && !empty($submittedCourses) && is_array($submittedCourses)) {
                    try {
                        $stmtDelC = $dbConn->prepare("DELETE FROM `student_enrollment_courses` WHERE `enrollment_id` = ?");
                        $stmtDelC->execute([$enrollmentId]);

                        $stmtInsC = $dbConn->prepare("INSERT IGNORE INTO `student_enrollment_courses` (`enrollment_id`, `course_code`) VALUES (?, ?)");
                        foreach ($submittedCourses as $cCode) {
                            $cCode = trim($cCode);
                            if (!empty($cCode)) {
                                $stmtInsC->execute([$enrollmentId, $cCode]);
                            }
                        }
                    } catch (PDOException $e) {
                        // Fail silently if table not yet migrated
                    }
                }

                header("Location: student-enroll-page.php");
                exit();
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
    <title><?php echo $isUpdateMode ? 'Update Enrollment' : 'Course Enrollment'; ?> - Virtual Exam System</title>
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
                        <?php if ($isUpdateMode): ?>
                            <i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i> Update Enrollment Details
                        <?php else: ?>
                            <i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i> Course Enrollment Form
                        <?php endif; ?>
                    </span>
                    <a href="student-enroll-page.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Batches</a>
                </div>

                <!-- Info Alert if Editing Saved Request -->
                <?php if ($isUpdateMode): ?>
                    <div class="alert alert-info">
                        <i class="fa-solid fa-circle-info"></i> You are viewing your saved enrollment details for <strong>Batch <?php echo htmlspecialchars($selectedBatch); ?></strong> (Status: <strong><?php echo htmlspecialchars($existingStatus); ?></strong>). You can modify your information and click update below.
                    </div>
                <?php endif; ?>

                <?php if (!empty($alertMessage)): ?>
                    <div class="alert alert-<?php echo $alertType; ?>">
                        <?php echo htmlspecialchars($alertMessage); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="mode" value="<?php echo htmlspecialchars($isUpdateMode ? 'pending' : ''); ?>">
                    <?php if (!empty($existingEnrollment['id'])): ?>
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

                    <!-- Selected Courses (To Enroll) -->
                    <div class="form-group">
                        <label for="selected_courses">Selected Courses (To Enroll)</label>
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

                    <!-- ENTER PAYMENT AMOUNT FIELD -->
                    <div class="form-group">
                        <label for="amount">Payment Amount (BDT / ৳) <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" min="1" name="amount" id="amount" class="form-control" required 
                               value="<?php echo htmlspecialchars($existingAmount); ?>" 
                               placeholder="e.g. 1500.00">
                    </div>

                    <!-- Payment Method -->
                    <div class="form-group">
                        <label for="payment_method">Payment Method <span style="color:red;">*</span></label>
                        <select name="payment_method" id="payment_method" class="form-control" required>
                            <option value="">-- Select Payment Method --</option>
                            <?php 
                            $methods = ['Bkash', 'Nagad', 'Rocket', 'Bank Transfer', 'Hand Cash'];
                            foreach ($methods as $m):
                                $sel = ($existingPaymentMethod === $m) ? 'selected' : '';
                                echo "<option value=\"$m\" $sel>$m</option>";
                            endforeach;
                            ?>
                        </select>
                    </div>

                    <!-- Transaction ID -->
                    <div class="form-group">
                        <label for="transaction_id">Transaction ID / TrxID <span style="color:red;">*</span></label>
                        <input type="text" name="transaction_id" id="transaction_id" class="form-control" required 
                               value="<?php echo htmlspecialchars($existingTransactionId); ?>" 
                               placeholder="e.g. 9J87X6TYZ">
                    </div>

                    <!-- Payment Slip Upload -->
                    <div class="form-group">
                        <label for="payment_slip">
                            <?php echo $isUpdateMode ? 'Replace Payment Slip / Screenshot (Optional)' : 'Upload Payment Slip / Screenshot (JPG, PNG, PDF)'; ?>
                        </label>
                        <input type="file" name="payment_slip" id="payment_slip" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                        
                        <?php if (!empty($existingPaymentSlip)): ?>
                            <div class="current-file-badge">
                                <i class="fa-solid fa-paperclip" style="color: #0266cc;"></i> <strong>Current Saved Slip:</strong> 
                                <a href="../../<?php echo htmlspecialchars($existingPaymentSlip); ?>" target="_blank" style="color:#0266cc; text-decoration: underline; margin-left: 5px;">
                                    View Uploaded File <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 11px;"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions: Cancel and Submit Buttons -->
                    <div class="btn-actions-group">
                        <?php if ($existingStatus === 'Pending'): ?>
                            <button type="submit" name="cancel_enrollment" class="btn-cancel-danger" formnovalidate onclick="return confirm('Are you sure you want to cancel your pending enrollment request for Batch <?php echo htmlspecialchars($selectedBatch, ENT_QUOTES); ?>?');">
                                <i class="fa-solid fa-ban"></i> Cancel Request
                            </button>
                        <?php else: ?>
                            <a href="student-enroll-page.php" class="btn-cancel">
                                <i class="fa-solid fa-xmark"></i> Cancel
                            </a>
                        <?php endif; ?>
                        
                        <button type="submit" name="submit_enrollment" class="btn-submit">
                            <i class="fa-solid <?php echo $isUpdateMode ? 'fa-rotate' : 'fa-paper-plane'; ?>"></i> 
                            <?php echo $isUpdateMode ? 'Update Enrollment Information' : 'Complete Enrollment'; ?>
                        </button>
                    </div>

                </form>
            </div>
        </main>
    </div>
    <script src="../assets/js/student/student-viewEnroll-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>
</html>
