import './bootstrap';
import Swal from 'sweetalert2';
import { showConfirmation } from './notification-popups';
import './session-notifications';
import './wallet-pin';

if (document.querySelector('[data-fcm-controls]')) {
    import('./firebase-push.js');
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

    showConfirmation(Swal, {
        title: form.dataset.confirmTitle ?? 'Are you sure?',
        text: form.dataset.confirm,
        confirmButtonText: form.dataset.confirmButton ?? 'Continue',
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
