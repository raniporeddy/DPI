// assets/js/main.js
// Main JavaScript Helpers for Digital Investor Onboarding System

document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap tooltip initialization
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // File input size & extension validation helper
    var fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                var maxSize = (this.getAttribute('data-max-size') || 5) * 1024 * 1024; // MB
                var allowedExts = (this.getAttribute('data-allowed-ext') || 'jpg,jpeg,png,pdf').split(',');
                var ext = file.name.split('.').pop().toLowerCase();

                if (file.size > maxSize) {
                    alert('File size exceeds maximum allowed limit of ' + (maxSize / (1024 * 1024)) + 'MB.');
                    this.value = '';
                    return;
                }

                if (!allowedExts.includes(ext)) {
                    alert('Invalid file format. Allowed formats: ' + allowedExts.join(', '));
                    this.value = '';
                    return;
                }
            }
        });
    });

    // Password visibility toggle
    var togglePassBtns = document.querySelectorAll('.toggle-password');
    togglePassBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetInput = document.querySelector(this.getAttribute('data-target'));
            if (targetInput) {
                var type = targetInput.getAttribute('type') === 'password' ? 'text' : 'password';
                targetInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            }
        });
    });
});
