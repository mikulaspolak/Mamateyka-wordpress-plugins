(function () {
  'use strict';

  const STRINGS = {
    en: {
      dialog: 'Image gallery',
      close: 'Close',
      prev: 'Previous image',
      next: 'Next image',
      thumbs: 'Thumbnails',
      image: 'Image {n} of {total}',
      photo: 'Photo {n} of {total}',
    },
    sk: {
      dialog: 'Galéria obrázkov',
      close: 'Zavrieť',
      prev: 'Predchádzajúci obrázok',
      next: 'Nasledujúci obrázok',
      thumbs: 'Miniatúry',
      image: 'Obrázok {n} z {total}',
      photo: 'Fotografia {n} z {total}',
    },
  };
  const FOCUSABLE = 'button:not([disabled]):not([hidden])';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const isEditor = /[?&]breakdance(_iframe)?=/.test(window.location.search);

  function strings() {
    const lang = (document.documentElement.lang || '').toLowerCase();
    return lang.indexOf('sk') === 0 ? STRINGS.sk : STRINGS.en;
  }

  const format = (template, n, total) => template.replace('{n}', String(n)).replace('{total}', String(total));
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  /* Gallery grid: masonry positioning, responsive image sizes, accessible names, lazy fade-in. */
  let revealBatch = 0;
  let revealTimer = 0;

  function reveal(img) {
    img.style.transitionDelay = Math.min(revealBatch, 10) * 45 + 'ms';
    revealBatch += 1;
    window.clearTimeout(revealTimer);
    revealTimer = window.setTimeout(() => {
      revealBatch = 0;
    }, 120);
    img.classList.remove('is-pending');
  }

  function measure(grid) {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;height:0;overflow:hidden';
    const make = (width) => {
      const node = document.createElement('span');
      node.style.cssText = 'display:block;height:0;width:' + width;
      probe.appendChild(node);
      return node;
    };
    const columnGap = make('var(--miki-column-gap, var(--miki-gap-fallback, 16px))');
    const rowGap = make('var(--miki-row-gap, var(--miki-gap-fallback, 16px))');

    grid.appendChild(probe);
    const result = {
      columnGap: columnGap.getBoundingClientRect().width,
      rowGap: rowGap.getBoundingClientRect().width,
    };
    probe.remove();

    return result;
  }

  /* Tiles stay in DOM order (reading and tab order); each goes into the currently shortest column. */
  function layoutMasonry(grid) {
    const width = grid.clientWidth;

    if (!width) {
      return;
    }

    const items = Array.from(grid.children).filter((node) => node.classList.contains('miki-post-gallery__item'));
    const gaps = measure(grid);
    const columns = Math.max(1, parseInt(window.getComputedStyle(grid).getPropertyValue('--miki-cols'), 10) || 3);
    const columnWidth = (width - gaps.columnGap * (columns - 1)) / columns;
    const tops = new Array(columns).fill(0);

    // Class changes are only made when needed so the grid's own mutation observer does not loop.
    if (!grid.classList.contains('is-masonry')) {
      grid.classList.add('is-masonry');
    }

    items.forEach((item) => {
      item.style.width = columnWidth + 'px';
    });

    const heights = items.map((item) => item.offsetHeight);

    items.forEach((item, index) => {
      let column = 0;

      for (let i = 1; i < columns; i += 1) {
        if (tops[i] < tops[column] - 0.5) {
          column = i;
        }
      }

      item.style.left = column * (columnWidth + gaps.columnGap) + 'px';
      item.style.top = tops[column] + 'px';
      tops[column] += heights[index] + gaps.rowGap;
    });

    grid.style.height = Math.max(0, Math.max.apply(null, tops) - gaps.rowGap) + 'px';

    if (!grid.classList.contains('is-ready')) {
      grid.classList.add('is-ready');
    }
  }

  function refineSizes(grid) {
    grid.querySelectorAll('.miki-post-gallery__img').forEach((img) => {
      const width = img.parentElement.getBoundingClientRect().width;

      if (width > 0) {
        img.sizes = Math.round(width) + 'px';
      }
    });
  }

  /* Idempotent: runs again whenever the editor swaps the tiles inside an existing grid. */
  function prepare(grid) {
    const text = strings();
    const links = grid.querySelectorAll('[data-miki-gallery-item]');

    links.forEach((link, index) => {
      link.setAttribute('aria-haspopup', 'dialog');

      if (!link.dataset.alt && !link.dataset.caption) {
        link.setAttribute('aria-label', format(text.photo, index + 1, links.length));
      }
    });

    grid.querySelectorAll('.miki-post-gallery__img').forEach((img) => {
      if (img.dataset.mikiSeen) {
        return;
      }

      img.dataset.mikiSeen = '1';

      if (img.complete || reducedMotion.matches || isEditor) {
        return;
      }

      img.classList.add('is-pending');
      img.addEventListener('load', () => reveal(img), { once: true });
      img.addEventListener('error', () => img.classList.remove('is-pending'), { once: true });
    });
  }

  function initGallery(grid) {
    if (grid.dataset.mikiReady) {
      return;
    }

    grid.dataset.mikiReady = '1';

    const refresh = () => {
      prepare(grid);
      layoutMasonry(grid);
      refineSizes(grid);
    };
    let frame = 0;
    const schedule = () => {
      window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(refresh);
    };

    refresh();

    if (typeof ResizeObserver === 'function') {
      let lastWidth = grid.clientWidth;

      new ResizeObserver(() => {
        if (grid.clientWidth !== lastWidth) {
          lastWidth = grid.clientWidth;
          schedule();
        }
      }).observe(grid);
    }

    // The editor patches the existing grid in place when images are added or removed.
    if (typeof MutationObserver === 'function') {
      new MutationObserver(schedule).observe(grid, { childList: true, attributes: true, attributeFilter: ['class'] });
    }

    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(schedule);
    }
  }

  function scan(root) {
    if (root.matches && root.matches('[data-miki-gallery]')) {
      initGallery(root);
    }

    if (root.querySelectorAll) {
      root.querySelectorAll('[data-miki-gallery]').forEach(initGallery);
    }
  }

  /* Lightbox: built lazily on first open and shared by all galleries; each open gets its own item list. */
  let lightbox = null;
  let state = null;
  let ignorePop = false;

  function createLightbox() {
    const text = strings();
    const root = document.createElement('div');
    root.className = 'miki-post-gallery-lightbox';
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', text.dialog);
    root.innerHTML =
      '<div class="miki-post-gallery-lightbox__backdrop"></div>' +
      '<div class="miki-post-gallery-lightbox__bar">' +
      '<p class="miki-post-gallery-lightbox__counter" aria-live="polite"></p>' +
      '<button type="button" class="miki-post-gallery-lightbox__btn miki-post-gallery-lightbox__close" aria-label="' + text.close + '">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
      '</div>' +
      '<div class="miki-post-gallery-lightbox__stage">' +
      '<img class="miki-post-gallery-lightbox__image" alt="" draggable="false">' +
      '<button type="button" class="miki-post-gallery-lightbox__btn miki-post-gallery-lightbox__nav miki-post-gallery-lightbox__nav--prev" aria-label="' + text.prev + '">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>' +
      '<button type="button" class="miki-post-gallery-lightbox__btn miki-post-gallery-lightbox__nav miki-post-gallery-lightbox__nav--next" aria-label="' + text.next + '">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>' +
      '</div>' +
      '<div class="miki-post-gallery-lightbox__footer">' +
      '<p class="miki-post-gallery-lightbox__caption"></p>' +
      '<div class="miki-post-gallery-lightbox__thumbs" role="group" aria-label="' + text.thumbs + '"></div>' +
      '</div>';

    const q = (selector) => root.querySelector(selector);
    const lb = {
      root,
      stage: q('.miki-post-gallery-lightbox__stage'),
      image: q('.miki-post-gallery-lightbox__image'),
      counter: q('.miki-post-gallery-lightbox__counter'),
      caption: q('.miki-post-gallery-lightbox__caption'),
      thumbs: q('.miki-post-gallery-lightbox__thumbs'),
      closeBtn: q('.miki-post-gallery-lightbox__close'),
      prevBtn: q('.miki-post-gallery-lightbox__nav--prev'),
      nextBtn: q('.miki-post-gallery-lightbox__nav--next'),
    };

    q('.miki-post-gallery-lightbox__backdrop').addEventListener('click', () => {
      if (state && state.closeOnBackdrop) {
        close();
      }
    });
    lb.closeBtn.addEventListener('click', () => close());
    lb.prevBtn.addEventListener('click', () => step(-1));
    lb.nextBtn.addEventListener('click', () => step(1));
    lb.thumbs.addEventListener('click', (event) => {
      const thumb = event.target.closest('[data-index]');

      if (thumb && state) {
        const target = Number(thumb.dataset.index);
        show(target, target > state.index ? 1 : -1);
      }
    });

    bindGestures(lb);
    document.body.appendChild(root);

    return lb;
  }

  const zoom = { scale: 1, x: 0, y: 0, baseW: 0, baseH: 0 };

  function applyTransform() {
    const { image, stage } = lightbox;
    const rect = stage.getBoundingClientRect();

    if (zoom.scale <= 1.001) {
      zoom.scale = 1;
      zoom.x = 0;
      zoom.y = 0;
    } else {
      const maxX = Math.max(0, (zoom.baseW * zoom.scale - rect.width) / 2);
      const maxY = Math.max(0, (zoom.baseH * zoom.scale - rect.height) / 2);
      zoom.x = clamp(zoom.x, -maxX, maxX);
      zoom.y = clamp(zoom.y, -maxY, maxY);
    }

    image.style.transform = 'translate(-50%, -50%) translate3d(' + zoom.x + 'px, ' + zoom.y + 'px, 0) scale(' + zoom.scale + ')';
    stage.classList.toggle('is-zoomed', zoom.scale > 1.01);
  }

  function resetZoom() {
    zoom.scale = 1;
    zoom.x = 0;
    zoom.y = 0;

    if (lightbox) {
      applyTransform();
    }
  }

  /* The lightbox shows a ~1536px version; the original file is only fetched once the user zooms in. */
  function upgradeToFull() {
    const item = state && state.items[state.index];

    if (!item || !item.full || item.full === item.src || item.upgrading || lightbox.image.getAttribute('src') === item.full) {
      return;
    }

    item.upgrading = true;
    const loader = new Image();
    loader.onload = () => {
      item.upgrading = false;

      if (state && state.items[state.index] === item) {
        lightbox.image.src = item.full;
      }
    };
    loader.onerror = () => {
      item.upgrading = false;
    };
    loader.src = item.full;
  }

  function setZoom(next, clientX, clientY) {
    const target = clamp(next, 1, state ? state.maxZoom : 2.5);
    const previous = zoom.scale;

    if (Math.abs(target - previous) < 0.0001) {
      return;
    }

    const rect = lightbox.stage.getBoundingClientRect();
    const originX = clientX == null ? 0 : clientX - rect.left - rect.width / 2;
    const originY = clientY == null ? 0 : clientY - rect.top - rect.height / 2;

    zoom.x = originX - (target / previous) * (originX - zoom.x);
    zoom.y = originY - (target / previous) * (originY - zoom.y);
    zoom.scale = target;
    applyTransform();

    if (target > 1.2) {
      upgradeToFull();
    }
  }

  function toggleZoom(clientX, clientY) {
    if (zoom.scale > 1.01) {
      resetZoom();
    } else {
      setZoom(Math.min(2, state.maxZoom), clientX, clientY);
    }
  }

  /* Size the image to fit the stage (never upscaled) so clicks outside the image hit the stage. */
  function fit() {
    const item = state.items[state.index];
    const { image, stage } = lightbox;
    const rect = stage.getBoundingClientRect();
    const width = item.width || image.naturalWidth;
    const height = item.height || image.naturalHeight;

    if (!width || !height || !rect.width || !rect.height) {
      return;
    }

    const ratio = Math.min(rect.width / width, rect.height / height, 1);
    zoom.baseW = width * ratio;
    zoom.baseH = height * ratio;
    image.style.width = zoom.baseW + 'px';
    image.style.height = zoom.baseH + 'px';
    applyTransform();
  }

  function preload(index) {
    const item = state.items[index];

    if (item && !item.preloaded) {
      item.preloaded = new Image();
      item.preloaded.decoding = 'async';
      item.preloaded.src = item.src;
    }
  }

  function updateChrome() {
    const total = state.items.length;
    const item = state.items[state.index];

    lightbox.counter.textContent = state.showCounter && total > 1 ? state.index + 1 + ' / ' + total : '';
    lightbox.caption.textContent = item.caption;
    lightbox.prevBtn.hidden = total < 2 || (!state.loop && state.index === 0);
    lightbox.nextBtn.hidden = total < 2 || (!state.loop && state.index === total - 1);

    lightbox.thumbs.querySelectorAll('[data-index]').forEach((thumb) => {
      const active = Number(thumb.dataset.index) === state.index;

      if (active) {
        thumb.setAttribute('aria-current', 'true');
        const strip = lightbox.thumbs;
        strip.scrollTo({
          left: thumb.offsetLeft - strip.clientWidth / 2 + thumb.offsetWidth / 2,
          behavior: reducedMotion.matches ? 'auto' : 'smooth',
        });
      } else {
        thumb.removeAttribute('aria-current');
      }
    });

    lightbox.image.alt = item.alt;
  }

  function show(index, direction) {
    const total = state.items.length;
    const next = state.loop ? (index + total) % total : index;

    if (next < 0 || next >= total) {
      return;
    }

    state.index = next;
    state.loadToken += 1;
    const token = state.loadToken;
    const item = state.items[next];
    const { image } = lightbox;

    resetZoom();
    image.style.translate = '';
    image.style.width = '';
    image.style.height = '';
    image.src = item.placeholder || item.thumb || item.src;
    fit();
    updateChrome();

    if (!reducedMotion.matches && typeof image.animate === 'function') {
      const slide = state.transition === 'slide' && direction !== 0;
      image.animate(
        direction === 0
          ? [{ opacity: 0, scale: '0.97' }, { opacity: 1, scale: '1' }]
          : [
              { opacity: 0, translate: slide ? direction * 48 + 'px 0' : '0 0' },
              { opacity: 1, translate: '0 0' },
            ],
        { duration: slide ? 260 : 220, easing: 'ease-out' }
      );
    }

    const loader = new Image();
    const done = () => {
      if (!state || token !== state.loadToken) {
        return;
      }

      if (image.getAttribute('src') !== item.src && loader.naturalWidth) {
        image.src = item.src;
      }

      fit();
      preload(next + 1 < total ? next + 1 : state.loop ? 0 : -1);
      preload(next - 1 >= 0 ? next - 1 : state.loop ? total - 1 : -1);
    };

    loader.onload = done;
    loader.onerror = done;
    loader.src = item.src;
  }

  function step(delta) {
    if (!state || zoom.scale > 1.01) {
      return;
    }

    const total = state.items.length;
    const target = state.index + delta;

    if (total < 2 || (!state.loop && (target < 0 || target >= total))) {
      return;
    }

    show(target, delta);
  }

  function buildThumbs() {
    const strip = lightbox.thumbs;
    const text = strings();
    strip.textContent = '';

    if (!state.showThumbs || state.items.length < 2) {
      return;
    }

    const fragment = document.createDocumentFragment();

    state.items.forEach((item, index) => {
      const button = document.createElement('button');
      const img = document.createElement('img');

      button.type = 'button';
      button.className = 'miki-post-gallery-lightbox__thumb';
      button.dataset.index = String(index);
      button.setAttribute('aria-label', format(text.image, index + 1, state.items.length));
      img.src = item.thumb || item.src;
      img.alt = '';
      img.loading = 'lazy';
      img.decoding = 'async';
      button.appendChild(img);
      fragment.appendChild(button);
    });

    strip.appendChild(fragment);
  }

  function setPageLock(locked) {
    const body = document.body;

    if (locked) {
      const scrollbar = window.innerWidth - document.documentElement.clientWidth;
      state.previousOverflow = body.style.overflow;
      state.previousPadding = body.style.paddingRight;
      body.style.overflow = 'hidden';

      if (scrollbar > 0) {
        body.style.paddingRight = scrollbar + 'px';
      }
    } else {
      body.style.overflow = state.previousOverflow;
      body.style.paddingRight = state.previousPadding;
    }
  }

  function open(grid, startIndex, opener, viaPointer) {
    const links = Array.from(grid.querySelectorAll('[data-miki-gallery-item]'));

    if (!links.length) {
      return;
    }

    if (!lightbox) {
      lightbox = createLightbox();
    }

    if (state) {
      return;
    }

    const items = links.map((link) => {
      const img = link.querySelector('img');
      const full = link.dataset.full || link.href;

      return {
        full,
        src: link.dataset.src || full,
        thumb: link.dataset.thumb || '',
        placeholder: img ? img.currentSrc || img.src : '',
        width: Number(link.dataset.width) || 0,
        height: Number(link.dataset.height) || 0,
        alt: link.dataset.alt || '',
        caption: link.dataset.caption || '',
        preloaded: null,
        upgrading: false,
      };
    });

    state = {
      grid,
      links,
      opener,
      viaPointer,
      items,
      index: 0,
      loadToken: 0,
      closing: null,
      pushed: false,
      loop: false,
      showCounter: true,
      showThumbs: true,
      closeOnBackdrop: true,
      transition: 'fade',
      maxZoom: 2.5,
      inerted: [],
      previousOverflow: '',
      previousPadding: '',
    };

    Array.from(document.body.children).forEach((child) => {
      if (child !== lightbox.root && !child.inert) {
        child.inert = true;
        state.inerted.push(child);
      }
    });

    // One history entry lets the phone's back button close the lightbox instead of leaving the page.
    try {
      window.history.pushState({ mikiGallery: true }, '');
      state.pushed = true;
    } catch (error) {
      state.pushed = false;
    }

    setPageLock(true);
    lightbox.root.classList.remove('is-chrome-hidden');
    lightbox.root.hidden = false;
    buildThumbs();
    show(clamp(startIndex, 0, items.length - 1), 0);
    window.requestAnimationFrame(() => lightbox.root.classList.add('is-open'));
    lightbox.closeBtn.focus({ preventScroll: true });
  }

  function close() {
    if (!state || state.closing) {
      return;
    }

    const current = state;

    if (current.pushed) {
      current.pushed = false;
      ignorePop = true;
      window.history.back();
    }

    const finish = () => {
      lightbox.root.hidden = true;
      lightbox.image.removeAttribute('src');
      lightbox.image.style.width = '';
      lightbox.image.style.height = '';
      current.inerted.forEach((node) => {
        node.inert = false;
      });
      setPageLock(false);
      state = null;
      resetZoom();

      const target = current.links[current.index];
      const focusTarget = target && !target.closest('[hidden]') ? target : current.opener;

      if (focusTarget && focusTarget.isConnected) {
        if (current.viaPointer) {
          focusTarget.dataset.mikiPointer = '1';
          focusTarget.addEventListener('blur', () => delete focusTarget.dataset.mikiPointer, { once: true });
        }

        focusTarget.focus({ preventScroll: true });
      }
    };

    lightbox.root.classList.remove('is-open');
    current.loadToken += 1;

    if (reducedMotion.matches) {
      finish();
    } else {
      current.closing = window.setTimeout(finish, 190);
    }
  }

  function bindGestures(lb) {
    const { stage, image } = lb;
    const pointers = new Map();
    let gesture = null;
    let lastTap = { time: 0, x: 0, y: 0 };
    let lastPointerType = 'mouse';
    let tapTimer = 0;

    const distance = () => {
      const [a, b] = Array.from(pointers.values());
      return Math.hypot(a.x - b.x, a.y - b.y);
    };
    const midpoint = () => {
      const [a, b] = Array.from(pointers.values());
      return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    };

    stage.addEventListener('pointerdown', (event) => {
      if (!state || event.target.closest('button') || (event.pointerType === 'mouse' && event.button !== 0)) {
        return;
      }

      lastPointerType = event.pointerType;

      try {
        stage.setPointerCapture(event.pointerId);
      } catch (error) {
        // Capture is only an optimisation for drags leaving the stage.
      }

      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

      if (pointers.size === 1) {
        gesture = {
          startX: event.clientX,
          startY: event.clientY,
          baseX: zoom.x,
          baseY: zoom.y,
          moved: false,
          multi: false,
          axis: '',
          onImage: event.target === image,
        };
      } else if (pointers.size === 2 && gesture) {
        gesture.multi = true;
        gesture.moved = true;
        gesture.pinchDistance = distance();
        gesture.pinchScale = zoom.scale;
        image.style.translate = '';
        stage.classList.add('is-dragging');
      }
    });

    stage.addEventListener('pointermove', (event) => {
      if (!gesture || !pointers.has(event.pointerId)) {
        return;
      }

      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

      if (pointers.size >= 2 && gesture.pinchDistance) {
        const center = midpoint();
        setZoom(gesture.pinchScale * (distance() / gesture.pinchDistance), center.x, center.y);
        return;
      }

      const dx = event.clientX - gesture.startX;
      const dy = event.clientY - gesture.startY;

      if (!gesture.moved && Math.hypot(dx, dy) > 6) {
        gesture.moved = true;
        gesture.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
      }

      if (!gesture.moved || gesture.multi) {
        return;
      }

      if (zoom.scale > 1.01) {
        stage.classList.add('is-dragging');
        zoom.x = gesture.baseX + dx;
        zoom.y = gesture.baseY + dy;
        applyTransform();
      } else if (event.pointerType !== 'mouse') {
        // The image follows the finger so a swipe feels direct.
        image.style.transition = 'none';
        image.style.translate = gesture.axis === 'x' ? dx + 'px 0' : '0 ' + Math.max(0, dy) + 'px';
      }
    });

    const release = (event, cancelled) => {
      if (!pointers.has(event.pointerId)) {
        return;
      }

      pointers.delete(event.pointerId);

      if (pointers.size > 0) {
        // A finger was lifted during a pinch: continue panning from the remaining finger without a jump.
        if (gesture) {
          const [rest] = Array.from(pointers.values());
          gesture.startX = rest.x;
          gesture.startY = rest.y;
          gesture.baseX = zoom.x;
          gesture.baseY = zoom.y;
          gesture.pinchDistance = 0;
        }

        return;
      }

      stage.classList.remove('is-dragging');
      const current = gesture;
      gesture = null;
      image.style.transition = '';
      image.style.translate = '';

      if (!current || cancelled || !state) {
        return;
      }

      const dx = event.clientX - current.startX;
      const dy = event.clientY - current.startY;

      if (!current.moved) {
        if (event.pointerType !== 'mouse') {
          const now = Date.now();

          if (now - lastTap.time < 300 && Math.hypot(event.clientX - lastTap.x, event.clientY - lastTap.y) < 30 && current.onImage) {
            lastTap.time = 0;
            window.clearTimeout(tapTimer);
            toggleZoom(event.clientX, event.clientY);
            return;
          }

          lastTap = { time: now, x: event.clientX, y: event.clientY };

          if (current.onImage) {
            // A single tap on the photo shows or hides the controls (after waiting for a possible double tap).
            window.clearTimeout(tapTimer);
            tapTimer = window.setTimeout(() => {
              if (state) {
                lightbox.root.classList.toggle('is-chrome-hidden');
              }
            }, 260);
          }
        }

        if (!current.onImage && state.closeOnBackdrop) {
          close();
        }

        return;
      }

      if (current.multi || zoom.scale > 1.01) {
        return;
      }

      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.2) {
        step(dx < 0 ? 1 : -1);
      } else if (dy > 100 && dy > Math.abs(dx) * 1.5) {
        close();
      }
    };

    stage.addEventListener('pointerup', (event) => release(event, false));
    stage.addEventListener('pointercancel', (event) => release(event, true));

    // Pointer capture retargets click events to the stage, so the image hit-test is done by coordinates.
    stage.addEventListener('dblclick', (event) => {
      if (!state || lastPointerType !== 'mouse') {
        return;
      }

      const rect = image.getBoundingClientRect();

      if (event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom) {
        event.preventDefault();
        toggleZoom(event.clientX, event.clientY);
      }
    });

    stage.addEventListener(
      'wheel',
      (event) => {
        if (!state) {
          return;
        }

        event.preventDefault();
        setZoom(zoom.scale * Math.exp(-event.deltaY * 0.0015), event.clientX, event.clientY);
      },
      { passive: false }
    );
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const link = target ? target.closest('[data-miki-gallery-item]') : null;

    if (!link) {
      return;
    }

    const grid = link.closest('[data-miki-gallery]');

    if (!grid) {
      return;
    }

    if (isEditor) {
      event.preventDefault();
      return;
    }

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    event.preventDefault();
    open(grid, Array.from(grid.querySelectorAll('[data-miki-gallery-item]')).indexOf(link), link, event.detail > 0);
  });

  window.addEventListener('popstate', () => {
    if (ignorePop) {
      ignorePop = false;
      return;
    }

    if (state) {
      state.pushed = false;
      close();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!state) {
      return;
    }

    lightbox.root.classList.remove('is-chrome-hidden');

    switch (event.key) {
      case 'Escape':
        event.preventDefault();

        if (zoom.scale > 1.01) {
          resetZoom();
        } else {
          close();
        }

        break;
      case 'ArrowLeft':
        step(-1);
        break;
      case 'ArrowRight':
        step(1);
        break;
      case 'Home':
        if (zoom.scale <= 1.01) {
          event.preventDefault();
          show(0, -1);
        }

        break;
      case 'End':
        if (zoom.scale <= 1.01) {
          event.preventDefault();
          show(state.items.length - 1, 1);
        }

        break;
      case '+':
      case '=':
        setZoom(zoom.scale + 0.5);
        break;
      case '-':
        setZoom(zoom.scale - 0.5);
        break;
      case '0':
        resetZoom();
        break;
      case 'Tab': {
        const focusable = Array.from(lightbox.root.querySelectorAll(FOCUSABLE));
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (!first) {
          event.preventDefault();
        } else if (event.shiftKey && (document.activeElement === first || !lightbox.root.contains(document.activeElement))) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !lightbox.root.contains(document.activeElement))) {
          event.preventDefault();
          first.focus();
        }

        break;
      }
      default:
    }
  });

  window.addEventListener('resize', () => {
    if (state) {
      fit();
    }
  });

  function boot() {
    scan(document);

    if (isEditor || window.self !== window.top || document.body.classList.contains('breakdance-builder')) {
      new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
              scan(node);
            }
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
