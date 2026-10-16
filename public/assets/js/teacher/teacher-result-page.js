/**
 * teacher-result-page.js
 * Controls-card filtering and Evaluation / Feedback Modal management for Teacher Result Page
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // -------------------------------------------------------------
    // CONTROLS-CARD FILTERING (STATUS TABS + LIVE SEARCH)
    // Prototyped from admin-manageEnroll-page
    // -------------------------------------------------------------
    let currentTeacherResultStatusFilter = 'all';

    function filterResultStatus(status, tabElement) {
        currentTeacherResultStatusFilter = (status || 'all').toLowerCase().trim();

        const container = document.getElementById('resultStatusTabs');
        if (container) {
            const tabs = container.querySelectorAll('.tab-link');
            tabs.forEach(t => t.classList.remove('active'));
        }
        if (tabElement) {
            tabElement.classList.add('active');
        }

        applyTeacherResultFilters();
    }

    function filterTeacherResults() {
        applyTeacherResultFilters();
    }

    function applyTeacherResultFilters() {
        const searchInput = document.getElementById('teacherResultSearch');
        const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const cards = document.querySelectorAll('.batch-card');

        let visibleCards = 0;

        cards.forEach(card => {
            const cardBatchName = (card.getAttribute('data-batch') || '').toLowerCase();
            const cardText = card.innerText.toLowerCase();
            const rows = card.querySelectorAll('tbody tr.teacher-result-row');

            const cardMatchesSearch = term === '' || cardText.includes(term) || cardBatchName.includes(term);

            if (!cardMatchesSearch) {
                card.style.display = 'none';
                return;
            }

            let visibleRowsCount = 0;

            if (rows.length > 0) {
                rows.forEach(r => {
                    const rowStatus = (r.getAttribute('data-status') || '').toLowerCase().trim();
                    const rowText = r.innerText.toLowerCase();

                    // Status match logic
                    let matchesStatus = false;
                    if (currentTeacherResultStatusFilter === 'all') {
                        matchesStatus = true;
                    } else if (currentTeacherResultStatusFilter === 'graded') {
                        matchesStatus = (rowStatus === 'graded');
                    } else if (currentTeacherResultStatusFilter.includes('review')) {
                        matchesStatus = (rowStatus.includes('review'));
                    } else if (currentTeacherResultStatusFilter === 'submitted') {
                        matchesStatus = (rowStatus === 'submitted');
                    } else if (currentTeacherResultStatusFilter.includes('not taken') || currentTeacherResultStatusFilter === 'nottaken') {
                        matchesStatus = (rowStatus.includes('not taken') || rowStatus === 'nottaken' || rowStatus === '');
                    } else {
                        matchesStatus = (rowStatus === currentTeacherResultStatusFilter);
                    }

                    // Row search match logic
                    const matchesSearch = (term === '') || rowText.includes(term);

                    if (matchesStatus && matchesSearch) {
                        r.style.display = '';
                        visibleRowsCount++;
                    } else {
                        r.style.display = 'none';
                    }
                });

                if (visibleRowsCount > 0) {
                    card.style.display = 'block';
                    visibleCards++;
                } else {
                    card.style.display = 'none';
                }
            } else {
                if (currentTeacherResultStatusFilter === 'all' && cardMatchesSearch) {
                    card.style.display = 'block';
                    visibleCards++;
                } else {
                    card.style.display = 'none';
                }
            }
        });

        const emptyContainer = document.getElementById('searchNoResultsMessage');
        if (emptyContainer) {
            emptyContainer.style.display = (visibleCards === 0 && cards.length > 0) ? 'block' : 'none';
        }
    }

    window.filterResultStatus = filterResultStatus;
    window.filterTeacherResults = filterTeacherResults;

    // -------------------------------------------------------------
    // EVALUATION / REMARKS MODAL
    // -------------------------------------------------------------
    const evalModal = document.getElementById('evaluationModalBackdrop');
    const evalForm = document.getElementById('evaluationForm');

    function openEvaluationModal(submissionId, examId, studentName, studentId, examTitle, currentMarks, totalMarks, currentFeedback, currentStatus) {
        document.getElementById('evalModalSubmissionId').value = submissionId;
        document.getElementById('evalModalExamTitle').textContent = examTitle;
        document.getElementById('evalModalStudentName').textContent = studentName + ' (' + studentId + ')';
        document.getElementById('evalModalTotalMarks').textContent = totalMarks;
        
        const marksInput = document.getElementById('evalMarksObtained');
        marksInput.value = currentMarks;
        marksInput.max = totalMarks;
        
        document.getElementById('evalFeedback').value = currentFeedback || '';
        document.getElementById('evalStatus').value = currentStatus || 'Graded';

        const fullSheetBtn = document.getElementById('evalModalFullSheetBtn');
        if (fullSheetBtn) {
            fullSheetBtn.href = 'teacher-answerSheet-page.php?exam_id=' + encodeURIComponent(examId) + '&submission_id=' + encodeURIComponent(submissionId) + '&student_id=' + encodeURIComponent(studentId);
        }

        if (evalModal) {
            evalModal.style.display = 'flex';
        }
    }

    function closeEvaluationModal() {
        if (evalModal) {
            evalModal.style.display = 'none';
        }
    }

    window.openEvaluationModal = openEvaluationModal;
    window.closeEvaluationModal = closeEvaluationModal;

    // Handle Form Submit via AJAX
    if (evalForm) {
        evalForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('evalSaveBtn');
            const originalBtnHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            const formData = new FormData(evalForm);
            formData.append('action', 'update_evaluation');

            try {
                const response = await fetch('teacher-result-page-logic.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();
                if (data.success) {
                    const subId = formData.get('submission_id');
                    const row = document.querySelector(`tr[data-submission-id="${subId}"]`);
                    if (row) {
                        const marks = parseFloat(formData.get('marks_obtained')) || 0;
                        const status = formData.get('status');
                        const totMarks = parseFloat(row.getAttribute('data-total-marks')) || 100;
                        const percentage = totMarks > 0 ? ((marks / totMarks) * 100).toFixed(1) : '0';

                        // Update row attributes
                        row.setAttribute('data-status', status.toLowerCase());
                        
                        // Update Marks cell
                        const marksCell = row.querySelector('.cell-marks-obtained');
                        if (marksCell) marksCell.innerHTML = `<strong>${marks.toFixed(2)}</strong>`;

                        // Update Percentage cell
                        const pctCell = row.querySelector('.cell-percentage');
                        if (pctCell) pctCell.innerHTML = `<strong>${percentage}%</strong>`;

                        // Update Status cell
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            if (status === 'Graded') {
                                statusCell.innerHTML = '<span class="badge badge-graded"><i class="fa-solid fa-circle-check"></i> Graded</span>';
                            } else if (status === 'Under Review') {
                                statusCell.innerHTML = '<span class="badge badge-review"><i class="fa-solid fa-hourglass-half"></i> Under Review</span>';
                            } else {
                                statusCell.innerHTML = '<span class="badge badge-submitted"><i class="fa-solid fa-file-arrow-up"></i> Submitted</span>';
                            }
                        }
                    }

                    closeEvaluationModal();
                    // Alert success briefly
                    const toast = document.createElement('div');
                    toast.className = 'alert-box alert-success';
                    toast.style.position = 'fixed';
                    toast.style.bottom = '24px';
                    toast.style.right = '24px';
                    toast.style.zIndex = '10000';
                    toast.style.boxShadow = '0 8px 24px rgba(0,0,0,0.15)';
                    toast.innerHTML = '<i class="fa-solid fa-circle-check"></i> Evaluation saved successfully.';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3500);
                } else {
                    alert(data.message || 'Failed to save evaluation.');
                }
            } catch (err) {
                console.error(err);
                alert('An error occurred while saving the evaluation.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
        });
    }
});
