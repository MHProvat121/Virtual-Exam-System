<?php
/**
 * Admin Header & Sidebar Section Backend Logic
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

// 4. Helper Function: Profile Image URL (Fixes relative path resolution for subfolders)
if (!function_exists('getProfileImageUrl')) {
    function getProfileImageUrl(?string $imagePath): string {
        $defaultAvatar = '../assets/images/user-avatar.jpg';
        if (empty($imagePath)) {
            return $defaultAvatar;
        }
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        $path = $imagePath;
        if (strpos($path, 'assets/') === 0) {
            $path = '../' . $path;
        }

        $serverFilePath = __DIR__ . '/../' . ltrim(str_replace('../', '', $path), '/');
        if (file_exists($serverFilePath)) {
            return $path;
        }

        return $defaultAvatar;
    }
}

// 5. Get Session Keys
$sessionEmail   = $_SESSION['email'] ?? $_SESSION['user_email'] ?? '';
$sessionAdminId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? '';
$sessionName    = $_SESSION['full_name'] ?? $_SESSION['name'] ?? '';

// 6. Default Fallback Array matching `admins` table schema
$admin = [
    'id'             => $_SESSION['admin_db_id'] ?? 0,
    'admin_id'       => !empty($sessionAdminId) ? $sessionAdminId : 'ADM-00000',
    'full_name'      => !empty($sessionName) ? $sessionName : 'System Administrator',
    'email'          => !empty($sessionEmail) ? $sessionEmail : 'admin@example.com',
    'designation'    => 'System Administrator',
    'department'     => 'IT Department',
    'contact_number' => 'N/A',
    'profile_image'  => $_SESSION['profile_image'] ?? 'assets/images/user-avatar.jpg',
    'created_at'     => date('Y-m-d H:i:s'),
    'updated_at'     => date('Y-m-d H:i:s')
];

// 7. Fetch Real Admin Data from Database Safely
if ($dbConn) {
    try {
        if ($dbConn instanceof mysqli) {
            $stmt = $dbConn->prepare("
                SELECT a.*, u.name AS user_table_name, u.role 
                FROM `admins` a
                LEFT JOIN `users-table` u 
                       ON LOWER(TRIM(a.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci
                WHERE a.email = ? OR a.admin_id = ? OR u.username = ? OR a.id = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param("ssss", $sessionEmail, $sessionAdminId, $sessionAdminId, $sessionAdminId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($dbAdmin = $result->fetch_assoc()) {
                    if (empty($dbAdmin['full_name']) && !empty($dbAdmin['user_table_name'])) {
                        $dbAdmin['full_name'] = $dbAdmin['user_table_name'];
                    }
                    $admin = array_merge($admin, array_filter($dbAdmin));
                    $_SESSION['admin_db_id'] = $admin['id'];
                    $_SESSION['admin_id']    = $admin['admin_id'];
                    if (!empty($dbAdmin['profile_image'])) {
                        $_SESSION['profile_image'] = $dbAdmin['profile_image'];
                    }
                }
                $stmt->close();
            }
        } elseif ($dbConn instanceof PDO) {
            $stmt = $dbConn->prepare("
                SELECT a.*, u.name AS user_table_name, u.role 
                FROM `admins` a
                LEFT JOIN `users-table` u 
                       ON LOWER(TRIM(a.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci
                WHERE a.email = :email OR a.admin_id = :admin_id OR u.username = :username OR a.id = :admin_id
                LIMIT 1
            ");
            $stmt->execute([
                ':email'    => (string)$sessionEmail,
                ':admin_id' => (string)$sessionAdminId,
                ':username' => (string)$sessionAdminId
            ]);
            if ($dbAdmin = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($dbAdmin['full_name']) && !empty($dbAdmin['user_table_name'])) {
                    $dbAdmin['full_name'] = $dbAdmin['user_table_name'];
                }
                $admin = array_merge($admin, array_filter($dbAdmin));
                $_SESSION['admin_db_id'] = $admin['id'];
                $_SESSION['admin_id']    = $admin['admin_id'];
                if (!empty($dbAdmin['profile_image'])) {
                    $_SESSION['profile_image'] = $dbAdmin['profile_image'];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Admin Header/Sidebar Logic Error: " . $e->getMessage());
    }

    // 8. Dynamic Notification Counter (Counts pending student enrollments needing review)
    try {
        if ($dbConn instanceof mysqli) {
            $n_stmt = $dbConn->prepare("SELECT COUNT(*) AS total FROM `student_enrollments` WHERE `status` = 'Pending'");
            if ($n_stmt) {
                $n_stmt->execute();
                $res = $n_stmt->get_result()->fetch_assoc();
                $unread_count = (int)($res['total'] ?? 0);
                $n_stmt->close();
            }
        } elseif ($dbConn instanceof PDO) {
            $n_stmt = $dbConn->prepare("SELECT COUNT(*) AS total FROM `student_enrollments` WHERE `status` = 'Pending'");
            $n_stmt->execute();
            $res = $n_stmt->fetch(PDO::FETCH_ASSOC);
            $unread_count = (int)($res['total'] ?? 0);
        }
    } catch (Exception $e) {
        $unread_count = 0;
    }
}

if (!isset($unread_count)) {
    $unread_count = 0;
}