const initializeCanonicalCopy = () => {
  document.querySelectorAll('[data-canonical-copy]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', async () => {
      const url = button.dataset.canonicalCopy;
      const status = button.parentElement.querySelector('[data-canonical-copy-status]');
      let copied = false;
      button.disabled = true;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(url);
          copied = true;
        }
      } catch {
        copied = false;
      }
      if (!copied) {
        const input = document.createElement('textarea');
        input.value = url;
        input.readOnly = true;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        try {
          input.select();
          input.setSelectionRange(0, input.value.length);
          copied = document.execCommand('copy');
        } catch {
          copied = false;
        } finally {
          input.remove();
        }
      }
      button.disabled = false;
      button.focus();
      if (status) {
        status.textContent = copied ? button.dataset.copySuccess : button.dataset.copyError;
      }
    });
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeCanonicalCopy, { once: true });
} else {
  initializeCanonicalCopy();
}
