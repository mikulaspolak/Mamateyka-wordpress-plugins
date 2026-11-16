(function () {
  const DEFAULT_KEY = 'top-toast-seen';
  const SWIPE_DISTANCE = 80;
  const HIDE_DELAY = 300;
  const initialized = new WeakSet();
  let scheduled = false;

  function isEditorContext() {
    return (
      /[?&]breakdance(_iframe)?=/.test(window.location.search) ||
      window.self !== window.top ||
      (document.body && document.body.classList.contains('breakdance-builder'))
    );
  }

  function init(toast, editor) {
    // The editor re-renders the markup constantly, so the toast is only shown as a static preview there.
    if (editor) {
      toast.classList.add('miki-toast--static');

      return;
    }

    const remember = toast.dataset.remember !== '0';
    const storageKey = toast.dataset.storageKey || DEFAULT_KEY;
    const close = toast.querySelector('.miki-toast__close');
    const button = toast.querySelector('.miki-toast__button');

    // sessionStorage may be unavailable in some browsers or modes
    function markSeen() {
      if (!remember) {
        return;
      }

      try {
        sessionStorage.setItem(storageKey, '1');
      } catch (e) {}
    }

    let alreadySeen = false;
    if (remember) {
      try {
        alreadySeen = sessionStorage.getItem(storageKey) === '1';
      } catch (e) {}
    }

    if (alreadySeen) {
      toast.remove();

      return;
    }

    // Force a style flush so the entrance transition also runs when the script executes early.
    void toast.offsetWidth;
    toast.classList.add('is-visible');

    function hide() {
      markSeen();
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(-50%) translateY(-20px)';
      setTimeout(() => toast.remove(), HIDE_DELAY);
    }

    if (close) {
      close.addEventListener('click', hide);
    }

    if (button) {
      button.addEventListener('click', markSeen);
    }

    let startX = 0;
    let currentX = 0;
    let dragging = false;

    toast.addEventListener('touchstart', (event) => {
      startX = event.touches[0].clientX;
      currentX = startX;
      dragging = true;
      toast.style.transition = 'none';
    }, { passive: true });

    toast.addEventListener('touchmove', (event) => {
      if (!dragging) {
        return;
      }

      currentX = event.touches[0].clientX;
      toast.style.transform = `translateX(calc(-50% + ${currentX - startX}px))`;
    }, { passive: true });

    toast.addEventListener('touchend', () => {
      if (!dragging) {
        return;
      }

      dragging = false;
      const diff = currentX - startX;
      toast.style.transition = '';

      if (Math.abs(diff) > SWIPE_DISTANCE) {
        markSeen();
        toast.style.transform = `translateX(calc(-50% + ${diff > 0 ? 1000 : -1000}px))`;
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), HIDE_DELAY);
      } else {
        toast.style.transform = '';
      }
    });
  }

  function scan() {
    scheduled = false;
    const editor = isEditorContext();

    document.querySelectorAll('[data-miki-toast]').forEach((toast) => {
      if (initialized.has(toast)) {
        return;
      }

      initialized.add(toast);
      init(toast, editor);
    });
  }

  function schedule() {
    if (!scheduled) {
      scheduled = true;
      window.requestAnimationFrame(scan);
    }
  }

  function boot() {
    scan();

    // The editor replaces the element markup after every change.
    if (isEditorContext()) {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
