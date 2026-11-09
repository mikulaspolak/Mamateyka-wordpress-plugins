(function () {
  const mobileQuery = window.matchMedia('(max-width: 767px)');
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  const expandedClass = 'miki-memories__card--expanded';
  const popupClass = 'miki-memories__card--popup';
  const modalGridClass = 'miki-memories--modal-open';
  const backdropVisibleClass = 'miki-memories__backdrop--visible';
  let activePopup = null;
  let dialogHeadingSequence = 0;

  function setExpanded(card, expanded) {
    const button = card.querySelector('.miki-memories__toggle');
    const body = card.querySelector('.miki-memories__body');
    const icon = button?.querySelector('.miki-memories__toggle-icon');

    card.classList.toggle(expandedClass, expanded);

    if (button) {
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      const label = expanded ? button.dataset.closeLabel : button.dataset.openLabel;

      if (label) {
        button.setAttribute('aria-label', label);
      }
    }

    if (icon) {
      icon.textContent = expanded ? '−' : '+';
    }

    if (body) {
      if (mobileQuery.matches) {
        body.setAttribute('aria-hidden', expanded ? 'false' : 'true');
        body.inert = !expanded;
      } else {
        body.removeAttribute('aria-hidden');
        body.inert = false;
      }
    }
  }

  function initializeCardTilts(grid) {
    if (!grid.classList.contains('miki-memories__grid--tilt')) {
      return;
    }

    grid.querySelectorAll('[data-miki-memory-card]').forEach((card, index) => {
      if (card.style.getPropertyValue('--miki-rot')) {
        return;
      }

      const heading = card.querySelector('.miki-memories__heading')?.textContent.trim() || '';
      const seed = `${heading}:${index}`;
      let hash = 2166136261;

      for (let charIndex = 0; charIndex < seed.length; charIndex += 1) {
        hash = Math.imul(hash ^ seed.charCodeAt(charIndex), 16777619);
      }

      const normalized = (hash >>> 0) / 4294967295;
      const degrees = (normalized * 2 - 1) * 1.6;
      card.style.setProperty('--miki-rot', `${degrees.toFixed(2)}deg`);
    });
  }

  function transformFromRect(rect, targetRect) {
    const deltaX = rect.left + rect.width / 2 - (targetRect.left + targetRect.width / 2);
    const deltaY = rect.top + rect.height / 2 - (targetRect.top + targetRect.height / 2);
    const scaleX = rect.width / targetRect.width;
    const scaleY = rect.height / targetRect.height;

    return `translate(calc(-50% + ${deltaX}px), calc(-50% + ${deltaY}px)) scale(${scaleX}, ${scaleY})`;
  }

  function animatePopupOpen(state, sourceRect) {
    if (reducedMotionQuery.matches || typeof Element.prototype.animate !== 'function') {
      return;
    }

    const targetRect = state.card.getBoundingClientRect();
    const animation = state.card.animate(
      [
        { transform: transformFromRect(sourceRect, targetRect), opacity: 0.9 },
        { transform: 'translate(-50%, -50%) scale(1)', opacity: 1 },
      ],
      {
        duration: 640,
        easing: 'cubic-bezier(0.18, 0.84, 0.22, 1)',
        fill: 'both',
      }
    );

    state.openAnimation = animation;
    animation.onfinish = () => {
      if (state.openAnimation === animation) {
        state.openAnimation = null;
        animation.cancel();
      }
    };
  }

  function getPageScrollLock() {
    const body = document.body;
    const root = document.documentElement;
    const state = {
      x: window.scrollX,
      y: window.scrollY,
      bodyPosition: body.style.position,
      bodyTop: body.style.top,
      bodyLeft: body.style.left,
      bodyRight: body.style.right,
      bodyWidth: body.style.width,
      bodyOverflow: body.style.overflow,
      rootOverflow: root.style.overflow,
      rootScrollBehavior: root.style.scrollBehavior,
    };

    body.style.position = 'fixed';
    body.style.top = `-${state.y}px`;
    body.style.left = `-${state.x}px`;
    body.style.right = '0';
    body.style.width = '100%';
    body.style.overflow = 'hidden';
    root.style.overflow = 'hidden';

    return state;
  }

  function restorePageScroll(state) {
    const body = document.body;
    const root = document.documentElement;

    body.style.position = state.bodyPosition;
    body.style.top = state.bodyTop;
    body.style.left = state.bodyLeft;
    body.style.right = state.bodyRight;
    body.style.width = state.bodyWidth;
    body.style.overflow = state.bodyOverflow;
    root.style.overflow = state.rootOverflow;
    root.style.scrollBehavior = 'auto';
    window.scrollTo(state.x, state.y);
    root.style.scrollBehavior = state.rootScrollBehavior;
  }

  function setDialogSemantics(state) {
    const { card, trigger } = state;
    const heading = card.querySelector('.miki-memories__heading');

    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');

    if (heading) {
      if (!heading.id) {
        let id;

        do {
          id = `miki-memory-dialog-heading-${++dialogHeadingSequence}`;
        } while (document.getElementById(id));

        heading.id = id;
      }

      card.setAttribute('aria-labelledby', heading.id);
    } else {
      card.setAttribute('aria-label', trigger.getAttribute('aria-label') || 'Memory');
    }
  }

  function removeDialogSemantics(card) {
    card.removeAttribute('role');
    card.removeAttribute('aria-modal');
    card.removeAttribute('aria-labelledby');
    card.removeAttribute('aria-label');
  }

  function focusDialog(state) {
    const heading = state.card.querySelector('.miki-memories__heading');

    if (heading) {
      heading.focus({ preventScroll: true });
    } else {
      state.card.setAttribute('tabindex', '-1');
      state.card.focus({ preventScroll: true });
    }
  }

  function focusExpandedCard(card) {
    const heading = card.querySelector('.miki-memories__heading');

    if (heading) {
      heading.focus({ preventScroll: true });
    } else {
      card.setAttribute('tabindex', '-1');
      card.focus({ preventScroll: true });
    }
  }

  function openPopup(grid, card, trigger) {
    const sourceRect = card.getBoundingClientRect();
    const placeholder = document.createElement('div');
    placeholder.className = 'miki-memories__placeholder';
    placeholder.setAttribute('aria-hidden', 'true');
    placeholder.style.height = `${card.offsetHeight}px`;
    card.before(placeholder);
    grid.append(card);

    const backdrop = grid.querySelector('[data-miki-backdrop]');
    const inertStates = new Map();
    grid.querySelectorAll('[data-miki-memory-card]').forEach((otherCard) => {
      if (otherCard !== card) {
        inertStates.set(otherCard, otherCard.inert);
        otherCard.inert = true;
      }
    });

    grid.querySelectorAll(`.${expandedClass}`).forEach((otherCard) => {
      if (otherCard !== card) {
        setExpanded(otherCard, false);
      }
    });

    const state = {
      grid,
      card,
      trigger,
      backdrop,
      placeholder,
      inertStates,
      pageScroll: getPageScrollLock(),
      openAnimation: null,
      closeAnimation: null,
      closeTimeout: null,
      closing: false,
      originalTabIndex: card.getAttribute('tabindex'),
    };

    activePopup = state;
    setExpanded(card, true);
    card.classList.add(popupClass);
    grid.classList.add(modalGridClass);
    setDialogSemantics(state);
    backdrop.hidden = false;
    backdrop.classList.remove('miki-memories__backdrop--closing');
    backdrop.classList.remove(backdropVisibleClass);

    requestAnimationFrame(() => {
      if (activePopup === state && !state.closing) {
        backdrop.classList.add(backdropVisibleClass);
      }
    });

    focusDialog(state);
    animatePopupOpen(state, sourceRect);
  }

  function finishPopupClose(state, restoreFocus) {
    if (activePopup !== state) {
      return;
    }

    state.openAnimation?.cancel();
    state.closeAnimation?.cancel();
    window.clearTimeout(state.closeTimeout);
    state.placeholder.before(state.card);
    state.card.classList.remove(popupClass);
    setExpanded(state.card, false);
    removeDialogSemantics(state.card);
    state.grid.classList.remove(modalGridClass);
    state.backdrop.classList.remove(backdropVisibleClass);
    state.backdrop.classList.remove('miki-memories__backdrop--closing');
    state.backdrop.hidden = true;
    state.placeholder.remove();
    state.inertStates.forEach((wasInert, card) => {
      card.inert = wasInert;
    });

    if (state.originalTabIndex === null) {
      state.card.removeAttribute('tabindex');
    } else {
      state.card.setAttribute('tabindex', state.originalTabIndex);
    }

    restorePageScroll(state.pageScroll);
    activePopup = null;

    if (restoreFocus && state.trigger.isConnected) {
      state.trigger.focus({ preventScroll: true });
    }
  }

  function closePopup(restoreFocus = true, immediate = false) {
    const state = activePopup;

    if (!state || (state.closing && !immediate)) {
      return;
    }

    state.closing = true;
    state.backdrop.classList.remove(backdropVisibleClass);
    state.backdrop.classList.add('miki-memories__backdrop--closing');

    if (immediate || reducedMotionQuery.matches || typeof Element.prototype.animate !== 'function') {
      finishPopupClose(state, restoreFocus);
      return;
    }

    const currentRect = state.card.getBoundingClientRect();
    state.openAnimation?.cancel();
    state.openAnimation = null;
    const modalRect = state.card.getBoundingClientRect();
    const startTransform = transformFromRect(currentRect, modalRect);
    const animation = state.card.animate(
      [
        { transform: startTransform, opacity: 1 },
        { transform: 'translate(-50%, -50%) scale(0.94)', opacity: 0 },
      ],
      {
        duration: 300,
        easing: 'cubic-bezier(0.4, 0, 1, 1)',
        fill: 'both',
      }
    );

    state.closeAnimation = animation;
    animation.onfinish = () => finishPopupClose(state, restoreFocus);
    state.closeTimeout = window.setTimeout(
      () => finishPopupClose(state, restoreFocus),
      400
    );
  }

  function syncResponsiveState() {
    if (activePopup) {
      closePopup(false, true);
    }

    document.querySelectorAll('[data-miki-memories]').forEach((grid) => {
      initializeCardTilts(grid);
      grid.querySelectorAll('[data-miki-memory-card]').forEach((card) => {
        card.classList.remove(popupClass);
        setExpanded(card, false);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncResponsiveState, { once: true });
  } else {
    syncResponsiveState();
  }

  window.addEventListener('pageshow', syncResponsiveState);
  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', syncResponsiveState);
  } else {
    mobileQuery.addListener(syncResponsiveState);
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : event.target.parentElement;
    const backdrop = target?.closest('[data-miki-backdrop]');

    if (backdrop) {
      if (activePopup && activePopup.grid === backdrop.closest('[data-miki-memories]')) {
        closePopup();
      }

      return;
    }

    const button = target?.closest('.miki-memories__toggle');

    if (!button || !mobileQuery.matches) {
      return;
    }

    const card = button.closest('[data-miki-memory-card]');

    if (!card) {
      return;
    }

    const grid = activePopup?.card === card
      ? activePopup.grid
      : button.closest('[data-miki-memories]');

    if (!grid) {
      return;
    }

    initializeCardTilts(grid);

    if (grid.dataset.mobileAnimation === 'popup') {
      if (activePopup?.card === card) {
        closePopup();
      } else if (activePopup) {
        closePopup(false, true);
        openPopup(grid, card, button);
      } else {
        openPopup(grid, card, button);
      }

      return;
    }

    if (activePopup) {
      closePopup(false, true);
    }

    const wasExpanded = card.classList.contains(expandedClass);

    grid.querySelectorAll(`.${expandedClass}`).forEach((expandedCard) => {
      setExpanded(expandedCard, false);
    });

    if (!wasExpanded) {
      setExpanded(card, true);
    }

    if (!wasExpanded) {
      focusExpandedCard(card);
    }
  });

  document.addEventListener('keydown', (event) => {
    const state = activePopup;

    if (!state) {
      return;
    }

    if (event.key === 'Escape') {
      if (!state.closing) {
        event.preventDefault();
        closePopup();
      }

      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusableElements = [...state.card.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )];
    const first = focusableElements[0];
    const last = focusableElements[focusableElements.length - 1];
    const activeElement = document.activeElement;
    const activeIsFocusable = focusableElements.includes(activeElement);

    if (!first) {
      event.preventDefault();
      state.card.focus();
    } else if (event.shiftKey && (!activeIsFocusable || activeElement === first)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (!activeIsFocusable || activeElement === last)) {
      event.preventDefault();
      first.focus();
    }
  });
})();
