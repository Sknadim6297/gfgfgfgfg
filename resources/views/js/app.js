import './bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import '../css/notifications.css';
import './wallet-pin';

if (document.querySelector('[data-fcm-controls]')) {
    import('./firebase-push.js');
}

const notifications = document.getElementById('quickpay-notifications');

if (notifications) {
    const payload = JSON.parse(notifications.textContent);
    const validationErrors = payload.errors ?? [];
    const notice = payload.notice;

    if (validationErrors.length) {
        Swal.fire({
            title: 'Please check the form',
            text: validationErrors.join('\n'),
            icon: 'error',
            confirmButtonText: 'OK',
            customClass: { htmlContainer: 'quickpay-alert-text' },
        });
    } else if (notice) {
        if (notice.type === 'success' || notice.type === 'info') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: notice.type,
                title: notice.message,
                showConfirmButton: false,
                timer: 4500,
                timerProgressBar: true,
            });
        } else {
            Swal.fire({
                title: notice.type === 'warning' ? 'Please confirm' : 'Unable to complete request',
                text: notice.message,
                icon: notice.type,
                confirmButtonText: 'OK',
            });
        }
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }

    if (form.dataset.confirmed === 'true') {
        delete form.dataset.confirmed;
        return;
    }

    event.preventDefault();

    Swal.fire({
        title: form.dataset.confirmTitle ?? 'Are you sure?',
        text: form.dataset.confirm,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton ?? 'Continue',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    }).then(({ isConfirmed }) => {
        if (isConfirmed) {
            form.dataset.confirmed = 'true';
            form.requestSubmit();
        }
    });
});

document.querySelectorAll('[data-user-menu-toggle]').forEach((toggle) => {
    const panel = document.getElementById(toggle.getAttribute('aria-controls'));
    const menu = toggle.closest('.user-menu');

    if (!panel || !menu) {
        return;
    }

    const closeMenu = (restoreFocus = false) => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');

        if (restoreFocus) {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        panel.hidden = isOpen;
        toggle.setAttribute('aria-expanded', String(!isOpen));
    });

    toggle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            const items = [...panel.querySelectorAll('[role="menuitem"]')];
            const target = event.key === 'ArrowDown' ? items[0] : items[items.length - 1];
            target?.focus();
        }
    });

    menu.addEventListener('keydown', (event) => {
        const items = [...panel.querySelectorAll('[role="menuitem"]')];
        const currentIndex = items.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            closeMenu(true);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = (currentIndex + direction + items.length) % items.length;
            items[nextIndex]?.focus();
        }
    });

    menu.addEventListener('focusout', () => {
        setTimeout(() => {
            if (!menu.contains(document.activeElement)) {
                closeMenu();
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
            closeMenu();
        }
    });

    panel.querySelectorAll('a[role="menuitem"]').forEach((item) => {
        item.addEventListener('click', () => closeMenu());
    });
});
