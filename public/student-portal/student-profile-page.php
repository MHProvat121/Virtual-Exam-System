<?php
// Core profile framework - Dynamic MySQL integration for Students
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../src/config/config.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['email'])) {
    header("Location: ../login.php");
    exit();
}

$student = false;
$user_email = $_SESSION['email'] ?? '';
$user_id    = $_SESSION['user_id'] ?? null;

// 1. Fetch student profile by stored student_id
if (isset($_SESSION['student_id'])) {
    $stmt = $conn->prepare("SELECT * FROM `students` WHERE `student_id` = :sid OR `id` = :id LIMIT 1");
    $stmt->execute(['sid' => $_SESSION['student_id'], 'id' => $_SESSION['student_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 2. Try fetching by user's session email
if (!$student && !empty($user_email)) {
    $stmt = $conn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
    $stmt->execute(['email' => $user_email]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 3. AUTO-CREATE STUDENT PROFILE IF NOT FOUND
if (!$student && !empty($user_email)) {
    $stmt_u = $conn->prepare("SELECT name FROM `users-table` WHERE email = :email LIMIT 1");
    $stmt_u->execute(['email' => $user_email]);
    $u_data = $stmt_u->fetch(PDO::FETCH_ASSOC);
    $full_name = $u_data['name'] ?? 'New Student';

    $generated_student_id = 'STD-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

    $stmt_ins = $conn->prepare("
        INSERT INTO `students` (student_id, full_name, email) 
        VALUES (:sid, :fname, :email)
    ");
    $stmt_ins->execute([
        'sid'   => $generated_student_id,
        'fname' => $full_name,
        'email' => $user_email
    ]);

    $stmt_fetch = $conn->prepare("SELECT * FROM `students` WHERE `email` = :email LIMIT 1");
    $stmt_fetch->execute(['email' => $user_email]);
    $student = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    die("Student profile record could not be loaded or created.");
}

// Keep session synchronized
$_SESSION['student_db_id'] = $student['id'];          // Primary Key ID (int)
$_SESSION['student_id']    = $student['student_id'];   // Varchar Code (e.g. STD-XXXX)

// Fetch Username from users-table
$username = '';
if (!empty($student['email'])) {
    $stmt_user = $conn->prepare("SELECT `username` FROM `users-table` WHERE `email` = :email LIMIT 1");
    $stmt_user->execute(['email' => $student['email']]);
    $u_row = $stmt_user->fetch(PDO::FETCH_ASSOC);
    $username = $u_row['username'] ?? '';
}

// Format profile image path so subfolders can locate it without 404
$profile_img = !empty($student['profile_image']) ? $student['profile_image'] : '../assets/images/user-avatar.jpg';
if (strpos($profile_img, 'assets/') === 0) {
    $profile_img = '../' . $profile_img;
}

// ========================================================
// FETCH STUDENT EDUCATION & PAYMENT INFO FROM DB
// ========================================================
$stmt_edu = $conn->prepare("SELECT * FROM `student_education` WHERE `student_id` = :sid LIMIT 1");
$stmt_edu->execute(['sid' => $student['id']]);
$education = $stmt_edu->fetch(PDO::FETCH_ASSOC) ?: [
    'institution' => '',
    'program'     => $student['program'] ?? ''
];

$stmt_pay = $conn->prepare("SELECT * FROM `student_payments` WHERE `student_id` = :sid LIMIT 1");
$stmt_pay->execute(['sid' => $student['id']]);
$payment = $stmt_pay->fetch(PDO::FETCH_ASSOC) ?: [
    'bank_name'             => '',
    'account_name'          => '',
    'account_number'        => '',
    'mobile_banking_number' => ''
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-profile-page.css">
</head>

<body>

    <div class="main-layout">

        <!-- STUDENT HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/student-header-sidebar-section.php'; ?>

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

            <!-- Student Summary Banner -->
            <section class="profile-banner-card">
                <div class="avatar-wrapper">
                    <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Student Profile Picture" class="avatar-img" id="profile-display-img">
                
                    <div class="avatar-edit-overlay" id="trigger-avatar-upload" title="Update Profile Picture">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                
                    <!-- Hidden Form for Avatar Upload -->
                    <form id="avatarUploadForm" action="student-profile-page-logic.php" method="POST" enctype="multipart/form-data" style="display: none;">
                        <input type="hidden" name="section" value="avatar">
                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                        <input type="file" name="avatar" id="avatar-file-input" accept="image/*" onchange="document.getElementById('avatarUploadForm').submit();">
                    </form>
                </div>
                <div class="banner-info">
                    <h2 class="banner-name" id="val-banner-name"><?php echo htmlspecialchars($student['full_name'] ?? ''); ?></h2>
                    <div class="banner-meta">
                        <span><i class="fa-regular fa-id-card"></i> Student ID: <span id="val-banner-id"><?php echo htmlspecialchars($student['student_id']); ?></span></span>
                        <span><i class="fa-solid fa-layer-group"></i> Batch: <span id="val-banner-batch"><?php echo htmlspecialchars(!empty($student['batch']) ? $student['batch'] : 'N/A'); ?></span></span>
                    </div>
                    <span class="status-badge"><i class="fa-solid fa-graduation-cap"></i> <span id="val-banner-status">Enrolled Student</span></span>
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
                        <span class="detail-value" id="val-fullname"><?php echo htmlspecialchars(!empty($student['full_name']) ? $student['full_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-at"></i> Username</span>
                        <span class="detail-value" id="val-username"><?php echo htmlspecialchars(!empty($username) ? $username : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-envelope"></i> Email</span>
                        <span class="detail-value" id="val-email"><?php echo htmlspecialchars(!empty($student['email']) ? $student['email'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-id-card"></i> Student ID</span>
                        <span class="detail-value" id="val-student-id"><?php echo htmlspecialchars(!empty($student['student_id']) ? $student['student_id'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-layer-group"></i> Assigned Batch</span>
                        <span class="detail-value" id="val-batch"><?php echo htmlspecialchars(!empty($student['batch']) ? $student['batch'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-phone"></i> Contact Number</span>
                        <span class="detail-value" id="val-contact"><?php echo htmlspecialchars(!empty($student['contact_number']) ? $student['contact_number'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-user-group"></i> Guardian Contact</span>
                        <span class="detail-value" id="val-guardian"><?php echo htmlspecialchars(!empty($student['guardian_contact']) ? $student['guardian_contact'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-location-dot"></i> Present Address</span>
                        <span class="detail-value" id="val-present"><?php echo htmlspecialchars(!empty($student['present_address']) ? $student['present_address'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-house-chimney"></i> Permanent Address</span>
                        <span class="detail-value" id="val-permanent"><?php echo htmlspecialchars(!empty($student['permanent_address']) ? $student['permanent_address'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-regular fa-calendar-days"></i> Registration Date</span>
                        <span class="detail-value" id="val-regdate">
                            <?php 
                                echo !empty($student['registration_date']) 
                                    ? date('d/m/Y', strtotime($student['registration_date'])) 
                                    : (!empty($student['created_at']) ? date('d/m/Y', strtotime($student['created_at'])) : 'N/A'); 
                            ?>
                        </span>
                    </div>
                </div>
            </section>

            <!-- Education Details Card -->
            <section class="info-card" id="academic-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Education Details</h2>
                    <button class="edit-action-btn" onclick="openEditModal('education')" title="Edit Education Info">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-university"></i> Institution</span>
                        <span class="detail-value" id="val-institution"><?php echo htmlspecialchars(!empty($education['institution']) ? $education['institution'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-book-bookmark"></i> Program / Degree</span>
                        <span class="detail-value" id="val-program"><?php echo htmlspecialchars(!empty($education['program']) ? $education['program'] : (!empty($student['program']) ? $student['program'] : 'N/A')); ?></span>
                    </div>
                </div>
            </section>

            <!-- Payment Information Card -->
            <section class="info-card" id="payment-section">
                <div class="card-header-row">
                    <h2 class="card-heading">Payment Information</h2>
                    <button class="edit-action-btn" onclick="openEditModal('payment')" title="Edit Payment Details">
                        <i class="fa-regular fa-pen-to-square"></i>
                    </button>
                </div>
                <div class="details-grid">
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-building-columns"></i> Bank Name</span>
                        <span class="detail-value" id="val-bank-name"><?php echo htmlspecialchars(!empty($payment['bank_name']) ? $payment['bank_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-user-check"></i> Account Name</span>
                        <span class="detail-value" id="val-account-name"><?php echo htmlspecialchars(!empty($payment['account_name']) ? $payment['account_name'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-credit-card"></i> Account Number</span>
                        <span class="detail-value" id="val-account-number"><?php echo htmlspecialchars(!empty($payment['account_number']) ? $payment['account_number'] : 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Banking</span>
                        <span class="detail-value" id="val-mobile-banking"><?php echo htmlspecialchars(!empty($payment['mobile_banking_number']) ? $payment['mobile_banking_number'] : 'N/A'); ?></span>
                    </div>
                </div>
            </section>

        </main>

    </div>

<!-- EDIT DATA MODAL -->
<div id="editDataModal" class="modal-overlay">
    <div class="modal-container">
        <h3 id="modalTitle">Edit Information</h3>
        <form id="modalForm" method="POST" action="student-profile-page-logic.php">
            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
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
<script src="../assets/js/student/student-profile-page.js"></script>
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>

</body>

</html>