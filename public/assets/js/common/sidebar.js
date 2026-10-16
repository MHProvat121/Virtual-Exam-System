document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const mainContent = document.querySelector('.main-content'); // Adjust selector to your main page wrapper

    // 1. Read state from LocalStorage to keep user preference on page reload
    const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';

    if (isCollapsed) {
        sidebar.classList.add('collapsed');
        if (mainContent) mainContent.style.marginLeft = '78px';
    }

    // 2. Click Handler for Collapsing / Expanding
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            const collapsedNow = sidebar.classList.contains('collapsed');

            // Adjust main page layout margin
            if (mainContent) {
                mainContent.style.marginLeft = collapsedNow ? '78px' : '260px';
            }

            // Save choice in browser memory
            localStorage.setItem('sidebar-collapsed', collapsedNow);
        });
    }

    // 3. Highlight current active page link automatically
    const currentPath = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            navLinks.forEach(l => l.classList.remove('active'));
            link.classList.add('active');
        }
    });
});

//Handling Refresh on-  Double Click (on Dashboard link)
document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
    link.addEventListener('click', function (e) {
        // Prevent action if already clicked once
        if (this.classList.contains('is-navigating')) {
            e.preventDefault();
            return;
        }
        
        // Mark link as clicked
        this.classList.add('is-navigating');
        
        // Prevent navigation if clicking the already active link
        if (this.classList.contains('active')) {
            e.preventDefault();
            this.classList.remove('is-navigating');
        }
    });
});