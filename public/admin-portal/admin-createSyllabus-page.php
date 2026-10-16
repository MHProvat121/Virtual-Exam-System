<?php
// =========================================================================
// ADMIN CREATE / EDIT SYLLABUS PAGE
// Prototypes create-syllabus-page.php with Administrator privileges & context
// =========================================================================

// Always start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// RBAC Session Gatekeeper - Admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

require_once __DIR__ . '/../../src/config/config.php';

// Handle PDO standard safely
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

// Retrieve batch ID from URL (?id=1 or ?batch_id=1)
$batch_id = $_GET['id'] ?? $_GET['batch_id'] ?? 0;

$batch_name          = '';
$course_code         = '';
$credit              = '';
$subject_name        = '';
$exam_title          = '';
$teacher_name        = '';
$teacher_id_code     = '';
$syllabus_db_content = '';
$syllabus_db_file    = '';

// Check success flag or session message
$is_saved = (isset($_GET['status']) && $_GET['status'] === 'success') || isset($_GET['saved']) || isset($_SESSION['success_message']);
$success_message = $_SESSION['success_message'] ?? 'Syllabus saved successfully!';
unset($_SESSION['success_message']);

// 1. DIRECT FETCH FROM `batches` TABLE
if (!empty($batch_id) && is_numeric($batch_id) && $dbConn) {
    try {
        $stmt = $dbConn->prepare("
            SELECT `batch`, `course_code`, `credit`, `subject`, `exam_title`, `syllabus_details`, `syllabus_file`, `teacher_id` 
            FROM `batches` 
            WHERE `id` = :batch_id 
            LIMIT 1
        ");
        $stmt->execute([':batch_id' => $batch_id]);
        $batch_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($batch_data) {
            $batch_name          = $batch_data['batch'] ?? '';
            $course_code         = $batch_data['course_code'] ?? '';
            $credit              = $batch_data['credit'] ?? '';
            $subject_name        = $batch_data['subject'] ?? '';
            $exam_title          = $batch_data['exam_title'] ?? '';
            $syllabus_db_content = $batch_data['syllabus_details'] ?? '';
            $syllabus_db_file    = $batch_data['syllabus_file'] ?? '';
            $teacher_id_code     = $batch_data['teacher_id'] ?? '';
        }
    } catch (PDOException $e) {
        error_log("Error fetching batch details: " . $e->getMessage());
    }
}

// 2. FETCH TEACHER NAME FROM `teachers` TABLE
if (!empty($teacher_id_code) && $dbConn) {
    try {
        $t_stmt = $dbConn->prepare("
            SELECT `full_name`, `teacher_id` FROM `teachers` 
            WHERE `teacher_id` = :tid OR `id` = :tid 
            LIMIT 1
        ");
        $t_stmt->execute([':tid' => $teacher_id_code]);
        $teacher_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
        if ($teacher_row) {
            $teacher_name = $teacher_row['full_name'] ?? '';
            if (!empty($teacher_row['teacher_id'])) {
                $teacher_id_code = $teacher_row['teacher_id'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher name: " . $e->getMessage());
    }
}

// Fallback defaults for teacher display
if (empty($teacher_name)) $teacher_name = 'Unassigned';
if (empty($teacher_id_code)) $teacher_id_code = 'N/A';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create / Edit Syllabus (Admin) - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="admin-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/admin/admin-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/teacher/create-syllabus-page.css">

    <!-- TinyMCE Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="no-referrer"></script>

    <style>
        .syllabus-builder-section {
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin: 20px auto;
            max-width: 1100px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h2 {
            margin: 0;
            color: #2c3e50;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-badge {
            background: #1d2b53;
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.3px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success-banner {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
            font-weight: 500;
        }

        .autosave-status {
            font-size: 13px;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid #e9ecef;
        }

        .autosave-status.saved i {
            color: #28a745;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }

        .form-group label i {
            margin-right: 6px;
            color: #007bff;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            outline: none;
            box-sizing: border-box;
        }

        .form-control[readonly] {
            background-color: #e9ecef;
            color: #495057;
            cursor: not-allowed;
        }

        .file-upload-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .current-file-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e2e8f0;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 13px;
            margin-top: 8px;
        }

        .editor-workspace {
            background-color: #e9ecef;
            padding: 20px 10px;
            border-radius: 8px;
            border: 1px solid #ced4da;
        }

        .form-actions {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .submit-btn {
            background-color: #28a745;
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s ease;
        }

        .submit-btn:hover {
            background-color: #218838;
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
            border: none;
            transition: all 0.2s ease;
        }

        .btn-back {
            background-color: #64748b;
            color: #ffffff;
        }

        .btn-back:hover {
            background-color: #475569;
            color: #ffffff;
        }

        .view-syllabus-btn {
            background-color: #3f28a7;
            color: #ffffff;
            padding: 9px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s ease;
        }

        .view-syllabus-btn:hover {
            background-color: #2e1d7a;
            color: #ffffff;
        }

        .clear-btn {
            background-color: #dc3545;
            color: white;
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s ease;
        }

        .clear-btn:hover {
            background-color: #c82333;
        }
    </style>
</head>

<body>

    <div class="main-layout">

        <!-- Header and Sidebar Integration -->
        <?php 
        if (file_exists(__DIR__ . '/admin-header-sidebar-section.php')) {
            require_once __DIR__ . '/admin-header-sidebar-section.php'; 
        } elseif (file_exists(__DIR__ . '/teacher-header-sidebar-section.php')) {
            require_once __DIR__ . '/teacher-header-sidebar-section.php'; 
        }
        ?>

        <!-- Syllabus Builder Section -->
        <section class="syllabus-builder-section">

            <!-- Top Notification Alert Banner on Success -->
            <?php if ($is_saved): ?>
                <div class="alert-success-banner">
                    <div>
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($success_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-header">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <h2><i class="fa-solid fa-file-pen"></i> Create / Edit Syllabus</h2>
                    <span class="admin-badge"><i class="fa-solid fa-shield-halved"></i> Administrator</span>
                </div>
                
                <div class="header-actions">
                    <div id="autosaveStatus" class="autosave-status saved">
                        <i class="fa-solid fa-cloud"></i>
                        <span id="autosaveText"><?= $is_saved ? 'Saved successfully' : 'Draft Ready'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Form submitting to admin-createSyllabus-page-logic.php -->
            <form id="syllabusForm" action="admin-createSyllabus-page-logic.php" method="POST" enctype="multipart/form-data" onsubmit="prepareFormSubmit()">
                <!-- Hidden Batch ID -->
                <input type="hidden" name="batch_id" value="<?= htmlspecialchars($batch_id); ?>">

                <!-- Batch, Subject, Exam Title & Teacher Metadata Fields -->
                <div class="form-grid">
                    <div class="form-group">
                        <label for="batch_name"><i class="fa-solid fa-users"></i> Batch / Class Name</label>
                        <input type="text" id="batch_name" name="batch_name" class="form-control" value="<?= htmlspecialchars($batch_name); ?>" readonly placeholder="Auto-filled Batch Name">
                    </div>

                    <div class="form-group">
                        <label for="course_code"><i class="fa-solid fa-barcode"></i> Course Code </label>
                        <input type="text" id="course_code" name="course_code" class="form-control" value="<?= htmlspecialchars($course_code); ?>" placeholder="Auto-filled Course Code">
                    </div>

                    <div class="form-group">
                        <label for="credit"><i class="fa-solid fa-award"></i> Credit</label>
                        <input type="text" id="credit" name="credit" class="form-control" value="<?= htmlspecialchars($credit); ?>" placeholder="e.g. 3.0">
                    </div>

                    <div class="form-group">
                        <label for="subject_name"><i class="fa-solid fa-book"></i> Subject Name</label>
                        <input type="text" id="subject_name" name="subject_name" class="form-control" value="<?= htmlspecialchars($subject_name); ?>" readonly placeholder="Auto-filled Subject Name">
                    </div>

                    <div class="form-group full-width">
                        <label for="exam_title"><i class="fa-solid fa-heading"></i> Exam Title / Overview</label>
                        <input type="text" id="exam_title" name="exam_title" class="form-control" value="<?= htmlspecialchars($exam_title); ?>" readonly placeholder="Auto-filled Exam Title">
                    </div>

                    <div class="form-group">
                        <label for="teacher_name"><i class="fa-solid fa-user-tie"></i> Assigned Teacher Name</label>
                        <input type="text" id="teacher_name" name="teacher_name" class="form-control" value="<?= htmlspecialchars($teacher_name); ?>" readonly placeholder="Auto-filled Teacher Name">
                    </div>

                    <div class="form-group">
                        <label for="teacher_id"><i class="fa-solid fa-id-badge"></i> Assigned Teacher ID</label>
                        <input type="text" id="teacher_id" name="teacher_id" class="form-control" value="<?= htmlspecialchars($teacher_id_code); ?>" readonly placeholder="Auto-filled Teacher ID">
                    </div>
                </div>

                <!-- Syllabus Text Editor Canvas -->
                <div class="form-group">
                    <label><i class="fa-solid fa-file-lines"></i> Syllabus Content (Text / Editor Details)</label>
                    
                    <div class="editor-workspace">
                        <!-- Preloaded with syllabus_details from DB -->
                        <textarea id="syllabusDocumentEditor" name="syllabus_content"><?= htmlspecialchars($syllabus_db_content); ?></textarea>
                    </div>
                </div>

                <!-- Syllabus File Upload Field -->
                <div class="form-group">
                    <label for="syllabus_file"><i class="fa-solid fa-file-pdf"></i> Upload Syllabus Document (PDF / DOC / DOCX)</label>
                    <div class="file-upload-box">
                        <input type="file" id="syllabus_file" name="syllabus_file" class="form-control" accept=".pdf,.doc,.docx,.txt">
                        <p style="margin: 8px 0 0; font-size: 13px; color: #64748b;">
                            Optional: Upload a PDF or Word document version of the syllabus.
                        </p>

                        <?php if (!empty($syllabus_db_file)): ?>
                            <div class="current-file-badge">
                                <i class="fa-solid fa-paperclip"></i>
                                <span>Current File: <strong><?= htmlspecialchars(basename($syllabus_db_file)); ?></strong></span>
                                <a href="../<?= htmlspecialchars($syllabus_db_file); ?>" target="_blank" style="margin-left: 10px; color: #0284c7; text-decoration: underline;">
                                    View File
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons Row -->
                <div class="form-actions">
                    <button type="button" class="clear-btn" onclick="discardDraft()">
                        <i class="fa-solid fa-trash-can"></i> Reset Content
                    </button>

                    <a href="admin-courseSyllabus-page.php" class="btn-action btn-back">
                        <i class="fa-solid fa-arrow-left"></i> Back to Courses
                    </a>
                    
                    <button type="submit" class="submit-btn">
                        <i class="fa-solid fa-floppy-disk"></i> Save Syllabus
                    </button>

                    <?php if (!empty($batch_id)): ?>
                        <a href="admin-viewSyllabus-page.php?id=<?= urlencode($batch_id); ?>" class="view-syllabus-btn" target="_blank">
                            <i class="fa-solid fa-eye"></i> View Syllabus
                        </a>
                    <?php endif; ?>
                </div>
            </form>

        </section>

    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
    
    <script>
        const batchId = "<?= htmlspecialchars($batch_id); ?>";
        const AUTOSAVE_KEY = 'admin_syllabus_tinymce_draft_' + (batchId ? batchId : 'general');
        let autoSaveTimer = null;

        // Initialize TinyMCE Document Editor
        tinymce.init({
            selector: '#syllabusDocumentEditor',
            height: 600,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'nonbreaking',
                'pagebreak', 'directionality', 'visualchars'
            ],
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image charmap | preview fullscreen code',
            
            content_style: `
                body {
                    font-family: Arial, sans-serif;
                    font-size: 15px;
                    line-height: 1.6;
                    color: #333;
                    background: #ffffff;
                    margin: 20px;
                    padding: 20px;
                }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                table, th, td { border: 1px solid #ccc; }
                th, td { padding: 8px 12px; text-align: left; }
                th { background-color: #f8f9fa; }
            `,
            
            branding: false,
            promotion: false,
            
            setup: function (editor) {
                editor.on('init', function () {
                    restoreSavedDraft();
                });

                editor.on('change keyup NodeChange', function () {
                    triggerAutoSave();
                });
            }
        });

        function triggerAutoSave() {
            updateStatus('saving', 'Saving draft...');
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveDraftToLocalStorage, 600);
        }

        function saveDraftToLocalStorage() {
            const editorContent = tinymce.get('syllabusDocumentEditor') ? tinymce.get('syllabusDocumentEditor').getContent() : '';
            
            const draftData = {
                content: editorContent,
                savedAt: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
            };

            localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(draftData));
            updateStatus('saved', `Draft saved at ${draftData.savedAt}`);
        }

        function restoreSavedDraft() {
            const savedData = localStorage.getItem(AUTOSAVE_KEY);
            if (savedData) {
                try {
                    const data = JSON.parse(savedData);
                    if (data.content && data.content.trim() !== '' && tinymce.get('syllabusDocumentEditor')) {
                        // Only set if editor was originally empty
                        if (!tinymce.get('syllabusDocumentEditor').getContent().trim()) {
                            tinymce.get('syllabusDocumentEditor').setContent(data.content);
                        }
                    }
                } catch (e) {
                    console.error('Failed to restore syllabus draft:', e);
                }
            }
        }

        function updateStatus(state, message) {
            const container = document.getElementById('autosaveStatus');
            const text = document.getElementById('autosaveText');
            if (container && text) {
                container.className = 'autosave-status ' + state;
                text.innerText = message;
            }
        }

        function discardDraft() {
            if (confirm("Are you sure you want to reset the editor to the last saved database state?")) {
                localStorage.removeItem(AUTOSAVE_KEY);
                location.reload();
            }
        }

        function prepareFormSubmit() {
            if (tinymce.get('syllabusDocumentEditor')) {
                tinymce.get('syllabusDocumentEditor').save();
            }
            localStorage.removeItem(AUTOSAVE_KEY);
        }
    </script>
</body>

</html>
