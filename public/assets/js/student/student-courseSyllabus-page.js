/**
 * teacher-courseSyllabus-page.js
 */
let deleteTimerInterval = null;
let deleteBatchTimerInterval = null;

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'none';
    
    if (modalId === 'deleteTableModal' && deleteTimerInterval) {
        clearInterval(deleteTimerInterval);
        document.getElementById('confirmDeleteTableBtn').disabled = true;
    }
    
    if (modalId === 'deleteBatchModal' && deleteBatchTimerInterval) {
        clearInterval(deleteBatchTimerInterval);
        document.getElementById('confirmDeleteBatchBtn').disabled = true;
    }
}

function openCreateCourseModal() {
    document.getElementById('createCourseModal').style.display = 'block';
}

function openAddSubjectModal(batchName) {
    document.getElementById('addSubjectBatchInput').value = batchName;
    document.getElementById('addSubjectBatchDisplay').value = batchName;
    document.getElementById('addSubjectModal').style.display = 'block';
}

function openEditModal(examData) {
    document.getElementById('editExamId').value = examData.id;
    document.getElementById('editBatch').value = examData.batch;
    document.getElementById('editSubject').value = examData.subject;
    document.getElementById('editExamTitle').value = examData.exam_title;
    document.getElementById('editModal').style.display = 'block';
}

function openDeleteTableModal(batchName) {
    const modal = document.getElementById('deleteTableModal');
    const confirmBtn = document.getElementById('confirmDeleteTableBtn');
    document.getElementById('deleteBatchInput').value = batchName;
    document.getElementById('deleteBatchNameSpan').innerText = batchName;

    modal.style.display = 'block';
    confirmBtn.disabled = true;
    let countdown = 3;
    confirmBtn.innerText = `Clear (${countdown}s)`;

    if (deleteTimerInterval) clearInterval(deleteTimerInterval);
    deleteTimerInterval = setInterval(() => {
        countdown--;
        if (countdown > 0) {
            confirmBtn.innerText = `Clear (${countdown}s)`;
        } else {
            clearInterval(deleteTimerInterval);
            confirmBtn.disabled = false;
            confirmBtn.innerText = 'Confirm Clear Table';
        }
    }, 1000);
}

function openDeleteBatchModal(batchName) {
    const modal = document.getElementById('deleteBatchModal');
    const confirmBtn = document.getElementById('confirmDeleteBatchBtn');
    document.getElementById('deleteBatchNameInput').value = batchName;
    document.getElementById('deleteBatchNameDisplay').innerText = batchName;

    modal.style.display = 'block';
    confirmBtn.disabled = true;
    let countdown = 3;
    confirmBtn.innerText = `Delete (${countdown}s)`;

    if (deleteBatchTimerInterval) clearInterval(deleteBatchTimerInterval);
    deleteBatchTimerInterval = setInterval(() => {
        countdown--;
        if (countdown > 0) {
            confirmBtn.innerText = `Delete (${countdown}s)`;
        } else {
            clearInterval(deleteBatchTimerInterval);
            confirmBtn.disabled = false;
            confirmBtn.innerText = 'Delete';
        }
    }, 1000);
}

// Close on outside click
window.onclick = (event) => {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        if (deleteTimerInterval) clearInterval(deleteTimerInterval);
        if (deleteBatchTimerInterval) clearInterval(deleteBatchTimerInterval);
    }
};

/**
 * student-courseSyllabus-page.js
 * Supporting client-side interactions:
 * Feature 1: Live search filtering
 * Feature 2: Pinning tables to top
 * Feature 3: Archiving tables
 * Feature 4: Toggling archived courses view
 * Feature 5: In archive section: Restore and Pin only
 */

// Feature 1: Real-time search filter for courses & batches
function filterCourseBatches() {
    const input = document.getElementById('courseBatchSearch');
    if (!input) return;
    const term = input.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.batch-card');

    let visibleCount = 0;
    cards.forEach(card => {
        const cardText = card.innerText.toLowerCase();
        if (cardText.includes(term)) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const emptyContainer = document.getElementById('searchNoResultsMessage');
    if (emptyContainer) {
        emptyContainer.style.display = visibleCount === 0 && cards.length > 0 ? 'block' : 'none';
    }
}

