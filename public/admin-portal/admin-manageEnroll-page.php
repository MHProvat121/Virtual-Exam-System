<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Session Gatekeeper for Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Database Connection
require_once __DIR__ . '/../../src/config/config.php';
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

if ($dbConn) {
    $dbConn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Clean up withdrawal-only records so they never contaminate the enrollment table
    try {
        $dbConn->exec("
            UPDATE `student_enrollments` 
            SET `enroll_requested_at` = NULL, `status` = 'Unenrolled' 
            WHERE `withdraw_status` IN ('Pending', 'Withdrawn', 'Rejected') 
              AND `withdraw_requested_at` IS NOT NULL 
              AND (`amount` = 0.00 OR `amount` IS NULL) 
              AND (`payment_method` = 'None' OR `payment_method` = '' OR `payment_method` IS NULL)
        ");
    } catch (PDOException $e) {}
} else {
    die("Database connection error.");
}

// Helper to format slip URL safely
function formatSlipUrl(?string $path): string {
    if (empty($path)) return '';
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    if (strpos($path, 'uploads/') === 0 || strpos($path, 'assets/') === 0) {
        return '../../' . ltrim($path, '/');
    }
    if (strpos($path, '../') === 0) return $path;
    return '../../' . ltrim($path, '/');
}

// Helper to format avatar URL safely
function formatAvatarUrl(?string $path): string {
    $defaultAvatar = '../assets/images/user-avatar.jpg';
    if (empty($path)) return $defaultAvatar;
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    if (strpos($path, 'assets/') === 0) return '../' . $path;
    if (strpos($path, '../') === 0) return $path;
    return '../' . ltrim($path, '/');
}

// 4. Fetch Admin Details
$admin = [];
if (isset($_SESSION['email'])) {
    $stmtAdmin = $dbConn->prepare("SELECT * FROM `admins` WHERE `email` = :email LIMIT 1");
    $stmtAdmin->execute(['email' => $_SESSION['email']]);
    $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (empty($admin) && isset($_SESSION['user_id'])) {
    $stmtAdmin = $dbConn->prepare("
        SELECT a.* FROM `admins` a 
        JOIN `users-table` u ON a.email = u.email 
        WHERE u.id = :uid LIMIT 1
    ");
    $stmtAdmin->execute(['uid' => $_SESSION['user_id']]);
    $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: [];
}

$adminCode = !empty($admin['admin_id']) ? $admin['admin_id'] : ($_SESSION['admin_id'] ?? 'ADM-001');
$adminName = !empty($admin['full_name']) ? $admin['full_name'] : ($_SESSION['admin_name'] ?? 'System Administrator');

// Determine Active Section: 'enrollment' (default) or 'withdraw'
$activeSection = $_GET['section'] ?? 'enrollment';
if (!in_array($activeSection, ['enrollment', 'withdraw', 'all'], true)) {
    $activeSection = 'enrollment';
}

// 5. Handle Status Actions (Enrollment: Approve/Cancel | Withdrawal: Approve/Reject)
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $actionType   = $_POST['action'];

    if ($enrollmentId > 0) {
        // --- Enrollment Actions ---
        if ($actionType === 'approve') {
            // Check if already processed
            $stmtCheck = $dbConn->prepare("SELECT status, approved_by_name, cancelled_by_name FROM `student_enrollments` WHERE `id` = ?");
            $stmtCheck->execute([$enrollmentId]);
            $curRec = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($curRec && ($curRec['status'] === 'Enrolled' || $curRec['status'] === 'Cancelled' || !empty($curRec['approved_by_name']) || !empty($curRec['cancelled_by_name']))) {
                $flashMessage = "This enrollment request has already been processed and cannot be approved again.";
                $flashType = "warning";
                $activeSection = 'enrollment';
            } else {
                try {
                    // Fetch info about the enrollment record being approved
                    $stmtEnrInfo = $dbConn->prepare("SELECT student_id, batch FROM `student_enrollments` WHERE `id` = ?");
                    $stmtEnrInfo->execute([$enrollmentId]);
                    $enrInfo = $stmtEnrInfo->fetch(PDO::FETCH_ASSOC);

                    // Update student_enrollments with unique parameter names and reset withdraw status fields
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Enrolled', 
                            `withdraw_status` = 'None',
                            `withdraw_reason` = NULL,
                            `withdraw_requested_at` = NULL,
                            `withdraw_action_at` = NULL,
                            `withdraw_reviewed_by_id` = NULL,
                            `withdraw_reviewed_by_name` = NULL,
                            `reviewed_by_id` = :aid1, 
                            `reviewed_by_name` = :aname1, 
                            `approved_by_id` = :aid2,
                            `approved_by_name` = :aname2,
                            `enrolled_at` = NOW(),
                            `action_at` = NOW(),
                            `cancellation_reason` = NULL 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid1'   => $adminCode, 
                        'aname1' => $adminName, 
                        'aid2'   => $adminCode, 
                        'aname2' => $adminName, 
                        'eid'    => $enrollmentId
                    ]);
                } catch (PDOException $ex) {
                    // Fallback in case optional columns like approved_by_id or enrolled_at are missing
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Enrolled', 
                            `withdraw_status` = 'None',
                            `withdraw_reason` = NULL,
                            `withdraw_requested_at` = NULL,
                            `withdraw_action_at` = NULL,
                            `withdraw_reviewed_by_id` = NULL,
                            `withdraw_reviewed_by_name` = NULL,
                            `reviewed_by_id` = :aid, 
                            `reviewed_by_name` = :aname, 
                            `action_at` = NOW(),
                            `cancellation_reason` = NULL 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'   => $adminCode, 
                        'aname' => $adminName, 
                        'eid'   => $enrollmentId
                    ]);
                }

                // Clear any conflicting withdrawal records and older cancelled enrollment records for this student and batch
                if (!empty($enrInfo)) {
                    $stId = $enrInfo['student_id'];
                    $bName = $enrInfo['batch'];

                    // When 2nd (or newer) enrollment request is approved, remove older cancelled records for this student and batch
                    // so the cancelled <tr> no longer shows in admin table and cancellation notice no longer shows to student
                    try {
                        $stmtDelCancCourses = $dbConn->prepare("
                            DELETE ec FROM `student_enrollment_courses` ec
                            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                              AND e.batch = ? 
                              AND e.status = 'Cancelled'
                        ");
                        $stmtDelCancCourses->execute([$stId, (string)$stId, $bName]);

                        $stmtDelCancEnr = $dbConn->prepare("
                            DELETE FROM `student_enrollments` 
                            WHERE (student_id = ? OR CAST(student_id AS CHAR) = ?) 
                              AND batch = ? 
                              AND status = 'Cancelled'
                        ");
                        $stmtDelCancEnr->execute([$stId, (string)$stId, $bName]);
                    } catch (PDOException $e) {}

                    // Check which courses were approved in this enrollment
                    $approvedCourses = [];
                    try {
                        $stmtApprovedCourses = $dbConn->prepare("SELECT course_code FROM `student_enrollment_courses` WHERE `enrollment_id` = ?");
                        $stmtApprovedCourses->execute([$enrollmentId]);
                        $approvedCourses = $stmtApprovedCourses->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    } catch (PDOException $e) {}

                    if (!empty($approvedCourses)) {
                        // Remove approved courses from student_withdrawal_courses for this student and batch
                        try {
                            $stmtDelWithdrawn = $dbConn->prepare("
                                DELETE wc FROM `student_withdrawal_courses` wc
                                JOIN `student_enrollments` e ON wc.enrollment_id = e.id
                                WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                                  AND e.batch = ? 
                                  AND wc.course_code = ?
                            ");
                            foreach ($approvedCourses as $acCode) {
                                $stmtDelWithdrawn->execute([$stId, (string)$stId, $bName, trim($acCode)]);
                            }
                        } catch (PDOException $e) {}
                    } else {
                        // If no course-specific records (all batch courses enrolled): clear all withdrawal courses for this batch
                        try {
                            $stmtDelAllWithdrawn = $dbConn->prepare("
                                DELETE wc FROM `student_withdrawal_courses` wc
                                JOIN `student_enrollments` e ON wc.enrollment_id = e.id
                                WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                                  AND e.batch = ?
                            ");
                            $stmtDelAllWithdrawn->execute([$stId, (string)$stId, $bName]);
                        } catch (PDOException $e) {}
                    }

                    // If no remaining withdrawal courses exist for this student and batch, reset withdraw_status on any older records
                    try {
                        $stmtCheckRem = $dbConn->prepare("
                            SELECT COUNT(*) FROM `student_withdrawal_courses` wc
                            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                              AND e.batch = ?
                        ");
                        $stmtCheckRem->execute([$stId, (string)$stId, $bName]);
                        $remCount = (int)$stmtCheckRem->fetchColumn();
                        if ($remCount === 0) {
                            $dbConn->prepare("
                                UPDATE `student_enrollments` 
                                SET `withdraw_status` = 'None',
                                    `withdraw_reason` = NULL,
                                    `withdraw_requested_at` = NULL,
                                    `withdraw_action_at` = NULL,
                                    `withdraw_reviewed_by_id` = NULL,
                                    `withdraw_reviewed_by_name` = NULL
                                WHERE (student_id = ? OR CAST(student_id AS CHAR) = ?) 
                                  AND `batch` = ? 
                                  AND `withdraw_status` = 'Withdrawn'
                            ")->execute([$stId, (string)$stId, $bName]);
                        }
                    } catch (PDOException $e) {}
                }

                $flashMessage = "Enrollment request approved successfully! Student is now enrolled in the batch.";
                $flashType = "success";
                $activeSection = 'enrollment';
            }
        } elseif ($actionType === 'cancel') {
            $reason = trim($_POST['cancellation_reason'] ?? '');
            
            // Check if already cancelled
            $stmtCheck = $dbConn->prepare("SELECT status, cancelled_by_name FROM `student_enrollments` WHERE `id` = ?");
            $stmtCheck->execute([$enrollmentId]);
            $curRec = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($curRec && ($curRec['status'] === 'Cancelled' || !empty($curRec['cancelled_by_name']))) {
                $flashMessage = "This enrollment request has already been cancelled.";
                $flashType = "warning";
                $activeSection = 'enrollment';
            } else {
                try {
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Cancelled', 
                            `cancelled_by_id` = :aid, 
                            `cancelled_by_name` = :aname, 
                            `cancelled_at` = NOW(),
                            `cancellation_reason` = :reason 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'    => $adminCode, 
                        'aname'  => $adminName, 
                        'reason' => !empty($reason) ? $reason : 'Rejected by administrator',
                        'eid'    => $enrollmentId
                    ]);
                } catch (PDOException $ex) {
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Cancelled', 
                            `reviewed_by_id` = :aid, 
                            `reviewed_by_name` = :aname, 
                            `action_at` = NOW(),
                            `cancellation_reason` = :reason 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'    => $adminCode, 
                        'aname'  => $adminName, 
                        'reason' => !empty($reason) ? $reason : 'Rejected by administrator',
                        'eid'    => $enrollmentId
                    ]);
                }

                // If this enrollment had course records, remove them since it is now cancelled
                try {
                    $dbConn->prepare("DELETE FROM `student_enrollment_courses` WHERE `enrollment_id` = ?")->execute([$enrollmentId]);
                } catch (PDOException $e) {}

                $flashMessage = "Enrollment request cancelled.";
                $flashType = "warning";
                $activeSection = 'enrollment';
            }
        } 
        // --- Withdrawal Actions ---
        elseif ($actionType === 'approve_withdraw') {
            // Check if already processed
            $stmtCheck = $dbConn->prepare("SELECT withdraw_status, withdraw_reviewed_by_name, withdraw_cancelled_by_name FROM `student_enrollments` WHERE `id` = ?");
            $stmtCheck->execute([$enrollmentId]);
            $curW = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($curW && ($curW['withdraw_status'] === 'Withdrawn' || $curW['withdraw_status'] === 'Rejected' || !empty($curW['withdraw_reviewed_by_name']) || !empty($curW['withdraw_cancelled_by_name']))) {
                $flashMessage = "This withdrawal request has already been processed and cannot be approved again.";
                $flashType = "warning";
                $activeSection = 'withdraw';
            } else {
                // Fetch enrollment details to identify student and batch
                $stmtEnrInfo = $dbConn->prepare("SELECT student_id, batch FROM `student_enrollments` WHERE `id` = ?");
                $stmtEnrInfo->execute([$enrollmentId]);
                $enrInfo = $stmtEnrInfo->fetch(PDO::FETCH_ASSOC);
                $wStId = $enrInfo['student_id'] ?? 0;
                $wBatch = $enrInfo['batch'] ?? '';

                // Fetch withdrawn courses requested for this enrollment
                $withdrawnCourses = [];
                try {
                    $stmtWc = $dbConn->prepare("SELECT course_code FROM `student_withdrawal_courses` WHERE `enrollment_id` = ?");
                    $stmtWc->execute([$enrollmentId]);
                    $withdrawnCourses = $stmtWc->fetchAll(PDO::FETCH_COLUMN) ?: [];
                } catch (PDOException $e) {}

                // Fetch all available course codes for this batch
                $allBatchCourses = [];
                if (!empty($wBatch)) {
                    try {
                        $stmtAllBc = $dbConn->prepare("SELECT DISTINCT course_code FROM `batches` WHERE `batch` = ? AND course_code IS NOT NULL AND course_code != ''");
                        $stmtAllBc->execute([$wBatch]);
                        $allBatchCourses = $stmtAllBc->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    } catch (PDOException $e) {}
                }

                // Fetch existing enrolled courses for this enrollment record
                $enrolledCourses = [];
                try {
                    $stmtEnrC = $dbConn->prepare("SELECT course_code FROM `student_enrollment_courses` WHERE `enrollment_id` = ?");
                    $stmtEnrC->execute([$enrollmentId]);
                    $enrolledCourses = $stmtEnrC->fetchAll(PDO::FETCH_COLUMN) ?: [];
                } catch (PDOException $e) {}

                // Full withdrawal if no specific courses given (entire batch), or covers all enrolled courses, or covers all batch courses
                $isFullWithdraw = empty($withdrawnCourses) || 
                                  (!empty($allBatchCourses) && count($withdrawnCourses) >= count($allBatchCourses)) ||
                                  (!empty($enrolledCourses) && count($withdrawnCourses) >= count($enrolledCourses));

                if ($isFullWithdraw) {
                    // If withdrawn courses was empty, populate all batch courses into student_withdrawal_courses
                    if (empty($withdrawnCourses) && !empty($allBatchCourses)) {
                        try {
                            $stmtInsAllW = $dbConn->prepare("INSERT IGNORE INTO `student_withdrawal_courses` (`enrollment_id`, `course_code`) VALUES (?, ?)");
                            foreach ($allBatchCourses as $abc) {
                                $stmtInsAllW->execute([$enrollmentId, trim($abc)]);
                            }
                        } catch (PDOException $e) {}
                    }

                    // Delete all course enrollments for this student and batch
                    try {
                        $dbConn->prepare("
                            DELETE ec FROM `student_enrollment_courses` ec
                            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?)
                              AND e.batch = ?
                        ")->execute([$wStId, (string)$wStId, $wBatch]);
                        $dbConn->prepare("DELETE FROM `student_enrollment_courses` WHERE `enrollment_id` = ?")->execute([$enrollmentId]);
                    } catch (PDOException $e) {}

                    // Mark enrollment as Withdrawn
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Withdrawn',
                            `withdraw_status` = 'Withdrawn', 
                            `withdraw_reviewed_by_id` = :aid, 
                            `withdraw_reviewed_by_name` = :aname, 
                            `withdraw_action_at` = NOW() 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'   => $adminCode, 
                        'aname' => $adminName, 
                        'eid'   => $enrollmentId
                    ]);

                    // Also update any older enrolled records for this student and batch to Withdrawn
                    if (!empty($wBatch) && !empty($wStId)) {
                        try {
                            $dbConn->prepare("
                                UPDATE `student_enrollments` 
                                SET `status` = 'Withdrawn',
                                    `withdraw_status` = 'Withdrawn',
                                    `withdraw_reviewed_by_id` = :aid,
                                    `withdraw_reviewed_by_name` = :aname,
                                    `withdraw_action_at` = NOW()
                                WHERE (student_id = :sid OR CAST(student_id AS CHAR) = :sid_str)
                                  AND batch = :b
                                  AND id != :eid
                                  AND status = 'Enrolled'
                            ")->execute([
                                'aid' => $adminCode,
                                'aname' => $adminName,
                                'sid' => $wStId,
                                'sid_str' => (string)$wStId,
                                'b' => $wBatch,
                                'eid' => $enrollmentId
                            ]);
                        } catch (PDOException $e) {}
                    }
                } else {
                    // Partial withdrawal: delete only the withdrawn courses from student_enrollment_courses across all student records for this batch
                    try {
                        $stmtDelC = $dbConn->prepare("
                            DELETE ec FROM `student_enrollment_courses` ec
                            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?)
                              AND e.batch = ?
                              AND ec.course_code = ?
                        ");
                        foreach ($withdrawnCourses as $wc) {
                            $stmtDelC->execute([$wStId, (string)$wStId, $wBatch, trim($wc)]);
                        }

                        $stmtDelLocal = $dbConn->prepare("DELETE FROM `student_enrollment_courses` WHERE `enrollment_id` = ? AND `course_code` = ?");
                        foreach ($withdrawnCourses as $wc) {
                            $stmtDelLocal->execute([$enrollmentId, trim($wc)]);
                        }
                    } catch (PDOException $e) {}

                    // Check remaining enrolled courses across all enrollment records for this student and batch
                    $remCoursesCount = 0;
                    try {
                        $stmtCountRem = $dbConn->prepare("
                            SELECT COUNT(DISTINCT ec.course_code) 
                            FROM `student_enrollment_courses` ec
                            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?)
                              AND e.batch = ?
                              AND e.status = 'Enrolled'
                        ");
                        $stmtCountRem->execute([$wStId, (string)$wStId, $wBatch]);
                        $remCoursesCount = (int)$stmtCountRem->fetchColumn();
                    } catch (PDOException $e) {}

                    // If no remaining enrolled courses, update all active enrollments to Withdrawn
                    if ($remCoursesCount === 0 && !empty($wBatch) && !empty($wStId)) {
                        try {
                            $dbConn->prepare("
                                UPDATE `student_enrollments` 
                                SET `status` = 'Withdrawn'
                                WHERE (student_id = ? OR CAST(student_id AS CHAR) = ?)
                                  AND batch = ?
                                  AND status = 'Enrolled'
                            ")->execute([$wStId, (string)$wStId, $wBatch]);
                        } catch (PDOException $e) {}
                    }

                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = 'Withdrawn',
                            `withdraw_status` = 'Withdrawn', 
                            `withdraw_reviewed_by_id` = :aid, 
                            `withdraw_reviewed_by_name` = :aname, 
                            `withdraw_action_at` = NOW() 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'   => $adminCode, 
                        'aname' => $adminName, 
                        'eid'   => $enrollmentId
                    ]);
                }

                // When 2nd (or newer) withdrawal request is approved, remove older rejected withdrawal records for this student and batch
                // so the rejected/cancelled <tr> no longer shows in admin withdrawal table and cancellation notice no longer shows to student
                if (!empty($wBatch) && !empty($wStId)) {
                    try {
                        $stmtDelRejWc = $dbConn->prepare("
                            DELETE wc FROM `student_withdrawal_courses` wc
                            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
                            WHERE (e.student_id = ? OR CAST(e.student_id AS CHAR) = ?) 
                              AND e.batch = ? 
                              AND e.withdraw_status = 'Rejected'
                        ");
                        $stmtDelRejWc->execute([$wStId, (string)$wStId, $wBatch]);

                        $stmtDelRejW = $dbConn->prepare("
                            DELETE FROM `student_enrollments` 
                            WHERE (student_id = ? OR CAST(student_id AS CHAR) = ?) 
                              AND batch = ? 
                              AND withdraw_status = 'Rejected'
                        ");
                        $stmtDelRejW->execute([$wStId, (string)$wStId, $wBatch]);
                    } catch (PDOException $e) {}
                }

                $flashMessage = "Withdrawal request approved successfully! Student has been withdrawn from the requested batch / courses.";
                $flashType = "success";
                $activeSection = 'withdraw';
            }
        } elseif ($actionType === 'reject_withdraw') {
            $rejectReason = trim($_POST['rejection_reason'] ?? '');
            $adminNote = !empty($rejectReason) ? " | Admin Rejection Note: " . $rejectReason : "";

            // Check if already cancelled/rejected
            $stmtCheck = $dbConn->prepare("SELECT withdraw_status, withdraw_cancelled_by_name, student_id, batch FROM `student_enrollments` WHERE `id` = ?");
            $stmtCheck->execute([$enrollmentId]);
            $curW = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($curW && ($curW['withdraw_status'] === 'Rejected' || !empty($curW['withdraw_cancelled_by_name']))) {
                $flashMessage = "This withdrawal request has already been cancelled/rejected.";
                $flashType = "warning";
                $activeSection = 'withdraw';
            } else {
                $wasWithdrawn = ($curW['withdraw_status'] === 'Withdrawn');

                try {
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = CASE WHEN `status` = 'Withdrawn' THEN 'Enrolled' ELSE `status` END,
                            `withdraw_status` = 'Rejected', 
                            `withdraw_cancelled_by_id` = :aid, 
                            `withdraw_cancelled_by_name` = :aname, 
                            `withdraw_cancelled_at` = NOW(),
                            `withdraw_reason` = CONCAT(COALESCE(`withdraw_reason`, ''), :note) 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'   => $adminCode, 
                        'aname' => $adminName, 
                        'note'  => $adminNote,
                        'eid'   => $enrollmentId
                    ]);
                } catch (PDOException $ex) {
                    $stmtUpd = $dbConn->prepare("
                        UPDATE `student_enrollments` 
                        SET `status` = CASE WHEN `status` = 'Withdrawn' THEN 'Enrolled' ELSE `status` END,
                            `withdraw_status` = 'Rejected', 
                            `withdraw_reviewed_by_id` = :aid, 
                            `withdraw_reviewed_by_name` = :aname, 
                            `withdraw_action_at` = NOW(),
                            `withdraw_reason` = CONCAT(COALESCE(`withdraw_reason`, ''), :note) 
                        WHERE `id` = :eid
                    ");
                    $stmtUpd->execute([
                        'aid'   => $adminCode, 
                        'aname' => $adminName, 
                        'note'  => $adminNote,
                        'eid'   => $enrollmentId
                    ]);
                }

                // If the withdrawal was previously approved or pending, restore student enrollment
                if (!empty($curW)) {
                    $wStId = $curW['student_id'] ?? 0;
                    $wBatch = $curW['batch'] ?? '';

                    try {
                        $dbConn->prepare("UPDATE `student_enrollments` SET `status` = 'Enrolled' WHERE `id` = ? AND `status` = 'Withdrawn'")->execute([$enrollmentId]);
                        
                        // Restore courses from student_withdrawal_courses back into student_enrollment_courses
                        $stmtWCourses = $dbConn->prepare("SELECT course_code FROM `student_withdrawal_courses` WHERE `enrollment_id` = ?");
                        $stmtWCourses->execute([$enrollmentId]);
                        $wcList = $stmtWCourses->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        if (!empty($wcList)) {
                            $stmtFindEnr = $dbConn->prepare("SELECT id FROM `student_enrollments` WHERE (student_id = ? OR CAST(student_id AS CHAR) = ?) AND batch = ? AND status = 'Enrolled' ORDER BY id DESC LIMIT 1");
                            $stmtFindEnr->execute([$wStId, (string)$wStId, $wBatch]);
                            $activeEid = (int)$stmtFindEnr->fetchColumn();
                            if ($activeEid <= 0) $activeEid = $enrollmentId;

                            $stmtInsBack = $dbConn->prepare("INSERT IGNORE INTO `student_enrollment_courses` (`enrollment_id`, `course_code`) VALUES (?, ?)");
                            foreach ($wcList as $c) {
                                $stmtInsBack->execute([$activeEid, trim($c)]);
                            }
                        }
                        // Remove from student_withdrawal_courses since withdrawal request was rejected
                        $dbConn->prepare("DELETE FROM `student_withdrawal_courses` WHERE `enrollment_id` = ?")->execute([$enrollmentId]);
                    } catch (PDOException $e) {}
                }

                $flashMessage = "Withdrawal request cancelled/rejected successfully.";
                $flashType = "warning";
                $activeSection = 'withdraw';
            }
        }
    }
}

// Check if child course tables exist
$hasCourseTables = false;
try {
    $dbConn->query("SELECT 1 FROM `student_enrollment_courses` LIMIT 1");
    $hasCourseTables = true;
} catch (PDOException $e) {
    $hasCourseTables = false;
}

// ==============================================================================
// SYNCHRONIZATION: Clean up orphaned enrollments & courses whose batch was removed
// ==============================================================================
try {
    // 1. If child course tables exist, clean up child courses first to prevent FK constraint failures
    if ($hasCourseTables) {
        // Delete child courses for batches that no longer exist
        $dbConn->exec("
            DELETE wc FROM `student_withdrawal_courses` wc
            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
            LEFT JOIN `batches` b ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
            WHERE b.id IS NULL
        ");

        $dbConn->exec("
            DELETE ec FROM `student_enrollment_courses` ec
            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
            LEFT JOIN `batches` b ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
            WHERE b.id IS NULL
        ");

        // Also clean up specific course codes that were removed from existing batches
        $dbConn->exec("
            DELETE ec FROM `student_enrollment_courses` ec
            JOIN `student_enrollments` e ON ec.enrollment_id = e.id
            LEFT JOIN `batches` b 
                   ON (e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci 
                   AND ec.course_code COLLATE utf8mb4_unicode_ci = b.course_code COLLATE utf8mb4_unicode_ci)
            WHERE b.id IS NULL
        ");

        $dbConn->exec("
            DELETE wc FROM `student_withdrawal_courses` wc
            JOIN `student_enrollments` e ON wc.enrollment_id = e.id
            LEFT JOIN `batches` b 
                   ON (e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci 
                   AND wc.course_code COLLATE utf8mb4_unicode_ci = b.course_code COLLATE utf8mb4_unicode_ci)
            WHERE b.id IS NULL
        ");
    }

    // 2. Delete enrollments whose batch no longer exists in `batches` table
    $dbConn->exec("
        DELETE e FROM `student_enrollments` e 
        LEFT JOIN `batches` b 
               ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci 
        WHERE b.id IS NULL
    ");
} catch (PDOException $e) {
    error_log("Error cleaning up orphaned batch enrollments: " . $e->getMessage());
}

// Clean any records cancelled by the student so they have no impact on admin page
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

// Ensure unique_student_batch is dropped to allow multiple separate enrollment/withdrawal requests
try {
    $chk = $dbConn->query("SHOW INDEX FROM `student_enrollments` WHERE Key_name = 'unique_student_batch'")->fetch();
    if ($chk) {
        $dbConn->exec("ALTER TABLE `student_enrollments` DROP INDEX `unique_student_batch`");
        $dbConn->exec("ALTER TABLE `student_enrollments` ADD INDEX `idx_student_batch` (`student_id`, `batch`)");
    }
} catch (PDOException $e) {
    // Non-fatal if index already dropped or not permitted
}

// Ensure cancelled columns exist in student_enrollments
try {
    $colCheck = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'cancelled_by_id'")->fetch();
    if (!$colCheck) {
        $dbConn->exec("ALTER TABLE `student_enrollments` 
            ADD `cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled enrollment request' AFTER `cancellation_reason`,
            ADD `cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled enrollment request' AFTER `cancelled_by_id`,
            ADD `cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when Admin cancelled enrollment request' AFTER `cancelled_by_name`");
    }
} catch (PDOException $e) {}

try {
    $colCheckReq = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'enroll_requested_at'")->fetch();
    if (!$colCheckReq) {
        $dbConn->exec("ALTER TABLE `student_enrollments` 
            ADD `enroll_requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when student submitted enrollment request' AFTER `payment_slip`");
    }
} catch (PDOException $e) {}

try {
    $colCheckW = $dbConn->query("SHOW COLUMNS FROM `student_enrollments` LIKE 'withdraw_cancelled_by_id'")->fetch();
    if (!$colCheckW) {
        $dbConn->exec("ALTER TABLE `student_enrollments` 
            ADD `withdraw_cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled/rejected withdrawal' AFTER `withdraw_reviewed_by_name`,
            ADD `withdraw_cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled/rejected withdrawal' AFTER `withdraw_cancelled_by_id`,
            ADD `withdraw_cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when admin cancelled/rejected withdrawal' AFTER `withdraw_cancelled_by_name`");
    }
} catch (PDOException $e) {}

// Fetch all batch courses with credits and subject for dropdown and credit calculations
$batchCourseCatalog = [];
$batchAllCoursesCatalog = [];
try {
    $stmtCatalog = $dbConn->query("
        SELECT id, batch, course_code, credit, subject 
        FROM `batches` 
        WHERE `course_code` IS NOT NULL AND `course_code` != ''
        ORDER BY id ASC
    ");
    while ($catRow = $stmtCatalog->fetch(PDO::FETCH_ASSOC)) {
        $b = trim($catRow['batch']);
        $c = trim($catRow['course_code']);
        $batchCourseCatalog[$b][$c] = $catRow;
        $batchAllCoursesCatalog[$b][] = [
            'code' => $c,
            'credit' => !empty($catRow['credit']) ? $catRow['credit'] : '0.0',
            'subject' => $catRow['subject'] ?? 'N/A'
        ];
    }
} catch (PDOException $e) {
    error_log("Error loading batch course catalog: " . $e->getMessage());
}

if (!function_exists('getEnrollmentCoursesAndCredit')) {
    function getEnrollmentCoursesAndCredit(string $batchName, ?string $enrolledCoursesStr, array $batchCourseCatalog, array $batchAllCoursesCatalog): array {
        $courseList = [];
        $totalCredit = 0.0;
        $bName = trim($batchName);

        $codes = [];
        if (!empty($enrolledCoursesStr)) {
            $codes = array_values(array_filter(array_map('trim', explode(',', $enrolledCoursesStr))));
        }

        if (!empty($codes)) {
            foreach ($codes as $cCode) {
                $cat = $batchCourseCatalog[$bName][$cCode] ?? null;
                $cr = floatval($cat['credit'] ?? 0);
                $totalCredit += $cr;
                $courseList[] = [
                    'code' => $cCode,
                    'credit' => !empty($cat['credit']) ? $cat['credit'] : '0.0',
                    'subject' => $cat['subject'] ?? 'N/A'
                ];
            }
        } elseif (!empty($batchAllCoursesCatalog[$bName])) {
            foreach ($batchAllCoursesCatalog[$bName] as $bc) {
                $cr = floatval($bc['credit'] ?? 0);
                $totalCredit += $cr;
                $courseList[] = $bc;
            }
        }

        return [
            'courses' => $courseList,
            'total_credit' => $totalCredit
        ];
    }
}

// 6. Fetch Enrollment Stats Overview
$stats = [
    'total' => 0,
    'pending' => 0,
    'enrolled' => 0,
    'cancelled' => 0,
    'revenue' => 0.00
];

try {
    $stmtStats = $dbConn->query("
        SELECT 
            COUNT(DISTINCT e.id) AS total_count,
            SUM(CASE WHEN e.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN e.status = 'Enrolled' THEN 1 ELSE 0 END) AS enrolled_count,
            SUM(CASE WHEN e.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN e.status = 'Enrolled' THEN e.amount ELSE 0 END) AS total_revenue
        FROM `student_enrollments` e
        INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
                ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
        LEFT JOIN `student_enrollments` active_enr
               ON (e.student_id = active_enr.student_id OR CAST(e.student_id AS CHAR) = CAST(active_enr.student_id AS CHAR))
              AND e.batch COLLATE utf8mb4_unicode_ci = active_enr.batch COLLATE utf8mb4_unicode_ci
              AND active_enr.status = 'Enrolled'
        WHERE (e.cancellation_reason IS NULL OR e.cancellation_reason != 'Cancelled by student')
          AND e.enroll_requested_at IS NOT NULL
          AND e.status IN ('Pending', 'Enrolled', 'Cancelled')
          AND (e.status != 'Cancelled' OR active_enr.id IS NULL)
    ");
    if ($sRow = $stmtStats->fetch(PDO::FETCH_ASSOC)) {
        $stats['total']     = (int)($sRow['total_count'] ?? 0);
        $stats['pending']   = (int)($sRow['pending_count'] ?? 0);
        $stats['enrolled']  = (int)($sRow['enrolled_count'] ?? 0);
        $stats['cancelled'] = (int)($sRow['cancelled_count'] ?? 0);
        $stats['revenue']   = (float)($sRow['total_revenue'] ?? 0.00);
    }
} catch (PDOException $e) {
    error_log("Error fetching enrollment stats: " . $e->getMessage());
}

// 7. Fetch Withdrawal Stats Overview
$withdrawStats = [
    'total' => 0,
    'pending' => 0,
    'withdrawn' => 0,
    'rejected' => 0
];

try {
    $stmtWStats = $dbConn->query("
        SELECT 
            COUNT(DISTINCT e.id) AS total_count,
            SUM(CASE WHEN e.withdraw_status = 'Pending' AND e.withdraw_requested_at IS NOT NULL THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN e.withdraw_status = 'Withdrawn' AND e.withdraw_requested_at IS NOT NULL THEN 1 ELSE 0 END) AS withdrawn_count,
            SUM(CASE WHEN e.withdraw_status = 'Rejected' AND e.withdraw_requested_at IS NOT NULL THEN 1 ELSE 0 END) AS rejected_count
        FROM `student_enrollments` e
        INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
                ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
        LEFT JOIN `student_enrollments` active_w
               ON (e.student_id = active_w.student_id OR CAST(e.student_id AS CHAR) = CAST(active_w.student_id AS CHAR))
              AND e.batch COLLATE utf8mb4_unicode_ci = active_w.batch COLLATE utf8mb4_unicode_ci
              AND active_w.withdraw_status = 'Withdrawn'
        WHERE e.withdraw_status IN ('Pending', 'Withdrawn', 'Rejected')
          AND e.withdraw_requested_at IS NOT NULL
          AND (e.withdraw_status != 'Rejected' OR active_w.id IS NULL)
    ");
    if ($wRow = $stmtWStats->fetch(PDO::FETCH_ASSOC)) {
        $withdrawStats['total']     = (int)($wRow['total_count'] ?? 0);
        $withdrawStats['pending']   = (int)($wRow['pending_count'] ?? 0);
        $withdrawStats['withdrawn'] = (int)($wRow['withdrawn_count'] ?? 0);
        $withdrawStats['rejected']  = (int)($wRow['rejected_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Error fetching withdrawal stats: " . $e->getMessage());
}

// 8. Fetch Filtered Enrollments List
$statusFilter = $_GET['status'] ?? 'All';
$searchQuery  = trim($_GET['search'] ?? '');

$sqlEnroll = "
    SELECT 
        e.*, 
        COALESCE(s.full_name, CONCAT('Student #', e.student_id)) AS student_name, 
        COALESCE(s.student_id, CAST(e.student_id AS CHAR)) AS student_code, 
        s.email AS student_email,
        s.profile_image AS student_avatar
        " . ($hasCourseTables ? ", GROUP_CONCAT(DISTINCT ec.course_code ORDER BY ec.course_code ASC SEPARATOR ', ') AS enrolled_courses" : "") . "
    FROM `student_enrollments` e 
    INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
            ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
    LEFT JOIN `student_enrollments` active_enr
           ON (e.student_id = active_enr.student_id OR CAST(e.student_id AS CHAR) = CAST(active_enr.student_id AS CHAR))
          AND e.batch COLLATE utf8mb4_unicode_ci = active_enr.batch COLLATE utf8mb4_unicode_ci
          AND active_enr.status = 'Enrolled'
    LEFT JOIN `students` s 
           ON (e.student_id = s.id 
            OR CAST(e.student_id AS CHAR) COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci)
    " . ($hasCourseTables ? "LEFT JOIN `student_enrollment_courses` ec ON ec.enrollment_id = e.id" : "") . "
    WHERE (e.cancellation_reason IS NULL OR e.cancellation_reason != 'Cancelled by student')
      AND e.enroll_requested_at IS NOT NULL
      AND e.status IN ('Pending', 'Enrolled', 'Cancelled')
      AND (e.status != 'Cancelled' OR active_enr.id IS NULL)
";

$paramsEnroll = [];

if ($statusFilter !== 'All') {
    $sqlEnroll .= " AND e.status COLLATE utf8mb4_unicode_ci = ?";
    $paramsEnroll[] = $statusFilter;
}

if (!empty($searchQuery)) {
    $sqlEnroll .= " AND (
        s.full_name COLLATE utf8mb4_unicode_ci LIKE ? 
        OR s.student_id COLLATE utf8mb4_unicode_ci LIKE ? 
        OR e.batch COLLATE utf8mb4_unicode_ci LIKE ? 
        OR e.transaction_id COLLATE utf8mb4_unicode_ci LIKE ? 
        OR CAST(e.amount AS CHAR) COLLATE utf8mb4_unicode_ci LIKE ?
    )";
    $term = '%' . $searchQuery . '%';
    $paramsEnroll[] = $term;
    $paramsEnroll[] = $term;
    $paramsEnroll[] = $term;
    $paramsEnroll[] = $term;
    $paramsEnroll[] = $term;
}

$sqlEnroll .= " GROUP BY e.id ORDER BY e.id DESC";
$stmtEnroll = $dbConn->prepare($sqlEnroll);
$stmtEnroll->execute($paramsEnroll);
$enrollments = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);

// 9. Fetch Filtered Withdrawals List
$withdrawStatusFilter = $_GET['withdraw_status'] ?? 'All';
$withdrawSearchQuery  = trim($_GET['withdraw_search'] ?? '');

$sqlWithdraw = "
    SELECT 
        e.*, 
        COALESCE(s.full_name, CONCAT('Student #', e.student_id)) AS student_name, 
        COALESCE(s.student_id, CAST(e.student_id AS CHAR)) AS student_code, 
        s.email AS student_email,
        s.profile_image AS student_avatar
        " . ($hasCourseTables ? ", GROUP_CONCAT(DISTINCT wc.course_code ORDER BY wc.course_code ASC SEPARATOR ', ') AS withdrawal_courses" : "") . "
    FROM `student_enrollments` e 
    INNER JOIN (SELECT DISTINCT `batch` FROM `batches`) b 
            ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
    LEFT JOIN `student_enrollments` active_w
           ON (e.student_id = active_w.student_id OR CAST(e.student_id AS CHAR) = CAST(active_w.student_id AS CHAR))
          AND e.batch COLLATE utf8mb4_unicode_ci = active_w.batch COLLATE utf8mb4_unicode_ci
          AND active_w.withdraw_status = 'Withdrawn'
    LEFT JOIN `students` s 
           ON (e.student_id = s.id 
            OR CAST(e.student_id AS CHAR) COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci)
    " . ($hasCourseTables ? "LEFT JOIN `student_withdrawal_courses` wc ON wc.enrollment_id = e.id" : "") . "
    WHERE e.withdraw_status IN ('Pending', 'Withdrawn', 'Rejected')
      AND e.withdraw_requested_at IS NOT NULL
      AND (e.withdraw_status != 'Rejected' OR active_w.id IS NULL)
";

$paramsWithdraw = [];

if ($withdrawStatusFilter !== 'All') {
    $sqlWithdraw .= " AND e.withdraw_status COLLATE utf8mb4_unicode_ci = ?";
    $paramsWithdraw[] = $withdrawStatusFilter;
}

if (!empty($withdrawSearchQuery)) {
    $sqlWithdraw .= " AND (
        s.full_name COLLATE utf8mb4_unicode_ci LIKE ? 
        OR s.student_id COLLATE utf8mb4_unicode_ci LIKE ? 
        OR e.batch COLLATE utf8mb4_unicode_ci LIKE ? 
        OR e.withdraw_reason COLLATE utf8mb4_unicode_ci LIKE ?
    )";
    $wTerm = '%' . $withdrawSearchQuery . '%';
    $paramsWithdraw[] = $wTerm;
    $paramsWithdraw[] = $wTerm;
    $paramsWithdraw[] = $wTerm;
    $paramsWithdraw[] = $wTerm;
}

$sqlWithdraw .= " GROUP BY e.id ORDER BY COALESCE(e.withdraw_requested_at, e.updated_at, e.created_at) DESC, e.id DESC";
$stmtWithdraw = $dbConn->prepare($sqlWithdraw);
$stmtWithdraw->execute($paramsWithdraw);
$withdrawals = $stmtWithdraw->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Student Enrollment & Withdrawal Management</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    
    <link rel="stylesheet" href="../assets/css/admin/admin-manageEnroll-page.css">
</head>

<body>
    <div class="main-layout">

        <!-- ADMIN HEADER AND SIDEBAR -->
        <?php 
        if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
            require_once __DIR__ . '/admin-header-sidebar-section.php'; 
        }
        ?>

        <!-- MAIN DASHBOARD CONTENT -->
        <main class="dashboard">

            <!-- PAGE HEADER ROW -->
            <div class="page-header-container">
                <div class="header-left-title">
                    <a href="admin-dashboard-page.php" class="btn-back-dashboard">
                        <i class="fa-solid fa-arrow-left"></i> Dashboard
                    </a>
                    <h1>
                        <?php if ($activeSection === 'withdraw'): ?>
                            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#dc2626;"></i> Student Withdrawal Requests
                        <?php else: ?>
                            <i class="fa-solid fa-user-check" style="color:#2563eb;"></i> Student Enrollment Requests
                        <?php endif; ?>
                    </h1>
                </div>

                <div class="admin-badge-info">
                    <i class="fa-solid fa-user-shield" style="color: #2563eb;"></i>
                    <strong>Admin:</strong> <?php echo htmlspecialchars($adminName); ?> 
                    <span style="color: #64748b;">(<?php echo htmlspecialchars($adminCode); ?>)</span>
                </div>
            </div>

            <!-- SECTION SWITCHER NAVIGATION -->
            <div class="section-switch-nav">
                <a href="?section=enrollment" class="section-switch-btn <?php echo ($activeSection === 'enrollment') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-check"></i> Student Enrollment Requests
                    <?php if ($stats['pending'] > 0): ?>
                        <span class="badge-count"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
                
                <a href="?section=withdraw" class="section-switch-btn withdraw-tab <?php echo ($activeSection === 'withdraw') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Student Withdrawal Requests
                    <?php if ($withdrawStats['pending'] > 0): ?>
                        <span class="badge-count badge-danger"><?php echo $withdrawStats['pending']; ?></span>
                    <?php endif; ?>
                </a>

                <a href="?section=all" class="section-switch-btn <?php echo ($activeSection === 'all') ? 'active' : ''; ?>" style="font-size:0.82rem;">
                    <i class="fa-solid fa-table-cells"></i> View Both Sections
                </a>
            </div>

            <!-- FLASH ALERTS -->
            <?php if (!empty($flashMessage)): ?>
                <div class="alert-box alert-<?php echo $flashType; ?>">
                    <i class="fa-solid <?php echo $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php echo htmlspecialchars($flashMessage); ?>
                </div>
            <?php endif; ?>

            <!-- ========================================================
                 PART 1: STUDENT ENROLLMENT REQUESTS
                 ======================================================== -->
            <?php if ($activeSection === 'enrollment' || $activeSection === 'all'): ?>
                
                <?php if ($activeSection === 'all'): ?>
                    <div class="table-section-header" style="margin-top: 10px;">
                        <h2><i class="fa-solid fa-user-check" style="color:#2563eb;"></i> Student Enrollment Requests</h2>
                    </div>
                <?php endif; ?>

                <!-- ENROLLMENT STATS OVERVIEW CARDS -->
                <section class="stats-grid-enrollment">
                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-blue-bg"><i class="fa-solid fa-list-check"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">TOTAL REQUESTS</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-amber-bg"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">PENDING REQUESTS</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-green-bg"><i class="fa-solid fa-book"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $stats['enrolled']; ?></div>
                            <div class="stat-label">APPROVED ENROLLMENTS</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-red-bg"><i class="fa-solid fa-user-xmark"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                            <div class="stat-label">CANCELLED</div>
                        </div>
                    </div>
                </section>

                <!-- CONTROLS ROW (TABS + SEARCH) -->
                <div class="controls-card">
                    <div class="filter-tabs-group">
                        <?php foreach (['All', 'Pending', 'Enrolled', 'Cancelled'] as $st): ?>
                            <a href="?section=<?php echo urlencode($activeSection); ?>&status=<?php echo $st; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                               class="tab-link <?php echo ($statusFilter === $st) ? 'active' : ''; ?>">
                                <?php echo $st; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <form method="GET" class="search-form">
                        <input type="hidden" name="section" value="<?php echo htmlspecialchars($activeSection); ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <input type="text" name="search" class="search-input-field" 
                               value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Search student name, ID, batch, amount, TrxID...">
                        <button type="submit" class="btn-search-submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                    </form>
                </div>

                <!-- ENROLLMENT DATA TABLE -->
                <section class="table-section">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Student Name & ID</th>
                                    <th>Batch</th>
                                    <th>Courses</th>
                                    <th>Total Credit</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Transaction ID</th>
                                    <th>Payment Slip</th>
                                    <th>Requested At</th>
                                    <th>Status</th>
                                    <th>Approved Status</th>
                                    <th> Cancelled Status </th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($enrollments)): foreach ($enrollments as $index => $row): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        
                                        <!-- Student Profile & Info -->
                                        <td>
                                            <div class="name-cell">
                                                <img src="<?php echo htmlspecialchars(formatAvatarUrl($row['student_avatar'] ?? null)); ?>" 
                                                     alt="Student" 
                                                     class="avatar-thumb"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="avatar-icon-placeholder" style="display: none;">
                                                    <i class="fa-solid fa-user-graduate"></i>
                                                </div>
                                                <div>
                                                    <strong style="display:block; color:#0f172a;"><?php echo htmlspecialchars($row['student_name']); ?></strong>
                                                    <span style="font-size: 0.78rem; color: #64748b;">ID: <?php echo htmlspecialchars($row['student_code']); ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Batch -->
                                        <td>
                                            <span class="badge-course">
                                                <i class="fa-solid fa-layer-group"></i> Batch <?php echo htmlspecialchars($row['batch']); ?>
                                            </span>
                                        </td>

                                        <?php 
                                            $enrCourseData = getEnrollmentCoursesAndCredit($row['batch'], $row['enrolled_courses'] ?? '', $batchCourseCatalog, $batchAllCoursesCatalog);
                                            $selectedCourseList = $enrCourseData['courses'];
                                            $rowTotalCredit = $enrCourseData['total_credit'];
                                        ?>

                                        <!-- Selected Courses -->
                                        <td>
                                            <div style="min-width: 130px; max-width: 220px;">
                                                <select class="form-control" style="background: #f8fafc; cursor: pointer; font-size: 0.82rem; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; height: auto; width: 100%;" onfocus="this.defaultIndex=this.selectedIndex;" onchange="this.selectedIndex=this.defaultIndex;">
                                                    <?php if (!empty($selectedCourseList)): ?>
                                                        <?php foreach ($selectedCourseList as $cIdx => $course): ?>
                                                            <option value="<?php echo htmlspecialchars($course['code']); ?>" <?php echo ($cIdx === 0 ? 'selected' : ''); ?>>
                                                                <?php echo htmlspecialchars($course['code']); ?><?php if (!empty($course['credit']) && $course['credit'] !== '0.0'): ?> (<?php echo htmlspecialchars($course['credit']); ?> cr)<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <option value="" disabled selected>All Batch Courses</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </td>

                                        <!-- Total Credit -->
                                        <td>
                                            <span class="badge-credit" style="display: inline-flex; align-items: center; gap: 5px; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.83rem; white-space: nowrap;">
                                                <i class="fa-solid fa-graduation-cap"></i> <?php echo number_format($rowTotalCredit, 1); ?> Credits
                                            </span>
                                        </td>
                                        <!-- Amount -->
                                        <td>
                                            <strong style="color: #059669; font-size: 0.95rem;">
                                                ৳<?php echo number_format((float)($row['amount'] ?? 0), 2); ?>
                                            </strong>
                                        </td>

                                        <!-- Payment Method -->
                                        <td>
                                            <span class="badge-method">
                                                <i class="fa-solid <?php 
                                                    $m = strtolower($row['payment_method']);
                                                    if (strpos($m, 'bank') !== false) echo 'fa-building-columns';
                                                    elseif (strpos($m, 'bkash') !== false || strpos($m, 'nagad') !== false || strpos($m, 'rocket') !== false) echo 'fa-mobile-screen-button';
                                                    else echo 'fa-money-bill-wave';
                                                ?>"></i>
                                                <?php echo htmlspecialchars($row['payment_method']); ?>
                                            </span>
                                        </td>

                                        <!-- Transaction ID -->
                                        <td>
                                            <span class="trx-code"><?php echo htmlspecialchars($row['transaction_id']); ?></span>
                                        </td>

                                        <!-- Payment Slip Link -->
                                        <td>
                                            <?php if (!empty($row['payment_slip'])): ?>
                                                <a href="<?php echo htmlspecialchars(formatSlipUrl($row['payment_slip'])); ?>" target="_blank" style="color: #2563eb; font-size: 0.83rem; font-weight: 600; text-decoration: underline; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-file-image"></i> View Slip
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.82rem; font-style: italic;">No Slip</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Enroll Requested At -->
                                        <td>
                                            <span style="font-size: 0.83rem; color: #475569;">
                                                <?php 
                                                    $enrReqDate = $row['enroll_requested_at'] ?? $row['created_at'] ?? $row['updated_at'] ?? null;
                                                    echo !empty($enrReqDate) ? date('M d, Y h:i A', strtotime($enrReqDate)) : '-'; 
                                                ?>
                                            </span>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <span class="status-badge-custom status-<?php echo strtolower($row['status']); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>

                                        <!-- Approved Status  -->
                                        <td>
                                            <?php 
                                                $approvedName = !empty($row['approved_by_name']) ? $row['approved_by_name'] : (!empty($row['reviewed_by_name']) && $row['status'] === 'Enrolled' ? $row['reviewed_by_name'] : '');
                                                $approvedTime = !empty($row['enrolled_at']) ? $row['enrolled_at'] : (!empty($row['action_at']) && $row['status'] === 'Enrolled' ? $row['action_at'] : '');
                                            ?>
                                            <?php if (!empty($approvedName)): ?>
                                                <div style="font-size: 0.82rem; color: #334155;">
                                                    <strong><?php echo htmlspecialchars($approvedName); ?></strong>
                                                    <br>
                                                    <span style="color:#64748b; font-size: 0.75rem;">
                                                        <?php echo !empty($approvedTime) ? date('M d, Y h:i A', strtotime($approvedTime)) : '-'; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size: 0.82rem;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Cancelled Status  -->
                                        <td>
                                            <?php 
                                                $cancelledName = !empty($row['cancelled_by_name']) ? $row['cancelled_by_name'] : (!empty($row['reviewed_by_name']) && $row['status'] === 'Cancelled' ? $row['reviewed_by_name'] : '');
                                                $cancelledTime = !empty($row['cancelled_at']) ? $row['cancelled_at'] : (!empty($row['action_at']) && $row['status'] === 'Cancelled' ? $row['action_at'] : '');
                                            ?>
                                            <?php if (!empty($cancelledName)): ?>
                                                <div style="font-size: 0.82rem; color: #334155;">
                                                    <strong><?php echo htmlspecialchars($cancelledName); ?></strong>
                                                    <br>
                                                    <span style="color:#64748b; font-size: 0.75rem;">
                                                        <?php echo !empty($cancelledTime) ? date('M d, Y h:i A', strtotime($cancelledTime)) : '-'; ?>
                                                    </span>
                                                    <?php if (!empty($row['cancellation_reason'])): ?>
                                                        <div style="font-size: 0.72rem; color: #dc2626; margin-top: 2px;" title="<?php echo htmlspecialchars($row['cancellation_reason']); ?>">
                                                            <em><?php echo htmlspecialchars(mb_strimwidth($row['cancellation_reason'], 0, 26, '...')); ?></em>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size: 0.82rem;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align: right;">
                                            <?php
                                                $isEnrollApproved = ($row['status'] === 'Enrolled' || !empty($row['approved_by_name']));
                                                $isEnrollCancelled = ($row['status'] === 'Cancelled' || !empty($row['cancelled_by_name']));
                                                $enrollApproveDisabled = ($isEnrollApproved || $isEnrollCancelled);
                                                $enrollCancelDisabled = $isEnrollCancelled;
                                            ?>
                                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                                <?php if ($enrollApproveDisabled): ?>
                                                    <button type="button" class="btn-action-small btn-approve-action disabled" disabled 
                                                            title="<?php echo $isEnrollApproved ? 'Already Approved' : 'Request is Cancelled'; ?>">
                                                        <i class="fa-solid fa-check"></i> <?php echo $isEnrollApproved ? 'Approved' : 'Approve'; ?>
                                                    </button>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve this enrollment request? Student will be granted course access.');">
                                                        <input type="hidden" name="enrollment_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn-action-small btn-approve-action" title="Approve Request">
                                                            <i class="fa-solid fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($enrollCancelDisabled): ?>
                                                    <button type="button" class="btn-action-small btn-cancel-action disabled" disabled 
                                                            title="Already Cancelled">
                                                        <i class="fa-solid fa-xmark"></i> Cancelled
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-action-small btn-cancel-action" 
                                                            onclick="openCancelModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['student_name'])); ?>')"
                                                            title="Cancel Request">
                                                        <i class="fa-solid fa-xmark"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="14" style="text-align: center; padding: 40px; color: #64748b;">
                                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                            No enrollment requests found matching criteria.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>


            <!-- ========================================================
                 PART 2: STUDENT WITHDRAWAL REQUESTS (PROTOTYPED)
                 ======================================================== -->
            <?php if ($activeSection === 'withdraw' || $activeSection === 'all'): ?>

                <?php if ($activeSection === 'all'): ?>
                    <div class="table-section-header" style="margin-top: 35px;">
                        <h2><i class="fa-solid fa-arrow-right-from-bracket" style="color:#dc2626;"></i> Student Withdrawal Requests</h2>
                    </div>
                <?php endif; ?>

                <!-- WITHDRAWAL STATS OVERVIEW CARDS -->
                <section class="stats-grid-enrollment">
                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-purple-bg"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $withdrawStats['total']; ?></div>
                            <div class="stat-label">Withdrawal Requests</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-amber-bg"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $withdrawStats['pending']; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-green-bg"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $withdrawStats['withdrawn']; ?></div>
                            <div class="stat-label">Approved Withdrawn</div>
                        </div>
                    </div>

                    <div class="stat-card-item">
                        <div class="stat-icon-wrapper icon-red-bg"><i class="fa-solid fa-ban"></i></div>
                        <div class="stat-details">
                            <div class="stat-number"><?php echo $withdrawStats['rejected']; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                </section>

                <!-- WITHDRAWAL CONTROLS ROW (TABS + SEARCH) -->
                <div class="controls-card">
                    <div class="filter-tabs-group">
                        <?php foreach (['All', 'Pending', 'Withdrawn', 'Rejected'] as $wst): ?>
                            <a href="?section=<?php echo urlencode($activeSection); ?>&withdraw_status=<?php echo $wst; ?>&withdraw_search=<?php echo urlencode($withdrawSearchQuery); ?>" 
                               class="tab-link withdraw-active <?php echo ($withdrawStatusFilter === $wst) ? 'active withdraw-active' : ''; ?>">
                                <?php echo $wst; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <form method="GET" class="search-form">
                        <input type="hidden" name="section" value="<?php echo htmlspecialchars($activeSection); ?>">
                        <input type="hidden" name="withdraw_status" value="<?php echo htmlspecialchars($withdrawStatusFilter); ?>">
                        <input type="text" name="withdraw_search" class="search-input-field" 
                               value="<?php echo htmlspecialchars($withdrawSearchQuery); ?>" 
                               placeholder="Search student name, ID, batch, withdrawal reason...">
                        <button type="submit" class="btn-search-submit withdraw-search"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                    </form>
                </div>

                <!-- WITHDRAWAL DATA TABLE -->
                <section class="table-section">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Student Name & ID</th>
                                    <th>Batch</th>
                                    <th>Courses to Withdraw</th>
                                    <th>Total Credit</th>
                                    <th>Reason for Withdrawal</th>
                                    <th>Requested At</th>
                                    <th>Withdrawal Status</th>
                                    <th>Approved Status</th>
                                    <th>Cancelled Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($withdrawals)): foreach ($withdrawals as $wIndex => $wRow): ?>
                                    <tr>
                                        <td><?php echo $wIndex + 1; ?></td>

                                        <!-- Student Profile & Info -->
                                        <td>
                                            <div class="name-cell">
                                                <img src="<?php echo htmlspecialchars(formatAvatarUrl($wRow['student_avatar'] ?? null)); ?>" 
                                                     alt="Student" 
                                                     class="avatar-thumb"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="avatar-icon-placeholder" style="display: none;">
                                                    <i class="fa-solid fa-user-graduate"></i>
                                                </div>
                                                <div>
                                                    <strong style="display:block; color:#0f172a;"><?php echo htmlspecialchars($wRow['student_name']); ?></strong>
                                                    <span style="font-size: 0.78rem; color: #64748b;">ID: <?php echo htmlspecialchars($wRow['student_code']); ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Batch -->
                                        <td>
                                            <span class="badge-course">
                                                <i class="fa-solid fa-layer-group"></i> Batch <?php echo htmlspecialchars($wRow['batch']); ?>
                                            </span>
                                        </td>

                                        <?php 
                                            $wCourseData = getEnrollmentCoursesAndCredit($wRow['batch'], $wRow['withdrawal_courses'] ?? '', $batchCourseCatalog, $batchAllCoursesCatalog);
                                            $wSelectedCourseList = $wCourseData['courses'];
                                            $wTotalCredit = $wCourseData['total_credit'];
                                        ?>

                                        <!-- Courses to Withdraw -->
                                        <td>
                                            <div style="min-width: 130px; max-width: 220px;">
                                                <select class="form-control" style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; cursor: pointer; font-size: 0.82rem; padding: 6px 10px; border-radius: 6px; height: auto; width: 100%;" onfocus="this.defaultIndex=this.selectedIndex;" onchange="this.selectedIndex=this.defaultIndex;">
                                                    <?php if (!empty($wSelectedCourseList)): ?>
                                                        <?php foreach ($wSelectedCourseList as $wcIdx => $wCourse): ?>
                                                            <option value="<?php echo htmlspecialchars($wCourse['code']); ?>" <?php echo ($wcIdx === 0 ? 'selected' : ''); ?>>
                                                                <?php echo htmlspecialchars($wCourse['code']); ?><?php if (!empty($wCourse['credit']) && $wCourse['credit'] !== '0.0'): ?> (<?php echo htmlspecialchars($wCourse['credit']); ?> cr)<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <option value="" disabled selected>All Batch Courses</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </td>

                                        <!-- Total Credit to Withdraw -->
                                        <td>
                                            <span class="badge-credit" style="display: inline-flex; align-items: center; gap: 5px; background: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.83rem; white-space: nowrap;">
                                                <i class="fa-solid fa-graduation-cap"></i> <?php echo number_format($wTotalCredit, 1); ?> Credits
                                            </span>
                                        </td>

                                        <!-- Reason for Withdrawal -->
                                        <td>
                                            <div class="reason-text" title="<?php echo htmlspecialchars($wRow['withdraw_reason'] ?? ''); ?>">
                                                <span class="reason-truncated">
                                                    <?php echo !empty($wRow['withdraw_reason']) ? htmlspecialchars($wRow['withdraw_reason']) : '<span style="color:#94a3b8; font-style:italic;">No reason provided</span>'; ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Requested At -->
                                        <td>
                                            <span style="font-size: 0.83rem; color: #475569;">
                                                <?php 
                                                    $reqDate = $wRow['withdraw_requested_at'] ?? $wRow['updated_at'] ?? $wRow['created_at'] ?? null;
                                                    echo !empty($reqDate) ? date('M d, Y h:i A', strtotime($reqDate)) : '-'; 
                                                ?>
                                            </span>
                                        </td>

                                        <!-- Withdrawal Status -->
                                        <td>
                                            <span class="status-badge-custom status-<?php echo strtolower($wRow['withdraw_status']); ?>">
                                                <?php echo htmlspecialchars($wRow['withdraw_status']); ?>
                                            </span>
                                        </td>

                                        <!-- Approved Status -->
                                        <td>
                                            <?php 
                                                $wApprovedName = !empty($wRow['withdraw_reviewed_by_name']) ? $wRow['withdraw_reviewed_by_name'] : '';
                                                $wApprovedTime = !empty($wRow['withdraw_action_at']) ? $wRow['withdraw_action_at'] : '';
                                            ?>
                                            <?php if (!empty($wApprovedName)): ?>
                                                <div style="font-size: 0.82rem; color: #334155;">
                                                    <strong><?php echo htmlspecialchars($wApprovedName); ?></strong>
                                                    <br>
                                                    <span style="color:#64748b; font-size: 0.75rem;">
                                                        <?php echo !empty($wApprovedTime) ? date('M d, Y h:i A', strtotime($wApprovedTime)) : '-'; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size: 0.82rem;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Cancelled Status -->
                                        <td>
                                            <?php 
                                                $wCancelledName = !empty($wRow['withdraw_cancelled_by_name']) ? $wRow['withdraw_cancelled_by_name'] : ($wRow['withdraw_status'] === 'Rejected' && !empty($wRow['withdraw_reviewed_by_name']) ? $wRow['withdraw_reviewed_by_name'] : '');
                                                $wCancelledTime = !empty($wRow['withdraw_cancelled_at']) ? $wRow['withdraw_cancelled_at'] : ($wRow['withdraw_status'] === 'Rejected' && !empty($wRow['withdraw_action_at']) ? $wRow['withdraw_action_at'] : '');
                                            ?>
                                            <?php if (!empty($wCancelledName)): ?>
                                                <div style="font-size: 0.82rem; color: #334155;">
                                                    <strong><?php echo htmlspecialchars($wCancelledName); ?></strong>
                                                    <br>
                                                    <span style="color:#64748b; font-size: 0.75rem;">
                                                        <?php echo !empty($wCancelledTime) ? date('M d, Y h:i A', strtotime($wCancelledTime)) : '-'; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size: 0.82rem;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align: right;">
                                            <?php
                                                $isWithdrawApproved = ($wRow['withdraw_status'] === 'Withdrawn' || !empty($wRow['withdraw_reviewed_by_name']));
                                                $isWithdrawCancelled = ($wRow['withdraw_status'] === 'Rejected' || !empty($wRow['withdraw_cancelled_by_name']));
                                                $withdrawApproveDisabled = ($isWithdrawApproved || $isWithdrawCancelled);
                                                $withdrawCancelDisabled = $isWithdrawCancelled;
                                            ?>
                                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                                <?php if ($withdrawApproveDisabled): ?>
                                                    <button type="button" class="btn-action-small btn-approve-action disabled" disabled 
                                                            title="<?php echo $isWithdrawApproved ? 'Withdrawal Already Approved' : 'Withdrawal is Cancelled/Rejected'; ?>">
                                                        <i class="fa-solid fa-check"></i> <?php echo $isWithdrawApproved ? 'Approved' : 'Approve'; ?>
                                                    </button>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve this withdrawal request? The student will be withdrawn from the selected courses/batch.');">
                                                        <input type="hidden" name="enrollment_id" value="<?php echo $wRow['id']; ?>">
                                                        <input type="hidden" name="action" value="approve_withdraw">
                                                        <button type="submit" class="btn-action-small btn-approve-action" title="Approve Withdrawal">
                                                            <i class="fa-solid fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($withdrawCancelDisabled): ?>
                                                    <button type="button" class="btn-action-small btn-cancel-action disabled" disabled 
                                                            title="Already Cancelled / Rejected">
                                                        <i class="fa-solid fa-xmark"></i> Cancelled
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-action-small btn-cancel-action" 
                                                            onclick="openRejectWithdrawModal(<?php echo $wRow['id']; ?>, '<?php echo htmlspecialchars(addslashes($wRow['student_name'])); ?>')"
                                                            title="Cancel Withdrawal Request">
                                                        <i class="fa-solid fa-xmark"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center; padding: 40px; color: #64748b;">
                                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 8px; display: block; color: #94a3b8;"></i>
                                            No withdrawal requests found matching criteria.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

        </main>
    </div>

    <!-- ========================================================
         MODAL 1: CANCELLATION MODAL FOR ENROLLMENT
         ======================================================== -->
    <div id="cancelModal" class="modal-overlay">
        <div class="cancel-modal-container">
            <div class="cancel-modal-header">
                <i class="fa-solid fa-triangle-exclamation"></i> Cancel Enrollment Request
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="enrollment_id" id="modalEnrollmentId">
                
                <p style="margin-bottom: 14px; font-size: 0.9rem; color: #475569; line-height: 1.4;">
                    Are you sure you want to cancel enrollment request for <strong id="modalStudentName" style="color:#0f172a;"></strong>?
                </p>

                <div style="margin-bottom: 18px;">
                    <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 0.83rem; color:#334155;">
                        Cancellation Reason (Optional):
                    </label>
                    <textarea name="cancellation_reason" 
                              style="width:100%; height:85px; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; outline:none; resize:vertical;" 
                              placeholder="e.g. Invalid Transaction ID, incorrect payment amount, or unverified slip."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeCancelModal()" class="btn-modal-cancel">Close</button>
                    <button type="submit" class="btn-action-small btn-cancel-action" style="padding: 9px 16px; font-size: 0.85rem;">
                        <i class="fa-solid fa-ban"></i> Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 2: REJECTION MODAL FOR WITHDRAWAL (PROTOTYPED)
         ======================================================== -->
    <div id="rejectWithdrawModal" class="modal-overlay">
        <div class="cancel-modal-container">
            <div class="cancel-modal-header">
                <i class="fa-solid fa-ban"></i> Reject Withdrawal Request
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="reject_withdraw">
                <input type="hidden" name="enrollment_id" id="modalRejectWithdrawId">
                
                <p style="margin-bottom: 14px; font-size: 0.9rem; color: #475569; line-height: 1.4;">
                    Are you sure you want to reject the withdrawal request for <strong id="modalRejectStudentName" style="color:#0f172a;"></strong>?
                </p>

                <div style="margin-bottom: 18px;">
                    <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 0.83rem; color:#334155;">
                        Rejection Reason / Note (Optional):
                    </label>
                    <textarea name="rejection_reason" 
                              style="width:100%; height:85px; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.85rem; outline:none; resize:vertical;" 
                              placeholder="e.g. Withdrawal deadline passed, examination has started, or prerequisites pending."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeRejectWithdrawModal()" class="btn-modal-cancel">Close</button>
                    <button type="submit" class="btn-action-small btn-cancel-action" style="padding: 9px 16px; font-size: 0.85rem;">
                        <i class="fa-solid fa-ban"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SHARED HEADER & SIDEBAR SCRIPTS -->
    <script src="../assets/js/admin/admin-manageEnroll-page.js"></script>
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>

    <script>
        // Enrollment Cancel Modal Handlers
        function openCancelModal(id, studentName) {
            document.getElementById('modalEnrollmentId').value = id;
            document.getElementById('modalStudentName').textContent = studentName;
            const modal = document.getElementById('cancelModal');
            modal.classList.add('active');
        }

        function closeCancelModal() {
            const modal = document.getElementById('cancelModal');
            modal.classList.remove('active');
        }

        // Withdrawal Reject Modal Handlers
        function openRejectWithdrawModal(id, studentName) {
            document.getElementById('modalRejectWithdrawId').value = id;
            document.getElementById('modalRejectStudentName').textContent = studentName;
            const modal = document.getElementById('rejectWithdrawModal');
            modal.classList.add('active');
        }

        function closeRejectWithdrawModal() {
            const modal = document.getElementById('rejectWithdrawModal');
            modal.classList.remove('active');
        }

        // Close on background click
        window.addEventListener('click', function(event) {
            const cModal = document.getElementById('cancelModal');
            const rModal = document.getElementById('rejectWithdrawModal');
            if (event.target === cModal) {
                closeCancelModal();
            }
            if (event.target === rModal) {
                closeRejectWithdrawModal();
            }
        });
    </script>
</body>
</html>
