export function enhanceAgentAccess(root = document) {
    root.querySelector('[data-reveal-key]')?.addEventListener('click', event => {
        const button = event.currentTarget;
        const input = root.getElementById(button.getAttribute('aria-controls'));
        const revealed = input.type === 'password';
        input.type = revealed ? 'text' : 'password';
        button.textContent = revealed ? 'Hide key' : 'Show key';
        button.setAttribute('aria-pressed', String(revealed));
    });

    root.querySelectorAll('[data-copy-field]').forEach(button => button.addEventListener('click', async () => {
        const input = root.getElementById(button.dataset.copyField);
        const status = root.querySelector('[data-copy-status]');
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Copied.';
        } catch {
            input.type = 'text';
            input.focus();
            input.select();
            const reveal = root.querySelector('[data-reveal-key]');
            if (reveal && reveal.getAttribute('aria-controls') === input.id) {
                reveal.textContent = 'Hide key';
                reveal.setAttribute('aria-pressed', 'true');
            }
            status.textContent = 'Copy failed. The value is selected; copy it manually.';
        }
    }));

    root.querySelectorAll('[data-copy-text]').forEach(button => button.addEventListener('click', async () => {
        const status = button.closest('.project-canonical')?.querySelector('[data-copy-status]') ?? root.querySelector('[data-copy-status]');
        try {
            await navigator.clipboard.writeText(button.dataset.copyText);
            button.classList.add('copied');
            if (status) status.textContent = 'Copied.';
            setTimeout(() => { button.classList.remove('copied'); if (status) status.textContent = ''; }, 1600);
        } catch {
            if (status) status.textContent = `Copy failed. The code is ${button.dataset.copyText}.`;
        }
    }));

    root.querySelectorAll('[data-confirm-action]').forEach(form => form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirmAction)) {
            event.preventDefault();
            return;
        }
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Saving…';
    }));
}
