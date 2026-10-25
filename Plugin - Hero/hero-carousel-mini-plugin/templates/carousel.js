(function () {
  class HeroCarousel {
    constructor(root) {
      this.root = root;
      this.container = root.querySelector(".carousel-container");
      this.track = root.querySelector(".carousel-track");
      this.prevBtn = root.querySelector(".carousel-nav-prev");
      this.nextBtn = root.querySelector(".carousel-nav-next");
      this.thumbnailContainer = root.querySelector(".carousel-thumbnails");
      this.loadingState = root.querySelector(".carousel-loading");
      this.emptyState = root.querySelector(".carousel-empty");
      this.emptyMessage = root.querySelector(".carousel-empty-text");
      this.currentIndex = 0;
      this.items = [];
      this.autoplayInterval = null;
      this.autoplayTimeout = null;
      this.autoplayDelay = 5000;
      this.resumeDelay = 8000;
      this.touchStartX = 0;
      this.touchEndX = 0;
      this.isTransitioning = false;
      this.minSwipeDistance = 50;
      this.hasEventsBound = false;
      this.transitionDurationMs = 640;
      this.fadeClassTimeout = null;

      if (!this.container || !this.track || !this.prevBtn || !this.nextBtn) {
        return;
      }

      this.init();
    }

    async init() {
      this.showLoading();

      try {
        const inlineItems = this.getInlineItems();
        const dataItems =
          inlineItems === null ? await this.fetchCarouselData() : inlineItems;
        this.items = this.prepareItems(dataItems);

        if (this.items.length === 0) {
          this.renderEmptyState("Momentalne nie su dostupne ziadne obrazky.");
          return;
        }

        this.currentIndex = 0;
        this.render();

        if (!this.hasEventsBound) {
          this.attachEventListeners();
          this.hasEventsBound = true;
        }

        this.goToSlide(0, false);
        this.startAutoplay();
      } catch (error) {
        this.renderEmptyState("Obsah sa momentalne neda nacitat.");
      }
    }

    getInlineItems() {
      const dataElement = this.root.querySelector(".hcm-carousel-data");
      if (!dataElement) {
        return null;
      }

      try {
        const data = JSON.parse(dataElement.textContent || "");
        return Array.isArray(data) ? data : null;
      } catch (error) {
        return null;
      }
    }

    async fetchCarouselData() {
      const apiUrl = "/wp-json/hcm/v1/carousel";
      const response = await fetch(apiUrl, { credentials: "same-origin" });

      if (!response.ok) {
        throw new Error("HTTP " + response.status);
      }

      return response.json();
    }

    prepareItems(data) {
      if (!Array.isArray(data)) {
        return [];
      }

      return data
        .map((item) => {
          const imageUrl = (item.image_url || "").toString().trim();
          const thumbnailUrl = (item.thumbnail_url || imageUrl)
            .toString()
            .trim();
          const titleSource =
            item.carousel_title ||
            (item.title && item.title.rendered ? item.title.rendered : "") ||
            "";
          const normalizedTitle = this.normalizeTitle(titleSource.toString());
          const link = this.sanitizeUrl(item.carousel_link || "");
          const showButton = this.parseBoolean(item.carousel_show_button, true);

          return {
            imageUrl,
            thumbnailUrl,
            imageSrcset: (item.image_srcset || "").toString().trim(),
            imageSizes: (item.image_sizes || "").toString().trim(),
            imageAlt: (
              item.image_alt ||
              normalizedTitle ||
              "Carousel item"
            ).toString(),
            title: this.escapeHtml(normalizedTitle),
            titleIsLong: normalizedTitle.length > 50,
            link,
            showButton,
          };
        })
        .filter((item) => item.imageUrl.length > 0);
    }

    render() {
      this.hideLoading();
      this.hideEmptyState();
      this.showControls();

      const slidesMarkup = this.items
        .map((item, idx) => this.buildSlideMarkup(item, idx))
        .join("");

      this.track.innerHTML = slidesMarkup;

      this.track.querySelectorAll("img").forEach((img) => {
        img.addEventListener("error", () => {
          const itemIndex = parseInt(
            img.getAttribute("data-item-index") || "-1",
            10,
          );
          if (Number.isNaN(itemIndex) || itemIndex < 0) {
            return;
          }

          this.items.splice(itemIndex, 1);
          if (this.items.length === 0) {
            this.renderEmptyState("Momentalne nie su dostupne ziadne obrazky.");
            return;
          }

          if (this.currentIndex >= this.items.length) {
            this.currentIndex = this.items.length - 1;
          }

          this.render();
          this.goToSlide(this.currentIndex, false);
        });
      });

      if (this.thumbnailContainer) {
        this.thumbnailContainer.innerHTML = this.items
          .map((item, idx) => {
            const imageUrl = this.escapeAttr(item.thumbnailUrl);
            const imageAlt = this.escapeAttr(item.imageAlt);
            return `<button class="carousel-thumbnail ${idx === 0 ? "active" : ""}" data-index="${idx}" type="button" aria-label="Prejst na polozku ${idx + 1}"><img src="${imageUrl}" alt="${imageAlt}" loading="lazy" /></button>`;
          })
          .join("");
      }
      this.updateIndicators();
    }

    buildSlideMarkup(item, idx) {
      const imageUrl = this.escapeAttr(item.imageUrl);
      const imageAlt = this.escapeAttr(item.imageAlt);
      const responsiveAttrs = item.imageSrcset
        ? `sizes="${this.escapeAttr(item.imageSizes || "100vw")}" srcset="${this.escapeAttr(item.imageSrcset)}"`
        : "";
      // First slide is the LCP candidate: load it eagerly with high priority, defer the rest.
      const loadingAttrs =
        idx === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
      const hasTitle = item.title.length > 0;
      const hasLink = item.link.length > 0;
      const shouldShowButton = hasLink && item.showButton;
      const isImageClickable = hasLink && !item.showButton;

      const titleClass = item.titleIsLong
        ? "carousel-item-title is-long"
        : "carousel-item-title";
      const titleHtml = hasTitle
        ? `<h2 class="${titleClass}">${item.title}</h2>`
        : "";
      const buttonHtml = shouldShowButton
        ? `<a href="${item.link}" class="carousel-item-button" aria-label="Zobraziť viac">Zobraziť viac</a>`
        : "";
      const hasOverlayContent = hasTitle || shouldShowButton;
      const contentHtml = hasOverlayContent
        ? `<div class="carousel-item-content">
          <div class="carousel-copy">
            ${titleHtml}
            ${buttonHtml}
          </div>
        </div>`
        : "";
      const imageLinkHtml = isImageClickable
        ? `<a href="${item.link}" class="carousel-item-image-link" style="position:absolute;inset:0;z-index:2;display:block;pointer-events:auto;cursor:pointer;" aria-label="Otvorit polozku"></a>`
        : "";
      return `
      <article class="carousel-item" data-slide-index="${idx}">
        <img ${responsiveAttrs} src="${imageUrl}" alt="${imageAlt}" ${loadingAttrs} data-item-index="${idx}" />
        ${imageLinkHtml}
        ${contentHtml}
      </article>
    `;
    }

    attachEventListeners() {
      this.prevBtn.addEventListener("click", () => this.handleNavClick("prev"));
      this.nextBtn.addEventListener("click", () => this.handleNavClick("next"));

      if (this.thumbnailContainer) {
        this.thumbnailContainer.addEventListener("click", (event) => {
          const target = event.target;
          const thumb =
            target instanceof HTMLElement
              ? target.closest(".carousel-thumbnail")
              : null;
          if (!(thumb instanceof HTMLElement)) return;
          const idx = parseInt(thumb.dataset.index || "0", 10);
          this.goToSlide(idx);
          this.pauseAutoplay();
          this.resumeAutoplayAfterDelay();
        });
      }

      this.track.addEventListener(
        "touchstart",
        (event) => this.handleTouchStart(event),
        false,
      );
      this.track.addEventListener(
        "touchend",
        (event) => this.handleTouchEnd(event),
        false,
      );

      this.container.addEventListener("mouseenter", () => this.pauseAutoplay());
      this.container.addEventListener("mouseleave", () =>
        this.resumeAutoplayAfterDelay(),
      );

      window.addEventListener("resize", () => {
        this.goToSlide(this.currentIndex, false);
      });
    }

    handleNavClick(direction) {
      if (this.isTransitioning || this.items.length <= 1) {
        return;
      }

      this.pauseAutoplay();
      if (direction === "prev") {
        this.prevSlide();
      } else {
        this.nextSlide();
      }
      this.resumeAutoplayAfterDelay();
    }

    handleTouchStart(event) {
      this.touchStartX = event.changedTouches[0].screenX;
    }

    handleTouchEnd(event) {
      this.touchEndX = event.changedTouches[0].screenX;
      const diff = this.touchStartX - this.touchEndX;

      if (Math.abs(diff) > this.minSwipeDistance) {
        this.pauseAutoplay();
        if (diff > 0) {
          this.nextSlide();
        } else {
          this.prevSlide();
        }
        this.resumeAutoplayAfterDelay();
      }
    }

    nextSlide() {
      if (this.items.length <= 1 || this.isTransitioning) {
        return;
      }

      this.goToSlide(this.currentIndex + 1);
    }

    prevSlide() {
      if (this.items.length <= 1 || this.isTransitioning) {
        return;
      }

      this.goToSlide(this.currentIndex - 1);
    }

    goToSlide(index, animate = true) {
      if (this.items.length === 0) {
        return;
      }

      const n = this.items.length;
      this.currentIndex = ((index % n) + n) % n;

      if (animate && n > 1) {
        this.triggerWhiteFade();
      }

      this.updateActiveSlideState();

      if (animate) {
        this.isTransitioning = true;
        clearTimeout(this.transitionEndTimeout);
        this.transitionEndTimeout = setTimeout(() => {
          this.isTransitioning = false;
        }, this.transitionDurationMs);
      } else {
        this.isTransitioning = false;
      }

      this.updateIndicators();
    }

    triggerWhiteFade() {
      this.container.classList.remove("is-fading");
      // Force reflow so the fade animation can restart on repeated interactions.
      void this.container.offsetWidth;
      this.container.classList.add("is-fading");

      clearTimeout(this.fadeClassTimeout);
      this.fadeClassTimeout = setTimeout(() => {
        this.container.classList.remove("is-fading");
      }, this.transitionDurationMs);
    }

    updateActiveSlideState() {
      const slides = this.track.querySelectorAll(".carousel-item");
      const activeVisualIndex = this.currentIndex;

      slides.forEach((slide, idx) => {
        const isActive = idx === activeVisualIndex;

        slide.classList.toggle("is-active", isActive);
        slide.setAttribute("aria-hidden", isActive ? "false" : "true");

        const interactiveElements = slide.querySelectorAll("a, button");
        interactiveElements.forEach((element) => {
          if (!(element instanceof HTMLElement)) return;
          element.setAttribute("tabindex", isActive ? "0" : "-1");
          element.setAttribute("aria-hidden", isActive ? "false" : "true");
        });
      });
    }

    updateIndicators() {
      const total = this.items.length;

      if (this.thumbnailContainer) {
        this.thumbnailContainer
          .querySelectorAll(".carousel-thumbnail")
          .forEach((thumb, idx) => {
            thumb.classList.toggle("active", idx === this.currentIndex);
          });
      }

      if (total <= 1) {
        this.prevBtn.style.display = "none";
        this.nextBtn.style.display = "none";
        if (this.thumbnailContainer) {
          this.thumbnailContainer.style.display = "none";
        }
      } else {
        this.prevBtn.style.display = "flex";
        this.nextBtn.style.display = "flex";
      }
    }

    startAutoplay() {
      this.pauseAutoplay();
      if (this.items.length <= 1) return;
      this.autoplayInterval = setInterval(
        () => this.nextSlide(),
        this.autoplayDelay,
      );
    }

    pauseAutoplay() {
      clearInterval(this.autoplayInterval);
      this.autoplayInterval = null;
    }

    resumeAutoplayAfterDelay() {
      clearTimeout(this.autoplayTimeout);
      this.autoplayTimeout = setTimeout(
        () => this.startAutoplay(),
        this.resumeDelay,
      );
    }

    showLoading() {
      if (this.loadingState) {
        this.loadingState.style.display = "flex";
      }
    }

    hideLoading() {
      if (this.loadingState) {
        this.loadingState.style.display = "none";
      }
    }

    renderEmptyState(message) {
      this.hideLoading();
      this.pauseAutoplay();
      clearTimeout(this.autoplayTimeout);
      clearTimeout(this.transitionEndTimeout);
      clearTimeout(this.fadeClassTimeout);
      this.container.classList.remove("is-fading");
      this.isTransitioning = false;
      this.track.innerHTML = "";
      this.prevBtn.style.display = "none";
      this.nextBtn.style.display = "none";
      if (this.thumbnailContainer) {
        this.thumbnailContainer.innerHTML = "";
        this.thumbnailContainer.style.display = "none";
      }
      if (this.emptyMessage) {
        this.emptyMessage.textContent = message;
      }
      if (this.emptyState) {
        this.emptyState.style.display = "flex";
      }
    }

    hideEmptyState() {
      if (this.emptyState) {
        this.emptyState.style.display = "none";
      }
    }

    showControls() {
      this.prevBtn.style.display = "flex";
      this.nextBtn.style.display = "flex";
      if (this.thumbnailContainer) {
        this.thumbnailContainer.style.display = "flex";
      }
    }

    sanitizeUrl(url) {
      if (!url) {
        return "";
      }

      try {
        const parsed = new URL(url, window.location.origin);
        if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
          return "";
        }
        return parsed.href;
      } catch (error) {
        return "";
      }
    }

    parseBoolean(value, defaultValue = true) {
      if (value === undefined || value === null || value === "") {
        return defaultValue;
      }

      if (typeof value === "boolean") {
        return value;
      }

      if (typeof value === "number") {
        return value === 1;
      }

      const normalized = value.toString().trim().toLowerCase();
      if (["1", "true", "yes", "on"].includes(normalized)) {
        return true;
      }
      if (["0", "false", "no", "off"].includes(normalized)) {
        return false;
      }

      return defaultValue;
    }

    normalizeTitle(value) {
      return value
        .replace(/<[^>]*>/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    }

    escapeHtml(value) {
      return value
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    escapeAttr(value) {
      return this.escapeHtml(value || "");
    }
  }

  function hcmInitCarousels() {
    document.querySelectorAll(".hero-carousel-host").forEach((root) => {
      if (root.dataset.hcmInitialized === "1") {
        return;
      }
      root.dataset.hcmInitialized = "1";
      new HeroCarousel(root);
    });
  }

  if (window.__hcmCarouselBootstrapped) {
    hcmInitCarousels();
    return;
  }
  window.__hcmCarouselBootstrapped = true;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", hcmInitCarousels);
  } else {
    hcmInitCarousels();
  }
})();
