<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Session Gatekeeper: Restrict access to authorized users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Detect logged-in user role dynamically across common session keys
$userRole = 'teacher'; // default fallback
if (!empty($_SESSION['user_role'])) {
    $userRole = strtolower(trim($_SESSION['user_role']));
} elseif (!empty($_SESSION['role'])) {
    $userRole = strtolower(trim($_SESSION['role']));
} elseif (!empty($_SESSION['user_type'])) {
    $userRole = strtolower(trim($_SESSION['user_type']));
} elseif (!empty($_SESSION['user']['role'])) {
    $userRole = strtolower(trim($_SESSION['user']['role']));
}

// 4. Search directories for the role-specific header/sidebar file
$searchDirs = [
    __DIR__ . "/../{$userRole}/",
    __DIR__ . "/../{$userRole}s/",
    __DIR__ . "/../{$userRole}-portal/",
    __DIR__ . "/../teacher/",
    __DIR__ . "/"
];

$possibleFileNames = [
    "{$userRole}-header-sidebar-section.php",
    "{$userRole}-header-sidebar.php",
    "{$userRole}-sidebar-section.php",
    "{$userRole}-sidebar.php",
    "{$userRole}-header.php",
    "header-sidebar-section.php",
    "teacher-header-sidebar-section.php"
];

$headerSidebarFile = null;
$foundFolder = $userRole;

foreach ($searchDirs as $dir) {
    foreach ($possibleFileNames as $fileName) {
        if (file_exists($dir . $fileName)) {
            $headerSidebarFile = $dir . $fileName;
            $foundFolder = basename(dirname($headerSidebarFile));
            break 2;
        }
    }
}

// 5. Resolve Header CSS Path
$headerSidebarCss = "../assets/css/{$userRole}/{$userRole}-header-sidebar-section.css";
if (!file_exists(__DIR__ . "/../assets/css/{$userRole}/{$userRole}-header-sidebar-section.css")) {
    if (file_exists(__DIR__ . "/../assets/css/student/student-header-sidebar-section.css") && $userRole === 'student') {
        $headerSidebarCss = "../assets/css/student/student-header-sidebar-section.css";
    } else {
        $headerSidebarCss = "../assets/css/teacher/teacher-header-sidebar-section.css";
    }
}

// 6. Dynamic Dashboard URL Resolution for "Back to Dashboard" button
switch ($userRole) {
    case 'admin':
        $dashboardUrl = '../admin/admin-dashboard-page.php';
        break;
    case 'student':
        $dashboardUrl = '../student/student-dashboard-page.php';
        break;
    case 'teacher':
    default:
        $dashboardUrl = '../teacher/teacher-dashboard-page.php';
        break;
}

// 7. Include Database Configuration safely
$configPaths = [
    __DIR__ . '/../../src/config/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../../config/config.php'
];

foreach ($configPaths as $cPath) {
    if (file_exists($cPath)) {
        require_once $cPath;
        break;
    }
}

if (!isset($conn) && isset($pdo)) {
    $conn = $pdo;
}

// Helper function to resolve avatar image paths correctly
function formatAvatarUrl($path) {
    if (empty($path)) {
        return '../assets/images/user-avatar.jpg';
    }
    if (preg_match('/^(http|https|\/)/', $path)) {
        return $path;
    }
    if (strpos($path, '../') === 0) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}

// 8. FETCH TEACHERS AND ADMINS CONTACT INFORMATION
$contacts = [];

if (isset($conn) && $conn instanceof PDO) {
    try {
        // A. Fetch Teachers
        $sql_teachers = "
            SELECT 
                t.full_name AS name,
                t.teacher_id AS code_id,
                'Teacher' AS role,
                COALESCE(NULLIF(t.designation, ''), 'Lecturer') AS designation,
                t.email,
                COALESCE(NULLIF(t.contact_number, ''), 'N/A') AS contact_number,
                t.profile_image
            FROM `teachers` t
        ";
        $stmt_t = $conn->query($sql_teachers);
        $teachers_list = $stmt_t ? $stmt_t->fetchAll(PDO::FETCH_ASSOC) : [];

        // B. Fetch Admins
        $admins_list = [];
        try {
            $sql_admins = "
                SELECT 
                    a.full_name AS name,
                    a.admin_id AS code_id,
                    'Admin' AS role,
                    COALESCE(NULLIF(a.designation, ''), 'System Administrator') AS designation,
                    a.email,
                    COALESCE(NULLIF(a.contact_number, ''), 'N/A') AS contact_number,
                    a.profile_image
                FROM `admins` a
            ";
            $stmt_a = $conn->query($sql_admins);
            $admins_list = $stmt_a ? $stmt_a->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            $admins_list = [];
        }

        // Merge contact lists
        $contacts = array_merge($teachers_list, $admins_list);

    } catch (PDOException $e) {
        error_log("Error fetching contact list: " . $e->getMessage());
        $contacts = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Directory - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="<?= htmlspecialchars($headerSidebarCss); ?>">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-quesTable-page.css">

    <style>
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .search-box {
            position: relative;
            width: 100%;
            max-width: 380px;
        }
        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-box input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .badge-teacher {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .badge-admin {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-code {
            font-family: monospace;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            color: #334155;
            font-size: 13px;
        }
        .contact-link {
            color: #2563eb;
            text-decoration: none;
            transition: color 0.2s;
        }
        .contact-link:hover {
            text-decoration: underline;
            color: #1d4ed8;
        }
        .avatar-thumb {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
            background-color: #f8fafc;
        }
        .name-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- DYNAMIC ROLE-BASED HEADER & SIDEBAR -->
        <?php 
        if ($headerSidebarFile && file_exists($headerSidebarFile)) {
            ob_start();
            include $headerSidebarFile;
            $headerHtml = ob_get_clean();

            if ($foundFolder !== 'common-portal') {
                $rolePrefix = "../" . $foundFolder . "/";
                $headerHtml = preg_replace(
                    '/href=["\'](?!http|https|\/|#|\.\.\/)([^"\']+\.php[^"\']*)["\']/i',
                    'href="' . $rolePrefix . '$1"',
                    $headerHtml
                );
            }

            echo $headerHtml;
        } else {
            echo "<!-- Warning: Header file not found for role '{$userRole}'. -->";
        }
        ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">
            
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <h1>TEACHER & ADMIN CONTACT DIRECTORY</h1>

                <a href="<?= htmlspecialchars($dashboardUrl); ?>" class="btn-action btn-back">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard 
                </a>
            </div>

            <section class="table-section">

                <!-- SEARCHBAR HEADER -->
                <div class="table-header">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="tableSearch" placeholder="Search by name, ID, role, designation, email..." onkeyup="filterContactTable()">
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="contactTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>ID</th>
                                <th>Role</th>
                                <th>Designation</th>
                                <th>Gmail</th>
                                <th>Contact Number</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($contacts)): ?>
                                <?php foreach ($contacts as $contact): ?>
                                    <tr class="contact-row">
                                        <td>
                                            <div class="name-cell">
                                                <img src="<?php echo htmlspecialchars(formatAvatarUrl($contact['profile_image'])); ?>" 
                                                     alt="Avatar" 
                                                     class="avatar-thumb"
                                                     onerror="this.src='../assets/images/user-avatar.jpg';">
                                                <span><?php echo htmlspecialchars($contact['name'] ?? 'N/A'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-code"><?php echo htmlspecialchars($contact['code_id'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <span class="role-badge <?php echo strtolower($contact['role']) === 'admin' ? 'badge-admin' : 'badge-teacher'; ?>">
                                                <i class="fa-solid <?php echo strtolower($contact['role']) === 'admin' ? 'fa-user-shield' : 'fa-chalkboard-user'; ?>"></i> 
                                                <?php echo htmlspecialchars($contact['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($contact['designation']); ?></td>
                                        <td>
                                            <?php if (!empty($contact['email']) && $contact['email'] !== 'N/A'): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($contact['email']); ?>" class="contact-link">
                                                    <i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($contact['email']); ?>
                                                </a>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($contact['contact_number']) && $contact['contact_number'] !== 'N/A'): ?>
                                                <a href="tel:<?php echo htmlspecialchars($contact['contact_number']); ?>" class="contact-link">
                                                    <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($contact['contact_number']); ?>
                                                </a>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: #64748b;">
                                        <i class="fa-solid fa-address-book" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No contact records found in the system.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </section>

        </main>

    </div>

<!-- Shared UI Scripts -->
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>

<!-- Real-time Instant Search Script -->
<script>
function filterContactTable() {
    const input = document.getElementById("tableSearch");
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById("contactTable");
    const rows = table.getElementsByTagName("tr");

    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent || row.innerText;
        
        if (text.toLowerCase().indexOf(filter) > -1) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    }
}
</script>

</body>
</html>