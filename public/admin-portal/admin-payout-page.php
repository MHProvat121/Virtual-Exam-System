<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-cache headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 2. RBAC Gatekeeper: Allow ONLY Admins
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration
require_once __DIR__ . '/../../src/config/config.php';

// Resolve PDO Connection
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Helper function to resolve avatar image paths correctly
function formatAvatarUrl(?string $path): string {
    $defaultAvatar = '../assets/images/user-avatar.jpg';
    if (empty($path)) {
        return $defaultAvatar;
    }
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }
    if (strpos($path, 'assets/') === 0) {
        return '../' . $path;
    }
    if (strpos($path, '../') === 0) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}

// 4. FETCH DATA
$salaries            = [];
$teachers            = [];
$teacher_courses_map = []; // Maps teacher_id/PK to assigned courses array from batches table
$all_db_courses      = []; // Global list of all courses/batches in database
$total_paid          = 0;
$pending_cnt         = 0;

if ($dbConn) {
    try {
        // A. Fetch All Registered Teachers into memory map
        $teacher_map = [];
        $stmt_t = $dbConn->query("SELECT * FROM `teachers` ORDER BY `full_name` ASC");
        if ($stmt_t) {
            while ($t = $stmt_t->fetch(PDO::FETCH_ASSOC)) {
                $teachers[] = $t;
                if (!empty($t['teacher_id'])) {
                    $teacher_map[trim($t['teacher_id'])] = $t;
                    $teacher_map[strtolower(trim($t['teacher_id']))] = $t;
                }
                if (!empty($t['id'])) {
                    $teacher_map[(string)$t['id']] = $t;
                }
                if (!empty($t['email'])) {
                    $teacher_map[strtolower(trim($t['email']))] = $t;
                }
            }
        }

        // B. Fetch Batches & Subjects Connected to Each Teacher
        $stmt_b = $dbConn->query("
            SELECT b.teacher_id, b.batch, b.subject, t.teacher_id AS code_id, t.id AS pk_id 
            FROM `batches` b
            LEFT JOIN `teachers` t 
                   ON (LOWER(TRIM(b.teacher_id)) = LOWER(TRIM(t.teacher_id)) 
                    OR LOWER(TRIM(b.teacher_id)) = LOWER(TRIM(CAST(t.id AS CHAR))))
        ");
        if ($stmt_b) {
            while ($b = $stmt_b->fetch(PDO::FETCH_ASSOC)) {
                $raw_tid = trim($b['teacher_id']);
                $code_id = !empty($b['code_id']) ? trim($b['code_id']) : $raw_tid;
                $pk_id   = !empty($b['pk_id']) ? (string)$b['pk_id'] : $raw_tid;
                
                // Prioritize subject name, fallback to batch name
                $course_name = !empty($b['subject']) ? trim($b['subject']) : trim($b['batch']);
                
                if (!empty($course_name)) {
                    if (!in_array($course_name, $all_db_courses)) {
                        $all_db_courses[] = $course_name;
                    }
                    
                    foreach (array_unique([$raw_tid, $code_id, $pk_id]) as $key) {
                        if (empty($key)) continue;
                        if (!isset($teacher_courses_map[$key])) {
                            $teacher_courses_map[$key] = [];
                        }
                        if (!in_array($course_name, $teacher_courses_map[$key])) {
                            $teacher_courses_map[$key][] = $course_name;
                        }
                    }
                }
            }
        }

        // C. Fetch All Payout Records
        $sql = "
            SELECT 
                s.*,
                t.full_name AS db_teacher_name,
                t.teacher_id AS db_teacher_code,
                COALESCE(t.registration_date, t.created_at) AS db_reg_date,
                t.profile_image AS db_profile_image
            FROM `teacher_salaries` s
            LEFT JOIN `teachers` t 
                   ON (LOWER(TRIM(s.teacher_id)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(t.teacher_id)) COLLATE utf8mb4_unicode_ci 
                    OR LOWER(TRIM(s.teacher_id)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(CAST(t.id AS CHAR))) COLLATE utf8mb4_unicode_ci)
            ORDER BY s.payment_date DESC, s.id DESC
        ";
        $stmt_s = $dbConn->query($sql);
        $raw_salaries = $stmt_s ? $stmt_s->fetchAll(PDO::FETCH_ASSOC) : [];

        // D. Process Payout Rows and resolve Teacher Names
        foreach ($raw_salaries as $row) {
            $t_id = trim($row['teacher_id']);
            $matched_teacher = $teacher_map[$t_id] ?? $teacher_map[strtolower($t_id)] ?? null;

            // Resolve Teacher Name
            $teacher_name = !empty($row['db_teacher_name']) ? $row['db_teacher_name'] : ($matched_teacher['full_name'] ?? '');

            // Fallback to users-table if missing in teachers table
            if (empty($teacher_name) || $teacher_name === 'N/A') {
                $stmt_u = $dbConn->prepare("SELECT `name` FROM `users-table` WHERE (`username` = :tid OR `email` = :tid) AND `role` = 'teacher' LIMIT 1");
                $stmt_u->execute(['tid' => $t_id]);
                $u_row = $stmt_u->fetch(PDO::FETCH_ASSOC);
                if (!empty($u_row['name'])) {
                    $teacher_name = $u_row['name'];
                } else {
                    $teacher_name = 'Faculty Member (' . $t_id . ')';
                }
            }

            // Resolve Registration Date
            $reg_date = !empty($row['db_reg_date']) ? $row['db_reg_date'] : ($matched_teacher['registration_date'] ?? $matched_teacher['created_at'] ?? null);

            // Resolve Profile Image
            $profile_img = !empty($row['db_profile_image']) ? $row['db_profile_image'] : ($matched_teacher['profile_image'] ?? 'assets/images/user-avatar.jpg');

            $row['teacher_name']     = $teacher_name;
            $row['teacher_reg_date'] = $reg_date;
            $row['profile_image']    = $profile_img;
            $row['code_id']          = !empty($row['db_teacher_code']) ? $row['db_teacher_code'] : $t_id;

            $salaries[] = $row;

            if (strtolower($row['status']) === 'paid') {
                $total_paid += floatval($row['amount']);
            } else {
                $pending_cnt++;
            }
        }

    } catch (PDOException $e) {
        error_log("Error in admin payout page: " . $e->getMessage());
    }
}

$admin_payor_id = $_SESSION['admin_id'] ?? $_SESSION['email'] ?? 'ADM-ADMIN';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Payout Management - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-payout-page.css">

    <style>
        /* Course Chips Tag Selector Styles */
        .course-selector-wrapper {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .course-selector-wrapper select,
        .custom-course-add input {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .course-selector-wrapper select:focus,
        .custom-course-add input:focus {
            border-color: #2563eb;
        }

        .custom-course-add {
            display: flex;
            gap: 6px;
            flex-grow: 1;
        }

        .custom-course-add input {
            flex-grow: 1;
        }

        .btn-add-course {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s;
        }

        .btn-add-course:hover {
            background: #1d4ed8;
        }

        .course-chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-height: 42px;
            padding: 6px 10px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            align-items: center;
        }

        .course-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 0.83rem;
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .course-chip .remove-chip-btn {
            background: transparent;
            border: none;
            color: #0284c7;
            cursor: pointer;
            font-size: 1.05rem;
            line-height: 1;
            padding: 0 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: color 0.15s;
        }

        .course-chip .remove-chip-btn:hover {
            color: #ef4444;
        }

        .empty-chips-notice {
            color: #94a3b8;
            font-size: 0.82rem;
            font-style: italic;
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- ADMIN HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/admin-header-sidebar-section.php'; ?>

        <!-- MAIN CONTENT AREA -->
        <main class="dashboard">
            
            <!-- Page Title & Header Actions -->
            <div class="page-header-row">
                <div>
                    <h1>TEACHER PAYOUT MANAGEMENT</h1>
                    <p style="color: #64748b; font-size: 0.9rem; margin-top: 4px;">Issue salaries, record transaction references, and manage teacher payment records.</p>
                </div>

                <button class="btn-primary-payout" onclick="openPayoutModal('add')">
                    <i class="fa-solid fa-plus-circle"></i> Issue New Payout
                </button>
            </div>

            <!-- Flash Alert Message -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert-box alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?>">
                    <i class="fa-solid <?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php 
                        echo htmlspecialchars($_SESSION['flash_message']); 
                        unset($_SESSION['flash_message']);
                        unset($_SESSION['flash_type']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- STATS OVERVIEW CARDS -->
            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Total Disbursed</span>
                        <h3 class="stat-value">৳<?php echo number_format($total_paid, 2); ?></h3>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon icon-amber"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Pending Payouts</span>
                        <h3 class="stat-value"><?php echo $pending_cnt; ?></h3>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Teachers Records</span>
                        <h3 class="stat-value"><?php echo count($salaries); ?></h3>
                    </div>
                </div>
            </section>

            <!-- PAYOUT TABLE SECTION -->
            <section class="table-section">

                <!-- SEARCHBAR HEADER -->
                <div class="table-header">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="tableSearch" placeholder="Search by teacher name, ID, courses, method, payor ID..." onkeyup="filterPayoutTable()">
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="payoutTable">
                        <thead>
                            <tr>
                                <th>Teacher Name</th>
                                <th>Teacher ID</th>
                                <th>Reg. Date</th>
                                <th>Courses</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Payor ID / Ref</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($salaries)): ?>
                                <?php foreach ($salaries as $row): ?>
                                    <tr class="payout-row">
                                        <!-- Teacher Name & Avatar -->
                                        <td>
                                            <div class="name-cell">
                                                <img src="<?php echo htmlspecialchars(formatAvatarUrl($row['profile_image'])); ?>" 
                                                     alt="Avatar" 
                                                     class="avatar-thumb"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="avatar-icon-placeholder" style="display: none;">
                                                    <i class="fa-solid fa-user-tie"></i>
                                                </div>
                                                <span><?php echo htmlspecialchars($row['teacher_name']); ?></span>
                                            </div>
                                        </td>

                                        <!-- Teacher ID -->
                                        <td>
                                            <span class="badge-code"><?php echo htmlspecialchars($row['code_id']); ?></span>
                                        </td>

                                        <!-- Reg Date -->
                                        <td>
                                            <i class="fa-regular fa-calendar-days" style="color: #64748b; margin-right: 4px;"></i>
                                            <?php 
                                                echo (!empty($row['teacher_reg_date']) && $row['teacher_reg_date'] !== '0000-00-00' && $row['teacher_reg_date'] !== 'N/A') 
                                                    ? date('d/m/Y', strtotime($row['teacher_reg_date'])) 
                                                    : 'N/A'; 
                                            ?>
                                        </td>

                                        <!-- Courses -->
                                        <td>
                                            <?php 
                                                $courses = explode(',', $row['registered_courses'] ?? '');
                                                $has_course = false;
                                                foreach ($courses as $c) {
                                                    $c = trim($c);
                                                    if (!empty($c) && $c !== 'N/A') {
                                                        $has_course = true;
                                                        echo '<span class="badge-course"><i class="fa-solid fa-book-open"></i> ' . htmlspecialchars($c) . '</span> ';
                                                    }
                                                }
                                                if (!$has_course) {
                                                    echo '<span style="color: #94a3b8; font-style: italic;">N/A</span>';
                                                }
                                            ?>
                                        </td>

                                        <!-- Amount & Status -->
                                        <td>
                                            <span class="amount-text">৳<?php echo number_format($row['amount'], 2); ?></span>
                                            <br>
                                            <span class="status-badge <?php echo strtolower($row['status']) === 'paid' ? 'status-paid' : (strtolower($row['status']) === 'processing' ? 'status-processing' : 'status-pending'); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>

                                        <!-- Payment Date -->
                                        <td>
                                            <i class="fa-solid fa-clock-rotate-left" style="color: #16a34a; margin-right: 4px;"></i>
                                            <?php echo !empty($row['payment_date']) ? date('d/m/Y', strtotime($row['payment_date'])) : 'N/A'; ?>
                                        </td>

                                        <!-- Method -->
                                        <td>
                                            <span class="badge-method">
                                                <i class="fa-solid <?php 
                                                    $method = strtolower($row['payment_method']);
                                                    if (strpos($method, 'bank') !== false) echo 'fa-building-columns';
                                                    elseif (strpos($method, 'bkash') !== false || strpos($method, 'nagad') !== false) echo 'fa-mobile-screen-button';
                                                    else echo 'fa-money-bill-wave';
                                                ?>"></i>
                                                <?php echo htmlspecialchars($row['payment_method']); ?>
                                            </span>
                                        </td>

                                        <!-- Payor ID -->
                                        <td>
                                            <span class="badge-code" style="background: #e2e8f0; color: #1e293b;">
                                                <i class="fa-solid fa-receipt"></i> <?php echo htmlspecialchars($row['payor_id']); ?>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td>
                                            <div class="action-btn-group">
                                                <button class="btn-action-edit" title="Edit Record" 
                                                        onclick='openPayoutModal("edit", <?php echo json_encode($row); ?>)'>
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                
                                                <form method="POST" action="admin-payout-page-logic.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this payout log?');">
                                                    <input type="hidden" name="action" value="delete_payout">
                                                    <input type="hidden" name="payout_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" class="btn-action-delete" title="Delete Record">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 30px; color: #64748b;">
                                        <i class="fa-solid fa-file-invoice-dollar" style="font-size: 2.2rem; margin-bottom: 10px; display: block;"></i>
                                        No payout records found. Click "Issue New Payout" above to record a payment.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </section>

        </main>

    </div>

<!-- MODAL DIALOG FOR NEW/EDIT PAYOUT -->
<div id="payoutModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fa-solid fa-money-check-dollar"></i> Issue New Payout</h3>
            <button class="close-modal-btn" onclick="closePayoutModal()">&times;</button>
        </div>

        <form id="payoutForm" method="POST" action="admin-payout-page-logic.php">
            <input type="hidden" name="action" id="formAction" value="add_payout">
            <input type="hidden" name="payout_id" id="formPayoutId" value="">

            <div class="form-grid">
                <!-- Teacher Select -->
                <div class="form-group full-width">
                    <label><i class="fa-solid fa-user-tie"></i> Select Teacher <span class="required">*</span></label>
                    <select name="teacher_id" id="inputTeacherId" onchange="onTeacherChange(this.value)" required>
                        <option value="">-- Choose Teacher --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo htmlspecialchars($t['teacher_id']); ?>">
                                <?php echo htmlspecialchars($t['full_name']); ?> (<?php echo htmlspecialchars($t['teacher_id']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Interactive Courses/Batches Tag Selector -->
                <div class="form-group full-width">
                    <label><i class="fa-solid fa-book-open"></i> Courses / Batches Taught</label>
                    
                    <div class="course-selector-wrapper">
                        <!-- Select dropdown populated from database batches -->
                        <select id="courseSelectDropdown" onchange="addCourseFromDropdown(this.value)">
                            <option value="">-- Select from Database Batches --</option>
                        </select>

                        <!-- Custom course adder input -->
                        <div class="custom-course-add">
                            <input type="text" id="customCourseInput" placeholder="Or type custom course name...">
                            <button type="button" class="btn-add-course" onclick="addCustomCourseTag()">
                                <i class="fa-solid fa-plus"></i> Add
                            </button>
                        </div>
                    </div>

                    <!-- Selected Courses Chips Box with Cross Buttons -->
                    <div id="courseChipsContainer" class="course-chips-container">
                        <span class="empty-chips-notice">No courses selected yet. Select a teacher or pick from above.</span>
                    </div>

                    <!-- Hidden Input field submitted with POST -->
                    <input type="hidden" name="registered_courses" id="hiddenRegisteredCourses" value="">
                    
                    <small id="courseHelpText" style="color: #64748b; font-size: 0.78rem; margin-top: 5px; display: block;">
                        Click on the &times; button on any course tag to remove it before saving.
                    </small>
                </div>

                <!-- Amount -->
                <div class="form-group">
                    <label><i class="fa-solid fa-bangladeshi-taka-sign"></i> Amount (৳) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="amount" id="inputAmount" placeholder="0.00" required>
                </div>

                <!-- Payment Date -->
                <div class="form-group">
                    <label><i class="fa-regular fa-calendar"></i> Payment Date <span class="required">*</span></label>
                    <input type="date" name="payment_date" id="inputPaymentDate" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <!-- Payment Method -->
                <div class="form-group">
                    <label><i class="fa-solid fa-wallet"></i> Payment Method <span class="required">*</span></label>
                    <select name="payment_method" id="inputPaymentMethod" required>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Bkash">Bkash</option>
                        <option value="Nagad">Nagad</option>
                        <option value="Hand Cash">Hand Cash</option>
                    </select>
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label><i class="fa-solid fa-circle-check"></i> Status <span class="required">*</span></label>
                    <select name="status" id="inputStatus" required>
                        <option value="Paid">Paid</option>
                        <option value="Pending">Pending</option>
                        <option value="Processing">Processing</option>
                    </select>
                </div>

                <!-- Payor ID / Ref -->
                <div class="form-group full-width">
                    <label><i class="fa-solid fa-receipt"></i> Payor ID / Transaction Ref <span class="required">*</span></label>
                    <input type="text" name="payor_id" id="inputPayorId" value="<?php echo htmlspecialchars($admin_payor_id); ?>" placeholder="e.g. ADM-001 / TXN-99882" required>
                </div>
            </div>

            <div class="modal-actions-row">
                <button type="button" class="btn-cancel" onclick="closePayoutModal()">Cancel</button>
                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Save Payout</button>
            </div>
        </form>
    </div>
</div>

<!-- Shared UI Scripts -->
<script src="../assets/js/common/sidebar.js"></script>
<script src="../assets/js/common/header.js"></script>

<!-- Real-time Instant Search & Dynamic Tag System JavaScript -->
<script>
// Server side dynamic batch and course maps
const teacherCoursesMap = <?php echo json_encode($teacher_courses_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const allDbCourses      = <?php echo json_encode($all_db_courses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Dynamic Tags State Array
let selectedCoursesList = [];

// Render chips/tags with remove (×) buttons
function renderCourseChips() {
    const container = document.getElementById("courseChipsContainer");
    const hiddenInput = document.getElementById("hiddenRegisteredCourses");
    
    container.innerHTML = "";

    if (selectedCoursesList.length === 0) {
        container.innerHTML = '<span class="empty-chips-notice">No courses selected yet. Select a teacher or add from above.</span>';
        hiddenInput.value = "";
        return;
    }

    selectedCoursesList.forEach((course) => {
        const chip = document.createElement("span");
        chip.className = "course-chip";
        chip.innerHTML = `
            <i class="fa-solid fa-book-open"></i> ${escapeHtml(course)}
            <button type="button" class="remove-chip-btn" onclick="removeCourseTag('${escapeJs(course)}')" title="Remove course">&times;</button>
        `;
        container.appendChild(chip);
    });

    hiddenInput.value = selectedCoursesList.join(", ");
}

// Add course tag to state
function addCourseTag(courseName) {
    const trimmed = courseName.trim();
    if (!trimmed) return;

    // Avoid duplicates
    if (!selectedCoursesList.some(c => c.toLowerCase() === trimmed.toLowerCase())) {
        selectedCoursesList.push(trimmed);
        renderCourseChips();
    }
}

// Remove course tag from state
function removeCourseTag(courseName) {
    selectedCoursesList = selectedCoursesList.filter(c => c.toLowerCase() !== courseName.trim().toLowerCase());
    renderCourseChips();
}

// Handle dynamic dropdown selection
function addCourseFromDropdown(val) {
    if (val) {
        addCourseTag(val);
        document.getElementById("courseSelectDropdown").value = "";
    }
}

// Handle manually typed course name
function addCustomCourseTag() {
    const input = document.getElementById("customCourseInput");
    if (input && input.value.trim() !== "") {
        addCourseTag(input.value.trim());
        input.value = "";
    }
}

// Populate course options dropdown based on selected teacher or all courses
function populateCourseDropdown(teacherId = "") {
    const dropdown = document.getElementById("courseSelectDropdown");
    dropdown.innerHTML = '<option value="">-- Select from Database Batches --</option>';

    let availableCourses = [];
    if (teacherId && teacherCoursesMap[teacherId] && teacherCoursesMap[teacherId].length > 0) {
        availableCourses = teacherCoursesMap[teacherId];
    } else {
        availableCourses = allDbCourses;
    }

    availableCourses.forEach(c => {
        const opt = document.createElement("option");
        opt.value = c;
        opt.textContent = c;
        dropdown.appendChild(opt);
    });
}

// Triggered when a teacher is selected from the dropdown
function onTeacherChange(teacherId) {
    populateCourseDropdown(teacherId);

    // Auto load teacher's assigned courses from Batches table into tags
    selectedCoursesList = [];
    if (teacherId && teacherCoursesMap[teacherId]) {
        teacherCoursesMap[teacherId].forEach(c => addCourseTag(c));
    } else {
        renderCourseChips();
    }
}

function filterPayoutTable() {
    const input = document.getElementById("tableSearch");
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById("payoutTable");
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

function openPayoutModal(type, data = null) {
    const modal = document.getElementById("payoutModal");
    const modalTitle = document.getElementById("modalTitle");
    const formAction = document.getElementById("formAction");
    const formPayoutId = document.getElementById("formPayoutId");

    selectedCoursesList = [];

    if (type === 'edit' && data) {
        modalTitle.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Payout Record';
        formAction.value = "update_payout";
        formPayoutId.value = data.id;

        const currentTeacherId = data.teacher_id || data.code_id;
        document.getElementById("inputTeacherId").value = currentTeacherId;
        
        populateCourseDropdown(currentTeacherId);

        // Parse existing comma separated courses into tags
        if (data.registered_courses && data.registered_courses !== 'N/A') {
            const rawCourses = data.registered_courses.split(',');
            rawCourses.forEach(c => addCourseTag(c.trim()));
        } else {
            renderCourseChips();
        }

        document.getElementById("inputAmount").value = data.amount;
        document.getElementById("inputPaymentDate").value = data.payment_date;
        document.getElementById("inputPaymentMethod").value = data.payment_method;
        document.getElementById("inputStatus").value = data.status;
        document.getElementById("inputPayorId").value = data.payor_id;
    } else {
        modalTitle.innerHTML = '<i class="fa-solid fa-money-check-dollar"></i> Issue New Payout';
        formAction.value = "add_payout";
        formPayoutId.value = "";

        document.getElementById("payoutForm").reset();
        document.getElementById("inputPayorId").value = "<?php echo htmlspecialchars($admin_payor_id); ?>";
        document.getElementById("inputPaymentDate").value = "<?php echo date('Y-m-d'); ?>";
        
        populateCourseDropdown();
        renderCourseChips();
    }

    modal.classList.add("active");
    modal.style.display = "flex";
}

function closePayoutModal() {
    const modal = document.getElementById("payoutModal");
    if (modal) {
        modal.classList.remove("active");
        modal.style.display = "none";
    }
}

// Utility Escape Helpers
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function escapeJs(str) {
    return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
}

window.addEventListener('click', (event) => {
    const modal = document.getElementById('payoutModal');
    if (event.target === modal) {
        closePayoutModal();
    }
});
</script>

</body>
</html>