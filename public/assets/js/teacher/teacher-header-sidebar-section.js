document.addEventListener('DOMContentLoaded', () => {
    // 1. SIDEBAR TOGGLE & PERSISTENCE
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');

    if (sidebar && sidebarToggle) {
        // Restore collapse state from localStorage
        const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }

        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        });
    }

    // 2. DROPDOWN CONTROLS (Notification & Profile)
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');

    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');

    if (notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (profileDropdown) profileDropdown.classList.remove('active');
            notificationDropdown.classList.toggle('active');
        });
    }

    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (notificationDropdown) notificationDropdown.classList.remove('active');
            profileDropdown.classList.toggle('active');
        });
    }

    // 3. CLOSE DROPDOWNS ON OUTSIDE CLICK
    document.addEventListener('click', (e) => {
        if (notificationDropdown && !notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
            notificationDropdown.classList.remove('active');
        }

        if (profileDropdown && !profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDropdown.classList.remove('active');
        }
    });

    // Prevent clicks inside dropdown menus from closing them automatically
    if (notificationDropdown) {
        notificationDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
    if (profileDropdown) {
        profileDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
});