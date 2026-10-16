// 1. Grab all the eye icons using the class name
const eyeIcons = document.querySelectorAll(".toggle-password");

// 2. Loop through each eye icon to give it click functionality
eyeIcons.forEach(function (icon) {
    icon.addEventListener("click", function () {
        // Find the specific password field inside the exact same container as this clicked eye
        const passwordField = this.parentElement.querySelector(".password-field");

        // Toggle BOTH classes so they switch cleanly back and forth
        this.classList.toggle("fa-eye");
        this.classList.toggle("fa-eye-slash");

        // Switch the input type between password and text
        const type = passwordField.getAttribute("type") === "password" ? "text" : "password";
        passwordField.setAttribute("type", type);
    });
});