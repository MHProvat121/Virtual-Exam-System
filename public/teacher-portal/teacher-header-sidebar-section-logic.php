<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Include Database Configuration safely
$configPath = __DIR__ . '/../../src/config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
} else {
    @include_once __DIR__ . '/../config/config.php';
}

// Support both $conn and $pdo database variables
if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// Helper function to build accurate image relative path
if (!function_exists('getProfileImageUrl')) {
    function getProfileImageUrl($path) {
        if (empty($path)) {
            return '../assets/images/user-avatar.jpg';
        }
        if (preg_match('~^(https?://|/|\.\./)~i', $path)) {
            return $path;
        }
        if (file_exists($path)) {
            return $path;
        }
        if (file_exists('../' . $path)) {
            return '../' . $path;
        }
        return '../' . ltrim($path, '/');
    }
}

// ========================================================
//  FETCH TEACHER PERSONAL INFORMATION
// ========================================================
$headerTeacher = null;

if (isset($conn) && $conn instanceof PDO) {
    try {
        // Step 1: Lookup by session email
        if (!empty($_SESSION['email'])) {
            $stmt = $conn->prepare("SELECT * FROM `teachers` WHERE LOWER(TRIM(`email`)) = LOWER(TRIM(:email)) LIMIT 1");
            $stmt->execute(['email' => $_SESSION['email']]);
            $headerTeacher = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Step 2: Lookup by teacher_id code or primary key id
        if (!$headerTeacher && !empty($_SESSION['teacher_id'])) {
            $stmt = $conn->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = :tid OR `id` = :id_num LIMIT 1");
            $stmt->execute([
                'tid'    => $_SESSION['teacher_id'],
                'id_num' => is_numeric($_SESSION['teacher_id']) ? (int)$_SESSION['teacher_id'] : 0
            ]);
            $headerTeacher = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Step 3: Fallback lookup via user_id in users-table
        if (!$headerTeacher && !empty($_SESSION['user_id'])) {
            $stmt = $conn->prepare("
                SELECT t.* 
                FROM `users-table` u
                LEFT JOIN `teachers` t 
                       ON LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(t.email)) COLLATE utf8mb4_unicode_ci
                WHERE u.id = :uid LIMIT 1
            ");
            $stmt->execute(['uid' => $_SESSION['user_id']]);
            $headerTeacher = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error in header teacher lookup: " . $e->getMessage());
        $headerTeacher = null;
    }
}

// Fallback array ONLY if all database lookups failed completely
if (!$headerTeacher) {
    $headerTeacher = [
        'id'            => 0,
        'teacher_id'    => $_SESSION['teacher_id'] ?? 'N/A',
        'full_name'     => $_SESSION['teacher_name'] ?? 'Teacher',
        'designation'   => 'Senior Lecturer',
        'profile_image' => $_SESSION['profile_image'] ?? 'assets/images/user-avatar.jpg'
    ];
}

// Keep session synchronized
$_SESSION['teacher_id']     = $headerTeacher['teacher_id'];
$_SESSION['teacher_name']   = $headerTeacher['full_name'];
$_SESSION['profile_image']  = $headerTeacher['profile_image'];

// Resolve Image URL
$header_profile_img = getProfileImageUrl($headerTeacher['profile_image']);

// ========================================================
//  FETCH DYNAMIC NOTIFICATIONS FROM exam_details TABLE
// ========================================================
$headerNotifications = [];
$headerUnreadCount = 0;

if (isset($conn) && $conn instanceof PDO) {
    try {
        $stmt_header_notif = $conn->query("SELECT * FROM `exam_details` ORDER BY `updated_at` DESC, `id` DESC LIMIT 5");
        $rawHeaderExams = $stmt_header_notif->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawHeaderExams as $exam) {
            $examLower = array_change_key_case($exam, CASE_LOWER);

            $batch     = !empty($examLower['batch']) ? trim($examLower['batch']) : 'Batch';
            $subject   = !empty($examLower['subject']) ? trim($examLower['subject']) : 'Subject';
            $examType  = !empty($examLower['exam_type']) ? trim($examLower['exam_type']) : 'Exam';
            $statusRaw = !empty($examLower['status']) ? trim($examLower['status']) : 'Active';

            $examDate = $examLower['exam_date'] ?? '';
            $examTime = $examLower['exam_time'] ?? '';
            $formattedDateTime = 'N/A';

            if (!empty($examDate)) {
                $fullDateTimeStr = !empty($examTime) ? ($examDate . ' ' . $examTime) : $examDate;
                $ts = strtotime($fullDateTimeStr);
                if ($ts) {
                    $formattedDateTime = date('d/n/Y g:i a', $ts);
                }
            }

            $timestamp = $examLower['updated_at'] ?? $examLower['created_at'] ?? '';
            $relTime = 'Recently';
            if (!empty($timestamp)) {
                $diff = time() - strtotime($timestamp);
                if ($diff < 60) {
                    $relTime = 'Just now';
                } elseif ($diff < 3600) {
                    $relTime = floor($diff / 60) . ' min ago';
                } elseif ($diff < 86400) {
                    $relTime = floor($diff / 3600) . ' hr ago';
                } else {
                    $days = floor($diff / 86400);
                    $relTime = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                }
            }

            $statusNorm = strtolower($statusRaw);
            $isUnread = false;

            if (strpos($statusNorm, 'cancel') !== false) {
                $iconClass = 'fa-solid fa-ban';
                $message = "<strong>{$batch}</strong> <strong>{$subject}</strong> <strong>{$examType}</strong> exam cancelled.";
                $itemClass = '';
            } elseif (strpos($statusNorm, 'edit') !== false || strpos($statusNorm, 'resched') !== false) {
                $iconClass = 'fa-solid fa-calendar-days';
                $message = "<strong>{$batch}</strong> <strong>{$subject}</strong> <strong>{$examType}</strong> rescheduled to {$formattedDateTime}.";
                $itemClass = 'unread';
                $isUnread = true;
            } else {
                $iconClass = 'fa-solid fa-file-signature';
                $message = "New Exam: <strong>{$batch}</strong> - <strong>{$subject}</strong> published.";
                $itemClass = 'unread';
                $isUnread = true;
            }

            if ($isUnread) {
                $headerUnreadCount++;
            }

            $headerNotifications[] = [
                'icon_class' => $iconClass,
                'message'    => $message,
                'time'       => $relTime,
                'item_class' => $itemClass
            ];
        }
    } catch (PDOException $e) {
        $headerNotifications = [];
    }
}

// Detect current page filename for active menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);