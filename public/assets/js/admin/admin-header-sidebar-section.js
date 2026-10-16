document.addEventListener('DOMContentLoaded', () => {
    // =============================================
    // 1. SIDEBAR TOGGLE & LOCAL STORAGE PERSISTENCE
    // =============================================
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const STORAGE_KEY = 'student_sidebar_collapsed';

    if (sidebar && sidebarToggle) {
        // Restore collapse state from localStorage
        const isCollapsed = localStorage.getItem(STORAGE_KEY) === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }

        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('collapsed');
            
            // Save current state to localStorage
            const collapsedState = sidebar.classList.contains('collapsed');
            localStorage.setItem(STORAGE_KEY, collapsedState);
        });
    }

    // =============================================
    // 2. DROPDOWN CONTROLS (Notification & Profile)
    // =============================================
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');

    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');

    // Toggle Notifications Dropdown
    if (notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close profile dropdown if open
            if (profileDropdown) profileDropdown.classList.remove('active');
            
            notificationDropdown.classList.toggle('active');
        });
    }

    // Toggle Profile Dropdown
    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close notification dropdown if open
            if (notificationDropdown) notificationDropdown.classList.remove('active');
            
            profileDropdown.classList.toggle('active');
        });
    }

    // =============================================
    // 3. CLOSE DROPDOWNS ON OUTSIDE CLICK
    // =============================================
    document.addEventListener('click', (e) => {
        if (notificationDropdown && !notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
            notificationDropdown.classList.remove('active');
        }

        if (profileDropdown && !profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDropdown.classList.remove('active');
        }
    });

    // Prevent clicks inside dropdown containers from closing them automatically
    if (notificationDropdown) {
        notificationDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
    if (profileDropdown) {
        profileDropdown.addEventListener('click', (e) => e.stopPropagation());
    }

    // =============================================
    // 4. ACCESSIBILITY: CLOSE DROPDOWNS ON ESCAPE KEY
    // =============================================
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (notificationDropdown) notificationDropdown.classList.remove('active');
            if (profileDropdown) profileDropdown.classList.remove('active');
        }
    });

    // =============================================
    // 5. NOTIFICATION MARK AS READ ACTION
    // =============================================
    const markReadBtn = document.querySelector('.notification-dropdown .mark-read');
    if (markReadBtn) {
        markReadBtn.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Remove unread class from all notification items
            const unreadItems = document.querySelectorAll('.notification-list li.unread');
            unreadItems.forEach(item => item.classList.remove('unread'));

            // Hide or reset badge counter in header
            const badge = document.querySelector('.badge-count');
            if (badge) {
                badge.style.display = 'none';
            }
        });
    }
});