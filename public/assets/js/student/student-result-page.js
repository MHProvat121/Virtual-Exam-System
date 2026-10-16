/**
 * student-result-page.js
 * Client-side script for student-result-page.php:
 * 1. Controls-card status tabs filtering (All, Graded, Under Review, Submitted, Not Taken)
 * 2. Multi-keyword search across batch names, course codes, subjects, titles, and instructors
 * 3. Quick feedback & evaluation detail modal handling
 */

var currentResultStatusFilter = "all";

function filterResultStatus(status, tabElement) {
    currentResultStatusFilter = (status || "all").toLowerCase().trim();

    var container = document.getElementById("resultStatusTabs");
    if (container) {
        var tabs = container.querySelectorAll(".tab-link");
        tabs.forEach(function(t) {
            t.classList.remove("active");
        });
    }

    if (tabElement) {
        tabElement.classList.add("active");
    } else if (container) {
        var matchingTab = container.querySelector('.tab-link[data-status="' + currentResultStatusFilter + '"]');
        if (matchingTab) {
            matchingTab.classList.add("active");
        }
    }

    applyResultFilters();
}

function filterResultBatches() {
    applyResultFilters();
}

function applyResultFilters() {
    var searchInput = document.getElementById("resultBatchSearch");
    var filter = searchInput ? searchInput.value.toLowerCase().trim() : "";
    var batchCards = document.querySelectorAll(".batch-card");
    var visibleCardCount = 0;

    batchCards.forEach(function(card) {
        var batchName = (card.getAttribute("data-batch") || "").toLowerCase();
        var cardText = card.innerText.toLowerCase();
        var rows = card.querySelectorAll("tbody tr.student-result-row, tbody tr.exam-row");

        var cardMatchesSearch = !filter || cardText.indexOf(filter) > -1 || batchName.indexOf(filter) > -1;

        if (!cardMatchesSearch) {
            card.style.display = "none";
            return;
        }

        var visibleRowsCount = 0;

        if (rows.length > 0) {
            rows.forEach(function(row) {
                var rowStatusAttr = (row.getAttribute("data-status") || "").toLowerCase().trim();
                var statusCell = row.querySelector(".status-cell");
                var badgeText = statusCell ? statusCell.textContent.toLowerCase().trim() : "";

                // Status matching logic
                var matchesStatus = false;
                if (currentResultStatusFilter === "all") {
                    matchesStatus = true;
                } else if (currentResultStatusFilter === "graded") {
                    matchesStatus = (rowStatusAttr === "graded" || badgeText.indexOf("graded") > -1);
                } else if (currentResultStatusFilter === "under review" || currentResultStatusFilter === "review") {
                    matchesStatus = (rowStatusAttr === "under review" || rowStatusAttr === "review" || badgeText.indexOf("review") > -1);
                } else if (currentResultStatusFilter === "submitted") {
                    matchesStatus = (rowStatusAttr === "submitted" || badgeText.indexOf("submitted") > -1);
                } else if (currentResultStatusFilter === "not taken" || currentResultStatusFilter === "nottaken") {
                    matchesStatus = (rowStatusAttr === "not taken" || rowStatusAttr === "nottaken" || rowStatusAttr === "" || badgeText.indexOf("not taken") > -1);
                } else {
                    matchesStatus = (rowStatusAttr.indexOf(currentResultStatusFilter) > -1 || badgeText.indexOf(currentResultStatusFilter) > -1);
                }

                // Row search matching logic
                var textContent = row.textContent.toLowerCase();
                var matchesSearch = !filter || textContent.indexOf(filter) > -1 || batchName.indexOf(filter) > -1;

                if (matchesStatus && matchesSearch) {
                    row.style.display = "";
                    visibleRowsCount++;
                } else {
                    row.style.display = "none";
                }
            });

            if (visibleRowsCount > 0) {
                card.style.display = "";
                visibleCardCount++;
            } else {
                card.style.display = "none";
            }
        } else {
            // Empty batch card with no rows
            if (currentResultStatusFilter === "all" && cardMatchesSearch) {
                card.style.display = "";
                visibleCardCount++;
            } else {
                card.style.display = "none";
            }
        }
    });

    var noResultsMsg = document.getElementById("searchNoResultsMessage");
    if (noResultsMsg) {
        noResultsMsg.style.display = (visibleCardCount === 0 && batchCards.length > 0) ? "block" : "none";
    }
}

// -------------------------------------------------------------
// FEEDBACK MODAL HANDLING
// -------------------------------------------------------------
function showFeedbackModal(title, evaluator, feedback, grade, marks) {
    var examTitleEl = document.getElementById("modalExamTitle");
    if (examTitleEl) {
        examTitleEl.innerHTML = '<i class="fa-solid fa-comments" style="color: #0266cc; margin-right: 6px;"></i> ' + (title || "Teacher Feedback");
    }

    var evaluatorEl = document.getElementById("modalEvaluator");
    if (evaluatorEl) {
        evaluatorEl.textContent = evaluator || "Instructor";
    }

    var feedbackEl = document.getElementById("modalFeedbackContent");
    if (feedbackEl) {
        feedbackEl.textContent = (feedback && feedback.trim()) ? feedback : "No remarks recorded for this evaluation.";
    }

    var gradeEl = document.getElementById("modalGrade");
    if (gradeEl) {
        gradeEl.textContent = grade || "N/A";
    }

    var marksEl = document.getElementById("modalMarksObtained");
    if (marksEl) {
        marksEl.textContent = marks || "-";
    }

    var modal = document.getElementById("feedbackModalBackdrop");
    if (modal) {
        modal.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
}

function closeFeedbackModal() {
    var modal = document.getElementById("feedbackModalBackdrop");
    if (modal) {
        modal.style.display = "none";
        document.body.style.overflow = "";
    }
}

// Attach all functions to window for immediate inline attribute accessibility
window.filterResultStatus = filterResultStatus;
window.filterResultBatches = filterResultBatches;
window.applyResultFilters = applyResultFilters;
window.showFeedbackModal = showFeedbackModal;
window.closeFeedbackModal = closeFeedbackModal;

// -------------------------------------------------------------
// INITIALIZATION
// -------------------------------------------------------------
document.addEventListener("DOMContentLoaded", function() {
    // Search input event listeners
    var searchInput = document.getElementById("resultBatchSearch");
    if (searchInput) {
        searchInput.addEventListener("input", filterResultBatches);
        searchInput.addEventListener("keyup", filterResultBatches);
    }

    // Modal close when clicking backdrop
    var modalBackdrop = document.getElementById("feedbackModalBackdrop");
    if (modalBackdrop) {
        modalBackdrop.addEventListener("click", function(e) {
            if (e.target === modalBackdrop) {
                closeFeedbackModal();
            }
        });
    }

    // Close modal on Escape key press
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" || e.keyCode === 27) {
            closeFeedbackModal();
        }
    });

    // Initial filter application
    applyResultFilters();
});
