(function () {
  const resetTimers = new WeakMap();

  function copyWithSelection(text) {
    const input = document.createElement('textarea');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    document.body.append(input);
    input.select();

    try {
      return document.execCommand('copy');
    } finally {
      input.remove();
    }
  }

  async function copyDocumentLink(button) {
    const link = button.dataset.copyLink;
    if (!link) return;
    const url = new URL(link, window.location.href).href;
    let copied = false;

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
        copied = true;
      }
    } catch {
      // Clipboard API can be unavailable on HTTP or denied by the browser.
    }

    if (!copied) {
      try {
        copied = copyWithSelection(url);
      } catch {
        // Some browsers disable execCommand too.
      }
      button.focus();
    }

    if (!copied) {
      window.prompt('Salin tautan:', url);
      return;
    }

    const label = button.querySelector('.copy-label');
    if (!button.dataset.copyDefaultLabel) {
      button.dataset.copyDefaultLabel = label?.textContent || 'Salin Tautan';
    }
    const originalAriaLabel = button.dataset.copyDefaultAriaLabel || button.getAttribute('aria-label');
    if (originalAriaLabel && !button.dataset.copyDefaultAriaLabel) {
      button.dataset.copyDefaultAriaLabel = originalAriaLabel;
    }
    if (label) label.textContent = 'Tersalin!';
    button.setAttribute('aria-label', 'Tersalin!');
    button.classList.add('is-copied');

    clearTimeout(resetTimers.get(button));
    resetTimers.set(button, setTimeout(() => {
      if (label) label.textContent = button.dataset.copyDefaultLabel;
      if (button.dataset.copyDefaultAriaLabel) {
        button.setAttribute('aria-label', button.dataset.copyDefaultAriaLabel);
      } else {
        button.removeAttribute('aria-label');
      }
      button.classList.remove('is-copied');
    }, 1600));
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-copy-link]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    copyDocumentLink(button);
  });
})();
