export const showNotification = (swal, message, type, title) => {
    const titles = {
        success: 'Success',
        error: 'Unable to complete request',
        warning: 'Please note',
        info: 'Information',
    };

    return swal.fire({
        title: title ?? titles[type],
        text: message,
        icon: type,
        confirmButtonText: 'OK',
        customClass: { htmlContainer: 'quickpay-alert-text' },
    });
};

export const showConfirmation = (swal, { title, text, confirmButtonText = 'Continue', cancelButtonText = 'Cancel' }) =>
    swal.fire({
        title,
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        focusCancel: true,
    });
