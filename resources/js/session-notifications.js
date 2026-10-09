import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import '../css/notifications.css';
import { showNotification } from './notification-popups';

const notifications = document.getElementById('quickpay-notifications');

if (notifications && notifications.dataset.displayed !== 'true') {
    notifications.dataset.displayed = 'true';
    const payload = JSON.parse(notifications.textContent);
    const validationErrors = payload.errors ?? [];
    const notice = payload.notice;

    if (validationErrors.length) {
        showNotification(Swal, validationErrors.join('\n'), 'error', 'Please check the form');
    } else if (notice) {
        showNotification(Swal, notice.message, notice.type);
    }
}
