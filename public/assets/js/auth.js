function togglePassword(inputId = 'password', iconId = 'passwordIcon') {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);

    if (!input || !icon) {
        return;
    }

    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('bi-eye-fill', input.type === 'password');
    icon.classList.toggle('bi-eye-slash-fill', input.type === 'text');
}
