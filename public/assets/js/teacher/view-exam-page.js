/**
 * Cancel Exam Confirmation Modal with 3-Second Countdown
 */

document.addEventListener('DOMContentLoaded', () => {
    const openBtn = document.getElementById('openCancelModalBtn');
    const modal = document.getElementById('cancelExamModal');
    const closeBtn = document.getElementById('closeCancelModalBtn');
    const backBtn = document.getElementById('backCancelModalBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const confirmDeleteText = document.getElementById('confirmDeleteText');

    let countdownInterval = null;
    const COUNTDOWN_TIME = 3; // 3 Seconds Pause Delay

    if (!openBtn || !modal) return;

    // Start 3-Second Countdown
    function startCountdown() {
        let remainingSeconds = COUNTDOWN_TIME;
        
        // Reset state
        confirmDeleteBtn.disabled = true;
        confirmDeleteText.textContent = `Delete Exam (${remainingSeconds}s)`;

        clearInterval(countdownInterval);

        countdownInterval = setInterval(() => {
            remainingSeconds -= 1;

            if (remainingSeconds > 0) {
                confirmDeleteText.textContent = `Delete Exam (${remainingSeconds}s)`;
            } else {
                clearInterval(countdownInterval);
                confirmDeleteBtn.disabled = false;
                confirmDeleteText.textContent = 'Delete Exam';
            }
        }, 1000);
    }

    // Stop and Reset Countdown
    function resetCountdown() {
        clearInterval(countdownInterval);
        confirmDeleteBtn.disabled = true;
        confirmDeleteText.textContent = `Delete Exam (${COUNTDOWN_TIME}s)`;
    }

    // Open Modal Function
    function openModal() {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        startCountdown();
    }

    // Close Modal Function
    function closeModal() {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        resetCountdown();
    }

    // Event Listeners
    openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backBtn) backBtn.addEventListener('click', closeModal);

    // Close modal when clicking on dark backdrop overlay
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    // Close on Escape Key
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeModal();
        }
    });
});