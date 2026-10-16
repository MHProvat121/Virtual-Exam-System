<?php
// Core profile framework - Dynamic MySQL integration for Admins
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['email'])) {
    header("Location: ../login.php");
    exit();
}

$admin = false;
$user_email = $_SESSION['email'] ?? '';
$user_id    = $_SESSION['user_id'] ?? null;

// 1. Try fetching admin profile by stored admin_id
if (isset($_SESSION['admin_id'])) {
    $stmt = $conn->prepare("SELECT * FROM `admins` WHERE `admin_id` = :aid OR `id` = :id LIMIT 1");
    $stmt->execute(['aid' => $_SESSION['admin_id'], 'id' => $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 2. Try fetching by user's session email
if (!$admin && !empty($user_email)) {
    $stmt = $conn->prepare("SELECT * FROM `admins` WHERE `email` = :email LIMIT 1");
    $stmt->execute(['email' => $user_email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 3. AUTO-CREATE ADMIN PROFILE IF NOT FOUND IN DB
if (!$admin && !empty($user_email)) {
    // Fetch full name from users-table
    $stmt_u = $conn->prepare("SELECT name FROM `users-table` WHERE email = :email LIMIT 1");
    $stmt_u->execute(['email' => $user_email]);
    $u_data = $stmt_u->fetch(PDO::FETCH_ASSOC);
    $full_name = $u_data['name'] ?? 'System Administrator';

    // Generate unique varchar admin_id (e.g. ADM-77A0)
    $generated_admin_id = 'ADM-' . strtoupper(substr(md5(uniqid((string)rand(), true)), 0, 6));

    // Create profile record in admins table matching table schema
    $stmt_ins = $conn->prepare("
        INSERT INTO `admins` (admin_id, full_name, email, designation, department) 
        VALUES (:aid, :fname, :email, 'System Administrator', 'IT Department')
    ");
    $stmt_ins->execute([
        'aid'   => $generated_admin_id,
        'fname' => $full_name,
        'email' => $user_email
    ]);

    // Fetch newly created record
    $stmt_fetch = $conn->prepare("SELECT * FROM `admins` WHERE `email` = :email LIMIT 1");
    $stmt_fetch->execute(['email' => $user_email]);
    $admin = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
}

if (!$admin) {
    die("Admin profile record could not be loaded or created.");
}

// Keep session synchronized
$_SESSION['admin_db_id'] = $admin['id'];          // Primary Key ID (int)
$_SESSION['admin_id']    = $admin['admin_id'];     // Varchar Code (e.g. ADM-XXXX)
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-profile-page.css">
</head>

<body>

    <div class="main-layout">

        <!-- ADMIN HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/admin-header-sidebar-section.php'; ?>

        <!-- INFORMATION CONTENT AREA -->
        <main class="content-container">

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div style="padding: 12px 18px; margin-bottom: 20px; border-radius: 6px; font-size: 0.95rem; background-color: <?php echo $_SESSION['flash_type'] === 'success' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $_SESSION['flash_type'] === 'success' ? '#166534' : '#991b1b'; ?>; border: 1px solid <?php echo $_SESSION['flash_type'] === 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
                    <i class="fa-solid <?php echo $_SESSION['flash_type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php 
                        echo htmlspecialchars($_SESSION['flash_message']); 
                        unset($_SESSION['flash_message']);
                        unset($_SESSION['flash_type']);
                    ?>
                </div>
            <?php endif; ?>

            <h1 class="page-title">Admin Profile</h1>

            <!-- Admin Summary Banner -->
            <section class="profile-banner-card">
                <div class="avatar-wrapper">
                    <img src="<?php echo htmlspecialchars(getProfileImageUrl($admin['profile_image'] ?? 'assets/images/user-avatar.jpg')); ?>" alt="Admin Profile Picture" class="avatar-img" id="profile-display-img">
                
                    <div class="avatar-edit-overlay" id="trigger-avatar-upload" title="Update Profile Picture">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                
                    <!-- Hidden Form for Avatar Upload -->
                    <form id="avatarUploadForm" action="admin-profile-page-logic.php" method="POST" enctype="multipart/form-data" style="display: none;">
                        <input type="hidden" name="section" value="avatar">
                        <input type="hidden" name="admin_id" value="<?php echo (int)$admin['id']; ?>">
                        <input type="file" name="avatar" id="avatar-file-input" accept="image/*" onchange="document.getElementById('avatarUploadForm').submit();">
                    </form>
                </div>
                <div class="banner-info">
                    <h2 class="banner-name" id="val-banner-name"><?php echo htmlspecialchars($admin['full_name'] ?? ''); ?></h2>
                    <div class="banner-meta">
                        <span><i class="fa-regular fa-id-card"></i> Admin ID: <span id="val-banner-id"><?php echo htmlspecialchars($admin['admin_id']); ?></span></span>
                        <span><i class="fa-solid fa-shield-halved"></i> Role: <span id="val-banner-role">System Admin</span></span>
                    </div>
                    <span class="status-badge" style="background-color: #dbeafe; color: #1e40af;"><i class="fa-solid fa-user-shield"></i> <span id="val-banner-designation"><?php echo htmlspecialchars(!empty($admin['designation']) ? $admin['designation'] : 'System Administrator'); ?></span></span>
                </div>
            </section>

            <!-- Personal Information Card -->
            <section class="info-card" id="personal-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Administrative Profile</h2>
                    <button class="edit-action-btn" onclick="openEditModal('personal')" title="Edit Information">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-user"></i> Full Name</span>
                        <span class="detail-value" id="val-fullname"><?php echo htmlspecialchars(!empty($admin['full_name']) ? $admin['full_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-envelope"></i> Email</span>
                        <span class="detail-value" id="val-email"><?php echo htmlspecialchars(!empty($admin['email']) ? $admin['email'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-id-card"></i> Admin ID</span>
                        <span class="detail-value" id="val-admin-id"><?php echo htmlspecialchars(!empty($admin['admin_id']) ? $admin['admin_id'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-phone"></i> Contact Number</span>
                        <span class="detail-value" id="val-contact"><?php echo htmlspecialchars(!empty($admin['contact_number']) ? $admin['contact_number'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-user-shield"></i> Designation</span>
                        <span class="detail-value" id="val-designation"><?php echo htmlspecialchars(!empty($admin['designation']) ? $admin['designation'] : 'System Administrator'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-building"></i> Department / Office</span>
                        <span class="detail-value" id="val-department"><?php echo htmlspecialchars(!empty($admin['department']) ? $admin['department'] : 'IT Department'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-calendar-days"></i> Registration Date</span>
                        <span class="detail-value" id="val-regdate">
                            <?php 
                                echo !empty($admin['created_at']) 
                                    ? date('d/m/Y', strtotime($admin['created_at'])) 
                                    : 'N/A'; 
                            ?>
                        </span>
                    </div>
                </div>
            </section>

        </main>

    </div>

<!-- EDIT DATA MODAL -->
<div id="editDataModal" class="modal-overlay">
    <div class="modal-container">
        <h3 id="modalTitle">Edit Information</h3>
        <form id="modalForm" method="POST" action="admin-profile-page-logic.php">
            <input type="hidden" name="admin_id" value="<?php echo (int)$admin['id']; ?>">
            <input type="hidden" name="section" id="modalSectionInput" value="">
            
            <div id="modalInputsContainer"></div>

            <div class="modal-actions-row">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript Files -->
<script src="../assets/js/admin/admin-profile-page.js"></script>
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>

</body>

</html>