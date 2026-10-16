<?php
// Include admin header/sidebar logic
require_once __DIR__ . '/admin-header-sidebar-section-logic.php';
?>

<!-- ============================================= -->
<!-- SIDEBAR NAVIGATION -->
<!-- ============================================= -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-logo">
            <i class="fa-solid fa-graduation-cap logo-icon"></i>
            <span class="brand-title">Virtual Exam System</span>
        </div>
        <button class="toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar">
            <i class="fa-solid fa-chevron-left toggle-icon"></i>
        </button>
    </div>

    <div class="portal-badge-container">
        <span class="portal-label">Admin Portal</span>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <li class="nav-item">
                <a href="admin-dashboard-page.php" class="nav-link <?php echo ($currentPage === 'admin-dashboard-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-pie nav-icon"></i>
                    <span class="link-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="admin-profile-page.php" class="nav-link <?php echo ($currentPage === 'admin-profile-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user nav-icon"></i>
                    <span class="link-text">My Profile</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="admin-courseSyllabus-page.php" class="nav-link <?php echo ($currentPage === 'admin-courseSyllabus-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-open nav-icon"></i>
                    <span class="link-text">Courses & Syllabus</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-quesTable-page.php" class="nav-link <?php echo ($currentPage === 'admin-examTable-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-pen nav-icon"></i>
                    <span class="link-text">All Exams & Quizzes</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-result-page.php" class="nav-link <?php echo ($currentPage === 'admin-result-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-award nav-icon"></i>
                    <span class="link-text">All Exam Results</span>
                </a>
            </li>
 
            <li class="nav-item">
                <a href="admin-manageEnroll-page.php" class="nav-link <?php echo ($currentPage === 'admin-enrollment-management-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-check nav-icon"></i>
                    <span class="link-text">Enrollment List</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="admin-payout-page.php" class="nav-link <?php echo ($currentPage === 'admin-payout-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-wallet nav-icon"></i>
                    <span class="link-text">Salary List</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../common-portal/notification-page.php" class="nav-link <?php echo ($currentPage === 'notification-page.php') ? 'active' : ''; ?>" data-tooltip="Notice Board">
                    <i class="fa-regular fa-bell nav-icon"></i>
                    <span class="link-text">Notice Board</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../common-portal/contact-page.php" class="nav-link <?php echo ($currentPage === 'contact-page.php') ? 'active' : ''; ?>" data-tooltip="Contact">
                    <i class="fa-solid fa-phone nav-icon"></i>
                    <span class="link-text">Emergency Contact</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="admin-setting-page.php" class="nav-link <?php echo ($currentPage === 'admin-setting-page.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-sliders nav-icon"></i>
                    <span class="link-text">Settings</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-arrow-right-from-bracket logout-icon"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>
</aside>

<!-- ============================================= -->
<!-- CONTENT WRAPPER START -->
<!-- ============================================= -->
<div class="content-wrapper">

    <!-- TOP HEADER AREA -->
    <header class="top-header">
        <div class="header-container">
            <div class="header-right">

                <!-- Notifications Dropdown -->
                <div class="notification-wrapper">
                    <button class="icon-btn" id="notificationBtn" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if (!empty($unread_count) && $unread_count > 0): ?>
                            <span class="badge-count"><?php echo (int)$unread_count; ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown-menu notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h3>Notifications</h3>
                            <a href="#" class="mark-read">Mark all as read</a>
                        </div>
                        <ul class="notification-list">
                            <li class="unread">
                                <i class="fa-solid fa-file-signature icon-type"></i>
                                <div class="notif-content">
                                    <p class="notif-text">New enrollment request pending review.</p>
                                    <span class="notif-time">10 mins ago</span>
                                </div>
                            </li>
                            <li class="unread">
                                <i class="fa-solid fa-square-check icon-type"></i>
                                <div class="notif-content">
                                    <p class="notif-text">Salary payout processed for active faculty.</p>
                                    <span class="notif-time">1 hour ago</span>
                                </div>
                            </li>
                            <li>
                                <i class="fa-solid fa-bullhorn icon-type"></i>
                                <div class="notif-content">
                                    <p class="notif-text">System maintenance scheduled for midnight.</p>
                                    <span class="notif-time">Yesterday</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="header-divider"></div>

                <!-- Admin Profile Dropdown -->
                <div class="profile-wrapper">
                    <button class="profile-trigger" id="profileBtn">
                        <img src="<?php echo htmlspecialchars(getProfileImageUrl($admin['profile_image'] ?? '../assets/images/user-avatar.jpg')); ?>" alt="Admin Avatar" class="profile-avatar" id="headerAvatar">
                        <div class="profile-info">
                            <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'System Admin'); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($admin['designation'] ?? 'System Administrator'); ?></span>
                        </div>
                        <i class="fa-solid fa-chevron-down dropdown-icon"></i>
                    </button>

                    <div class="dropdown-menu profile-dropdown" id="profileDropdown">
                        <div class="dropdown-card-header" style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0;">
                            <div class="card-user-details" style="display: flex; flex-direction: column; gap: 3px;">
                                <div style="font-weight: 700; font-size: 0.95rem; color: #1e293b; line-height: 1.2;">
                                    <?php echo htmlspecialchars($admin['full_name'] ?? 'System Admin'); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: #475569;">
                                    ID: <strong style="color: #2563eb;"><?php echo htmlspecialchars($admin['admin_id'] ?? 'N/A'); ?></strong>
                                </div>
                                <?php if (!empty($admin['department'])): ?>
                                    <div style="font-size: 0.8rem; color: #64748b; line-height: 1.2;">
                                        <?php echo htmlspecialchars($admin['department']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="admin-profile-page.php" class="dropdown-item">
                            <i class="fa-regular fa-user"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="admin-dashboard-page.php" class="dropdown-item">
                            <i class="fa-solid fa-gauge-high"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="admin-setting-page.php" class="dropdown-item">
                            <i class="fa-solid fa-sliders"></i>
                            <span>Settings</span>
                        </a>
                        
                        <div class="dropdown-divider"></div>
                        
                        <a href="logout.php" class="dropdown-item logout-link">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </header>