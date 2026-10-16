<?php
/**
 * Student Header & Sidebar Section Backend Logic
 */

// 1. Start Session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Database Connection (Include config.php safely across relative paths)
if (file_exists(__DIR__ . '/../../src/config/config.php')) {
    require_once __DIR__ . '/../../src/config/config.php';
} elseif (file_exists(__DIR__ . '/../config/config.php')) {
    require_once __DIR__ . '/../config/config.php';
} elseif (file_exists(__DIR__ . '/../src/config/config.php')) {
    require_once __DIR__ . '/../src/config/config.php';
}

// Resolve Connection Variable
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : (isset($conn) && $conn instanceof mysqli ? $conn : null));

// 3. Current Page Name
$currentPage = basename($_SERVER['PHP_SELF']);

// 4. Helper Function: Profile Image URL (Fixes 404 paths for subfolders)
if (!function_exists('getProfileImageUrl')) {
    function getProfileImageUrl(?string $imagePath): string {
        $defaultAvatar = '../assets/images/user-avatar.jpg';
        if (empty($imagePath)) {
            return $defaultAvatar;
        }
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        // Ensure relative path has ../ for subfolders like student/
        $path = $imagePath;
        if (strpos($path, 'assets/') === 0) {
            $path = '../' . $path;
        }

        // Check file existence relative to project root
        $serverFilePath = __DIR__ . '/../' . ltrim(str_replace('../', '', $path), '/');
        if (file_exists($serverFilePath)) {
            return $path;
        }

        return $defaultAvatar;
    }
}

// 5. Get Session Keys
$sessionEmail   = $_SESSION['email'] ?? $_SESSION['user_email'] ?? '';
$sessionUserId  = $_SESSION['student_id'] ?? $_SESSION['username'] ?? $_SESSION['user_id'] ?? '';
$sessionName    = $_SESSION['full_name'] ?? $_SESSION['name'] ?? $_SESSION['student_name'] ?? '';

// 6. Default Fallback Array
$student = [
    'id'            => 0,
    'student_id'    => !empty($sessionUserId) ? $sessionUserId : 'STU-00000',
    'full_name'     => !empty($sessionName) ? $sessionName : 'Student User',
    'email'         => !empty($sessionEmail) ? $sessionEmail : 'student@example.com',
    'program'       => $_SESSION['student_program'] ?? 'B.Sc. Program',
    'batch'         => $_SESSION['student_batch'] ?? 'N/A',
    'profile_image' => $_SESSION['profile_image'] ?? '../assets/images/user-avatar.jpg'
];

// 7. Fetch Real Student Data from Database Safely
if ($dbConn) {
    try {
        if ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("
                SELECT s.*, u.name AS user_table_name, u.role 
                FROM `students` s
                LEFT JOIN `users-table` u 
                       ON LOWER(TRIM(s.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci
                WHERE s.email = ? OR s.student_id = ? OR u.username = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param("sss", $sessionEmail, $sessionUserId, $sessionUserId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($dbStudent = $result->fetch_assoc()) {
                    if (empty($dbStudent['full_name']) && !empty($dbStudent['user_table_name'])) {
                        $dbStudent['full_name'] = $dbStudent['user_table_name'];
                    }
                    $student = array_merge($student, array_filter($dbStudent));
                    if (!empty($dbStudent['profile_image'])) {
                        $_SESSION['profile_image'] = $dbStudent['profile_image'];
                    }
                }
                $stmt->close();
            }
        } elseif ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("
                SELECT s.*, u.name AS user_table_name, u.role 
                FROM `students` s
                LEFT JOIN `users-table` u 
                       ON LOWER(TRIM(s.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci
                WHERE s.email = :email OR s.student_id = :student_id OR u.username = :username
                LIMIT 1
            ");
            $stmt->execute([
                ':email'      => (string)$sessionEmail,
                ':student_id' => (string)$sessionUserId,
                ':username'   => (string)$sessionUserId
            ]);
            if ($dbStudent = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($dbStudent['full_name']) && !empty($dbStudent['user_table_name'])) {
                    $dbStudent['full_name'] = $dbStudent['user_table_name'];
                }
                $student = array_merge($student, array_filter($dbStudent));
                if (!empty($dbStudent['profile_image'])) {
                    $_SESSION['profile_image'] = $dbStudent['profile_image'];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Student Logic Error: " . $e->getMessage());
    }
}

// 8. Alias for header compatibility
$teacher = &$student;

if (!isset($unread_count)) {
    $unread_count = 3;
}