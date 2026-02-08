const confirmAction = (options) => {
    const {
        title = 'Are you sure?',
        text = 'Please confirm to continue.',
        confirmButtonText = 'Yes, continue',
        cancelButtonText = 'Cancel',
    } = options;

    return Swal.fire({
        title,
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        focusCancel: true,
    });
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const submitter = event.submitter;
    const confirmMessage = submitter?.dataset.confirm || form.dataset.confirm;
    if (!confirmMessage) {
        return;
    }
    event.preventDefault();
    confirmAction({
        title: submitter?.dataset.confirmTitle || form.dataset.confirmTitle || 'Confirm action',
        text: confirmMessage,
        confirmButtonText: submitter?.dataset.confirmButton || form.dataset.confirmButton || 'Yes, continue',
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm-link]');
    if (!trigger) {
        return;
    }
    event.preventDefault();
    const link = trigger.getAttribute('href');
    if (!link) {
        return;
    }
    confirmAction({
        title: trigger.dataset.confirmTitle || 'Confirm navigation',
        text: trigger.dataset.confirmLink || 'Continue to this action?',
        confirmButtonText: trigger.dataset.confirmButton || 'Proceed',
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = link;
        }
    });
});
