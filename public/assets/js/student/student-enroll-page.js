/**
 * Student Enroll Page - Status-Driven Checkbox Controller
 * 
 * Status Logic:
 * 1. If (unenrolled or cancelled = unenrolled):
 *    - Withdraw checkbox NEVER opens (strictly disabled, checked=false).
 *    - Enroll checkbox is open/enabled for selection.
 *    - Checking or unchecking Enroll checkbox does NOT open withdraw checkbox.
 * 
 * 2. If (pending):
 *    - Withdraw checkbox NEVER opens (strictly disabled).
 *    - Enroll checkbox is locked (checked & disabled for enrollment pending).
 * 
 * 3. If (enrolled):
 *    - Withdraw checkbox OPENS (active/enabled for withdrawal selection).
 *    - Enroll checkbox is locked as already enrolled (checked & disabled).
 * 
 * Table Header:
 * - Check all checkboxes in <th> are removed.
 */
(function() {
    'use strict';

    // -------------------------------------------------------------
    // 1. MODAL HELPER & ALERT SYSTEM
    // -------------------------------------------------------------
    function getOrCreateModal() {
        let modalOverlay = document.getElementById('batchActionModal');
        if (!modalOverlay) {
            modalOverlay = document.createElement('div');
            modalOverlay.id = 'batchActionModal';
            modalOverlay.className = 'batch-modal-overlay';
            modalOverlay.style.display = 'none';
            modalOverlay.innerHTML = `
                <div class="batch-modal-card">
                    <div class="batch-modal-icon" id="batchModalIcon">
                        <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <h3 id="batchModalTitle" class="batch-modal-title">Confirm Action</h3>
                    <p id="batchModalPrompt" class="batch-modal-prompt"></p>
                    <div class="batch-modal-actions">
                        <button type="button" id="batchModalCancelBtn" class="batch-modal-btn batch-modal-cancel">Cancel</button>
                        <button type="button" id="batchModalConfirmBtn" class="batch-modal-btn batch-modal-confirm">Yes</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modalOverlay);
        }
        return modalOverlay;
    }

    const modalOverlay = getOrCreateModal();
    const modalIcon = document.getElementById('batchModalIcon');
    const modalTitle = document.getElementById('batchModalTitle');
    const modalPrompt = document.getElementById('batchModalPrompt');
    const modalConfirmBtn = document.getElementById('batchModalConfirmBtn');
    const modalCancelBtn = document.getElementById('batchModalCancelBtn');
    let pendingModalAction = null;

    function openActionModal(type, batchName, onConfirm, customPrompt) {
        if (modalCancelBtn) modalCancelBtn.style.display = '';
        if (type === 'enroll') {
            if (modalIcon) {
                modalIcon.className = 'batch-modal-icon';
                modalIcon.style.background = '#eff6ff';
                modalIcon.style.color = '#2563eb';
                modalIcon.innerHTML = '<i class="fa-solid fa-graduation-cap"></i>';
            }
            if (modalTitle) modalTitle.textContent = 'Enroll All Courses';
            if (modalPrompt) modalPrompt.textContent = customPrompt || ('Do you want to enroll all the courses of batch "' + batchName + '"?');
            if (modalConfirmBtn) {
                modalConfirmBtn.className = 'batch-modal-btn batch-modal-confirm';
                modalConfirmBtn.textContent = 'Yes';
            }
        } else if (type === 'withdraw') {
            if (modalIcon) {
                modalIcon.className = 'batch-modal-icon withdraw';
                modalIcon.style.background = '#fef2f2';
                modalIcon.style.color = '#dc2626';
                modalIcon.innerHTML = '<i class="fa-solid fa-arrow-right-from-bracket"></i>';
            }
            if (modalTitle) modalTitle.textContent = 'Withdraw All Courses';
            if (modalPrompt) modalPrompt.textContent = customPrompt || ('Do you want to widthdraw all the courses of batch "' + batchName + '"?');
            if (modalConfirmBtn) {
                modalConfirmBtn.className = 'batch-modal-btn batch-modal-confirm withdraw';
                modalConfirmBtn.textContent = 'Yes';
            }
        }
        pendingModalAction = onConfirm;
        if (modalOverlay) modalOverlay.style.display = 'flex';
    }

    function openAlertModal(type, title, message) {
        if (modalCancelBtn) modalCancelBtn.style.display = 'none';
        if (type === 'enrolled') {
            if (modalIcon) {
                modalIcon.className = 'batch-modal-icon';
                modalIcon.style.background = '#d1fae5';
                modalIcon.style.color = '#059669';
                modalIcon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
            }
            if (modalTitle) modalTitle.textContent = title || 'All Courses Enrolled';
            if (modalPrompt) modalPrompt.textContent = message || 'All the courses are enrolled.';
            if (modalConfirmBtn) {
                modalConfirmBtn.className = 'batch-modal-btn batch-modal-confirm';
                modalConfirmBtn.textContent = 'OK';
            }
        } else if (type === 'withdrawn') {
            if (modalIcon) {
                modalIcon.className = 'batch-modal-icon withdraw';
                modalIcon.style.background = '#fee2e2';
                modalIcon.style.color = '#dc2626';
                modalIcon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
            }
            if (modalTitle) modalTitle.textContent = title || 'All Courses Withdrawn';
            if (modalPrompt) modalPrompt.textContent = message || 'All the courses are withdrawn.';
            if (modalConfirmBtn) {
                modalConfirmBtn.className = 'batch-modal-btn batch-modal-confirm withdraw';
                modalConfirmBtn.textContent = 'OK';
            }
        } else {
            if (modalIcon) {
                modalIcon.className = 'batch-modal-icon';
                modalIcon.style.background = '#e0f2fe';
                modalIcon.style.color = '#0266cc';
                modalIcon.innerHTML = '<i class="fa-solid fa-circle-info"></i>';
            }
            if (modalTitle) modalTitle.textContent = title || 'Notice';
            if (modalPrompt) modalPrompt.textContent = message || '';
            if (modalConfirmBtn) {
                modalConfirmBtn.className = 'batch-modal-btn batch-modal-confirm';
                modalConfirmBtn.textContent = 'OK';
            }
        }
        pendingModalAction = null;
        if (modalOverlay) modalOverlay.style.display = 'flex';
    }

    function closeActionModal() {
        if (modalOverlay) modalOverlay.style.display = 'none';
        pendingModalAction = null;
        if (modalCancelBtn) modalCancelBtn.style.display = '';
        if (modalIcon) {
            modalIcon.style.background = '';
            modalIcon.style.color = '';
        }
    }

    if (modalCancelBtn) {
        modalCancelBtn.addEventListener('click', closeActionModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) closeActionModal();
        });
    }
    if (modalConfirmBtn) {
        modalConfirmBtn.addEventListener('click', function() {
            if (typeof pendingModalAction === 'function') {
                const action = pendingModalAction;
                closeActionModal();
                action();
            } else {
                closeActionModal();
            }
        });
    }

    // -------------------------------------------------------------
    // 2. STATUS-DRIVEN CHECKBOX CONTROLLER
    // -------------------------------------------------------------
    const StatusCheckboxController = {
        /**
         * Resolves the enrollment and withdrawal status for a row
         */
        getRowStatus: function(row) {
            const enrollCb = row.querySelector('.course-enroll-check');
            const withdrawCb = row.querySelector('.course-withdraw-check');
            if (!enrollCb || !withdrawCb) return null;

            // Direct data attributes or status badges
            let enrollStatus = enrollCb.getAttribute('data-enroll-status') || row.getAttribute('data-enroll-status');
            let withdrawStatus = withdrawCb.getAttribute('data-withdraw-status') || row.getAttribute('data-withdraw-status');

            if (!enrollStatus) {
                if (enrollCb.getAttribute('data-is-enrolled') === '1') {
                    enrollStatus = 'Enrolled';
                } else if (enrollCb.getAttribute('data-is-pending') === '1') {
                    enrollStatus = 'Pending';
                } else {
                    enrollStatus = 'Unenrolled';
                }
            }

            if (!withdrawStatus) {
                if (withdrawCb.getAttribute('data-is-withdraw-pending') === '1') {
                    withdrawStatus = 'Pending';
                } else if (withdrawCb.getAttribute('data-is-withdrawn') === '1') {
                    withdrawStatus = 'Withdrawn';
                } else {
                    withdrawStatus = 'None';
                }
            }

            return {
                row: row,
                enrollCb: enrollCb,
                withdrawCb: withdrawCb,
                enrollStatus: enrollStatus,
                withdrawStatus: withdrawStatus,
                courseCode: enrollCb.getAttribute('data-course-code') || withdrawCb.getAttribute('data-course-code') || ''
            };
        },

        /**
         * Applies the core status rules to a row:
         * 1. if (unenrolled or cancelled = unenrolled) -> withdraw checkbox NEVER opens
         * 2. if (pending) -> withdraw checkbox NEVER opens
         * 3. if (enrolled) -> withdraw checkbox OPENS
         */
        applyStatusRules: function(row) {
            const data = this.getRowStatus(row);
            if (!data) return;

            const { enrollCb, withdrawCb, enrollStatus, withdrawStatus } = data;

            // Normalize: 'cancelled' equals 'unenrolled'
            const isUnenrolledOrCancelled = (enrollStatus === 'Unenrolled' || enrollStatus === 'Cancelled');
            const isPending = (enrollStatus === 'Pending' || withdrawStatus === 'Pending');
            const isEnrolled = (enrollStatus === 'Enrolled');

            if (isPending) {
                // RULE 2: If (pending) -> withdraw checkbox never opens
                if (withdrawStatus === 'Pending') {
                    withdrawCb.checked = true;
                    withdrawCb.disabled = true;
                    withdrawCb.title = "Withdrawal Pending Approval";
                } else {
                    withdrawCb.checked = false;
                    withdrawCb.disabled = true;
                    withdrawCb.title = "Enrollment Pending Approval";
                }

                // Enroll checkbox is locked
                enrollCb.checked = true;
                enrollCb.disabled = true;
                enrollCb.title = "Enrollment Pending Approval";

            } else if (isEnrolled) {
                // RULE 3: If (enrolled) -> withdraw checkbox OPENS
                withdrawCb.disabled = false;
                withdrawCb.title = "Select course to withdraw";

                // Course is already enrolled, so enroll checkbox is locked as enrolled
                enrollCb.checked = true;
                enrollCb.disabled = true;
                enrollCb.title = "Already Enrolled";

            } else if (isUnenrolledOrCancelled) {
                // RULE 1: If (unenrolled or cancelled = unenrolled) -> withdraw checkbox NEVER opens
                withdrawCb.checked = false;
                withdrawCb.disabled = true;
                withdrawCb.title = (withdrawStatus === 'Withdrawn') 
                    ? "Course is already withdrawn" 
                    : "Withdrawal is not available for unenrolled courses";

                // Student can select/deselect course to enroll
                enrollCb.disabled = false;
                enrollCb.title = "Select course to enroll";
            }
        },

        /**
         * Initialize all rows and event listeners
         */
        init: function() {
            const self = this;
            const batchCards = document.querySelectorAll('.batch-card');

            batchCards.forEach(function(batchCard) {
                const rows = batchCard.querySelectorAll('tbody tr');

                // Apply status rules on every row
                rows.forEach(function(row) {
                    self.applyStatusRules(row);

                    // When enroll checkbox is toggled on unenrolled/cancelled courses:
                    const enrollCb = row.querySelector('.course-enroll-check');
                    if (enrollCb) {
                        enrollCb.addEventListener('change', function() {
                            // Enforce rule: even when selected to enroll, withdrawal NEVER opens for unenrolled/cancelled courses
                            self.applyStatusRules(row);
                        });
                    }
                });
            });

            // ---------------------------------------------------------
            // ENROLL BUTTON HANDLER (Condition 1 & Condition 2)
            // ---------------------------------------------------------
            document.querySelectorAll('.btn-enroll').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    const batchCard = this.closest('.batch-card');
                    if (!batchCard) return;

                    const batchName = batchCard.getAttribute('data-batch') || this.getAttribute('data-batch') || '';
                    const href = this.getAttribute('href') || ('student-viewEnroll-page.php?batch=' + encodeURIComponent(batchName));

                    const isPending = this.textContent.toLowerCase().includes('pending') || href.includes('mode=pending');
                    if (isPending) return;

                    e.preventDefault();

                    const allRows = Array.from(batchCard.querySelectorAll('tbody tr'));
                    const validCourseRows = allRows.filter(r => r.querySelector('.course-enroll-check'));

                    // Check which courses are already enrolled
                    const enrolledRows = validCourseRows.filter(function(r) {
                        const data = self.getRowStatus(r);
                        return data && data.enrollStatus === 'Enrolled';
                    });

                    // Available courses for enrollment (unenrolled or cancelled)
                    const availableRows = validCourseRows.filter(function(r) {
                        const data = self.getRowStatus(r);
                        return data && (data.enrollStatus === 'Unenrolled' || data.enrollStatus === 'Cancelled');
                    });

                    // If all courses in this batch are already enrolled:
                    if (validCourseRows.length > 0 && enrolledRows.length === validCourseRows.length) {
                        openAlertModal('enrolled', 'All Courses Enrolled', 'All the courses are enrolled for batch "' + batchName + '".');
                        return;
                    }

                    // Check which available courses are checked
                    const checkedAvailableCbs = availableRows.map(function(r) {
                        return r.querySelector('.course-enroll-check');
                    }).filter(function(cb) {
                        return cb && cb.checked;
                    });

                    if (checkedAvailableCbs.length === 0) {
                        // CONDITION 1:
                        // Enroll clicked without any course selection:
                        // Check all available unenrolled courses
                        availableRows.forEach(function(r) {
                            const cb = r.querySelector('.course-enroll-check');
                            if (cb) cb.checked = true;
                            // Enforce: withdraw checkbox still never opens because status is unenrolled
                            self.applyStatusRules(r);
                        });

                        const availableCodes = availableRows.map(function(r) {
                            const data = self.getRowStatus(r);
                            return data ? data.courseCode : '';
                        }).filter(Boolean);

                        const promptText = (enrolledRows.length === 0)
                            ? 'Do you want to enroll all the courses of batch "' + batchName + '"?'
                            : 'Do you want to enroll all remaining available courses of batch "' + batchName + '"?';

                        openActionModal('enroll', batchName, function() {
                            const targetUrl = new URL(href, window.location.href);
                            if (availableCodes.length > 0) {
                                targetUrl.searchParams.set('courses', availableCodes.join(','));
                            }
                            targetUrl.searchParams.delete('mode');
                            targetUrl.searchParams.delete('enrollment_id');
                            window.location.href = targetUrl.href;
                        }, promptText);

                    } else {
                        // CONDITION 2:
                        // User selected specific course(s) -> enroll only those selected
                        const selectedCodes = checkedAvailableCbs.map(function(cb) {
                            return cb.getAttribute('data-course-code') || cb.value;
                        }).filter(Boolean);

                        const targetUrl = new URL(href, window.location.href);
                        targetUrl.searchParams.set('courses', selectedCodes.join(','));
                        targetUrl.searchParams.delete('mode');
                        targetUrl.searchParams.delete('enrollment_id');
                        window.location.href = targetUrl.href;
                    }
                });
            });

            // ---------------------------------------------------------
            // WITHDRAW BUTTON HANDLER (Vice-Versa Conditions 1 & 2)
            // ---------------------------------------------------------
            document.querySelectorAll('.btn-withdraw').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    const batchCard = this.closest('.batch-card');
                    if (!batchCard) return;

                    const batchName = batchCard.getAttribute('data-batch') || this.getAttribute('data-batch') || '';
                    const href = this.getAttribute('href') || ('student-viewWidthdraw-page.php?batch=' + encodeURIComponent(batchName));

                    const isPending = this.textContent.toLowerCase().includes('pending') || href.includes('mode=pending');
                    if (isPending) return;

                    e.preventDefault();

                    const allRows = Array.from(batchCard.querySelectorAll('tbody tr'));
                    // Actively enrolled rows where status is 'Enrolled' (where withdraw checkbox is open)
                    const enrolledRows = allRows.filter(function(r) {
                        const data = self.getRowStatus(r);
                        return data && data.enrollStatus === 'Enrolled';
                    });

                    // If NO courses are enrolled (all are unenrolled, cancelled, or withdrawn):
                    if (enrolledRows.length === 0) {
                        openAlertModal('withdrawn', 'All Courses Withdrawn', 'All the courses are withdrawn for batch "' + batchName + '".');
                        return;
                    }

                    // Check which open withdraw checkboxes are checked
                    const checkedWithdrawCbs = enrolledRows.map(function(r) {
                        return r.querySelector('.course-withdraw-check');
                    }).filter(function(cb) {
                        return cb && !cb.disabled && cb.checked;
                    });

                    if (checkedWithdrawCbs.length === 0) {
                        // VICE-VERSA CONDITION 1:
                        // Withdraw clicked without any course selection:
                        // Check all open withdraw checkboxes for enrolled courses
                        enrolledRows.forEach(function(r) {
                            const wcb = r.querySelector('.course-withdraw-check');
                            if (wcb && !wcb.disabled) {
                                wcb.checked = true;
                            }
                        });

                        const enrolledCodes = enrolledRows.map(function(r) {
                            const data = self.getRowStatus(r);
                            return data ? data.courseCode : '';
                        }).filter(Boolean);

                        openActionModal('withdraw', batchName, function() {
                            const targetUrl = new URL(href, window.location.href);
                            if (enrolledCodes.length > 0) {
                                targetUrl.searchParams.set('courses', enrolledCodes.join(','));
                            }
                            targetUrl.searchParams.delete('mode');
                            targetUrl.searchParams.delete('enrollment_id');
                            window.location.href = targetUrl.href;
                        }, 'Do you want to widthdraw all the courses of batch "' + batchName + '"?');

                    } else {
                        // VICE-VERSA CONDITION 2:
                        // User selected specific enrolled course(s) to withdraw:
                        const selectedCodes = checkedWithdrawCbs.map(function(cb) {
                            return cb.getAttribute('data-course-code') || cb.value;
                        }).filter(Boolean);

                        const targetUrl = new URL(href, window.location.href);
                        targetUrl.searchParams.set('courses', selectedCodes.join(','));
                        targetUrl.searchParams.delete('mode');
                        targetUrl.searchParams.delete('enrollment_id');
                        window.location.href = targetUrl.href;
                    }
                });
            });
        }
    };

    // Expose controller globally
    window.StatusCheckboxController = StatusCheckboxController;

    // -------------------------------------------------------------
    // 3. REAL-TIME SEARCH BAR FILTER
    // -------------------------------------------------------------
    function initSearchFilter() {
        const searchInput = document.getElementById('batchSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase().trim();
                const batchCards = document.querySelectorAll('.batch-card');
                let hasResults = false;

                batchCards.forEach(function(card) {
                    const text = card.textContent.toLowerCase();
                    if (text.includes(filter)) {
                        card.style.display = '';
                        hasResults = true;
                    } else {
                        card.style.display = 'none';
                    }
                });

                const noResults = document.getElementById('noSearchResults');
                if (noResults) {
                    noResults.style.display = (hasResults || filter === '') ? 'none' : 'block';
                }
            });
        }
    }

    // -------------------------------------------------------------
    // 4. DOM BOOTSTRAP
    // -------------------------------------------------------------
    function bootstrap() {
        initSearchFilter();
        StatusCheckboxController.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
