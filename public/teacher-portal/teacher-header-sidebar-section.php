<?php
// Include the header/sidebar backend logic
require_once __DIR__ . '/teacher-header-sidebar-section-logic.php';
?>

<!-- SIDEBAR AREA -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-logo">
            <i class="fa-solid fa-graduation-cap logo-icon"></i>
            <span class="brand-title">Virtual Exam System</span>
        </div>
        <button type="button" class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar" aria-label="Toggle Sidebar">
            <i class="fa-solid fa-chevron-left toggle-icon"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <div class="portal-badge-container">
            <span class="portal-label">Teacher Portal</span>
        </div>

        <ul class="nav-list">
            <li class="nav-item">
                <a href="teacher-dashboard-page.php" class="nav-link <?php echo ($currentPage === 'teacher-dashboard-page.php') ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <i class="fa-solid fa-chart-pie nav-icon"></i>
                    <span class="link-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="teacher-profile-page.php" class="nav-link <?php echo ($currentPage === 'teacher-profile-page.php') ? 'active' : ''; ?>" data-tooltip="My Profile">
                    <i class="fa-regular fa-user nav-icon"></i>
                    <span class="link-text">My Profile</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="teacher-courseSyllabus-page.php" class="nav-link <?php echo ($currentPage === 'teacher-courseSyllabus-page.php') ? 'active' : ''; ?>" data-tooltip="Course Syllabus">
                    <i class="fa-solid fa-book-open nav-icon"></i>
                    <span class="link-text">Courses & Syllabus</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="teacher-quesTable-page.php" class="nav-link <?php echo ($currentPage === 'teacher-quesTable-page.php') ? 'active' : ''; ?>" data-tooltip="Exams & Quizzes">
                    <i class="fa-solid fa-file-pen nav-icon"></i>
                    <span class="link-text">Exam Questions</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="teacher-result-page.php" class="nav-link <?php echo ($currentPage === 'teacher-examValidation-page.php') ? 'active' : ''; ?>" data-tooltip="Results & Marks">
                    <i class="fa-solid fa-square-poll-vertical nav-icon"></i>
                    <span class="link-text">Exam Validation & Results</span>
                </a>
            </li>

        
            <li class="nav-item">
                <a href="teacher-salary-page.php" class="nav-link <?php echo ($currentPage === 'teacher-salary-page.php') ? 'active' : ''; ?>" data-tooltip="Contact">
                    <i class="fa-solid fa-dollar nav-icon"></i>
                    <span class="link-text">Salary Information</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="../common-portal/notification-page.php" class="nav-link <?php echo ($currentPage === 'teacher-notification-page.php') ? 'active' : ''; ?>" data-tooltip="Notice Board">
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
                    <a href="teacher-setting-page.php" class="nav-link <?php echo ($currentPage === 'teacher-setting-page.php') ? 'active' : ''; ?>" data-tooltip="Settings">
                        <i class="fa-solid fa-sliders nav-icon"></i>
                        <span class="link-text">Settings</span>
                    </a>
            </li>
        </ul>
    </nav>



    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn" data-tooltip="Logout">
            <i class="fa-solid fa-arrow-right-from-bracket logout-icon"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>
</aside>

<!-- CONTENT WRAPPER -->
<div class="content-wrapper">

    <!-- HEADER AREA -->
    <header class="top-header">
        <div class="header-container">
            <div class="header-right">

                <!-- Notifications Dropdown -->
                <div class="notification-wrapper">
                    <button class="icon-btn" id="notificationBtn" aria-label="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($headerUnreadCount > 0): ?>
                            <span class="badge-count"><?php echo $headerUnreadCount; ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown-menu notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h3>Notifications</h3>
                            <a href="../common-portal/notification-page.php" class="mark-read">View All</a>
                        </div>
                        <ul class="notification-list">
                            <?php if (!empty($headerNotifications)): ?>
                                <?php foreach ($headerNotifications as $hNotif): ?>
                                    <li class="<?php echo $hNotif['item_class']; ?>">
                                        <i class="<?php echo $hNotif['icon_class']; ?> icon-type"></i>
                                        <div class="notif-content">
                                            <p class="notif-text"><?php echo $hNotif['message']; ?></p>
                                            <span class="notif-time"><?php echo htmlspecialchars($hNotif['time']); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li style="padding: 15px; text-align: center; color: #888; font-size: 13px;">
                                    No notices or updates available.
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <div class="header-divider"></div>

                <!-- User Profile Dropdown -->
                <div class="profile-wrapper">
                    <button class="profile-trigger  " id="profileBtn" aria-label="User Profile">
                        <!-- Render Profile Avatar with Cache-Busting -->
                        <img src="<?php echo htmlspecialchars($header_profile_img); ?>?v=<?php echo time(); ?>" alt="Teacher Profile" class="profile-img">
                        <div class="profile-info">
                            <span class="user-name"><?php echo htmlspecialchars($headerTeacher['full_name']); ?></span>
                            <span class="user-role"><?php echo htmlspecialchars($headerTeacher['designation']); ?></span>
                        </div>
                        <i class="fa-solid fa-chevron-down dropdown-icon"></i>
                    </button>

                    <div class="dropdown-menu profile-dropdown" id="profileDropdown">
                        <div class="profile-dropdown-user">
                            <span class="user-name-title"><?php echo htmlspecialchars($headerTeacher['full_name']); ?></span>
                            <div class="user-meta-row">
                                <span class="id-tag">ID: <?php echo htmlspecialchars($headerTeacher['teacher_id']); ?></span>
                                <span class="designation-text"><?php echo htmlspecialchars($headerTeacher['designation']); ?></span>
                            </div>
                        </div>

                        <div class="dropdown-divider"></div>

                        <a href="teacher-profile-page.php" class="dropdown-item">
                            <i class="fa-regular fa-user"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="teacher-dashboard-page.php" class="dropdown-item">
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="settings-page.php" class="dropdown-item">
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