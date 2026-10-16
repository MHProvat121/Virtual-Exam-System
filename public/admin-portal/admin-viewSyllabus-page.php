<?php
// =========================================================================
// ADMIN VIEW SYLLABUS PAGE
// Prototypes view-syllabus-page.php with Administrator privileges & context
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper - Admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration
require_once __DIR__ . '/../../src/config/config.php';

// Handle PDO connection standard
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

$id = (int)($_GET['id'] ?? $_GET['exam_id'] ?? 0);

// -------------------------------------------------------------------------
// 4. HANDLE DELETE SYLLABUS ACTION (ADMIN)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_syllabus') {
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId > 0 && $dbConn) {
        try {
            // Reset syllabus content and file in batches table
            $stmt = $dbConn->prepare("
                UPDATE `batches` 
                SET `syllabus_details` = NULL, `syllabus_file` = NULL 
                WHERE `id` = :id
            ");
            $stmt->execute([':id' => $deleteId]);

            // Try clearing separate syllabus table if it exists
            try {
                $stmtSyl = $dbConn->prepare("DELETE FROM `syllabus` WHERE `batch_id` = :id OR `id` = :id");
                $stmtSyl->execute([':id' => $deleteId]);
            } catch (PDOException $ex) {
                // Ignore if syllabus table does not exist
            }
        } catch (PDOException $e) {
            error_log("Error clearing syllabus: " . $e->getMessage());
        }
    }
    header("Location: admin-courseSyllabus-page.php");
    exit();
}

// -------------------------------------------------------------------------
// 5. FETCH BATCH & SYLLABUS DATA
// -------------------------------------------------------------------------
$batchInfo = [];
$syllabusContent = '';
$syllabusFile    = '';

if ($id > 0 && $dbConn) {
    // A. Fetch Batch / Course Row
    try {
        $stmt = $dbConn->prepare("SELECT * FROM `batches` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $batchData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($batchData) {
            $batchInfo = $batchData;
        }
    } catch (PDOException $e) {
        error_log("Error fetching batch row: " . $e->getMessage());
    }

    // B. Check syllabus details & file from $batchInfo
    if (!empty($batchInfo)) {
        $syllabusContent = $batchInfo['syllabus_details'] ?? $batchInfo['syllabus'] ?? $batchInfo['syllabus_content'] ?? '';
        $syllabusFile    = $batchInfo['syllabus_file'] ?? '';
    }

    // C. Fallback: Check separate syllabus table if content is still empty
    if (empty($syllabusContent)) {
        try {
            $stmtSyl = $dbConn->prepare("SELECT * FROM `syllabus` WHERE `batch_id` = :id OR `id` = :id LIMIT 1");
            $stmtSyl->execute([':id' => $id]);
            $sylData = $stmtSyl->fetch(PDO::FETCH_ASSOC);

            if ($sylData) {
                $syllabusContent = $sylData['syllabus_details'] ?? $sylData['syllabus_content'] ?? $sylData['content'] ?? $sylData['description'] ?? '';
                if (empty($syllabusFile)) {
                    $syllabusFile = $sylData['syllabus_file'] ?? $sylData['file_attachment'] ?? '';
                }
            }
        } catch (PDOException $e) {
            // Table syllabus might not exist
        }
    }
}

// Extract batch variables with defaults
$batchName   = !empty($batchInfo['batch']) ? $batchInfo['batch'] : 'N/A';
$courseCode  = !empty($batchInfo['course_code']) ? $batchInfo['course_code'] : 'N/A';
$credit      = !empty($batchInfo['credit']) ? $batchInfo['credit'] : 'N/A';
$subjectName = !empty($batchInfo['subject']) ? $batchInfo['subject'] : 'N/A';
$examTitle   = !empty($batchInfo['exam_title']) ? $batchInfo['exam_title'] : 'N/A';
$teacherName = !empty($batchInfo['teacher_name']) ? $batchInfo['teacher_name'] : 'Unassigned';
$teacherId   = !empty($batchInfo['teacher_id']) ? $batchInfo['teacher_id'] : 'N/A';

// Fetch Teacher Name and ID from `teachers` table if teacher_id is present
if (!empty($teacherId) && $teacherId !== 'N/A' && $dbConn) {
    try {
        $stmtT = $dbConn->prepare("
            SELECT `full_name`, `teacher_id` FROM `teachers` 
            WHERE `teacher_id` = :tid OR `id` = :tid 
            LIMIT 1
        ");
        $stmtT->execute([':tid' => $teacherId]);
        $teacherRow = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($teacherRow) {
            $teacherName = $teacherRow['full_name'] ?? $teacherName;
            if (!empty($teacherRow['teacher_id'])) {
                $teacherId = $teacherRow['teacher_id'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher name: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Syllabus (Admin) - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="admin-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/teacher-quesTable-page.css">

    <style>
        .syllabus-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-top: 15px;
        }

        .syllabus-card-header {
            background: #a0bdd9;
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .syllabus-card-header h2 {
            color: #1d2b53;
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-badge {
            background: #1d2b53;
            color: #ffffff;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.3px;
        }

        .syllabus-body {
            padding: 30px;
            color: #334155;
        }

        .meta-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .meta-pill {
            background: #f8fafc;
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
        }

        .meta-pill strong {
            color: #1e293b;
        }

        .badge-teacher-id {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.85rem;
            border: 1px solid #cbd5e1;
        }

        .syllabus-content-box {
            background: #fafcff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            min-height: 200px;
            line-height: 1.7;
            font-size: 0.95rem;
            color: #1e293b;
        }

        .attachment-box {
            margin-top: 20px;
            padding: 16px 20px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .action-group-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-action {
            padding: 9px 16px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-back { background-color: #64748b; color: #ffffff; }
        .btn-back:hover { background-color: #475569; color: #ffffff; }

        .btn-edit { background-color: #0266cc; color: #ffffff; }
        .btn-edit:hover { background-color: #0252a3; color: #ffffff; }

        .btn-print { background-color: #059669; color: #ffffff; }
        .btn-print:hover { background-color: #047857; color: #ffffff; }

        .btn-delete { background-color: #dc2626; color: #ffffff; }
        .btn-delete:hover { background-color: #b91c1c; color: #ffffff; }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(15, 23, 42, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: #ffffff;
            border-radius: 12px;
            width: 90%;
            max-width: 480px;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #dc2626;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-secondary:hover { background: #cbd5e1; }

        .btn-confirm-delete {
            background-color: #dc2626;
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .btn-confirm-delete:disabled {
            background-color: #fca5a5;
            cursor: not-allowed;
            opacity: 0.7;
        }

        @media print {
            .sidebar, .header, .page-actions, .modal, header, aside, nav {
                display: none !important;
            }
            body, .main-layout, .dashboard {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .syllabus-card {
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }
            .syllabus-card-header {
                background: #f1f5f9 !important;
                color: #000 !important;
            }
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- ADMIN HEADER AND SIDEBAR -->
        <?php 
        if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
            require_once __DIR__ . '/admin-header-sidebar-section.php'; 
        } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
            require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
        }
        ?>

        <!-- MAIN CONTENT START -->
        <main class="dashboard">

            <div class="page-actions">
                <a href="admin-courseSyllabus-page.php" class="btn-action btn-back">
                    <i class="fa-solid fa-arrow-left"></i> Back to Courses
                </a>

                <div class="action-group-right">
                    <a href="admin-createSyllabus-page.php?id=<?php echo $id; ?>" class="btn-action btn-edit">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Syllabus
                    </a>
                    <button type="button" class="btn-action btn-print" onclick="window.print()">
                        <i class="fa-solid fa-file-pdf"></i> Print / Save PDF
                    </button>
                    <button type="button" class="btn-action btn-delete" onclick="openDeleteModal()">
                        <i class="fa-solid fa-trash"></i> Delete Syllabus
                    </button>
                </div>
            </div>

            <div class="syllabus-card">
                <div class="syllabus-card-header">
                    <h2><i class="fa-solid fa-book-open"></i> Course Syllabus</h2>
                    <span class="admin-badge"><i class="fa-solid fa-shield-halved"></i> Administrator View</span>
                </div>

                <div class="syllabus-body">
                    <!-- METADATA GRID -->
                    <div class="meta-grid">
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-users"></i> Batch:</strong> <?php echo htmlspecialchars($batchName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-barcode"></i> Course Code:</strong> <?php echo htmlspecialchars($courseCode); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-award"></i> Credit:</strong> <span class="badge-credit" style="background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:4px; font-weight:600;"><?php echo htmlspecialchars($credit); ?></span>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-book"></i> Subject:</strong> <?php echo htmlspecialchars($subjectName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-heading"></i> Exam Title:</strong> <?php echo htmlspecialchars($examTitle); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-user-tie"></i> Teacher Name:</strong> <?php echo htmlspecialchars($teacherName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-id-badge"></i> Teacher ID:</strong> <span class="badge-teacher-id"><?php echo htmlspecialchars($teacherId); ?></span>
                        </div>
                    </div>

                    <h3 style="margin-bottom: 12px; color: #1d2b53;">Syllabus Outline</h3>
                    
                    <!-- TEXT CONTENT AREA -->
                    <div class="syllabus-content-box">
                        <?php 
                        if (!empty($syllabusContent)) {
                            // Render HTML if content contains tags, otherwise format linebreaks
                            echo (strpos($syllabusContent, '<') !== false) ? $syllabusContent : nl2br(htmlspecialchars($syllabusContent));
                        } else {
                            echo "<em>No detailed syllabus description found. Click 'Edit Syllabus' above to add course topics and outline.</em>";
                        }
                        ?>
                    </div>

                    <!-- ATTACHED FILE DOWNLOAD AREA -->
                    <?php if (!empty($syllabusFile)): ?>
                        <div class="attachment-box">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <i class="fa-solid fa-file-pdf" style="font-size: 28px; color: #dc2626;"></i>
                                <div>
                                    <strong style="color: #0f172a; display: block;">Attached Syllabus File</strong>
                                    <span style="font-size: 13px; color: #64748b;"><?php echo htmlspecialchars(basename($syllabusFile)); ?></span>
                                </div>
                            </div>
                            <a href="../<?php echo htmlspecialchars($syllabusFile); ?>" target="_blank" class="btn-action btn-print" style="text-decoration: none;">
                                <i class="fa-solid fa-download"></i> Download / View Attachment
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </main>

    </div>

    <!-- 3-SECOND DELAY DELETE CONFIRMATION MODAL -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Delete Syllabus</h3>
                <button type="button" class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_syllabus">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                
                <p style="color: #475569; line-height: 1.5; margin-top: 10px;">
                    Are you sure you want to delete the syllabus for 
                    <strong style="color: #0f172a;"><?php echo htmlspecialchars($subjectName); ?></strong>? 
                    This action cannot be undone!
                </p>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" id="confirmDeleteBtn" class="btn-confirm-delete" disabled>Delete (3s)</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>

    <script>
        let deleteTimer = null;
        let countdown = 3;

        function openDeleteModal() {
            const modal = document.getElementById('deleteModal');
            const deleteBtn = document.getElementById('confirmDeleteBtn');
            
            modal.style.display = 'flex';
            countdown = 3;
            deleteBtn.disabled = true;
            deleteBtn.innerText = `Delete (${countdown}s)`;

            if (deleteTimer) {
                clearInterval(deleteTimer);
            }

            deleteTimer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    deleteBtn.innerText = `Delete (${countdown}s)`;
                } else {
                    clearInterval(deleteTimer);
                    deleteBtn.innerText = 'Delete';
                    deleteBtn.disabled = false;
                }
            }, 1000);
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'none';
            if (deleteTimer) {
                clearInterval(deleteTimer);
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        };
    </script>
</body>
</html>
