document.addEventListener("DOMContentLoaded", () => {
    const profileBtn = document.getElementById("profileBtn");
    const profileDropdown = document.getElementById("profileDropdown");
    
    const notificationBtn = document.getElementById("notificationBtn");
    const notificationDropdown = document.getElementById("notificationDropdown");
    
    const menuToggleBtn = document.getElementById("menuToggleBtn");

    // Toggle Profile Dropdown
    profileBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        notificationDropdown.classList.remove("active");
        profileDropdown.classList.toggle("active");
    });

    // Toggle Notification Dropdown
    notificationBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        profileDropdown.classList.remove("active");
        notificationDropdown.classList.toggle("active");
    });

    // Close Dropdowns on outside click
    document.addEventListener("click", (e) => {
        if (!profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDropdown.classList.remove("active");
        }
        if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
            notificationDropdown.classList.remove("active");
        }
    });

    // Sidebar Toggle Event Dispatcher
    if (menuToggleBtn) {
        menuToggleBtn.addEventListener("click", () => {
            document.body.classList.toggle("sidebar-collapsed");
        });
    }
});