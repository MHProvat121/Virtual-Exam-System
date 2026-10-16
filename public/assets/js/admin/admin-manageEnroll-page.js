
        // Enrollment Cancel Modal Handlers
        function openCancelModal(id, studentName) {
            document.getElementById('modalEnrollmentId').value = id;
            document.getElementById('modalStudentName').textContent = studentName;
            const modal = document.getElementById('cancelModal');
            modal.classList.add('active');
        }

        function closeCancelModal() {
            const modal = document.getElementById('cancelModal');
            modal.classList.remove('active');
        }

        // Withdrawal Reject Modal Handlers
        function openRejectWithdrawModal(id, studentName) {
            document.getElementById('modalRejectWithdrawId').value = id;
            document.getElementById('modalRejectStudentName').textContent = studentName;
            const modal = document.getElementById('rejectWithdrawModal');
            modal.classList.add('active');
        }

        function closeRejectWithdrawModal() {
            const modal = document.getElementById('rejectWithdrawModal');
            modal.classList.remove('active');
        }

        // Close on background click
        window.addEventListener('click', function(event) {
            const cModal = document.getElementById('cancelModal');
            const rModal = document.getElementById('rejectWithdrawModal');
            if (event.target === cModal) {
                closeCancelModal();
            }
            if (event.target === rModal) {
                closeRejectWithdrawModal();
            }
        });
    