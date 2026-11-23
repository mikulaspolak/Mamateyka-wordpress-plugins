(function () {
  const isEditor = /[?&]breakdance(_iframe)?=/.test(window.location.search);
  let scheduled = false;

  function sync() {
    scheduled = false;

    document.querySelectorAll('iframe[data-miki-google-calendar]').forEach((frame) => {
      const source = frame.dataset.src || '';

      if (source) {
        if (frame.getAttribute('src') !== source) {
          frame.setAttribute('src', source);
        }
      } else if (frame.hasAttribute('src')) {
        frame.removeAttribute('src');
      }
    });
  }

  function schedule() {
    if (!scheduled) {
      scheduled = true;
      window.requestAnimationFrame(sync);
    }
  }

  function boot() {
    sync();

    // The editor re-renders the element markup after every change.
    if (isEditor || window.self !== window.top) {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
