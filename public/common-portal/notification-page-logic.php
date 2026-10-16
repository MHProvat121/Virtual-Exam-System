<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Gatekeeper: Restrict access to logged-in users (Admin, Teacher, Student)
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration safely
$configPath = __DIR__ . '/../../src/config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
} else {
    @include_once __DIR__ . '/../config/config.php';
}

// Support both $conn and $pdo database connection variables
if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// Relative time calculator helper
function getRelativeTimeStr($dateTimeStr) {
    if (empty($dateTimeStr)) return 'Recently';
    
    $time = strtotime($dateTimeStr);
    if (!$time) return $dateTimeStr;

    $diff = $time - time();
    $absDiff = abs($diff);

    if ($diff > 0) {
        if ($absDiff < 3600) {
            $mins = ceil($absDiff / 60);
            return "starts in $mins min" . ($mins > 1 ? 's' : '');
        } elseif ($absDiff < 86400) {
            $hours = round($absDiff / 3600);
            return "starts in $hours hour" . ($hours > 1 ? 's' : '');
        } else {
            $days = round($absDiff / 86400);
            return "starts in $days day" . ($days > 1 ? 's' : '');
        }
    } else {
        if ($absDiff < 60) {
            return "Just now";
        } elseif ($absDiff < 3600) {
            $mins = floor($absDiff / 60);
            return "$mins min" . ($mins > 1 ? 's' : '') . " ago";
        } elseif ($absDiff < 86400) {
            $hours = floor($absDiff / 3600);
            return "$hours hour" . ($hours > 1 ? 's' : '') . " ago";
        } elseif ($absDiff < 172800) {
            return "Yesterday";
        } else {
            $days = floor($absDiff / 86400);
            return "$days day" . ($days > 1 ? 's' : '') . " ago";
        }
    }
}

// Renamed variable to $noticeBoardNotifications to prevent collision with header files
$noticeBoardNotifications = [];
$dbError = null;

if (isset($conn) && $conn instanceof PDO) {
    try {
        // Fetch all exam records ordered by latest updated/created
        $stmt = $conn->query("SELECT * FROM `exam_details` ORDER BY `updated_at` DESC, `id` DESC");
        $rawExams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawExams as $exam) {
            // Case-insensitive key extraction
            $examLower = array_change_key_case($exam, CASE_LOWER);

            $batch     = !empty($examLower['batch']) ? trim($examLower['batch']) : 'Batch';
            $subject   = !empty($examLower['subject']) ? trim($examLower['subject']) : 'Subject';
            $examType  = !empty($examLower['exam_type']) ? trim($examLower['exam_type']) : 'Exam';
            $statusRaw = !empty($examLower['status']) ? trim($examLower['status']) : 'Active';

            // Format Date & Time combined (e.g. 15/8/2026 6:44 pm)
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

            // Relative creation / update time
            $timestamp = $examLower['updated_at'] ?? $examLower['created_at'] ?? '';
            $relativeTime = getRelativeTimeStr($timestamp);

            // ISO timestamps for JS dynamic clock
            $startISO = '';
            $endISO   = '';
            if (!empty($examDate) && !empty($examTime)) {
                $sTS = strtotime($examDate . ' ' . $examTime);
                $durationMins = intval($examLower['duration'] ?? 0);
                $eTS = $sTS + ($durationMins * 60);
                $startISO = date('c', $sTS);
                $endISO   = date('c', $eTS);
            }

            // Normalize status for pattern matching
            $statusNorm = strtolower($statusRaw);

            if (strpos($statusNorm, 'cancel') !== false) {
                // CANCELLED EXAM
                $category = 'cancelled';
                $rowClass = 'notice-row';
                $iconWrapper = 'general-type';
                $iconClass = 'fa-solid fa-ban';
                $tagClass = 'tag-urgent';
                $tagText = 'Cancelled';
                $message = "<strong>" . htmlspecialchars($batch) . "</strong> <strong>" . htmlspecialchars($subject) . "</strong> <strong>" . htmlspecialchars($examType) . "</strong> exam has been cancelled today.";

            } elseif (strpos($statusNorm, 'edit') !== false || strpos($statusNorm, 'resched') !== false) {
                // RESCHEDULED EXAM
                $category = 'rescheduled';
                $rowClass = 'notice-row unread';
                $iconWrapper = 'result-type';
                $iconClass = 'fa-solid fa-calendar-days';
                $tagClass = 'tag-info';
                $tagText = 'Rescheduled';
                $message = "<strong>" . htmlspecialchars($batch) . "</strong> <strong>" . htmlspecialchars($subject) . "</strong> <strong>" . htmlspecialchars($examType) . "</strong> exam has been rescheduled at date <strong>" . htmlspecialchars($formattedDateTime) . "</strong>";

            } else {
                // NEW ACTIVE EXAM
                $category = 'active';
                $rowClass = 'notice-row unread';
                $iconWrapper = 'exam-type';
                $iconClass = 'fa-solid fa-file-signature';
                $tagClass = 'tag-urgent';
                $tagText = 'New';
                $message = "New Exam: <strong>" . htmlspecialchars($batch) . "</strong> - <strong>" . htmlspecialchars($subject) . "</strong> - <strong>" . htmlspecialchars($examType) . "</strong> exam has been published. Exam will be held at <strong>" . htmlspecialchars($formattedDateTime) . "</strong>";
            }

            $noticeBoardNotifications[] = [
                'id'                 => $examLower['id'] ?? 0,
                'batch'              => $batch,
                'subject'            => $subject,
                'exam_type'          => $examType,
                'status'             => $statusRaw,
                'category'           => $category,
                'row_class'          => $rowClass,
                'icon_wrapper'       => $iconWrapper,
                'icon_class'         => $iconClass,
                'tag_class'          => $tagClass,
                'tag_text'           => $tagText,
                'message'            => $message,
                'relative_time'      => $relativeTime,
                'formatted_datetime' => $formattedDateTime,
                'start_iso'          => $startISO,
                'end_iso'            => $endISO
            ];
        }
    } catch (PDOException $e) {
        $dbError = "Database Error: " . $e->getMessage();
    }
} else {
    $dbError = "Database Connection ($conn) not found or invalid.";
}