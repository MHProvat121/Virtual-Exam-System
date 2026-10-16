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

// 3. Include Database Configuration
require_once __DIR__ . '/../../src/config/config.php';

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

// 4. FETCH TEACHER SALARY DETAILS JOINED WITH TEACHER PROFILES
$salaries = [];

try {
    $sql = "
        SELECT 
            s.*,
            t.full_name AS teacher_name,
            t.teacher_id AS code_id,
            COALESCE(t.registration_date, t.created_at) AS teacher_reg_date,
            t.profile_image
        FROM `teacher_salaries` s
        LEFT JOIN `teachers` t 
               ON (s.teacher_id = t.teacher_id OR CAST(s.teacher_id AS CHAR) = CAST(t.id AS CHAR))
        ORDER BY s.payment_date DESC
    ";
    
    $stmt = $conn->query($sql);
    $salaries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

} catch (PDOException $e) {
    error_log("Error fetching salary history: " . $e->getMessage());
    $salaries = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Salary & Payout History - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
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
        .badge-code {
            font-family: monospace;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 4px;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-course {
            display: inline-block;
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            margin: 2px;
        }
        .badge-method {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            background-color: #f3f4f6;
            color: #374151;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-paid {
            background-color: #dcfce7;
            color: #15803d;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #b45309;
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
        .amount-text {
            font-weight: 700;
            color: #0f172a;
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- TABLE AREA CONTENT START -->
        <main class="dashboard">
            
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <h1>TEACHER SALARY & PAYOUT HISTORY</h1>

                <a href="teacher-dashboard-page.php" class="btn-action btn-back">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard 
                </a>
            </div>

            <section class="table-section">

                <!-- SEARCHBAR HEADER -->
                <div class="table-header">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="tableSearch" placeholder="Search by name, ID, course, payor ID, method..." onkeyup="filterSalaryTable()">
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="salaryTable">
                        <thead>
                            <tr>
                                <th>Teacher Name</th>
                                <th>Teacher ID</th>
                                <th>Registration Date</th>
                                <th>Registered Courses</th>
                                <th>Amount</th>
                                <th>Payment Received Date</th>
                                <th>Receive Method</th>
                                <th>Payor ID / Ref</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($salaries)): ?>
                                <?php foreach ($salaries as $row): ?>
                                    <tr class="salary-row">
                                        <!-- Teacher Name & Avatar -->
                                        <td>
                                            <div class="name-cell">
                                                <img src="<?php echo htmlspecialchars(formatAvatarUrl($row['profile_image'])); ?>" 
                                                     alt="Avatar" 
                                                     class="avatar-thumb"
                                                     onerror="this.src='../assets/images/user-avatar.jpg';">
                                                <span><?php echo htmlspecialchars($row['teacher_name'] ?? 'N/A'); ?></span>
                                            </div>
                                        </td>

                                        <!-- Teacher ID -->
                                        <td>
                                            <span class="badge-code"><?php echo htmlspecialchars($row['code_id'] ?? $row['teacher_id']); ?></span>
                                        </td>

                                        <!-- Registration Date -->
                                        <td>
                                            <i class="fa-regular fa-calendar-days" style="color: #64748b; margin-right: 5px;"></i>
                                            <?php 
                                                echo (!empty($row['teacher_reg_date']) && $row['teacher_reg_date'] !== '0000-00-00') 
                                                    ? date('d/m/Y', strtotime($row['teacher_reg_date'])) 
                                                    : 'N/A'; 
                                            ?>
                                        </td>

                                        <!-- Registered Courses -->
                                        <td>
                                            <?php 
                                                $courses = explode(',', $row['registered_courses'] ?? '');
                                                foreach ($courses as $c) {
                                                    $c = trim($c);
                                                    if (!empty($c)) {
                                                        echo '<span class="badge-course"><i class="fa-solid fa-book-open"></i> ' . htmlspecialchars($c) . '</span> ';
                                                    }
                                                }
                                            ?>
                                        </td>

                                        <!-- Salary Amount & Status -->
                                        <td>
                                            <span class="amount-text">৳<?php echo number_format($row['amount'], 2); ?></span>
                                            <br>
                                            <span class="status-badge <?php echo strtolower($row['status']) === 'paid' ? 'status-paid' : 'status-pending'; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>

                                        <!-- Payment Received Date -->
                                        <td>
                                            <i class="fa-solid fa-clock-rotate-left" style="color: #16a34a; margin-right: 5px;"></i>
                                            <?php echo !empty($row['payment_date']) ? date('d/m/Y', strtotime($row['payment_date'])) : 'N/A'; ?>
                                        </td>

                                        <!-- Receive Method -->
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

                                        <!-- Payor ID / Txn Ref -->
                                        <td>
                                            <span class="badge-code" style="background: #e2e8f0; color: #1e293b;">
                                                <i class="fa-solid fa-receipt"></i> <?php echo htmlspecialchars($row['payor_id']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 30px; color: #64748b;">
                                        <i class="fa-solid fa-file-invoice-dollar" style="font-size: 2.2rem; margin-bottom: 10px; display: block;"></i>
                                        No salary payout records found in the system.
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
function filterSalaryTable() {
    const input = document.getElementById("tableSearch");
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById("salaryTable");
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