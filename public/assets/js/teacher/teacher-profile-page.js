/**
 * Dynamic Modal and Profile Editing JS
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
        modalTitle.textContent = 'Edit Personal Information';

        const fields = [
            { label: 'Full Name', name: 'full_name', value: getVal('val-fullname'), type: 'text' },
            { label: 'User Name', name: 'username', value: getVal('val-username'), type: 'text' },
            { label: 'Email', name: 'email', value: getVal('val-email'), type: 'email' },
            { label: 'Teacher ID', name: 'teacher_code', value: getVal('val-teacher'), type: 'text' },
            { label: 'Contact Number', name: 'contact_number', value: getVal('val-contact'), type: 'text' },
            { label: 'Designation', name: 'designation', value: getVal('val-designation'), type: 'text' },
            { label: 'Present Address', name: 'present_address', value: getVal('val-present'), type: 'textarea' },
            { label: 'Permanent Address', name: 'permanent_address', value: getVal('val-permanent'), type: 'textarea' },
            { label: 'Registration Date', name: 'registration_date', value: formatDateForInput(getVal('val-regdate')), type: 'date' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });

    } else if (sectionType === 'education') {
        modalTitle.textContent = 'Edit Education Information';

        const fields = [
            { label: 'Institution', name: 'institution', value: getVal('val-institution'), type: 'text' },
            { label: 'Program / Degree', name: 'program', value: getVal('val-program'), type: 'text' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });

    } else if (sectionType === 'payment') {
        modalTitle.textContent = 'Edit Payment Information';

        const fields = [
            { label: 'Bank Name', name: 'bank_name', value: getVal('val-bank-name'), type: 'text' },
            { label: 'Account Holder Name', name: 'account_name', value: getVal('val-account-name'), type: 'text' },
            { label: 'Account Number', name: 'account_number', value: getVal('val-account-number'), type: 'text' },
            { label: 'Mobile Banking (Bkash/Nagad)', name: 'mobile_banking_number', value: getVal('val-mobile-payment'), type: 'text' }
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

// Clears 'N/A' so text fields open empty when no data is set
function getVal(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return '';
    const txt = el.textContent.trim();
    return (txt === 'N/A' || txt === 'n/a') ? '' : txt;
}

// Format DD/MM/YYYY or YYYY-MM-DD into standard YYYY-MM-DD format for date inputs
function formatDateForInput(dateStr) {
    if (!dateStr || dateStr === 'N/A' || dateStr === 'n/a') return '';
    
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
        return dateStr;
    }

    const parts = dateStr.split('/');
    if (parts.length === 3) {
        return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
    }

    return dateStr;
}

function createInputField(field) {
    const group = document.createElement('div');
    group.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = field.label;

    let input;
    if (field.type === 'textarea') {
        input = document.createElement('textarea');
        input.rows = 3;
    } else {
        input = document.createElement('input');
        input.type = field.type;
    }

    input.name = field.name;
    input.value = field.value;

    group.appendChild(label);
    group.appendChild(input);
    return group;
}

document.addEventListener('DOMContentLoaded', () => {
    const profileLinks = document.querySelectorAll('.nav-link');
    profileLinks.forEach(link => {
        if (link.getAttribute('href') === 'teacher-profile-page.php') {
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