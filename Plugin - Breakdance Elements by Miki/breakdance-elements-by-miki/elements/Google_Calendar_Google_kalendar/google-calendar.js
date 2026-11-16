(function () {
  const desktopQuery = window.matchMedia('(min-width: 1024px)');
  const isEditor = /[?&]breakdance(_iframe)?=/.test(window.location.search);
  let scheduled = false;

  // The iframe is loaded only on desktop widths; elsewhere the calendar column is normally hidden.
  function sync() {
    scheduled = false;
    const load = desktopQuery.matches || isEditor;

    document.querySelectorAll('iframe[data-miki-google-calendar]').forEach((frame) => {
      const source = frame.dataset.src || '';

      if (load && source) {
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
    desktopQuery.addEventListener('change', sync);

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
