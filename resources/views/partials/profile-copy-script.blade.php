<script>
    document.querySelectorAll('[data-copy-value]').forEach((button) => {
        button.addEventListener('click', async () => {
            const feedback = button.querySelector('[data-copy-feedback]');
            try {
                await navigator.clipboard.writeText(button.dataset.copyValue);
                feedback.textContent = 'Copied';
            } catch {
                const input = document.createElement('textarea');
                input.value = button.dataset.copyValue;
                input.setAttribute('readonly', '');
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.appendChild(input);
                input.select();
                const copied = document.execCommand('copy');
                input.remove();
                feedback.textContent = copied ? 'Copied' : 'Copy failed';
            }
            window.setTimeout(() => { if (feedback) feedback.textContent = ''; }, 1800);
        });
    });
</script>
