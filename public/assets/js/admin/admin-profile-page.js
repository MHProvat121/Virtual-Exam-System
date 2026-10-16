/**
 * Dynamic Modal and Profile Editing JS for Admin Portal
 */

function openEditModal(sectionType) {
    const modal = document.getElementById('editDataModal');
    const modalTitle = document.getElementById('modalTitle');
    const inputsContainer = document.getElementById('modalInputsContainer');
    const modalSectionInput = document.getElementById('modalSectionInput');

    if (!modal || !inputsContainer) return;

    modalSectionInput.value = sectionType;
    inputsContainer.innerHTML = '';

    if (sectionType === 'personal') {
        modalTitle.textContent = 'Edit Administrative Profile';

        const fields = [
            { label: 'Full Name', name: 'full_name', value: getVal('val-fullname'), type: 'text' },
            { label: 'Email', name: 'email', value: getVal('val-email'), type: 'email' },
            { label: 'Admin ID', name: 'admin_code', value: getVal('val-admin-id'), type: 'text' },
            { label: 'Contact Number', name: 'contact_number', value: getVal('val-contact'), type: 'text' },
            { label: 'Designation', name: 'designation', value: getVal('val-designation'), type: 'text' },
            { label: 'Department / Office', name: 'department', value: getVal('val-department'), type: 'text' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });
    }

    modal.classList.add('active');
    modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('editDataModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function getVal(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return '';
    const txt = el.textContent.trim();
    return (txt === 'N/A' || txt === 'n/a') ? '' : txt;
}

function createInputField(field) {
    const group = document.createElement('div');
    group.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = field.label;

    let input = document.createElement('input');
    input.type = field.type;
    input.name = field.name;
    input.value = field.value;

    group.appendChild(label);
    group.appendChild(input);
    return group;
}

document.addEventListener('DOMContentLoaded', () => {
    // Active Navigation Highlighting
    const profileLinks = document.querySelectorAll('.nav-link');
    profileLinks.forEach(link => {
        if (link.getAttribute('href') === 'admin-profile-page.php') {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });

    const avatarTrigger = document.getElementById('trigger-avatar-upload');
    const avatarInput = document.getElementById('avatar-file-input');

    if (avatarTrigger && avatarInput) {
        avatarTrigger.addEventListener('click', () => {
            avatarInput.click();
        });
    }

    window.addEventListener('click', (event) => {
        const modal = document.getElementById('editDataModal');
        if (event.target === modal) {
            closeEditModal();
        }
    });
});