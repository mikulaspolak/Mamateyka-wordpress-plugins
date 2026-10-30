(function () {
  "use strict";

  if (window.OznamyLightbox) {
    return;
  }
  window.OznamyLightbox = true;

  var ITEM_SELECTOR = ".oznamy-img-item";
  var SWIPE_MIN_PX = 50;

  var root = null;
  var imageEl = null;
  var closeBtn = null;
  var prevBtn = null;
  var nextBtn = null;
  var counterEl = null;

  var items = [];
  var index = -1;
  var opener = null;
  var savedBodyOverflow = "";
  var loadToken = 0;
  var touchStartX = null;
  var touchStartY = null;

  function createButton(id, className, label, text) {
    var button = document.createElement("button");
    button.type = "button";
    button.id = id;
    if (className) {
      button.className = className;
    }
    button.setAttribute("aria-label", label);
    button.textContent = text;
    return button;
  }

  function build() {
    // Starý HTML z cache mohol obsahovať vlastný lightbox; dva naraz by sa rušili.
    var legacy = document.getElementById("oznamy-lightbox");
    if (legacy) {
      legacy.parentNode.removeChild(legacy);
    }

    root = document.createElement("div");
    root.id = "oznamy-lightbox";
    root.setAttribute("role", "dialog");
    root.setAttribute("aria-modal", "true");
    root.setAttribute("aria-label", "Prehliadač príloh");
    root.tabIndex = -1;
    root.hidden = true;

    closeBtn = createButton("oznamy-lightbox-close", "", "Zavrieť", "\u00d7");
    prevBtn = createButton(
      "oznamy-lightbox-prev",
      "oznamy-lightbox-nav oznamy-lightbox-nav-prev",
      "Predchádzajúci obrázok",
      "\u2039"
    );
    nextBtn = createButton(
      "oznamy-lightbox-next",
      "oznamy-lightbox-nav oznamy-lightbox-nav-next",
      "Nasledujúci obrázok",
      "\u203a"
    );

    imageEl = document.createElement("img");
    imageEl.id = "oznamy-lightbox-img";
    imageEl.alt = "";
    imageEl.decoding = "async";

    counterEl = document.createElement("div");
    counterEl.id = "oznamy-lightbox-counter";
    counterEl.setAttribute("aria-live", "polite");

    root.appendChild(closeBtn);
    root.appendChild(prevBtn);
    root.appendChild(imageEl);
    root.appendChild(nextBtn);
    root.appendChild(counterEl);
    document.body.appendChild(root);

    closeBtn.addEventListener("click", close);
    prevBtn.addEventListener("click", function () {
      step(-1);
    });
    nextBtn.addEventListener("click", function () {
      step(1);
    });
    root.addEventListener("click", function (event) {
      if (event.target === root) {
        close();
      }
    });
    root.addEventListener(
      "touchstart",
      function (event) {
        if (event.touches.length !== 1) {
          touchStartX = null;
          return;
        }
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
      },
      { passive: true }
    );
    root.addEventListener(
      "touchend",
      function (event) {
        if (touchStartX === null || !event.changedTouches.length) {
          return;
        }
        var dx = event.changedTouches[0].clientX - touchStartX;
        var dy = event.changedTouches[0].clientY - touchStartY;
        touchStartX = null;
        if (Math.abs(dx) >= SWIPE_MIN_PX && Math.abs(dx) > Math.abs(dy) * 1.5) {
          step(dx < 0 ? 1 : -1);
        }
      },
      { passive: true }
    );
  }

  // Listuje sa len v rámci jednej galérie, aby šípky neskákali do inej sekcie stránky.
  function collectItems(item) {
    var scope = item.closest(".oznamy-images") || document;
    return Array.prototype.slice.call(scope.querySelectorAll(ITEM_SELECTOR));
  }

  function show(newIndex) {
    index = newIndex;
    var item = items[index];
    var fullSrc = item.getAttribute("data-src") || "";
    var thumb = item.querySelector("img");
    var placeholder = thumb ? thumb.currentSrc || thumb.getAttribute("src") || "" : "";
    var token = ++loadToken;

    imageEl.alt = item.getAttribute("data-alt") || "";
    imageEl.style.width = "";

    if (placeholder && placeholder !== fullSrc) {
      // Miniatúra sa zobrazí hneď a originál (až niekoľko MB) ju vymení po načítaní.
      // Pomer strán z miniatúry (alebo z jej width/height), aby sa pri výmene za originál nemenila veľkosť.
      var thumbW = thumb.naturalWidth || parseInt(thumb.getAttribute("width"), 10) || 0;
      var thumbH = thumb.naturalHeight || parseInt(thumb.getAttribute("height"), 10) || 0;
      if (thumbW > 0 && thumbH > 0) {
        imageEl.style.width = "min(100%, " + ((90 * thumbW) / thumbH).toFixed(2) + "vh)";
      }
      imageEl.classList.add("is-loading");
      imageEl.src = placeholder;

      var loader = new Image();
      loader.onload = function () {
        if (token !== loadToken) {
          return;
        }
        imageEl.style.width = "";
        imageEl.classList.remove("is-loading");
        imageEl.src = fullSrc;
      };
      loader.onerror = function () {
        if (token === loadToken) {
          imageEl.classList.remove("is-loading");
        }
      };
      loader.src = fullSrc;
    } else {
      imageEl.classList.remove("is-loading");
      imageEl.src = fullSrc;
    }

    var multiple = items.length > 1;
    prevBtn.hidden = !multiple;
    nextBtn.hidden = !multiple;
    prevBtn.disabled = index <= 0;
    nextBtn.disabled = index >= items.length - 1;
    counterEl.hidden = !multiple;
    counterEl.textContent = multiple ? index + 1 + " / " + items.length : "";
  }

  function step(delta) {
    var target = index + delta;
    if (!root || root.hidden || target < 0 || target >= items.length) {
      return;
    }
    show(target);
  }

  function open(item) {
    if (!root) {
      build();
    }

    var list = collectItems(item);
    var position = list.indexOf(item);
    if (position < 0) {
      return;
    }

    items = list;
    opener = item;
    savedBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    root.hidden = false;
    document.addEventListener("keydown", onLightboxKeydown, true);
    show(position);
    closeBtn.focus();
  }

  function close() {
    if (!root || root.hidden) {
      return;
    }

    loadToken++;
    root.hidden = true;
    imageEl.removeAttribute("src");
    imageEl.style.width = "";
    document.body.style.overflow = savedBodyOverflow;
    document.removeEventListener("keydown", onLightboxKeydown, true);

    if (opener && document.contains(opener)) {
      opener.focus();
    }
    opener = null;
    items = [];
    index = -1;
  }

  function trapTab(event) {
    var focusable = [closeBtn, prevBtn, nextBtn].filter(function (el) {
      return !el.hidden && !el.disabled;
    });
    if (!focusable.length) {
      event.preventDefault();
      return;
    }

    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    var active = document.activeElement;
    if (focusable.indexOf(active) === -1) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function onLightboxKeydown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      close();
    } else if (event.key === "ArrowRight") {
      step(1);
    } else if (event.key === "ArrowLeft") {
      step(-1);
    } else if (event.key === "Tab") {
      trapTab(event);
    }
  }

  function findItem(target) {
    return target && target.closest ? target.closest(ITEM_SELECTOR) : null;
  }

  document.addEventListener("click", function (event) {
    var item = findItem(event.target);
    if (item && !(root && root.contains(item))) {
      open(item);
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" && event.key !== " ") {
      return;
    }
    if (root && !root.hidden) {
      return;
    }
    var item = findItem(event.target);
    if (item) {
      event.preventDefault();
      open(item);
    }
  });
})();
