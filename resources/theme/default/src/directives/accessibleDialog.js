const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])'
].join(',');

const focusableElements = element => Array.from(element.querySelectorAll(FOCUSABLE_SELECTOR))
  .filter(item => !item.hasAttribute('hidden') && item.getAttribute('aria-hidden') !== 'true');

export default {
  mounted(element, binding) {
    element.__dialogClose = binding.value;
    element.__dialogPreviousFocus = document.activeElement;
    element.setAttribute('role', element.getAttribute('role') || 'dialog');
    element.setAttribute('aria-modal', 'true');
    if (!element.hasAttribute('tabindex')) element.setAttribute('tabindex', '-1');

    element.__dialogKeydown = event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        if (typeof element.__dialogClose === 'function') element.__dialogClose();
        return;
      }

      if (event.key !== 'Tab') return;
      const focusable = focusableElements(element);
      if (!focusable.length) {
        event.preventDefault();
        element.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    element.addEventListener('keydown', element.__dialogKeydown);
    requestAnimationFrame(() => {
      const initialTarget = element.querySelector('[autofocus]') || focusableElements(element)[0] || element;
      initialTarget.focus({ preventScroll: true });
    });
  },
  updated(element, binding) {
    element.__dialogClose = binding.value;
  },
  unmounted(element) {
    element.removeEventListener('keydown', element.__dialogKeydown);
    const previousFocus = element.__dialogPreviousFocus;
    if (previousFocus instanceof HTMLElement && document.contains(previousFocus)) {
      requestAnimationFrame(() => previousFocus.focus({ preventScroll: true }));
    }
    delete element.__dialogClose;
    delete element.__dialogKeydown;
    delete element.__dialogPreviousFocus;
  }
};
