(function () {
  'use strict';

  // Loaded only in the Breakdance editor. The editor canvas renders the element template in the browser, which cannot
  // read the featured image, so the preview image is fetched from the plugin's AJAX endpoint and put into the frame.
  const config = window.mikiFeaturedImage;

  if (!config) {
    return;
  }

  const FRAME = '.miki-featured-image__clip';
  const IMAGE = '.miki-featured-image__img';
  const MARKER = /^miki-featured-image:([a-z0-9_-]+)$/;
  const cache = new Map();

  function requestedPost() {
    const params = new URLSearchParams(window.location.search);

    for (const [key, value] of params) {
      if (/preview/i.test(key) && /^\d+$/.test(value)) {
        return value;
      }
    }

    const id = params.get('id');

    return id && /^\d+$/.test(id) ? id : '';
  }

  function sizeOf(frame) {
    for (const node of frame.childNodes) {
      if (node.nodeType === 8) {
        const match = MARKER.exec(node.nodeValue.trim());

        if (match) {
          return match[1];
        }
      }
    }

    return 'full';
  }

  function load(size) {
    if (!cache.has(size)) {
      const body = new URLSearchParams({
        action: config.action,
        nonce: config.nonce,
        size: size,
        post: requestedPost(),
      });

      cache.set(
        size,
        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
          .then((response) => response.json())
          .then((json) => (json && json.success && json.data && json.data.html ? json.data.html : ''))
          .catch(() => '')
      );
    }

    return cache.get(size);
  }

  function fill(frame) {
    if (frame.querySelector(IMAGE)) {
      return;
    }

    load(sizeOf(frame)).then((html) => {
      if (html && frame.isConnected && !frame.querySelector(IMAGE)) {
        frame.insertAdjacentHTML('beforeend', html);
      }
    });
  }

  function scan() {
    document.querySelectorAll(FRAME).forEach(fill);
  }

  let frameRequest = 0;

  function schedule() {
    window.cancelAnimationFrame(frameRequest);
    frameRequest = window.requestAnimationFrame(scan);
  }

  function boot() {
    scan();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
