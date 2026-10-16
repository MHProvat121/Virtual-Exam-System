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

// 4. FETCH LOGGED-IN STUDENT DETAILS
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

$studentDbId = $student['id'] ?? 0;
$studentCode = $student['student_id'] ?? '';

// 5. FETCH EXISTING ENROLLMENTS WITH DETAILED CANCELLATION & WITHDRAWAL INFO
$studentEnrollments = [];
$enrollmentsByBatch = [];
$enrolledCourseCodesByBatch = [];
$pendingEnrollCourseCodesByBatch = [];
$withdrawnCourseCodesByBatch = [];
$pendingWithdrawCourseCodesByBatch = [];

if ($dbConn && $studentDbId > 0) {
    // Auto-clean any orphaned enrollments whose batch was removed from `batches` table
    try {
        $dbConn->exec("
            DELETE e FROM `student_enrollments` e 
            LEFT JOIN `batches` b 
                   ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci 
            WHERE b.id IS NULL
        ");
    } catch (PDOException $e) {
        // Ignore error if temporary lock
    }

    // Clean any records cancelled by the student so they have no impact on admin page or enroll
    try {
        $dbConn->exec("DELETE ec FROM `student_enrollment_courses` ec JOIN `student_enrollments` e ON ec.enrollment_id = e.id WHERE e.cancellation_reason = 'Cancelled by student'");
        $dbConn->exec("DELETE FROM `student_enrollments` WHERE `cancellation_reason` = 'Cancelled by student'");
    } catch (PDOException $e) {}

    // Auto-clean older cancelled enrollments if student now has an approved ('Enrolled') enrollment for that batch
    try {
        $dbConn->exec("
            DELETE ec FROM `student_enrollment_courses` ec
            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.status = 'Cancelled' 
              AND e2.status = 'Enrolled'
        ");
        $dbConn->exec("
            DELETE e FROM `student_enrollments` e
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.status = 'Cancelled' 
              AND e2.status = 'Enrolled'
        ");
    } catch (PDOException $e) {}

    // Auto-clean older rejected withdrawals if student now has an approved ('Withdrawn') withdrawal for that batch
    try {
        $dbConn->exec("
            DELETE wc FROM `student_withdrawal_courses` wc
            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.withdraw_status = 'Rejected' 
              AND e2.withdraw_status = 'Withdrawn'
        ");
        $dbConn->exec("
            DELETE e FROM `student_enrollments` e
            INNER JOIN `student_enrollments` e2 
                    ON (e.student_id = e2.student_id OR CAST(e.student_id AS CHAR) = CAST(e2.student_id AS CHAR))
                   AND e.batch COLLATE utf8mb4_unicode_ci = e2.batch COLLATE utf8mb4_unicode_ci
            WHERE e.withdraw_status = 'Rejected' 
              AND e2.withdraw_status = 'Withdrawn'
        ");
    } catch (PDOException $e) {}

    try {
        $stmtEnr = $dbConn->prepare("
            SELECT e.*
            FROM `student_enrollments` e
            INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
                    ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
            WHERE (e.`student_id` = ? OR CAST(e.`student_id` AS CHAR) = ?)
            ORDER BY e.id DESC
        ");
        $stmtEnr->execute([$studentDbId, $studentCode ?: (string)$studentDbId]);
        $enrIds = [];
        $enrollmentMapById = [];

        while ($row = $stmtEnr->fetch(PDO::FETCH_ASSOC)) {
            $b = $row['batch'];
            $enrollmentsByBatch[$b][] = $row;
            $enrollmentMapById[$row['id']] = $row;
            // Rows are ordered by e.id DESC, so the first encountered row is the newest record
            if (!isset($studentEnrollments[$b])) {
                $studentEnrollments[$b] = $row;
            }
            if (!empty($row['id'])) {
                $enrIds[$row['id']] = $b;
            }
        }

        // Fetch course-level enrollment and withdrawal records if tables exist
        if (!empty($enrIds)) {
            $idList = implode(',', array_map('intval', array_keys($enrIds)));

            try {
                $stmtEC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_enrollment_courses` WHERE `enrollment_id` IN ($idList)");
                while ($eRow = $stmtEC->fetch(PDO::FETCH_ASSOC)) {
                    $eid = (int)$eRow['enrollment_id'];
                    $bName = $enrIds[$eid] ?? '';
                    $rec = $enrollmentMapById[$eid] ?? [];
                    if ($bName) {
                        if (($rec['status'] ?? '') === 'Pending') {
                            $pendingEnrollCourseCodesByBatch[$bName][] = $eRow['course_code'];
                        } elseif (($rec['status'] ?? '') === 'Enrolled') {
                            $enrolledCourseCodesByBatch[$bName][] = $eRow['course_code'];
                        }
                    }
                }
            } catch (PDOException $e) {
                // Table might not exist yet
            }

            try {
                $stmtWC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_withdrawal_courses` WHERE `enrollment_id` IN ($idList)");
                while ($wRow = $stmtWC->fetch(PDO::FETCH_ASSOC)) {
                    $eid = (int)$wRow['enrollment_id'];
                    $bName = $enrIds[$eid] ?? '';
                    $rec = $enrollmentMapById[$eid] ?? [];
                    if ($bName) {
                        $wStatus = $rec['withdraw_status'] ?? 'None';
                        if ($wStatus === 'Pending') {
                            $pendingWithdrawCourseCodesByBatch[$bName][] = $wRow['course_code'];
                        } elseif ($wStatus === 'Withdrawn') {
                            $withdrawnCourseCodesByBatch[$bName][] = $wRow['course_code'];
                        }
                    }
                }
            } catch (PDOException $e) {
                // Table might not exist yet
            }

            // Withdrawn courses are NOT enrolled! Remove withdrawn courses from enrolled courses list
            foreach ($withdrawnCourseCodesByBatch as $bKey => $wCodes) {
                if (!empty($enrolledCourseCodesByBatch[$bKey])) {
                    $enrolledCourseCodesByBatch[$bKey] = array_values(array_diff($enrolledCourseCodesByBatch[$bKey], $wCodes));
                }
            }

            // If a batch's latest/current enrollment has withdraw_status = 'Withdrawn' or status = 'Withdrawn'
            // and no course-specific withdrawal records were stored, it means the whole batch was withdrawn
            foreach ($studentEnrollments as $bKey => $sRec) {
                if (($sRec['withdraw_status'] ?? '') === 'Withdrawn' || (($sRec['status'] ?? '') === 'Withdrawn' && ($sRec['withdraw_status'] ?? '') !== 'Rejected' && ($sRec['withdraw_status'] ?? '') !== 'Pending')) {
                    if (empty($withdrawnCourseCodesByBatch[$bKey])) {
                        $enrolledCourseCodesByBatch[$bKey] = [];
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Fallback if withdrawal columns are not yet migrated
        $stmtEnr = $dbConn->prepare("
            SELECT e.* 
            FROM `student_enrollments` e
            INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
                    ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
            WHERE (e.`student_id` = ? OR CAST(e.`student_id` AS CHAR) = ?)
            ORDER BY e.id DESC
        ");
        $stmtEnr->execute([$studentDbId, $studentCode ?: (string)$studentDbId]);
        $enrIds = [];
        while ($row = $stmtEnr->fetch(PDO::FETCH_ASSOC)) {
            $enrollmentsByBatch[$row['batch']][] = $row;
            if (!isset($studentEnrollments[$row['batch']])) {
                $studentEnrollments[$row['batch']] = $row;
            } elseif ($row['status'] === 'Enrolled' && ($studentEnrollments[$row['batch']]['status'] ?? '') !== 'Enrolled') {
                $studentEnrollments[$row['batch']] = $row;
            }
            if (!empty($row['id'])) {
                $enrIds[$row['id']] = $row['batch'];
            }
        }

        if (!empty($enrIds)) {
            $idList = implode(',', array_map('intval', array_keys($enrIds)));
            try {
                $stmtEC = $dbConn->query("SELECT enrollment_id, course_code FROM `student_enrollment_courses` WHERE `enrollment_id` IN ($idList)");
                while ($eRow = $stmtEC->fetch(PDO::FETCH_ASSOC)) {
                    $bName = $enrIds[$eRow['enrollment_id']] ?? '';
                    if ($bName) {
                        $enrolledCourseCodesByBatch[$bName][] = $eRow['course_code'];
                    }
                }
            } catch (PDOException $e) {}
        }
    }
}

// 6. FETCH DATA FOR ALL BATCHES
$groupedExams = [];
if ($dbConn) {
    $stmt = $dbConn->prepare("
        SELECT 
            b.*, 
            COALESCE(t.full_name, 'Unknown Teacher') AS teacher_full_name, 
            COALESCE(t.teacher_id, b.teacher_id, 'N/A') AS display_teacher_id 
        FROM `batches` b 
        LEFT JOIN `teachers` t 
            ON (b.teacher_id COLLATE utf8mb4_unicode_ci = t.teacher_id COLLATE utf8mb4_unicode_ci 
             OR b.teacher_id COLLATE utf8mb4_unicode_ci = CAST(t.id AS CHAR) COLLATE utf8mb4_unicode_ci) 
        ORDER BY b.batch ASC, b.id DESC
    ");
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
        $groupedExams[$row['batch']][] = $row; 
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course Enrollment - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-enroll-page.css"> 
     
    <link rel="stylesheet" href="../assets/css/student/student-courseSyllabus-page.css">
    
</head>
<body>
    <div class="main-layout">
        <?php if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) { require_once __DIR__ . '/student-header-sidebar-section.php'; } ?>

        <main class="dashboard">
            <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1>Available Batches & Enrollment</h1>
                <a href="../common-portal/view-payment-guideline-page.php" class="btn-action btn-view-course">
                    <i class="fa-solid fa-circle-info"></i> View Payment Details
                </a>
            </div>

            <!-- Flash Messages -->
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success" style="padding: 12px 16px; background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_warning'])): ?>
                <div class="alert alert-warning" style="padding: 12px 16px; background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($_SESSION['flash_warning']); unset($_SESSION['flash_warning']); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_danger'])): ?>
                <div class="alert alert-danger" style="padding: 12px 16px; background-color: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($_SESSION['flash_danger']); unset($_SESSION['flash_danger']); ?></span>
                </div>
            <?php endif; ?>

            <!-- TOP SEARCH BAR -->
            <div class="search-bar-container">
                <i class="fa-solid fa-magnifying-glass" style="color: #64748b; font-size: 16px;"></i>
                <input type="text" id="batchSearchInput" placeholder="Search by Batch Name, Subject, Exam Title, or Teacher Name...">
            </div>

            <div id="noSearchResults" style="display:none; text-align:center; padding: 30px; background:#fff; border-radius:12px; border:1px solid #e2e8f0; color:#64748b;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:24px; color:#cbd5e1; margin-bottom:8px;"></i>
                <p>No matching batches found.</p>
            </div>

            <section class="table-section-container" id="batchCardsContainer">
                <?php if (!empty($groupedExams)): foreach ($groupedExams as $batchName => $exams): 
                    $earliestRecord = $exams[count($exams) - 1];
                    $batchCreatedBy = !empty($earliestRecord['teacher_full_name']) ? $earliestRecord['teacher_full_name'] : 'N/A';
                    $batchCreationDate = !empty($earliestRecord['created_at']) ? date('M d, Y', strtotime($earliestRecord['created_at'])) : 'N/A';
                    
                    // Student Enrollment Details for this batch
                    $batchEnrollmentList = $enrollmentsByBatch[$batchName] ?? [];
                    $enrDetails = $studentEnrollments[$batchName] ?? [];

                    // Check if any active enrollment record for this batch has withdraw_status = 'Pending'
                    $isWithdrawPending = false;
                    $withdrawPendingEnr = null;
                    foreach ($batchEnrollmentList as $bEnr) {
                        if (($bEnr['withdraw_status'] ?? '') === 'Pending') {
                            $isWithdrawPending = true;
                            $withdrawPendingEnr = $bEnr;
                            break;
                        }
                    }

                    $batchPendingWithdrawList = $pendingWithdrawCourseCodesByBatch[$batchName] ?? [];
                    if (!empty($batchPendingWithdrawList)) {
                        $isWithdrawPending = true;
                    }

                    // Check if any enrollment record for this batch has status = 'Pending'
                    $isEnrollPending = false;
                    $enrollPendingRecord = null;
                    foreach ($batchEnrollmentList as $bEnr) {
                        if (($bEnr['status'] ?? '') === 'Pending') {
                            $isEnrollPending = true;
                            $enrollPendingRecord = $bEnr;
                            break;
                        }
                    }

                    $batchPendingEnrollList = $pendingEnrollCourseCodesByBatch[$batchName] ?? [];
                    if (!empty($batchPendingEnrollList)) {
                        $isEnrollPending = true;
                    }

                    // Check if any record in this batch is truly actively Enrolled (not withdrawn)
                    $hasAnyActiveEnrolledRecord = false;
                    foreach ($batchEnrollmentList as $bEnr) {
                        if (($bEnr['status'] ?? '') === 'Enrolled' && ($bEnr['withdraw_status'] ?? '') !== 'Withdrawn') {
                            $hasAnyActiveEnrolledRecord = true;
                            if (($enrDetails['status'] ?? '') !== 'Enrolled' || ($enrDetails['withdraw_status'] ?? '') === 'Withdrawn') {
                                $enrDetails = $bEnr;
                            }
                            break;
                        }
                    }

                    $isSelfCancelled = (($enrDetails['cancellation_reason'] ?? '') === 'Cancelled by student');
                    $currentStatus = (!$isSelfCancelled && !empty($enrDetails['status'])) ? $enrDetails['status'] : 'Unenrolled';
                    if ($hasAnyActiveEnrolledRecord && $currentStatus !== 'Enrolled') {
                        $currentStatus = 'Enrolled';
                    } elseif (!$hasAnyActiveEnrolledRecord && $isEnrollPending) {
                        $currentStatus = 'Pending';
                    }

                    // Student Withdrawal Details for this batch
                    $currentWithdrawStatus = $enrDetails['withdraw_status'] ?? 'None';
                    if ($isWithdrawPending) {
                        $currentWithdrawStatus = 'Pending';
                    }

                    // Specific courses recorded for this student in this batch
                    $batchEnrolledList = $enrolledCourseCodesByBatch[$batchName] ?? [];
                    $batchWithdrawnList = $withdrawnCourseCodesByBatch[$batchName] ?? [];

                    // If batch is Withdrawn and no specific course rows, all courses in batch are withdrawn
                    if ($currentWithdrawStatus === 'Withdrawn' && empty($batchWithdrawnList)) {
                        foreach ($exams as $ex) {
                            if (!empty($ex['course_code'])) {
                                $batchWithdrawnList[] = $ex['course_code'];
                            }
                        }
                        $batchEnrolledList = [];
                    }

                    $hasEnrolledList = !empty($batchEnrolledList);
                    $hasWithdrawnList = !empty($batchWithdrawnList);

                    // Calculate course status counts for this batch
                    $validExams = array_filter($exams, function($e) { return !empty($e['subject']); });
                    $rowCount = count($validExams);

                    $activeEnrolledCount = 0;
                    $withdrawnCount = 0;
                    $unenrolledCount = 0;

                    foreach ($validExams as $ex) {
                        $cCode = $ex['course_code'] ?? '';
                        
                        $cWithdrawn = !empty($batchWithdrawnList) ? in_array($cCode, $batchWithdrawnList) : ($currentWithdrawStatus === 'Withdrawn');

                        if ($cWithdrawn) {
                            $cEnrolled = false;
                        } elseif ($hasEnrolledList) {
                            $cEnrolled = in_array($cCode, $batchEnrolledList);
                        } else {
                            $cEnrolled = ($currentStatus === 'Enrolled');
                        }

                        if ($cEnrolled) {
                            $activeEnrolledCount++;
                        } elseif ($cWithdrawn) {
                            $withdrawnCount++;
                            $unenrolledCount++;
                        } else {
                            $unenrolledCount++;
                        }
                    }

                    $isFullyWithdrawn = ($activeEnrolledCount === 0 && ($withdrawnCount > 0 || $currentWithdrawStatus === 'Withdrawn'));
                    $hasWithdrawnCourses = ($withdrawnCount > 0 || $currentWithdrawStatus === 'Withdrawn');
                    $isWithdrawn = $isFullyWithdrawn;

                    if ($isFullyWithdrawn) {
                        $currentStatus = 'Withdrawn';
                        $currentWithdrawStatus = 'Withdrawn';
                    }

                    $statusClass = 'status-' . strtolower($currentStatus);
                    $withdrawStatusClass = 'status-' . strtolower(str_replace(' ', '-', $currentWithdrawStatus));
                    $isEnrolled = ($currentStatus === 'Enrolled');
                    $isCancelled = ($currentStatus === 'Cancelled');

                    // Check for cancellation notice from admin for enrollment request:
                    // Only show if the enrollment was cancelled by admin, and the student does NOT have an approved enrollment or pending request in this batch
                    $adminCancelledEnroll = null;
                    $hasAnyApprovedEnrollment = false;
                    foreach ($batchEnrollmentList as $bEnr) {
                        if (($bEnr['status'] ?? '') === 'Enrolled') {
                            $hasAnyApprovedEnrollment = true;
                            break;
                        }
                    }

                    if (!$hasAnyApprovedEnrollment && !$hasAnyActiveEnrolledRecord && !$isEnrollPending) {
                        foreach ($batchEnrollmentList as $bEnr) {
                            if (($bEnr['status'] ?? '') === 'Cancelled' && ($bEnr['cancellation_reason'] ?? '') !== 'Cancelled by student') {
                                $adminCancelledEnroll = $bEnr;
                                break;
                            }
                        }
                    }

                    // Check for cancellation notice from admin for withdrawal request:
                    // Only show if withdrawal request was cancelled/rejected by admin, and the student does NOT have an approved withdrawal or pending withdrawal in this batch
                    $adminCancelledWithdraw = null;
                    $hasAnyApprovedWithdrawal = false;
                    foreach ($batchEnrollmentList as $bEnr) {
                        if (($bEnr['withdraw_status'] ?? '') === 'Withdrawn' || ($bEnr['status'] ?? '') === 'Withdrawn') {
                            $hasAnyApprovedWithdrawal = true;
                            break;
                        }
                    }

                    if (!$hasAnyApprovedWithdrawal && !$isWithdrawPending && $currentWithdrawStatus !== 'Withdrawn') {
                        foreach ($batchEnrollmentList as $bEnr) {
                            if (($bEnr['withdraw_status'] ?? '') === 'Rejected' || !empty($bEnr['withdraw_cancelled_by_name'])) {
                                $adminCancelledWithdraw = $bEnr;
                                break;
                            }
                        }
                    }
                ?>
                    <div class="batch-card" data-batch="<?php echo htmlspecialchars($batchName); ?>" style="background:#fff; border-radius:12px; border: 1px solid #e2e8f0; margin-bottom: 25px; overflow:hidden;">
                        
                        <!-- Batch Card Header -->
                        <div class="batch-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding: 18px 22px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            
                            <!-- Left Info -->
                            <div class="batch-title-info">
                                <h2 style="font-size: 18px; color: #1e293b; margin-bottom: 4px;">
                                    <i class="fa-solid fa-users-rectangle"></i> Batch: <?php echo htmlspecialchars($batchName); ?>
                                    <span class="status-badge <?php echo $statusClass; ?>" style="margin-left: 8px;">
                                        <?php echo htmlspecialchars($currentStatus); ?>
                                    </span>
                                </h2>
                                <div class="batch-meta-info" style="font-size: 13px; color: #64748b;">
                                    <span><i class="fa-solid fa-user" style="color: #0266cc;"></i> <strong>Created by:</strong> <?php echo htmlspecialchars($batchCreatedBy); ?></span>
                                    <span style="margin-left: 15px;"><i class="fa-solid fa-calendar-days" style="color: #059669;"></i> <strong>Creation date:</strong> <?php echo htmlspecialchars($batchCreationDate); ?></span>
                                </div>
                            </div>

                            <!-- Right Actions (Enrollment + Withdraw + View Course) -->
                            <div class="batch-header-actions">
                                
                                <!-- 1. ENROLLMENT BUTTON (Shows 'Enroll Pending' when pending approval, else 'Enroll Now') -->
                                <?php if ($isEnrollPending || !empty($batchPendingEnrollList) || $currentStatus === 'Pending'): 
                                    $targetEnrollPendingId = !empty($enrollPendingRecord['id']) ? (int)$enrollPendingRecord['id'] : (int)($enrDetails['id'] ?? 0);
                                ?>
                                    <a href="student-viewEnroll-page.php?batch=<?php echo urlencode($batchName); ?>&mode=pending&enrollment_id=<?php echo $targetEnrollPendingId; ?>" class="btn-action btn-enroll" style="background:#d97706;" data-batch="<?php echo htmlspecialchars($batchName); ?>">
                                        <i class="fa-solid fa-clock"></i> Enroll Pending
                                    </a>
                                <?php else: ?>
                                    <a href="student-viewEnroll-page.php?batch=<?php echo urlencode($batchName); ?>" class="btn-action btn-enroll" data-batch="<?php echo htmlspecialchars($batchName); ?>">
                                        <i class="fa-solid fa-pen-to-square"></i> Enroll Now
                                    </a>
                                <?php endif; ?>

                                <!-- 2. WITHDRAW BUTTON (Shows 'Withdraw Pending' when pending approval, else 'Withdraw') -->
                                <?php if ($isWithdrawPending || !empty($batchPendingWithdrawList)): 
                                    $targetWithdrawPendingId = !empty($withdrawPendingEnr['id']) ? (int)$withdrawPendingEnr['id'] : (int)($enrDetails['id'] ?? 0);
                                ?>
                                    <a href="student-viewWidthdraw-page.php?batch=<?php echo urlencode($batchName); ?>&mode=pending&enrollment_id=<?php echo $targetWithdrawPendingId; ?>" class="btn-action btn-withdraw" style="background:#ea580c;" data-batch="<?php echo htmlspecialchars($batchName); ?>">
                                        <i class="fa-solid fa-clock"></i> Withdraw Pending
                                    </a>
                                <?php else: ?>
                                    <a href="student-viewWidthdraw-page.php?batch=<?php echo urlencode($batchName); ?>" class="btn-action btn-withdraw" data-batch="<?php echo htmlspecialchars($batchName); ?>">
                                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Withdraw
                                    </a>
                                <?php endif; ?>

                                <!-- 3. VIEW COURSE BUTTON -->
                                <?php if ($activeEnrolledCount > 0): ?>
                                    <a href="#table-<?php echo htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '-', $batchName)); ?>" class="btn-action btn-view-course">
                                        <i class="fa-solid fa-book-open"></i> View Course
                                    </a>
                                <?php elseif ($hasWithdrawnCourses): ?>
                                    <button class="btn-action btn-view-course disabled" disabled title="Course/Batch is withdrawn. Please enroll to access courses.">
                                        <i class="fa-solid fa-lock"></i> View Course
                                    </button>
                                <?php elseif ($currentStatus === 'Pending'): ?>
                                    <button class="btn-action btn-view-course disabled" disabled title="Enrollment must be approved by Admin before accessing courses.">
                                        <i class="fa-solid fa-lock"></i> View Course
                                    </button>
                                <?php else: ?>
                                    <button class="btn-action btn-view-course disabled" disabled title="Please enroll to access course content.">
                                        <i class="fa-solid fa-lock"></i> View Course
                                    </button>
                                <?php endif; ?>

                            </div>

                        </div>

                        <!-- CANCELLATION NOTICE BANNER (ONLY SHOWS WHEN REQUEST WAS CANCELLED FROM ADMIN) -->
                        <?php if ($adminCancelledWithdraw !== null): ?>
                            <div class="cancellation-notice">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px; color: #dc2626; margin-top: 2px;"></i>
                                    <div>
                                        <strong>Notice: Your withdrawal request for Batch <?php echo htmlspecialchars($batchName); ?> was cancelled by Admin.</strong>
                                        <div style="margin-top: 4px; color: #7f1d1d;">
                                            <span><strong>Cancelled On:</strong> <?php echo !empty($adminCancelledWithdraw['withdraw_cancelled_at']) ? date('M d, Y - h:i A', strtotime($adminCancelledWithdraw['withdraw_cancelled_at'])) : (!empty($adminCancelledWithdraw['withdraw_action_at']) ? date('M d, Y - h:i A', strtotime($adminCancelledWithdraw['withdraw_action_at'])) : 'N/A'); ?></span> | 
                                            <span><strong>Cancelled By:</strong> <?php echo htmlspecialchars($adminCancelledWithdraw['withdraw_cancelled_by_name'] ?? $adminCancelledWithdraw['withdraw_reviewed_by_name'] ?? 'Admin'); ?> (ID: <?php echo htmlspecialchars($adminCancelledWithdraw['withdraw_cancelled_by_id'] ?? $adminCancelledWithdraw['withdraw_reviewed_by_id'] ?? 'N/A'); ?>)</span>
                                            <?php if (!empty($adminCancelledWithdraw['withdraw_reason'])): ?>
                                                <br><span><strong>Reason / Note:</strong> <?php echo htmlspecialchars($adminCancelledWithdraw['withdraw_reason']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="margin-top: 6px; font-weight: 600; color: #991b1b;">
                                            <i class="fa-solid fa-circle-info" style="margin-right: 4px;"></i> Your enrollment remains active. Please contact the administration if you have questions.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($adminCancelledEnroll !== null): ?>
                            <div class="cancellation-notice">
                                <div style="display: flex; align-items: flex-start; gap: 10px;">
                                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px; color: #dc2626; margin-top: 2px;"></i>
                                    <div>
                                        <strong>Notice: Your enrollment request for Batch <?php echo htmlspecialchars($batchName); ?> was cancelled by Admin.</strong>
                                        <div style="margin-top: 4px; color: #7f1d1d;">
                                            <span><strong>Cancelled On:</strong> <?php echo !empty($adminCancelledEnroll['cancelled_at']) ? date('M d, Y - h:i A', strtotime($adminCancelledEnroll['cancelled_at'])) : (!empty($adminCancelledEnroll['action_at']) ? date('M d, Y - h:i A', strtotime($adminCancelledEnroll['action_at'])) : 'N/A'); ?></span> | 
                                            <span><strong>Cancelled By:</strong> <?php echo htmlspecialchars($adminCancelledEnroll['cancelled_by_name'] ?? $adminCancelledEnroll['reviewed_by_name'] ?? 'Admin'); ?> (ID: <?php echo htmlspecialchars($adminCancelledEnroll['cancelled_by_id'] ?? $adminCancelledEnroll['reviewed_by_id'] ?? 'N/A'); ?>)</span>
                                            <?php if (!empty($adminCancelledEnroll['cancellation_reason'])): ?>
                                                <br><span><strong>Reason:</strong> <?php echo htmlspecialchars($adminCancelledEnroll['cancellation_reason']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="margin-top: 6px; font-weight: 600; color: #991b1b;">
                                            <i class="fa-solid fa-phone" style="margin-right: 4px;"></i> Please contact the office for further inquiry. You may click <strong>"Enroll Now"</strong> to submit a new request.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Course Table -->
                        <div class="table-responsive" id="table-<?php echo htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '-', $batchName)); ?>">
                            <table>
                                <colgroup>
                                    <col style="width: 7%;">
                                    <col style="width: 12%;">
                                    <col style="width: 8%;">
                                    <col style="width: 8%;">
                                    <col style="width: 5%;">
                                    <col style="width: 9%;">
                                    <col style="width: 10%;">
                                    <col style="width: 13%;">
                                    <col style="width: 13%;">
                                    <col style="width: 8%;">
                                    <col style="width: 7%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Teacher Name</th>
                                        <th>Teacher ID</th>
                                        <th>Course Code</th>
                                        <th>Credit</th>
                                        <th>Subject</th>
                                        <th>Exam Title</th>
                                        <th style="text-align: center;">Select (To Enroll)</th>
                                        <th style="text-align: center;">Select (To Widthdraw)</th>
                                        <th style="text-align: center;">Enrollment Status</th>
                                        <th style="text-align: center;">Widthraw Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $validExams = array_filter($exams, function($e) { return !empty($e['subject']); });
                                    $rowCount = count($validExams);
                                    $isFirstRow = true;

                                    if ($rowCount > 0):
                                        foreach ($validExams as $exam): 
                                            $courseCode = $exam['course_code'] ?? '';

                                            // 1. Is this specific course withdrawn?
                                            if (!empty($batchWithdrawnList)) {
                                                $isThisCourseWithdrawn = in_array($courseCode, $batchWithdrawnList);
                                            } else {
                                                $isThisCourseWithdrawn = ($currentWithdrawStatus === 'Withdrawn');
                                            }

                                            // 2. Is this specific course enrolled?
                                            if ($isThisCourseWithdrawn) {
                                                $isThisCourseEnrolled = false;
                                            } elseif ($hasEnrolledList) {
                                                $isThisCourseEnrolled = in_array($courseCode, $batchEnrolledList);
                                            } else {
                                                $isThisCourseEnrolled = ($currentStatus === 'Enrolled');
                                            }

                                            // 3. Is this specific course actively enrolled?
                                            $isCourseActiveEnrolled = $isThisCourseEnrolled && !$isThisCourseWithdrawn;

                                            // 4. Is this specific course withdrawal pending?
                                            $isThisCourseWithdrawPending = false;
                                            if (!empty($batchPendingWithdrawList)) {
                                                foreach ($batchPendingWithdrawList as $pwc) {
                                                    if (strcasecmp(trim($pwc), trim($courseCode)) === 0) {
                                                        $isThisCourseWithdrawPending = true;
                                                        break;
                                                    }
                                                }
                                            } elseif ($isWithdrawPending && $isCourseActiveEnrolled) {
                                                $isThisCourseWithdrawPending = true;
                                            }

                                            // 5. Is this specific course enrollment pending?
                                            if (!empty($pendingEnrollCourseCodesByBatch[$batchName])) {
                                                $isThisCourseEnrollPending = in_array($courseCode, $pendingEnrollCourseCodesByBatch[$batchName]);
                                            } else {
                                                $isThisCourseEnrollPending = ($currentStatus === 'Pending') && !$isThisCourseEnrolled;
                                            }

                                            // 6. Course-level Enrollment & Withdrawal Status for <td> rendering
                                            if ($isCourseActiveEnrolled) {
                                                $courseEnrollStatus = 'Enrolled';
                                                $courseEnrollClass  = 'status-enrolled';
                                            } elseif ($isThisCourseEnrollPending) {
                                                $courseEnrollStatus = 'Pending';
                                                $courseEnrollClass  = 'status-pending';
                                            } elseif ($isCancelled && !$isThisCourseWithdrawn) {
                                                $courseEnrollStatus = 'Cancelled';
                                                $courseEnrollClass  = 'status-cancelled';
                                            } else {
                                                $courseEnrollStatus = 'Unenrolled';
                                                $courseEnrollClass  = 'status-unenrolled';
                                            }

                                            if ($isThisCourseWithdrawn) {
                                                $courseWithdrawStatus = 'Withdrawn';
                                                $courseWithdrawClass  = 'status-withdrawn';
                                            } elseif ($isThisCourseWithdrawPending) {
                                                $courseWithdrawStatus = 'Pending';
                                                $courseWithdrawClass  = 'status-pending';
                                            } else {
                                                $courseWithdrawStatus = 'None';
                                                $courseWithdrawClass  = 'status-none';
                                            }
                                    ?>
                                    <tr data-course-code="<?php echo htmlspecialchars($courseCode); ?>" data-enroll-status="<?php echo htmlspecialchars($courseEnrollStatus); ?>" data-withdraw-status="<?php echo htmlspecialchars($courseWithdrawStatus); ?>">
                                        <td><strong><?php echo htmlspecialchars($exam['batch']); ?></strong></td>
                                        <td>
                                            <i class="fa-solid fa-user-tie" style="margin-right: 6px; color: #0266cc;"></i>
                                            <?php echo htmlspecialchars($exam['teacher_full_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($exam['display_teacher_id'] ?? 'N/A'); ?></td>
                                        <td><strong style="color: #4338ca;"><?php echo htmlspecialchars(!empty($exam['course_code']) ? $exam['course_code'] : 'N/A'); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars(!empty($exam['credit']) ? $exam['credit'] : 'N/A'); ?></strong></td>
                                        
                                        

                                        <td><span class="badge-subject"><?php echo htmlspecialchars($exam['subject'] ?? 'N/A'); ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($exam['exam_title'] ?? 'N/A'); ?></strong></td>

                                        <!-- Checkbox: Select (To Enroll) -->
                                        <td style="text-align: center;">
                                            <input type="checkbox" 
                                                   name="enroll_courses[<?php echo htmlspecialchars($batchName); ?>][]" 
                                                   value="<?php echo htmlspecialchars($courseCode); ?>" 
                                                   class="course-checkbox course-enroll-check" 
                                                   data-batch="<?php echo htmlspecialchars($batchName); ?>"
                                                   data-course-code="<?php echo htmlspecialchars($courseCode); ?>"
                                                   data-enroll-status="<?php echo htmlspecialchars($courseEnrollStatus); ?>"
                                                   data-withdraw-status="<?php echo htmlspecialchars($courseWithdrawStatus); ?>"
                                                   data-is-enrolled="<?php echo ($courseEnrollStatus === 'Enrolled') ? '1' : '0'; ?>"
                                                   data-is-pending="<?php echo ($courseEnrollStatus === 'Pending') ? '1' : '0'; ?>"
                                                   data-is-withdrawn="<?php echo ($courseWithdrawStatus === 'Withdrawn') ? '1' : '0'; ?>"
                                                   <?php 
                                                   if ($courseEnrollStatus === 'Enrolled') {
                                                       echo 'checked disabled title="Already Enrolled"';
                                                   } elseif ($courseEnrollStatus === 'Pending') {
                                                       echo 'checked disabled title="Enrollment Pending Approval"';
                                                   } else {
                                                       echo 'title="Select course to enroll"';
                                                   }
                                                   ?>
                                                   aria-label="Select course to enroll">
                                        </td>

                                        <!-- Checkbox: Select (To Widthdraw) -->
                                        <td style="text-align: center;">
                                            <input type="checkbox" 
                                                   name="withdraw_courses[<?php echo htmlspecialchars($batchName); ?>][]" 
                                                   value="<?php echo htmlspecialchars($courseCode); ?>" 
                                                   class="course-checkbox course-withdraw-check" 
                                                   data-batch="<?php echo htmlspecialchars($batchName); ?>"
                                                   data-course-code="<?php echo htmlspecialchars($courseCode); ?>"
                                                   data-enroll-status="<?php echo htmlspecialchars($courseEnrollStatus); ?>"
                                                   data-withdraw-status="<?php echo htmlspecialchars($courseWithdrawStatus); ?>"
                                                   data-is-enrolled="<?php echo ($courseEnrollStatus === 'Enrolled') ? '1' : '0'; ?>"
                                                   data-is-withdraw-pending="<?php echo ($courseWithdrawStatus === 'Pending') ? '1' : '0'; ?>"
                                                   data-is-withdrawn="<?php echo ($courseWithdrawStatus === 'Withdrawn') ? '1' : '0'; ?>"
                                                   <?php 
                                                   // Status logic:
                                                   // if (unenrolled or cancelled = unenrolled) -> widthdraw checkbox never opens
                                                   // if (pending) -> widthdraw checkbox never opens
                                                   // if (enrolled) -> widthdraw checkbox opens
                                                   if ($courseWithdrawStatus === 'Pending') {
                                                       echo 'checked disabled title="Withdrawal Pending Approval"';
                                                   } elseif ($courseEnrollStatus === 'Pending') {
                                                       echo 'disabled title="Enrollment Pending Approval"';
                                                   } elseif ($courseEnrollStatus === 'Enrolled') {
                                                       echo 'title="Select course to withdraw"';
                                                   } else {
                                                       // unenrolled or cancelled = unenrolled -> never opens
                                                       echo 'disabled title="Withdrawal is not available for unenrolled courses"';
                                                   }
                                                   ?>
                                                   aria-label="Select course to withdraw">
                                        </td>
                                        
                                        <!-- Course Enrollment Status in every <td> -->
                                        <td style="vertical-align: middle; text-align: center; background: #fff;">
                                            <span class="status-badge <?php echo $courseEnrollClass; ?>">
                                                <?php echo htmlspecialchars($courseEnrollStatus); ?>
                                            </span>
                                        </td>

                                        <!-- Course Widthraw Status in every <td> -->
                                        <td style="vertical-align: middle; text-align: center; background: #fff;">
                                            <span class="status-badge <?php echo $courseWithdrawClass; ?>">
                                                <?php echo htmlspecialchars($courseWithdrawStatus); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php 
                                        endforeach; 
                                    else: 
                                    ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; color: #64748b;">No subjects published yet for this batch.</td>
                                        <td style="text-align: center;">
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($currentStatus); ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="status-badge <?php echo $withdrawStatusClass; ?>"><?php echo htmlspecialchars($currentWithdrawStatus); ?></span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="empty-batch-card" style="text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <h3>No courses available.</h3>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Confirmation Modal for All Courses (Enroll / Withdraw) -->
    <div id="batchActionModal" class="batch-modal-overlay" style="display: none;">
        <div class="batch-modal-card">
            <div class="batch-modal-icon" id="batchModalIcon">
                <i class="fa-solid fa-circle-question"></i>
            </div>
            <h3 id="batchModalTitle" class="batch-modal-title">Confirm Action</h3>
            <p id="batchModalPrompt" class="batch-modal-prompt"></p>
            <div class="batch-modal-actions">
                <button type="button" id="batchModalCancelBtn" class="batch-modal-btn batch-modal-cancel">Cancel</button>
                <button type="button" id="batchModalConfirmBtn" class="batch-modal-btn batch-modal-confirm">Yes</button>
            </div>
        </div>
    </div>

    <!-- JavaScript Files & Live Search Logic -->
    <script src="../assets/js/student/student-enroll-page.js"></script>
    <script src="student-enroll-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    
</body>
</html>
