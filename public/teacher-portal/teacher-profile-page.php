<?php
// Core profile framework - dynamic MySQL integration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['email'])) {
    header("Location: ../login.php");
    exit();
}

$teacher    = false;
$user_data  = [];
$user_email = $_SESSION['email'] ?? '';
$user_id    = $_SESSION['user_id'] ?? null;

// 1. Fetch User Record (for Username)
if (!empty($user_email)) {
    $stmt_u = $conn->prepare("SELECT * FROM `users-table` WHERE `email` = :email LIMIT 1");
    $stmt_u->execute(['email' => $user_email]);
    $user_data = $stmt_u->fetch(PDO::FETCH_ASSOC) ?: [];
}

// 2. Fetch Teacher Profile
if (isset($_SESSION['teacher_id'])) {
    $stmt = $conn->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = :tid OR `id` = :id LIMIT 1");
    $stmt->execute(['tid' => $_SESSION['teacher_id'], 'id' => $_SESSION['teacher_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher && !empty($user_email)) {
    $stmt = $conn->prepare("SELECT * FROM `teachers` WHERE `email` = :email LIMIT 1");
    $stmt->execute(['email' => $user_email]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 3. AUTO-CREATE TEACHER PROFILE IF NOT FOUND (FIXED HERE)
if (!$teacher && !empty($user_email)) {
    $full_name = $user_data['name'] ?? 'New Teacher';
    $generated_teacher_id = 'TCH-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

    $stmt_ins = $conn->prepare("
        INSERT INTO `teachers` (teacher_id, full_name, email, designation) 
        VALUES (:tid, :fname, :email, 'Lecturer')
    ");
    $stmt_ins->execute([
        'tid'   => $generated_teacher_id,
        'fname' => $full_name,
        'email' => $user_email
    ]);

    $stmt_fetch = $conn->prepare("SELECT * FROM `teachers` WHERE `email` = :email LIMIT 1");
    $stmt_fetch->execute(['email' => $user_email]);
    $teacher = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    die("Teacher profile record could not be loaded or created.");
}

// Keep sessions in sync
$_SESSION['teacher_db_id'] = $teacher['id'];
$_SESSION['teacher_id']    = $teacher['teacher_id'];

/*===========================================================================
 * Resolves avatar image path to prevent 404 broken images
==================================================================================== */
function getProfileAvatarUrl($imagePath) {
    $defaultAvatar = '../assets/images/user-avatar.jpg';
    
    if (empty($imagePath)) {
        return $defaultAvatar;
    }

    // Full web URLs (e.g., http:// or https://) or root paths
    if (preg_match('/^(http|https|\/)/', $imagePath)) {
        return $imagePath;
    }

    // Already has relative parent directory prefix
    if (strpos($imagePath, '../') === 0) {
        return $imagePath;
    }

    // Prepend '../' so subfolder pages can reach root assets/
    return '../' . ltrim($imagePath, '/');
}

// FETCH EDUCATION INFORMATION
$education = [];
try {
    $stmt_edu = $conn->prepare("SELECT * FROM `teacher_education` WHERE `teacher_id` = :tid LIMIT 1");
    $stmt_edu->execute(['tid' => $teacher['id']]);
    $education = $stmt_edu->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $education = [];
}

// FETCH PAYMENT INFORMATION
$payment = [];
try {
    $stmt_pay = $conn->prepare("SELECT * FROM `teacher_payments` WHERE `teacher_id` = :tid LIMIT 1");
    $stmt_pay->execute(['tid' => $teacher['id']]);
    $payment = $stmt_pay->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $payment = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-profile-page.css">
</head>

<body>

    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

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

            <h1 class="page-title">My Profile</h1>

            <!-- Teacher Summary Banner -->
            <section class="profile-banner-card">
                <div class="avatar-wrapper">
                    <img src="<?php echo htmlspecialchars(getProfileAvatarUrl($teacher['profile_image'] ?? '')); ?>" 
                        alt="Teacher Profile Picture" 
                        class="avatar-img" 
                        id="profile-display-img"
                        onerror="this.onerror=null; this.src='../assets/images/user-avatar.jpg';">
                
                    <div class="avatar-edit-overlay" id="trigger-avatar-upload" title="Update Profile Picture">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                
                    <!-- Hidden Form for Avatar Upload -->
                    <form id="avatarUploadForm" action="teacher-profile-page-logic.php" method="POST" enctype="multipart/form-data" style="display: none;">
                        <input type="hidden" name="section" value="avatar">
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                        <input type="file" name="avatar" id="avatar-file-input" accept="image/*" onchange="document.getElementById('avatarUploadForm').submit();">
                    </form>
                </div>
                <div class="banner-info">
                    <h2 class="banner-name" id="val-banner-name"><?php echo htmlspecialchars($teacher['full_name'] ?? ''); ?></h2>
                    <div class="banner-meta">
                        <span><i class="fa-regular fa-id-card"></i> ID: <span id="val-banner-id"><?php echo htmlspecialchars($teacher['teacher_id']); ?></span></span>
                        <span><i class="fa-solid fa-at"></i> Username: <span><?php echo htmlspecialchars($user_data['username'] ?? 'N/A'); ?></span></span>
                    </div>
                    <span class="status-badge"><i class="fa-solid fa-circle-check"></i> <span id="val-banner-designation"><?php echo htmlspecialchars(!empty($teacher['designation']) ? $teacher['designation'] : 'Lecturer'); ?></span></span>
                </div>
            </section>

            <!-- Personal Information Card -->
            <section class="info-card" id="personal-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Personal Information</h2>
                    <button class="edit-action-btn" onclick="openEditModal('personal')" title="Edit Information">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-user"></i> Full Name</span>
                        <span class="detail-value" id="val-fullname"><?php echo htmlspecialchars(!empty($teacher['full_name']) ? $teacher['full_name'] : 'N/A'); ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-at"></i> User Name</span>
                        <span class="detail-value" id="val-username"><?php echo htmlspecialchars(!empty($user_data['username']) ? $user_data['username'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-envelope"></i> Email</span>
                        <span class="detail-value" id="val-email"><?php echo htmlspecialchars(!empty($teacher['email']) ? $teacher['email'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-id-card"></i> Teacher ID</span>
                        <span class="detail-value" id="val-teacher"><?php echo htmlspecialchars(!empty($teacher['teacher_id']) ? $teacher['teacher_id'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-phone"></i> Contact Number</span>
                        <span class="detail-value" id="val-contact"><?php echo htmlspecialchars(!empty($teacher['contact_number']) ? $teacher['contact_number'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-user-shield"></i> Designation</span>
                        <span class="detail-value" id="val-designation"><?php echo htmlspecialchars(!empty($teacher['designation']) ? $teacher['designation'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-location-dot"></i> Present Address</span>
                        <span class="detail-value" id="val-present"><?php echo htmlspecialchars(!empty($teacher['present_address']) ? $teacher['present_address'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-house-chimney"></i> Permanent Address</span>
                        <span class="detail-value" id="val-permanent"><?php echo htmlspecialchars(!empty($teacher['permanent_address']) ? $teacher['permanent_address'] : 'N/A'); ?></span>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-calendar-days"></i> Registration Date</span>
                        <span class="detail-value" id="val-regdate">
                            <?php 
                                echo !empty($user_data['created_at']) 
                                    ? date('d/m/Y', strtotime($user_data['created_at'])) 
                                    : 'N/A'; 
                            ?>
                        </span>
                    </div>

                </div>
            </section>

            <!-- Education Information Card -->
            <section class="info-card" id="education-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Education Information</h2>
                    <button class="edit-action-btn" onclick="openEditModal('education')" title="Edit Education">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-university"></i> Institution</span>
                        <span class="detail-value" id="val-institution"><?php echo htmlspecialchars(!empty($education['institution']) ? $education['institution'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-graduation-cap"></i> Program / Degree</span>
                        <span class="detail-value" id="val-program"><?php echo htmlspecialchars(!empty($education['program']) ? $education['program'] : 'N/A'); ?></span>
                    </div>
                </div>
            </section>

            <!-- Payment Information Card -->
            <section class="info-card" id="payment-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Payment Information</h2>
                    <button class="edit-action-btn" onclick="openEditModal('payment')" title="Edit Payment Information">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-building-columns"></i> Bank Name</span>
                        <span class="detail-value" id="val-bank-name"><?php echo htmlspecialchars(!empty($payment['bank_name']) ? $payment['bank_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-file-signature"></i> Account Holder Name</span>
                        <span class="detail-value" id="val-account-name"><?php echo htmlspecialchars(!empty($payment['account_name']) ? $payment['account_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-credit-card"></i> Account Number</span>
                        <span class="detail-value" id="val-account-number"><?php echo htmlspecialchars(!empty($payment['account_number']) ? $payment['account_number'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Banking (Bkash/Nagad)</span>
                        <span class="detail-value" id="val-mobile-payment"><?php echo htmlspecialchars(!empty($payment['mobile_banking_number']) ? $payment['mobile_banking_number'] : 'N/A'); ?></span>
                    </div>
                </div>
            </section>

        </main>

    </div>

<!-- EDIT DATA MODAL -->
<div id="editDataModal" class="modal-overlay">
    <div class="modal-container">
        <h3 id="modalTitle">Edit Information</h3>
        <form id="modalForm" method="POST" action="teacher-profile-page-logic.php">
            <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
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
<script src="../assets/js/teacher/teacher-profile-page.js"></script>
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>

</body>

</html>