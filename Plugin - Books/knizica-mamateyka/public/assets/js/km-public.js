(function () {
  "use strict";

  var cfg = window.KM_DATA || {};
  var t = cfg.i18n || {};

  function apiFetch(path, options) {
    options = options || {};
    var headers = options.headers || {};
    headers["X-WP-Nonce"] = cfg.nonce;
    if (options.body && !(options.body instanceof FormData)) {
      headers["Content-Type"] = "application/json";
    }
    options.headers = headers;
    options.credentials = "same-origin";
    return fetch(cfg.restUrl + path, options).then(function (res) {
      return res
        .json()
        .catch(function () {
          throw new Error(
            "Server vrátil neočakávanú odpoveď (HTTP " + res.status + ").",
          );
        })
        .then(function (json) {
          if (!res.ok) {
            var err = new Error(json && json.message ? json.message : "Error");
            if (json && json.code) {
              err.code = json.code;
            }
            if (json && json.data && json.data.field) {
              err.field = json.data.field;
            }
            if (json && json.data && json.data.fields) {
              err.fields = json.data.fields;
            }
            throw err;
          }
          return json;
        });
    });
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (key) {
      if (key === "class") {
        node.className = attrs[key];
      } else if (key === "html") {
        node.innerHTML = attrs[key];
      } else if (key.indexOf("on") === 0) {
        node.addEventListener(key.slice(2), attrs[key]);
      } else {
        node.setAttribute(key, attrs[key]);
      }
    });
    (children || []).forEach(function (child) {
      if (typeof child === "string") {
        node.appendChild(document.createTextNode(child));
      } else if (child) {
        node.appendChild(child);
      }
    });
    return node;
  }

  function twoLineLabel(label) {
    var words = String(label).trim().split(/\s+/);
    var splitAt = words.length > 1 ? 1 : 0;
    return [
      el("span", { class: "km-availability-label-line" }, [
        words.slice(0, splitAt).join(" "),
      ]),
      document.createTextNode(" "),
      el("span", { class: "km-availability-label-line" }, [
        words.slice(splitAt).join(" "),
      ]),
    ];
  }

  function renderCatalogAvailability(book) {
    var available = Math.max(0, Number(book.available_copies) || 0);
    var total = Math.max(0, Number(book.total_copies) || 0);
    var label;

    if (available > 0) {
      label =
        total === 1 ? "Dostupná" : "Dostupné " + available + " z " + total;
    } else {
      label = total === 1 ? "Momentálne vypožičaná" : "Momentálne nedostupná";
    }

    return el(
      "span",
      {
        class:
          "km-catalog-availability " +
          (available > 0
            ? "km-catalog-availability-ok"
            : "km-catalog-availability-empty"),
      },
      [label],
    );
  }

  document.addEventListener("click", function (e) {
    document
      .querySelectorAll(".km-filter-dropdown-open")
      .forEach(function (openEl) {
        if (!openEl.contains(e.target))
          openEl.classList.remove("km-filter-dropdown-open");
      });
  });

  function renderAvailabilityText(book) {
    var count = book.available_copies;
    var unit =
      count === 1 ? "kus" : count >= 2 && count <= 4 ? "kusy" : "kusov";
    var sentence = "Na sklade: " + Math.max(count, 0) + " " + unit + ".";
    return el(
      "p",
      {
        class:
          "km-availability-text " +
          (count > 0 ? "km-availability-ok" : "km-availability-empty"),
      },
      [sentence],
    );
  }

  /* ---------------- Catalog ---------------- */

  function initCatalog(root) {
    var state = {
      search: "",
      categories: [],
      year: [],
      availableOnly: false,
      reviewFilter: "all",
      sort: "title_asc",
      page: 1,
      perPage: 24,
      items: [],
      total: 0,
      meta: {
        categories: [],
        published_years: [],
      },
      viewMode:
        localStorage.getItem("km-view-mode") === "list" ? "list" : "grid",
    };

    // Pending inline-edit state for the currently open book detail page.
    var detailEdit = { bookId: null, editingKey: null, draft: {} };
    var detailHistory = {
      bookId: null,
      loaded: false,
      loading: false,
      items: [],
    };
    var currentDetailBook = null;
    var coverDialog = { bookId: null };
    var navigationToken = 0;
    var dirtyForms = {};
    var activeUrl = window.location.href;

    var SORT_OPTIONS = [
      ["title_asc", "Názov A-Z"],
      ["title_desc", "Názov Z-A"],
      ["author_asc", "Autor A-Z"],
      ["author_desc", "Autor Z-A"],
      ["year_desc", "Rok (najnovší)"],
      ["year_asc", "Rok (najstarší)"],
      ["created_desc", "Dátum pridania (najnovší)"],
      ["created_asc", "Dátum pridania (najstarší)"],
    ];

    var wrap = el("div", { class: "km-wrap" });
    root.appendChild(wrap);

    var editorBar = null;
    if (cfg.isEditor) {
      var editorBarText = t.loggedInAsEditor || "Prihlásený ako editor";
      var editorIdentity = el("div", { class: "km-editor-bar-identity" }, [
        editorBarText,
      ]);
      if (cfg.editorName) {
        editorIdentity.appendChild(
          el("span", { class: "km-editor-bar-name" }, [cfg.editorName]),
        );
      }
      editorBar = el("div", { class: "km-editor-bar" }, [editorIdentity]);
      wrap.appendChild(editorBar);
    }

    /*var libraryTitle = el("h1", { class: "km-library-title" }, [
      "Knižnica Mamateyka",
    ]);
    wrap.appendChild(libraryTitle);
    */

    var toolbar = el("div", { class: "km-toolbar" });
    var grid = el("div", {
      class: state.viewMode === "list" ? "km-list" : "km-grid",
    });
    var loadMoreBtn = el(
      "button",
      {
        class: "km-btn km-load-more",
        onclick: function () {
          state.page++;
          fetchBooks(true);
        },
      },
      [t.loadMore || "Load more"],
    );
    var modalHost = el("div", { id: "km-modal-host" });
    var detailHost = el("div", { class: "km-detail-page-host" });
    var categoriesHost = el("div", { class: "km-categories-page-host" });
    var reservationsHost = el("div", { class: "km-reservations-page-host" });
    var archiveHost = el("div", { class: "km-archive-page-host" });
    var resultsCount = el("p", { class: "km-results-count" });
    var TRANSITION_NAME = "km-book-transition";
    var NEW_BOOK_ID = "new";

    wrap.appendChild(toolbar);
    wrap.appendChild(resultsCount);
    wrap.appendChild(grid);
    wrap.appendChild(loadMoreBtn);
    wrap.appendChild(detailHost);
    wrap.appendChild(categoriesHost);
    wrap.appendChild(reservationsHost);
    wrap.appendChild(archiveHost);
    wrap.appendChild(modalHost);

    // Dropdown-button + checkbox-panel filter control for multi-value fields.
    // `selectedArray` is mutated in place (state.categories)
    // so the caller's state stays in sync without extra bookkeeping.
    function renderFilterDropdown(
      buttonLabel,
      options,
      selectedArray,
      onChange,
    ) {
      var wrapper = el("div", { class: "km-filter-dropdown" });
      function getButtonLabel() {
        var selectedLabels = (options || [])
          .filter(function (opt) {
            return selectedArray.indexOf(String(opt.value)) !== -1;
          })
          .map(function (opt) {
            return opt.label;
          });

        return selectedLabels.length ? selectedLabels.join(", ") : buttonLabel;
      }
      function updateButtonLabel() {
        button.textContent = getButtonLabel();
      }
      var button = el(
        "button",
        {
          type: "button",
          class: "km-input km-filter-dropdown-btn",
          onclick: function (e) {
            e.stopPropagation();
            var isOpen = wrapper.classList.contains("km-filter-dropdown-open");
            document
              .querySelectorAll(".km-filter-dropdown-open")
              .forEach(function (openEl) {
                openEl.classList.remove("km-filter-dropdown-open");
              });
            if (!isOpen) wrapper.classList.add("km-filter-dropdown-open");
          },
        },
        [getButtonLabel()],
      );
      var panel = el("div", { class: "km-filter-dropdown-panel" });
      (options || []).forEach(function (opt) {
        var valueStr = String(opt.value);
        var checkbox = el("input", {
          type: "checkbox",
          onchange: function (e) {
            if (e.target.checked) {
              if (selectedArray.indexOf(valueStr) === -1) {
                selectedArray.push(valueStr);
              }
            } else {
              var idx = selectedArray.indexOf(valueStr);
              if (idx !== -1) selectedArray.splice(idx, 1);
            }
            updateButtonLabel();
            onChange();
          },
        });
        if (selectedArray.indexOf(valueStr) !== -1) checkbox.checked = true;
        panel.appendChild(
          el("label", { class: "km-checkbox-group-item" }, [
            checkbox,
            " " + opt.label,
          ]),
        );
      });
      wrapper.appendChild(button);
      wrapper.appendChild(panel);
      return wrapper;
    }

    function renderToolbar() {
      toolbar.innerHTML = "";
      if (editorBar) {
        var existingEditorActions = editorBar.querySelector(
          ".km-editor-bar-actions",
        );
        if (existingEditorActions) existingEditorActions.remove();
      }

      var searchInput = el("input", {
        type: "search",
        class: "km-input km-search",
        placeholder: t.search || "Search",
        value: state.search,
        oninput: debounce(function (e) {
          state.search = e.target.value;
          state.page = 1;
          fetchBooks();
        }, 400),
      });

      var catFilter = renderFilterDropdown(
        t.allCategories || "Kategórie",
        (state.meta.categories || []).map(function (c) {
          return { value: String(c.id), label: c.name };
        }),
        state.categories,
        function () {
          state.page = 1;
          fetchBooks();
        },
      );

      var yearFilter = renderFilterDropdown(
        t.allYears || "Všetky roky",
        (state.meta.published_years || []).map(function (y) {
          return { value: String(y), label: String(y) };
        }),
        state.year,
        function () {
          state.page = 1;
          fetchBooks();
        },
      );

      var availableCheckbox = el("input", {
        type: "checkbox",
        class: "km-availability-checkbox",
        "aria-label": t.availableOnly || "Len dostupné",
        onchange: function (e) {
          state.availableOnly = e.target.checked;
          availabilityToggle
            .querySelector('[data-availability="all"]')
            .setAttribute("aria-pressed", e.target.checked ? "false" : "true");
          availabilityToggle
            .querySelector('[data-availability="available"]')
            .setAttribute("aria-pressed", e.target.checked ? "true" : "false");
          state.page = 1;
          fetchBooks();
        },
      });
      availableCheckbox.checked = state.availableOnly;

      var availabilityToggle = el(
        "div",
        {
          class: "km-availability-toggle",
          role: "group",
          "aria-label": t.availableOnly || "Dostupnosť",
        },
        [availableCheckbox],
      );

      function setAvailability(availableOnly) {
        availableCheckbox.checked = availableOnly;
        availableCheckbox.dispatchEvent(new Event("change", { bubbles: true }));
      }

      availabilityToggle.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-availability-option",
            "data-availability": "all",
            "aria-pressed": state.availableOnly ? "false" : "true",
            onclick: function () {
              setAvailability(false);
            },
          },
          [t.anyAvailability || "Všetky knihy"],
        ),
      );

      var reviewToggle = el(
        "div",
        {
          class: "km-availability-toggle km-review-toggle",
          role: "group",
          "aria-label": t.reviewFilter || "Kontrola kníh",
        },
        [],
      );
      function setReviewFilter(reviewFilter) {
        state.reviewFilter = reviewFilter;
        reviewToggle
          .querySelector('[data-review="all"]')
          .setAttribute(
            "aria-pressed",
            reviewFilter === "all" ? "true" : "false",
          );
        reviewToggle
          .querySelector('[data-review="unchecked"]')
          .setAttribute(
            "aria-pressed",
            reviewFilter === "unchecked" ? "true" : "false",
          );
        reviewToggle
          .querySelector('[data-review="checked"]')
          .setAttribute(
            "aria-pressed",
            reviewFilter === "checked" ? "true" : "false",
          );
        state.page = 1;
        fetchBooks();
      }
      reviewToggle.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-availability-option",
            "data-review": "all",
            "aria-pressed": state.reviewFilter === "all" ? "true" : "false",
            onclick: function () {
              setReviewFilter("all");
            },
          },
          twoLineLabel(t.anyAvailability || "Všetky knihy"),
        ),
      );
      reviewToggle.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-availability-option",
            "data-review": "unchecked",
            "aria-pressed":
              state.reviewFilter === "unchecked" ? "true" : "false",
            onclick: function () {
              setReviewFilter("unchecked");
            },
          },
          twoLineLabel(t.uncheckedOnly || "Len neskontrolované"),
        ),
      );
      reviewToggle.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-availability-option",
            "data-review": "checked",
            "aria-pressed": state.reviewFilter === "checked" ? "true" : "false",
            onclick: function () {
              setReviewFilter("checked");
            },
          },
          twoLineLabel(t.checkedOnly || "Len skontrolované"),
        ),
      );
      availabilityToggle.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-availability-option",
            "data-availability": "available",
            "aria-pressed": state.availableOnly ? "true" : "false",
            onclick: function () {
              setAvailability(true);
            },
          },
          [t.availableOnly || "Len dostupné"],
        ),
      );

      var sortSelect = el(
        "select",
        {
          class: "km-input km-sort-select",
          onchange: function (e) {
            state.sort = e.target.value;
            state.page = 1;
            fetchBooks();
          },
        },
        SORT_OPTIONS.map(function (opt) {
          var option = el("option", { value: opt[0] }, [opt[1]]);
          if (opt[0] === state.sort)
            option.setAttribute("selected", "selected");
          return option;
        }),
      );
      var sortIcon =
        '<svg viewBox="0 0 20 20" width="20" height="20" fill="none" aria-hidden="true"><path d="M6 3v14M6 3l-3 3M6 3l3 3M14 17V3M14 17l-3-3M14 17l3-3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      var sortControl = el("div", { class: "km-sort-control" }, [
        el("span", { class: "km-sort-icon", html: sortIcon }),
        sortSelect,
      ]);

      var gridIcon =
        '<svg viewBox="0 0 20 20" width="18" height="18" fill="currentColor" aria-hidden="true"><rect x="2" y="2" width="7" height="7" rx="1.5"/><rect x="11" y="2" width="7" height="7" rx="1.5"/><rect x="2" y="11" width="7" height="7" rx="1.5"/><rect x="11" y="11" width="7" height="7" rx="1.5"/></svg>';
      var listIcon =
        '<svg viewBox="0 0 20 20" width="18" height="18" fill="currentColor" aria-hidden="true"><rect x="2" y="3" width="16" height="2.6" rx="1.3"/><rect x="2" y="8.7" width="16" height="2.6" rx="1.3"/><rect x="2" y="14.4" width="16" height="2.6" rx="1.3"/></svg>';

      var gridBtn = el("button", {
        type: "button",
        class: "km-view-btn",
        "aria-pressed": state.viewMode === "grid" ? "true" : "false",
        "aria-label": t.gridView || "Grid",
        title: t.gridView || "Grid",
        html: gridIcon,
        onclick: function () {
          setViewMode("grid");
        },
      });
      var listBtn = el("button", {
        type: "button",
        class: "km-view-btn",
        "aria-pressed": state.viewMode === "list" ? "true" : "false",
        "aria-label": t.listView || "List",
        title: t.listView || "List",
        html: listIcon,
        onclick: function () {
          setViewMode("list");
        },
      });
      var viewToggle = el("div", { class: "km-view-toggle" }, [
        gridBtn,
        listBtn,
      ]);

      var searchIcon =
        '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="1.8"/><line x1="14.2" y1="14.2" x2="18" y2="18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
      var searchBtn = el("button", {
        type: "button",
        class: "km-btn km-search-btn",
        "aria-label": t.search || "Hľadať",
        title: t.search || "Hľadať",
        html: searchIcon,
        onclick: function () {
          state.page = 1;
          fetchBooks();
        },
      });

      var searchGroup = el("div", { class: "km-toolbar-search" }, [
        searchInput,
        searchBtn,
      ]);
      var filterGroup = el("div", { class: "km-toolbar-filters" }, [
        catFilter,
        yearFilter,
        availabilityToggle,
      ]);
      var reviewRow = cfg.isEditor
        ? el("div", { class: "km-review-row" }, [reviewToggle])
        : null;
      var controlGroup = el("div", { class: "km-toolbar-controls" }, [
        sortControl,
        viewToggle,
      ]);

      var rowPrimary = el("div", { class: "km-toolbar-row-primary" }, [
        searchGroup,
      ]);
      var rowControls = el("div", { class: "km-toolbar-row-controls" }, [
        controlGroup,
      ]);

      toolbar.appendChild(rowPrimary);
      toolbar.appendChild(filterGroup);
      toolbar.appendChild(rowControls);
      if (reviewRow) toolbar.appendChild(reviewRow);

      if (cfg.isEditor) {
        // Editor-only controls: always km-btn-editor (--km-editor purple), see km-public.css.
        var addBtn = el(
          "button",
          {
            class: "km-btn km-btn-editor",
            onclick: function () {
              navigateToNewBook();
            },
          },
          [t.addBook || "Add book"],
        );
        var resBtn = el(
          "button",
          { class: "km-btn km-btn-editor", onclick: navigateToReservations },
          [t.reservations || "Reservations"],
        );
        var categoriesBtn = el(
          "button",
          { class: "km-btn km-btn-editor", onclick: navigateToCategories },
          [t.categories || "Kategórie"],
        );
        var archiveBtn = el(
          "button",
          { class: "km-btn km-btn-editor", onclick: navigateToArchive },
          [t.archive || "Kôš"],
        );
        var editorActions = el("div", { class: "km-editor-bar-actions" }, [
          addBtn,
          resBtn,
          categoriesBtn,
          archiveBtn,
        ]);
        editorBar.appendChild(editorActions);
      }
    }

    function setViewMode(mode) {
      if (state.viewMode === mode) return;
      state.viewMode = mode;
      localStorage.setItem("km-view-mode", mode);
      grid.className = mode === "list" ? "km-list" : "km-grid";
      renderToolbar();
      renderGrid();
    }

    function debounce(fn, wait) {
      var timer;
      return function () {
        var args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function () {
          fn.apply(null, args);
        }, wait);
      };
    }

    function fetchMeta() {
      return apiFetch("/meta").then(function (data) {
        state.meta = data;
        cfg.isEditor = !!data.is_editor;
        renderToolbar();
      });
    }

    function fetchBooks(append) {
      var params = new URLSearchParams();
      if (state.search) params.set("search", state.search);
      if (state.categories.length)
        params.set("category_id", state.categories.join(","));
      if (state.year.length) params.set("year", state.year.join(","));
      if (state.availableOnly) params.set("available_only", "true");
      if (state.reviewFilter === "unchecked") params.set("unchecked", "true");
      if (state.reviewFilter === "checked") params.set("checked", "true");
      params.set("sort", state.sort);
      params.set("page", state.page);
      params.set("per_page", state.perPage);

      return apiFetch("/books?" + params.toString()).then(function (data) {
        state.items = append ? state.items.concat(data.items) : data.items;
        state.total = data.total;
        renderGrid();
      });
    }

    function hasActiveFilters() {
      return (
        !!state.search ||
        state.categories.length > 0 ||
        state.year.length > 0 ||
        state.availableOnly ||
        state.reviewFilter !== "all"
      );
    }

    function clearFilters() {
      state.search = "";
      state.categories = [];
      state.year = [];
      state.availableOnly = false;
      state.reviewFilter = "all";
      state.page = 1;
      renderToolbar();
      fetchBooks();
    }

    function renderGrid() {
      resultsCount.textContent = "Počet nájdených kníh: " + state.total;
      if (hasActiveFilters()) {
        var clearFiltersLink = el(
          "a",
          {
            href: "#",
            class: "km-clear-filters-link",
            onclick: function (event) {
              event.preventDefault();
              clearFilters();
            },
          },
          [t.clearFilters || "Zrušiť filter"],
        );
        resultsCount.appendChild(clearFiltersLink);
      }
      grid.innerHTML = "";
      if (!state.items.length) {
        grid.appendChild(
          el("p", { class: "km-empty" }, [t.noResults || "No results"]),
        );
      }
      state.items.forEach(function (book) {
        grid.appendChild(
          state.viewMode === "list" ? renderRow(book) : renderCard(book),
        );
      });
      loadMoreBtn.style.display =
        state.items.length < state.total ? "" : "none";
    }

    function bookImageUrl(book) {
      return book.image || cfg.defaultImage || "";
    }

    function renderBookCover(book, className) {
      var url = bookImageUrl(book);
      if (!url) return null;
      return el("div", { class: className }, [
        el("img", { src: url, alt: "", loading: "lazy" }),
      ]);
    }

    // Main category first, then the secondary ones.
    function bookCategoryNames(book) {
      var names = book.main_category ? [book.main_category] : [];
      if (Array.isArray(book.secondary_categories)) {
        names = names.concat(book.secondary_categories);
      }
      return names.filter(function (name) {
        return name;
      });
    }

    function renderCard(book) {
      var availability = renderCatalogAvailability(book);
      var categories = bookCategoryNames(book).join(", ");

      var card = el(
        "div",
        {
          class: "km-card",
          "data-book-id": book.id,
          onclick: function () {
            navigateToBook(book.id, card);
          },
        },
        [
          renderBookCover(book, "km-card-cover"),
          el("div", { class: "km-card-body" }, [
            el("h3", { class: "km-card-title" }, [book.title || ""]),
            el("p", { class: "km-card-author" }, [book.author || ""]),
            categories
              ? el("p", { class: "km-card-category" }, [categories])
              : null,
            availability,
          ]),
        ],
      );
      return card;
    }

    function renderRow(book) {
      var availability = renderCatalogAvailability(book);
      var categories = bookCategoryNames(book).join(", ");
      var row = el(
        "div",
        {
          class: "km-row",
          "data-book-id": book.id,
          onclick: function () {
            navigateToBook(book.id, row);
          },
        },
        [
          renderBookCover(book, "km-row-cover"),
          el("div", { class: "km-row-body" }, [
            el("h3", { class: "km-row-title" }, [book.title || ""]),
            el("p", { class: "km-row-author" }, [book.author || ""]),
            categories
              ? el("p", { class: "km-row-category" }, [categories])
              : null,
          ]),
          availability,
        ],
      );
      return row;
    }

    function openModal(content) {
      modalHost.innerHTML = "";
      var overlay = el("div", {
        class: "km-overlay",
        onclick: function (e) {
          if (e.target === overlay) closeModal();
        },
      });
      var box = el("div", { class: "km-modal" });
      var closeBtn = el(
        "button",
        { class: "km-modal-close", onclick: closeModal },
        ["✕"],
      );
      box.appendChild(closeBtn);
      box.appendChild(content);
      overlay.appendChild(box);
      modalHost.appendChild(overlay);
      document.body.classList.add("km-modal-open");
    }

    function closeModal() {
      modalHost.innerHTML = "";
      document.body.classList.remove("km-modal-open");
    }

    // Editor-only trigger buttons are purple (km-btn-editor); the destructive
    // confirm step itself stays red (km-btn-danger) to signal it can't be undone.
    function confirmDangerAction(message, confirmLabel, onConfirm) {
      var content = el("div", { class: "km-confirm-dialog" });
      content.appendChild(el("p", {}, [message]));
      var cancelBtn = el("button", { class: "km-btn", onclick: closeModal }, [
        t.cancel || "Zrušiť",
      ]);
      var confirmBtn = el(
        "button",
        {
          class: "km-btn km-btn-danger",
          onclick: function () {
            closeModal();
            onConfirm();
          },
        },
        [confirmLabel],
      );
      content.appendChild(
        el("div", { class: "km-actions" }, [confirmBtn, cancelBtn]),
      );
      openModal(content);
    }

    function hasUnsavedChanges() {
      return (
        Object.keys(detailEdit.draft).length > 0 ||
        Object.keys(dirtyForms).some(function (key) {
          return dirtyForms[key];
        })
      );
    }

    function markFormDirty(formKey) {
      dirtyForms[formKey] = true;
    }

    function clearDirtyForms() {
      dirtyForms = {};
    }

    // Runs `action` immediately, unless there are unsaved form changes, in
    // which case it first asks the user to confirm discarding them.
    function confirmLeaveIfUnsaved(action) {
      if (!hasUnsavedChanges()) {
        action();
        return;
      }
      confirmDangerAction(
        t.unsavedChangesWarning ||
          "Máte neuložené zmeny. Naozaj chcete odísť bez uloženia?",
        t.leaveWithoutSaving || "Odísť bez uloženia",
        function () {
          detailEdit.draft = {};
          detailEdit.editingKey = null;
          clearDirtyForms();
          action();
        },
      );
    }

    /* ---------------- Book detail page (URL-driven, replaces catalog view) ---------------- */

    function getBookIdFromUrl() {
      return new URLSearchParams(window.location.search).get("kniha");
    }

    function getCategoriesViewFromUrl() {
      return (
        new URLSearchParams(window.location.search).get("km_view") ===
        "categories"
      );
    }

    function getReservationsViewFromUrl() {
      return (
        new URLSearchParams(window.location.search).get("km_view") ===
        "reservations"
      );
    }

    function getArchiveViewFromUrl() {
      return (
        new URLSearchParams(window.location.search).get("km_view") === "archive"
      );
    }

    function isArchiveDetail() {
      return (
        new URLSearchParams(window.location.search).get("km_from") === "archive"
      );
    }

    function applyViewTransition(mutateFn) {
      if (document.startViewTransition) {
        return document
          .startViewTransition(mutateFn)
          .finished.catch(function () {});
      }
      mutateFn();
      return Promise.resolve();
    }

    function scrollToPageTop() {
      window.scrollTo(0, 0);
      document.documentElement.scrollTop = 0;
      document.body.scrollTop = 0;
    }

    function scrollToPageTopAfterTransition() {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(scrollToPageTop);
      });
    }

    function categoryName(name) {
      if (!name) return null;
      return name;
    }

    function categoryMeta(name) {
      var match = (state.meta.categories || []).filter(function (c) {
        return c.name === name;
      });
      return match.length ? match[0] : null;
    }

    function hexToRgb(value) {
      if (!/^#[0-9a-fA-F]{6}$/.test(String(value || ""))) return null;
      return {
        red: parseInt(value.slice(1, 3), 16),
        green: parseInt(value.slice(3, 5), 16),
        blue: parseInt(value.slice(5, 7), 16),
      };
    }

    function relativeLuminance(rgb) {
      function channel(value) {
        value /= 255;
        return value <= 0.03928
          ? value / 12.92
          : Math.pow((value + 0.055) / 1.055, 2.4);
      }

      return (
        0.2126 * channel(rgb.red) +
        0.7152 * channel(rgb.green) +
        0.0722 * channel(rgb.blue)
      );
    }

    function contrastRatio(firstColor, secondColor) {
      var firstRgb = hexToRgb(firstColor);
      var secondRgb = hexToRgb(secondColor);
      if (!firstRgb || !secondRgb) return 0;
      var firstLuminance = relativeLuminance(firstRgb);
      var secondLuminance = relativeLuminance(secondRgb);
      var lighter = Math.max(firstLuminance, secondLuminance);
      var darker = Math.min(firstLuminance, secondLuminance);
      return (lighter + 0.05) / (darker + 0.05);
    }

    function categoryTextColor(backgroundColor, preferredColor) {
      var lightColor = "#ffffff";
      var darkColor = "#161b23";
      if (
        preferredColor &&
        contrastRatio(backgroundColor, preferredColor) >= 4.5
      ) {
        return preferredColor;
      }
      return contrastRatio(backgroundColor, lightColor) >=
        contrastRatio(backgroundColor, darkColor)
        ? lightColor
        : darkColor;
    }

    function showToast(message, type, duration) {
      var toast = el(
        "div",
        {
          class: "km-toast" + (type ? " km-toast-" + type : ""),
          role: "status",
          "aria-live": "polite",
        },
        [message],
      );
      document.body.appendChild(toast);
      window.setTimeout(function () {
        toast.classList.add("km-toast-hidden");
        window.setTimeout(function () {
          if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 180);
      }, duration || 3000);
    }

    // Colored chip per category name; invalid or low-contrast legacy colors get a safe fallback.
    function categoryBadge(name) {
      var meta = categoryMeta(name);
      var attrs = { class: "km-category-badge" };
      if (meta && meta.color) {
        attrs.style =
          "background-color:" +
          meta.color +
          ";color:" +
          categoryTextColor(meta.color, meta.text_color) +
          ";";
      }
      return el("span", attrs, [categoryName(name)]);
    }

    function categoryBadges(names) {
      if (!names || !names.length) return null;
      return el(
        "span",
        { class: "km-category-badges" },
        names.map(categoryBadge),
      );
    }

    function getYearOptions() {
      var currentYear = new Date().getFullYear();
      var opts = [];
      for (var y = currentYear; y > currentYear - 100; y--) {
        opts.push({ value: y, label: String(y) });
      }
      return opts;
    }

    function getCategoryOptions() {
      return (state.meta.categories || []).map(function (c) {
        return { value: c.name, label: c.name };
      });
    }

    var PENCIL_ICON =
      '<svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M13.5 3.5l3 3L6 17l-3.6.6.6-3.6L13.5 3.5Z"/></svg>';
    var CHECK_ICON =
      '<svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9"/></svg>';
    var TRASH_ICON =
      '<svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 5.5h13M8 5.5V3.5h4v2M5.5 5.5l.8 11h7.4l.8-11M8.5 9v5M11.5 9v5"/></svg>';

    // Bordered checkbox list used for multi-value fields (categories).
    // Keeps the working selection on `wrapper._values` (array of strings) until confirmed.
    function renderCheckboxGroup(options, selectedValues) {
      var wrapper = el("div", { class: "km-checkbox-group" });
      wrapper._values = (selectedValues || []).map(String);
      (options || []).forEach(function (opt) {
        var valueStr = String(opt.value);
        var checkbox = el("input", {
          type: "checkbox",
          onchange: function (e) {
            if (e.target.checked) {
              if (wrapper._values.indexOf(valueStr) === -1) {
                wrapper._values.push(valueStr);
              }
            } else {
              wrapper._values = wrapper._values.filter(function (v) {
                return v !== valueStr;
              });
            }
          },
        });
        if (wrapper._values.indexOf(valueStr) !== -1) {
          checkbox.checked = true;
        }
        wrapper.appendChild(
          el("label", { class: "km-checkbox-group-item" }, [
            checkbox,
            " " + opt.label,
          ]),
        );
      });
      return wrapper;
    }

    // Reads the draft value for a field if edited but not yet saved, else the server value.
    function fieldValue(book, key) {
      return Object.prototype.hasOwnProperty.call(detailEdit.draft, key)
        ? detailEdit.draft[key]
        : book[key];
    }

    // Compares field values (arrays compared as unordered sets of strings) to
    // tell whether a committed edit actually changed anything.
    function valuesEqual(a, b) {
      if (Array.isArray(a) || Array.isArray(b)) {
        var arrA = (Array.isArray(a) ? a : []).map(String).sort();
        var arrB = (Array.isArray(b) ? b : []).map(String).sort();
        if (arrA.length !== arrB.length) return false;
        return arrA.every(function (v, i) {
          return v === arrB[i];
        });
      }
      var normA = a === null || a === undefined ? "" : a;
      var normB = b === null || b === undefined ? "" : b;
      return String(normA) === String(normB);
    }

    function closeDetailHistory() {
      detailHistory = {
        bookId: null,
        loaded: false,
        loading: false,
        items: [],
      };
    }

    function commitFieldEdit(key, rawValue, type) {
      closeDetailHistory();
      var isNumeric = type === "number" || type === "select-number";
      var value = isNumeric && rawValue !== "" ? Number(rawValue) : rawValue;
      var original = currentDetailBook ? currentDetailBook[key] : undefined;
      if (valuesEqual(value, original)) {
        delete detailEdit.draft[key];
      } else {
        detailEdit.draft[key] = value;
      }
      detailEdit.editingKey = null;
      refreshDetailView();
    }

    function makeFieldInput(rawValue, type, options, min) {
      var input;
      if (type === "textarea") {
        input = el("textarea", { class: "km-input", rows: "4" });
        input.value =
          rawValue !== null && rawValue !== undefined ? rawValue : "";
      } else if (type === "multiselect") {
        var selected = Array.isArray(rawValue) ? rawValue : [];
        input = renderCheckboxGroup(options, selected);
      } else if (type === "select" || type === "select-number") {
        input = el("select", { class: "km-input" });
        var currentStr =
          rawValue !== null && rawValue !== undefined ? String(rawValue) : "";
        [{ value: "", label: "—" }]
          .concat(options || [])
          .forEach(function (opt) {
            var optionEl = el("option", { value: String(opt.value) }, [
              opt.label,
            ]);
            if (String(opt.value) === currentStr) {
              optionEl.setAttribute("selected", "selected");
            }
            input.appendChild(optionEl);
          });
      } else {
        var attrs = {
          type:
            type === "number" ? "number" : type === "date" ? "date" : "text",
          class: "km-input",
        };
        if (type === "number")
          attrs.min = String(min !== undefined && min !== null ? min : 0);
        input = el("input", attrs);
        if (
          type === "date" &&
          (rawValue === null || rawValue === undefined || rawValue === "")
        ) {
          var now = new Date();
          var month = String(now.getMonth() + 1).padStart(2, "0");
          var day = String(now.getDate()).padStart(2, "0");
          input.value = now.getFullYear() + "-" + month + "-" + day;
        } else {
          input.value =
            rawValue !== null && rawValue !== undefined ? rawValue : "";
        }
      }
      return input;
    }

    function makeConfirmBtn(key, input, type) {
      return el("button", {
        type: "button",
        class: "km-detail-field-confirm-btn",
        "aria-label": t.save || "Uložiť",
        title: t.save || "Uložiť",
        html: CHECK_ICON,
        onclick: function () {
          var value = type === "multiselect" ? input._values : input.value;
          commitFieldEdit(key, value, type);
        },
      });
    }

    function makePencilBtn(key) {
      return el("button", {
        type: "button",
        class: "km-detail-field-edit-btn",
        "aria-label": t.edit || "Upraviť",
        title: t.edit || "Upraviť",
        html: PENCIL_ICON,
        onclick: function () {
          closeDetailHistory();
          detailEdit.editingKey = key;
          refreshDetailView();
        },
      });
    }

    // Editable meta-grid row: label + value, with a pencil/check toggle for editors.
    function renderEditableField(
      book,
      key,
      label,
      type,
      displayFn,
      options,
      min,
      className,
    ) {
      var isEditing = cfg.isEditor && detailEdit.editingKey === key;
      var rawValue = fieldValue(book, key);
      var row = el(
        "div",
        {
          class: "km-detail-field" + (className ? " " + className : ""),
        },
        [el("span", { class: "km-detail-field-label" }, [label])],
      );
      var valueWrap;
      if (isEditing) {
        var input = makeFieldInput(rawValue, type, options, min);
        valueWrap = el(
          "span",
          { class: "km-detail-field-value km-detail-field-editing" },
          [input, makeConfirmBtn(key, input, type)],
        );
      } else {
        var text =
          rawValue !== null &&
          rawValue !== undefined &&
          rawValue !== "" &&
          !(Array.isArray(rawValue) && rawValue.length === 0)
            ? displayFn
              ? displayFn(rawValue)
              : String(rawValue)
            : "—";
        valueWrap = el("span", { class: "km-detail-field-value" }, [text]);
        if (cfg.isEditor) valueWrap.appendChild(makePencilBtn(key));
      }
      row.appendChild(valueWrap);
      return row;
    }

    var COVER_MAX_HEIGHT = 1200;

    // Downscales a photo in the browser (mobile photos are huge); PNG/WebP stay PNG to keep transparency.
    function prepareCoverFile(file) {
      return new Promise(function (resolve, reject) {
        var objectUrl = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          URL.revokeObjectURL(objectUrl);
          var scale =
            img.naturalHeight > COVER_MAX_HEIGHT
              ? COVER_MAX_HEIGHT / img.naturalHeight
              : 1;
          var canvas = document.createElement("canvas");
          canvas.width = Math.round(img.naturalWidth * scale);
          canvas.height = Math.round(img.naturalHeight * scale);
          canvas
            .getContext("2d")
            .drawImage(img, 0, 0, canvas.width, canvas.height);
          var keepAlpha =
            file.type === "image/png" || file.type === "image/webp";
          canvas.toBlob(
            function (blob) {
              if (blob) resolve(blob);
              else
                reject(
                  new Error(
                    t.coverUnreadable || "Obrázok sa nepodarilo spracovať.",
                  ),
                );
            },
            keepAlpha ? "image/png" : "image/jpeg",
            0.88,
          );
        };
        img.onerror = function () {
          URL.revokeObjectURL(objectUrl);
          reject(
            new Error(
              t.coverUnreadable ||
                "Obrázok sa nepodarilo načítať. Použite JPG, PNG alebo WebP.",
            ),
          );
        };
        img.src = objectUrl;
      });
    }

    // Runs a cover request while the popup stays open (its file inputs must stay attached).
    function runCoverAction(book, request) {
      closeDetailHistory();
      coverDialog.busy = true;
      coverDialog.error = "";
      renderCoverDialog(book);
      return request()
        .then(function (res) {
          book.image = res.image;
          coverDialog.busy = false;
          if (coverDialog.root && modalHost.contains(coverDialog.root)) {
            closeModal();
          }
          refreshDetailView();
          fetchBooks();
        })
        .catch(function (err) {
          coverDialog.busy = false;
          coverDialog.error = err.message;
          renderCoverDialog(book);
          return err;
        });
    }

    function uploadCover(book, file) {
      if (!file) return;
      runCoverAction(book, function () {
        return prepareCoverFile(file).then(function (blob) {
          var form = new FormData();
          form.append(
            "image",
            blob,
            "cover." + (blob.type === "image/png" ? "png" : "jpg"),
          );
          return apiFetch("/books/" + book.id + "/image", {
            method: "POST",
            body: form,
          });
        });
      });
    }

    function removeCover(book) {
      coverDialog.confirmRemove = false;
      runCoverAction(book, function () {
        return apiFetch("/books/" + book.id + "/image", { method: "DELETE" });
      });
    }

    function restoreCover(book, item) {
      runCoverAction(book, function () {
        return apiFetch("/books/" + book.id + "/image/restore", {
          method: "POST",
          body: JSON.stringify({ url: item.url }),
        });
      }).then(function (err) {
        if (err && err.code === "km_cover_unavailable") {
          item.available = false;
          renderCoverDialog(book);
        }
      });
    }

    // Permanently deletes an uploaded cover file; the book's current cover is not touched.
    function deleteCoverFile(book, item) {
      coverDialog.busy = true;
      coverDialog.error = "";
      renderCoverDialog(book);
      apiFetch("/books/" + book.id + "/image-history", {
        method: "DELETE",
        body: JSON.stringify({ url: item.url }),
      })
        .then(function () {
          item.available = false;
          item.deletable = false;
          coverDialog.confirmDeleteItem = null;
        })
        .catch(function (err) {
          if (err.code === "km_cover_not_deletable") item.deletable = false;
          coverDialog.confirmDeleteItem = null;
          coverDialog.error =
            err.message || t.coverDeleteFailed || "Súbor sa nepodarilo zmazať.";
        })
        .then(function () {
          coverDialog.busy = false;
          renderCoverDialog(book);
        });
    }

    function makeCoverFileInput(book, capture) {
      var attrs = {
        type: "file",
        accept: "image/jpeg,image/png,image/webp,image/*",
        class: "km-cover-file-input",
        hidden: "hidden",
      };
      if (capture) attrs.capture = "environment";
      var input = el("input", attrs);
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        input.value = "";
        uploadCover(book, file);
      });
      return input;
    }

    function coverReasonText(item) {
      return item.reason === "removed"
        ? t.coverWasRemoved || "odstránená"
        : t.coverWasReplaced || "nahradená";
    }

    function renderCoverDeleteConfirm(book, item) {
      var view = el("div", { class: "km-cover-restore" });
      view.appendChild(
        el("p", {}, [
          t.coverDeleteConfirm ||
            "Natrvalo zmazať tento súbor z WordPressu? Nedá sa to vrátiť.",
        ]),
      );
      view.appendChild(
        el("div", { class: "km-cover-delete-preview" }, [
          el("span", { class: "km-cover-restore-thumb" }, [
            el("img", { src: item.url, alt: "" }),
          ]),
        ]),
      );
      var busy = coverDialog.busy;
      var confirmAttrs = {
        type: "button",
        class: "km-btn km-btn-danger",
        onclick: function () {
          deleteCoverFile(book, item);
        },
      };
      var cancelAttrs = {
        type: "button",
        class: "km-btn",
        onclick: function () {
          coverDialog.confirmDeleteItem = null;
          renderCoverDialog(book);
        },
      };
      if (busy) {
        confirmAttrs.disabled = "disabled";
        cancelAttrs.disabled = "disabled";
      }
      view.appendChild(
        el("div", { class: "km-actions" }, [
          el("button", confirmAttrs, [t.coverDeleteAction || "Zmazať súbor"]),
          el("button", cancelAttrs, [t.cancel || "Zrušiť"]),
        ]),
      );
      return view;
    }

    function renderCoverRestoreView(book) {
      if (coverDialog.confirmDeleteItem) {
        return renderCoverDeleteConfirm(book, coverDialog.confirmDeleteItem);
      }
      var view = el("div", { class: "km-cover-restore" });
      view.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: function () {
              coverDialog.view = "menu";
              renderCoverDialog(book);
            },
          },
          ["← " + (t.back || "Späť")],
        ),
      );
      view.appendChild(
        el("h3", { class: "km-editor-heading" }, [
          t.coverRestoreTitle || "Obnoviť obálku",
        ]),
      );
      if (coverDialog.error) {
        view.appendChild(
          el("p", { class: "km-form-msg km-error", role: "alert" }, [
            coverDialog.error,
          ]),
        );
      }
      var items = coverDialog.history || [];
      if (
        !items.some(function (item) {
          return item.available !== false;
        })
      ) {
        view.appendChild(
          el("p", { class: "km-history-empty" }, [
            t.coverRestoreNone ||
              "Žiadna z predchádzajúcich obálok už nie je dostupná.",
          ]),
        );
      }
      var grid = el("div", { class: "km-cover-restore-grid" });
      items.forEach(function (item) {
        var unavailable = item.available === false;
        var date = item.changed_at
          ? new Date(item.changed_at.replace(" ", "T") + "Z")
          : null;
        var dateText =
          date && !isNaN(date.getTime())
            ? date.toLocaleDateString("sk-SK")
            : "";
        var img = el("img", { src: item.url, alt: "", loading: "lazy" });
        img.addEventListener("error", function () {
          if (item.available === false) return;
          item.available = false;
          if (coverDialog.view === "restore") renderCoverDialog(book);
        });
        var attrs = {
          type: "button",
          class:
            "km-cover-restore-item" + (unavailable ? " is-unavailable" : ""),
          title: item.user_name || "",
          onclick: function () {
            restoreCover(book, item);
          },
        };
        if (unavailable || coverDialog.busy) attrs.disabled = "disabled";
        var card = el("div", { class: "km-cover-restore-card" }, [
          el("button", attrs, [
            el("span", { class: "km-cover-restore-thumb" }, [img]),
            el("span", { class: "km-cover-restore-caption" }, [
              unavailable
                ? t.coverUnavailable || "už nie je dostupná"
                : [dateText, coverReasonText(item)].filter(Boolean).join(" · "),
            ]),
          ]),
        ]);
        if (item.deletable && !unavailable && !coverDialog.busy) {
          card.appendChild(
            el("button", {
              type: "button",
              class: "km-cover-trash-btn",
              "aria-label": t.coverDeleteFile || "Natrvalo zmazať súbor",
              title: t.coverDeleteFile || "Natrvalo zmazať súbor",
              html: TRASH_ICON,
              onclick: function () {
                coverDialog.confirmDeleteItem = item;
                coverDialog.error = "";
                renderCoverDialog(book);
              },
            }),
          );
        }
        grid.appendChild(card);
      });
      view.appendChild(grid);
      return view;
    }

    function renderCoverMenuView(book) {
      var hasCustom = book.image && book.image !== cfg.defaultImage;
      var busy = coverDialog.busy;
      var isTouch =
        window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
      var view = el("div", { class: "km-cover-menu" });
      view.appendChild(
        el("h3", { class: "km-editor-heading" }, [
          t.coverDialogTitle || "Obálka knihy",
        ]),
      );
      if (coverDialog.error) {
        view.appendChild(
          el("p", { class: "km-form-msg km-error", role: "alert" }, [
            coverDialog.error,
          ]),
        );
      }

      if (coverDialog.confirmRemove && !busy) {
        view.appendChild(
          el("p", {}, [
            t.coverConfirmRemove || "Odstrániť obálku tejto knihy?",
          ]),
        );
        view.appendChild(
          el("div", { class: "km-actions" }, [
            el(
              "button",
              {
                type: "button",
                class: "km-btn km-btn-danger",
                onclick: function () {
                  removeCover(book);
                },
              },
              [t.coverRemove || "Odstrániť obálku"],
            ),
            el(
              "button",
              {
                type: "button",
                class: "km-btn",
                onclick: function () {
                  coverDialog.confirmRemove = false;
                  renderCoverDialog(book);
                },
              },
              [t.cancel || "Zrušiť"],
            ),
          ]),
        );
        return view;
      }

      function menuBtn(cls, label, onclick) {
        var attrs = {
          type: "button",
          class: "km-btn " + cls,
          onclick: onclick,
        };
        if (busy) attrs.disabled = "disabled";
        return el("button", attrs, [label]);
      }
      var buttons = [
        menuBtn(
          isTouch ? "km-btn-editor-secondary" : "km-btn-editor",
          busy
            ? t.coverUploading || "Nahrávam..."
            : hasCustom
              ? t.coverReplace || "Nahradiť obálku"
              : t.coverUpload || "Nahrať obálku",
          function () {
            coverDialog.galleryInput.click();
          },
        ),
      ];
      if (isTouch) {
        buttons.unshift(
          menuBtn(
            "km-btn-editor",
            t.coverCamera || "Odfotiť obálku",
            function () {
              coverDialog.cameraInput.click();
            },
          ),
        );
      }
      if (coverDialog.history && coverDialog.history.length) {
        buttons.push(
          menuBtn(
            "km-btn-editor-outline",
            t.coverRestore || "Obnoviť zmazanú obálku",
            function () {
              coverDialog.view = "restore";
              coverDialog.error = "";
              renderCoverDialog(book);
            },
          ),
        );
      }
      if (hasCustom) {
        buttons.push(
          menuBtn(
            "km-btn-editor-secondary",
            t.coverRemove || "Odstrániť obálku",
            function () {
              coverDialog.confirmRemove = true;
              renderCoverDialog(book);
            },
          ),
        );
      }
      view.appendChild(el("div", { class: "km-cover-actions" }, buttons));
      return view;
    }

    function renderCoverDialog(book) {
      var body = coverDialog.body;
      if (!body) return;
      body.innerHTML = "";
      body.appendChild(
        coverDialog.view === "restore"
          ? renderCoverRestoreView(book)
          : renderCoverMenuView(book),
      );
    }

    // File inputs live in the popup root (not re-rendered) so the picker result is never lost.
    function openCoverDialog(book) {
      var body = el("div", { class: "km-cover-dialog-body" });
      var galleryInput = makeCoverFileInput(book, false);
      var cameraInput = makeCoverFileInput(book, true);
      var root = el("div", { class: "km-cover-dialog" }, [
        body,
        galleryInput,
        cameraInput,
      ]);
      coverDialog = {
        bookId: book.id,
        view: "menu",
        busy: false,
        error: "",
        confirmRemove: false,
        history: null,
        root: root,
        body: body,
        galleryInput: galleryInput,
        cameraInput: cameraInput,
      };
      var dialog = coverDialog;
      renderCoverDialog(book);
      openModal(root);
      apiFetch("/books/" + book.id + "/image-history")
        .then(function (items) {
          return Array.isArray(items) ? items : [];
        })
        .catch(function () {
          return [];
        })
        .then(function (items) {
          if (coverDialog !== dialog) return;
          dialog.history = items;
          renderCoverDialog(book);
        });
    }

    // Editor-only cover: preview with a pencil that opens the cover popup.
    function renderCoverEditor(book) {
      return el("div", { class: "km-cover-editor" }, [
        renderBookCover(book, "km-detail-cover"),
        el("button", {
          type: "button",
          class: "km-cover-edit-btn",
          "aria-label": t.coverEdit || "Upraviť obálku",
          title: t.coverEdit || "Upraviť obálku",
          html: PENCIL_ICON,
          onclick: function () {
            openCoverDialog(book);
          },
        }),
      ]);
    }

    // Editable headline field (title/author): no label, custom tag, hidden when
    // empty for non-editors so an unused value doesn't leave a visible gap.
    function renderEditableHeadlineField(
      book,
      key,
      tag,
      className,
      type,
      prefix,
    ) {
      var isEditing = cfg.isEditor && detailEdit.editingKey === key;
      var rawValue = fieldValue(book, key);
      if (!isEditing && !rawValue && !cfg.isEditor) return null;
      var wrap = el("div", { class: "km-detail-headline-field" });
      if (prefix) {
        wrap.appendChild(
          el("span", { class: "km-detail-headline-prefix" }, [prefix]),
        );
      }
      if (isEditing) {
        var input = makeFieldInput(rawValue, type);
        wrap.appendChild(input);
        wrap.appendChild(makeConfirmBtn(key, input, type));
      } else {
        var valueAttrs = className ? { class: className } : {};
        wrap.appendChild(el(tag, valueAttrs, [rawValue || "—"]));
        if (cfg.isEditor) wrap.appendChild(makePencilBtn(key));
      }
      return wrap;
    }

    function renderDetailView(book) {
      var page = el("div", { class: "km-detail-page" });

      page.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: function () {
              confirmLeaveIfUnsaved(
                isArchiveDetail() ? navigateToArchive : navigateToCatalog,
              );
            },
          },
          ["← " + (t.close || "Späť do katalógu")],
        ),
      );
      if (book.deleted_at) {
        page.appendChild(
          el("p", { class: "km-archived-warning" }, [
            t.archivedWarning || "Táto kniha je v koši. Verejnosť ju nevidí.",
          ]),
        );
      }

      var headline = el("div", { class: "km-detail-headline" });
      var titleField = renderEditableHeadlineField(
        book,
        "title",
        "h2",
        "",
        "text",
      );
      var authorField = renderEditableHeadlineField(
        book,
        "author",
        "p",
        "km-detail-author",
        "text",
        "Autor: ",
      );
      if (titleField) headline.appendChild(titleField);
      if (authorField) headline.appendChild(authorField);
      headline.appendChild(renderAvailabilityText(book));

      var hero = el("div", { class: "km-detail-hero" }, [
        cfg.isEditor
          ? renderCoverEditor(book)
          : renderBookCover(book, "km-detail-cover"),
        headline,
      ]);
      page.appendChild(hero);

      var fields = [
        ["co_authors", "Spoluautori", "text"],
        ["language", "Jazyk", "text"],
        [
          "main_category",
          "Hlavná kategória",
          "select",
          categoryBadge,
          getCategoryOptions(),
        ],
        [
          "secondary_categories",
          "Vedľajšie kategórie",
          "multiselect",
          categoryBadges,
          getCategoryOptions(),
        ],
        [
          "published_year",
          "Rok vydania",
          "select-number",
          null,
          getYearOptions(),
        ],
        ["edition", "Vydanie", "text"],
        ["series", "Edícia", "text"],
        ["isbn", "ISBN", "text"],
        ["page_count", "Počet strán", "number", null, null, 1],
        ["total_copies", t.total || "Celkovo kópií", "number", null, null, 0],
        [
          "available_copies",
          t.copiesAvailable || "Dostupné kópie",
          "number",
          null,
          null,
          0,
        ],
        [
          "last_checked_at",
          t.lastCheckedAt || "Naposledy skontrolované",
          "date",
        ],
      ];
      var metaGrid = el("div", { class: "km-detail-grid" });
      metaGrid.appendChild(
        el("div", { class: "km-detail-field" }, [
          el("span", { class: "km-detail-field-label" }, [
            t.bookId || "ID knihy",
          ]),
          el("span", { class: "km-detail-field-value" }, [String(book.id)]),
        ]),
      );
      fields.forEach(function (f) {
        metaGrid.appendChild(
          renderEditableField(book, f[0], f[1], f[2], f[3], f[4], f[5], f[6]),
        );
      });
      var descRow = renderEditableField(
        book,
        "description",
        "Popis",
        "textarea",
      );
      descRow.classList.add("km-detail-field-full");
      metaGrid.appendChild(descRow);
      // Original categories are kept in the background: editors see them, nobody can edit them.
      if (cfg.isEditor && Array.isArray(book.legacy_categories)) {
        metaGrid.appendChild(
          el("div", { class: "km-detail-field km-detail-field-full" }, [
            el("span", { class: "km-detail-field-label" }, [
              "Pôvodná kategória",
            ]),
            el("span", { class: "km-detail-field-value" }, [
              book.legacy_categories.length
                ? book.legacy_categories.join(", ")
                : "—",
            ]),
          ]),
        );
      }
      page.appendChild(metaGrid);

      if (cfg.isEditor) {
        // Editor-only controls: always km-btn-editor (--km-editor purple), see km-public.css.
        var hasChanges = Object.keys(detailEdit.draft).length > 0;
        var saveAttrs = {
          class: "km-btn km-btn-editor",
          onclick: function () {
            if (!hasChanges) return;
            apiFetch("/books/" + book.id, {
              method: "PUT",
              body: JSON.stringify(detailEdit.draft),
            }).then(function () {
              Object.keys(detailEdit.draft).forEach(function (key) {
                book[key] = detailEdit.draft[key];
              });
              detailEdit.draft = {};
              detailEdit.editingKey = null;
              refreshDetailView();
              fetchBooks();
            });
          },
        };
        if (!hasChanges) saveAttrs.disabled = "disabled";
        var saveBtn = el("button", saveAttrs, [t.save || "Uložiť"]);
        var cancelBtn = el(
          "button",
          {
            class: "km-btn km-btn-editor-outline",
            onclick: function () {
              detailEdit.draft = {};
              detailEdit.editingKey = null;
              refreshDetailView();
            },
          },
          [t.cancel || "Zrušiť"],
        );
        var delBtn = el(
          "button",
          {
            class: "km-btn km-btn-editor-secondary",
            onclick: function () {
              closeDetailHistory();
              refreshDetailView();
              confirmDangerAction(
                t.confirmArchive || "Presunúť túto knihu do koša?",
                t.delete || "Presunúť do koša",
                function () {
                  apiFetch("/books/" + book.id, { method: "DELETE" }).then(
                    function () {
                      navigateToCatalog();
                      fetchBooks();
                    },
                  );
                },
              );
            },
          },
          [t.delete || "Presunúť do koša"],
        );
        var historyBtn = el(
          "button",
          {
            class: "km-btn km-btn-editor-outline",
            onclick: function () {
              if (detailHistory.bookId !== book.id) {
                detailHistory = {
                  bookId: book.id,
                  loaded: false,
                  loading: false,
                  items: [],
                };
              }
              if (detailHistory.loaded) {
                detailHistory.loaded = false;
                detailHistory.items = [];
                refreshDetailView();
                return;
              }
              loadBookHistory(book.id);
            },
          },
          [t.history || "História"],
        );
        var actionButton = delBtn;
        if (book.deleted_at) {
          var restoreBtn = el(
            "button",
            {
              class: "km-btn km-btn-editor",
              onclick: function () {
                closeDetailHistory();
                refreshDetailView();
                apiFetch("/books/" + book.id + "/restore", {
                  method: "POST",
                }).then(function () {
                  book.deleted_at = null;
                  book.deleted_by = null;
                  refreshDetailView();
                });
              },
            },
            [t.restore || "Obnoviť knihu"],
          );
          var permanentDeleteBtn = el(
            "button",
            {
              class: "km-btn km-btn-danger",
              onclick: function () {
                closeDetailHistory();
                refreshDetailView();
                confirmDangerAction(
                  t.confirmPermanentDelete ||
                    "Toto odstránenie je nevratné a vymaže aj celú históriu knihy. Naozaj chcete pokračovať?",
                  t.permanentDelete || "Trvalo odstrániť",
                  function () {
                    apiFetch("/books/" + book.id + "/permanent", {
                      method: "DELETE",
                    }).then(function () {
                      navigateToArchive();
                      fetchBooks();
                    });
                  },
                );
              },
            },
            [t.permanentDelete || "Trvalo odstrániť"],
          );
          actionButton = el("div", { class: "km-detail-destructive-actions" }, [
            restoreBtn,
            permanentDeleteBtn,
          ]);
        }
        var actionItems = [
          hasChanges ? saveBtn : null,
          hasChanges ? cancelBtn : null,
        ];
        if (!hasChanges || book.deleted_at) actionItems.push(actionButton);
        if (!hasChanges) actionItems.push(historyBtn);
        page.appendChild(el("div", { class: "km-actions" }, actionItems));
        if (
          !hasChanges &&
          detailHistory.bookId === book.id &&
          (detailHistory.loaded || detailHistory.loading)
        ) {
          page.appendChild(renderHistoryPanel());
        }
      } else if (book.available_copies > 0) {
        page.appendChild(renderReservationForm(book));
      } else {
        page.appendChild(
          el("p", { class: "km-warning" }, [
            (t.copiesAvailable || "Dostupné") + ": 0",
          ]),
        );
      }

      return page;
    }

    function formatHistoryValue(value, field) {
      if (null === value || undefined === value || "" === value) {
        return t.emptyValue || "-";
      }
      if (field === "image") {
        if (value === cfg.defaultImage) return "predvolená obálka";
        return String(value).split("/").pop();
      }
      if (Array.isArray(value)) {
        return value.length ? value.join(", ") : t.emptyValue || "-";
      }
      return String(value);
    }

    function historyFieldLabel(field) {
      var labels = {
        title: "Názov",
        author: "Autor",
        co_authors: "Spoluautori",
        language: "Jazyk",
        categories: "Pôvodné kategórie",
        main_category: "Hlavná kategória",
        secondary_categories: "Vedľajšie kategórie",
        published_year: "Rok vydania",
        edition: "Vydanie",
        series: "Edícia",
        isbn: "ISBN",
        page_count: "Počet strán",
        total_copies: "Celkovo kópií",
        available_copies: "Dostupné kópie",
        image: "Obálka",
        description: "Popis",
        last_checked_at: "Naposledy skontrolované",
        archived: "V koši",
      };
      return labels[field] || field;
    }

    function renderHistoryPanel() {
      var panel = el("section", { class: "km-history" });
      panel.appendChild(
        el("h3", { class: "km-history-title" }, [t.history || "História"]),
      );
      if (detailHistory.loading) {
        panel.appendChild(
          el("p", { class: "km-history-empty" }, [
            t.historyLoading || "Načítavam históriu...",
          ]),
        );
        return panel;
      }
      if (!detailHistory.items.length) {
        panel.appendChild(
          el("p", { class: "km-history-empty" }, [
            t.historyEmpty || "História je prázdna.",
          ]),
        );
        return panel;
      }
      var list = el("ol", { class: "km-history-list" });
      detailHistory.items.forEach(function (item) {
        var entry = el("li", { class: "km-history-item" });
        var date = item.created_at
          ? new Date(item.created_at.replace(" ", "T") + "Z")
          : null;
        var dateText =
          date && !isNaN(date.getTime())
            ? date.toLocaleString("sk-SK")
            : item.created_at;
        var actor = item.user_name || "Systém";
        var changedKeys = Object.keys(item.changes || {});
        var onlyImage =
          "updated" === item.event_type &&
          changedKeys.length === 1 &&
          changedKeys[0] === "image";
        var verb =
          "created" === item.event_type
            ? "vytvoril knihu"
            : "archived" === item.event_type
              ? "presunul knihu do koša"
              : "restored" === item.event_type
                ? "obnovil knihu"
                : onlyImage
                  ? item.changes.image.new === cfg.defaultImage
                    ? "odstránil obálku"
                    : "zmenil obálku"
                  : t.historyChanges || "urobil zmeny";
        entry.appendChild(
          el("div", { class: "km-history-meta" }, [
            dateText + "  " + actor + " " + verb + ":",
          ]),
        );
        var changes = el("ul", { class: "km-history-changes" });
        Object.keys(item.changes || {}).forEach(function (field) {
          if (field === "recommended_for") return;
          var change = item.changes[field] || {};
          changes.appendChild(
            el("li", {}, [
              historyFieldLabel(field) +
                ": " +
                formatHistoryValue(change.old, field) +
                " → " +
                formatHistoryValue(change.new, field),
            ]),
          );
        });
        if (changes.children.length) entry.appendChild(changes);
        list.appendChild(entry);
      });
      panel.appendChild(list);
      return panel;
    }

    function loadBookHistory(bookId) {
      detailHistory = {
        bookId: bookId,
        loaded: false,
        loading: true,
        items: [],
      };
      refreshDetailView();
      apiFetch("/books/" + bookId + "/history")
        .then(function (items) {
          if (detailHistory.bookId !== bookId) return;
          detailHistory.items = Array.isArray(items) ? items : [];
          detailHistory.loaded = true;
          detailHistory.loading = false;
          refreshDetailView();
        })
        .catch(function () {
          if (detailHistory.bookId !== bookId) return;
          detailHistory.items = [];
          detailHistory.loaded = true;
          detailHistory.loading = false;
          refreshDetailView();
        });
    }

    function showArchiveView() {
      var requestToken = ++navigationToken;
      currentDetailBook = null;
      detailHost.innerHTML = "";
      categoriesHost.innerHTML = "";
      reservationsHost.innerHTML = "";
      archiveHost.innerHTML = "";
      wrap.classList.remove(
        "km-showing-detail",
        "km-showing-categories",
        "km-showing-reservations",
      );
      var list = el("div", { class: "km-archive-list" });
      var page = el("div", { class: "km-archive-page" });
      page.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: navigateToCatalog,
          },
          ["← " + (t.backToCatalog || "Späť do katalógu")],
        ),
      );
      page.appendChild(
        el("h2", { class: "km-editor-heading" }, [t.archive || "Kôš"]),
      );
      page.appendChild(list);
      archiveHost.appendChild(page);
      wrap.classList.add("km-showing-archive");
      apiFetch("/archived-books").then(function (books) {
        if (requestToken !== navigationToken) return;
        if (!books.length) {
          list.appendChild(
            el("p", { class: "km-history-empty" }, [
              t.noArchivedBooks || "Kôš je prázdny.",
            ]),
          );
          return;
        }
        books.forEach(function (book) {
          var row = el("div", { class: "km-archive-row" });
          row.appendChild(el("span", {}, [book.title || "Kniha #" + book.id]));
          row.appendChild(
            el(
              "button",
              {
                class: "km-btn km-btn-editor-outline",
                onclick: function () {
                  navigateToBook(book.id, null, "archive");
                },
              },
              [t.viewDetail || "Zobraziť"],
            ),
          );
          list.appendChild(row);
        });
      });
    }

    function showDetail(id, sourceEl, immediate) {
      var requestToken = ++navigationToken;
      return apiFetch("/books/" + id)
        .then(function (book) {
          if (requestToken !== navigationToken) return undefined;
          currentDetailBook = book;
          if (detailEdit.bookId !== book.id) {
            detailEdit = { bookId: book.id, editingKey: null, draft: {} };
          }
          if (detailHistory.bookId !== book.id) {
            detailHistory = {
              bookId: book.id,
              loaded: false,
              loading: false,
              items: [],
            };
          }
          function mutate() {
            if (requestToken !== navigationToken) return;
            if (sourceEl) sourceEl.style.removeProperty("view-transition-name");
            detailHost.innerHTML = "";
            archiveHost.innerHTML = "";
            var page = renderDetailView(book);
            var hero = page.querySelector(".km-detail-hero");
            if (hero)
              hero.style.setProperty("view-transition-name", TRANSITION_NAME);
            detailHost.appendChild(page);
            wrap.classList.add("km-showing-detail");
            wrap.classList.remove("km-showing-archive");
          }
          if (sourceEl)
            sourceEl.style.setProperty("view-transition-name", TRANSITION_NAME);
          if (immediate) {
            mutate();
            return undefined;
          }
          return applyViewTransition(mutate);
        })
        .catch(function (err) {
          if (requestToken !== navigationToken) return undefined;
          if (sourceEl) sourceEl.style.removeProperty("view-transition-name");
          currentDetailBook = null;
          detailEdit = { bookId: null, editingKey: null, draft: {} };
          detailHost.innerHTML = "";
          var errorPage = el("div", { class: "km-detail-page" });
          errorPage.appendChild(
            el(
              "button",
              {
                type: "button",
                class: "km-detail-back",
                onclick: navigateToCatalog,
              },
              ["← " + (t.backToCatalog || "Späť do katalógu")],
            ),
          );
          errorPage.appendChild(
            el("p", { class: "km-form-msg km-error" }, [
              err && err.message ? err.message : "Knihu sa nepodarilo načítať.",
            ]),
          );
          detailHost.appendChild(errorPage);
          wrap.classList.add("km-showing-detail");
        });
    }

    // Re-renders the open detail page in place, without a navigation transition.
    function refreshDetailView() {
      if (!currentDetailBook) return;
      detailHost.innerHTML = "";
      detailHost.appendChild(renderDetailView(currentDetailBook));
      var activeInput = detailHost.querySelector(
        ".km-detail-field-editing input, .km-detail-field-editing textarea, .km-detail-headline-field input, .km-detail-headline-field textarea",
      );
      if (activeInput) activeInput.focus();
    }

    function navigateToBook(id, sourceEl, source) {
      state.scrollY = window.scrollY;
      var url = new URL(window.location.href);
      url.searchParams.set("kniha", id);
      url.searchParams.delete("km_view");
      if (source === "archive") url.searchParams.set("km_from", "archive");
      else url.searchParams.delete("km_from");
      history.pushState({ kniha: id, km_from: source || null }, "", url);
      activeUrl = url.href;
      showDetail(id, sourceEl).then(scrollToPageTopAfterTransition);
    }

    function navigateToNewBook() {
      if (hasUnsavedChanges()) {
        confirmLeaveIfUnsaved(navigateToNewBook);
        return;
      }
      state.scrollY = window.scrollY;
      var url = new URL(window.location.href);
      url.searchParams.set("kniha", NEW_BOOK_ID);
      url.searchParams.delete("km_view");
      url.searchParams.delete("km_from");
      history.pushState({ kniha: NEW_BOOK_ID }, "", url);
      activeUrl = url.href;
      showNewBookPage();
    }

    // Full-page "create book" view (replaces the old popup form).
    function showNewBookPage(immediate) {
      ++navigationToken;
      currentDetailBook = null;
      detailEdit = { bookId: null, editingKey: null, draft: {} };
      function mutate() {
        detailHost.innerHTML = "";
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        archiveHost.innerHTML = "";
        detailHost.appendChild(renderNewBookView());
        wrap.classList.add("km-showing-detail");
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-reservations");
        wrap.classList.remove("km-showing-archive");
      }
      if (immediate) {
        mutate();
        return undefined;
      }
      return applyViewTransition(mutate);
    }

    function navigateToCatalog() {
      if (hasUnsavedChanges()) {
        confirmLeaveIfUnsaved(navigateToCatalog);
        return;
      }
      ++navigationToken;
      var closingId = getBookIdFromUrl();
      var wasPageView =
        wrap.classList.contains("km-showing-categories") ||
        wrap.classList.contains("km-showing-reservations") ||
        wrap.classList.contains("km-showing-archive");
      var url = new URL(window.location.href);
      url.searchParams.delete("kniha");
      url.searchParams.delete("km_view");
      url.searchParams.delete("km_from");
      history.replaceState({}, "", url);
      activeUrl = url.href;

      if (wasPageView) {
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        archiveHost.innerHTML = "";
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-reservations");
        wrap.classList.remove("km-showing-archive");
        return;
      }

      currentDetailBook = null;
      detailEdit = { bookId: null, editingKey: null, draft: {} };
      clearDirtyForms();

      var hero = detailHost.querySelector(".km-detail-hero");
      applyViewTransition(function () {
        if (hero) hero.style.removeProperty("view-transition-name");
        detailHost.innerHTML = "";
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        wrap.classList.remove("km-showing-detail");
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-reservations");
        var card = closingId
          ? grid.querySelector('[data-book-id="' + closingId + '"]')
          : null;
        if (card)
          card.style.setProperty("view-transition-name", TRANSITION_NAME);
      }).then(function () {
        var card = closingId
          ? grid.querySelector('[data-book-id="' + closingId + '"]')
          : null;
        if (card) card.style.removeProperty("view-transition-name");
        window.scrollTo(0, state.scrollY || 0);
      });
    }

    function navigateToCategories() {
      if (hasUnsavedChanges()) {
        confirmLeaveIfUnsaved(navigateToCategories);
        return;
      }
      ++navigationToken;
      var url = new URL(window.location.href);
      url.searchParams.delete("kniha");
      url.searchParams.set("km_view", "categories");
      history.pushState({ km_view: "categories" }, "", url);
      activeUrl = url.href;
      showCategoriesView();
    }

    function navigateToReservations() {
      if (hasUnsavedChanges()) {
        confirmLeaveIfUnsaved(navigateToReservations);
        return;
      }
      ++navigationToken;
      var url = new URL(window.location.href);
      url.searchParams.delete("kniha");
      url.searchParams.set("km_view", "reservations");
      history.pushState({ km_view: "reservations" }, "", url);
      activeUrl = url.href;
      showReservationsView();
    }

    function navigateToArchive() {
      if (hasUnsavedChanges()) {
        confirmLeaveIfUnsaved(navigateToArchive);
        return;
      }
      ++navigationToken;
      var url = new URL(window.location.href);
      url.searchParams.delete("kniha");
      url.searchParams.set("km_view", "archive");
      url.searchParams.delete("km_from");
      history.pushState({ km_view: "archive" }, "", url);
      activeUrl = url.href;
      showArchiveView();
    }

    function showCategoriesView() {
      currentDetailBook = null;
      detailHost.innerHTML = "";
      wrap.classList.remove("km-showing-detail");
      reservationsHost.innerHTML = "";
      archiveHost.innerHTML = "";
      wrap.classList.remove("km-showing-reservations");
      wrap.classList.remove("km-showing-archive");
      categoriesHost.innerHTML = "";
      categoriesHost.appendChild(renderCategoriesView());
      wrap.classList.add("km-showing-categories");
    }

    function refreshCategoriesView() {
      delete dirtyForms.categories;
      var requestToken = ++navigationToken;
      return fetchMeta().then(function () {
        if (requestToken !== navigationToken || !getCategoriesViewFromUrl()) {
          return;
        }
        showCategoriesView();
      });
    }

    function showReservationsView() {
      var requestToken = ++navigationToken;
      currentDetailBook = null;
      detailHost.innerHTML = "";
      categoriesHost.innerHTML = "";
      wrap.classList.remove("km-showing-detail");
      wrap.classList.remove("km-showing-categories");
      reservationsHost.innerHTML = "";
      archiveHost.innerHTML = "";

      var page = el("div", { class: "km-reservations-page" });
      var list = el("div", { class: "km-reservations-list" });
      var msg = el("p", { class: "km-form-msg" });
      var filter = el("select", { class: "km-input km-reservation-filter" });
      var search = el("input", {
        class: "km-input km-reservation-search",
        type: "search",
        placeholder: t.reservationSearch || "Hľadať rezervácie",
      });
      var summary = el("div", { class: "km-reservation-summary" });
      var statusLabels = {
        pending: t.reservationPending || "Čaká na spracovanie",
        assigned: t.reservationAssigned || "Pridelená",
        borrowed: t.reservationBorrowed || "Vypožičaná",
        returned: t.reservationReturned || "Vrátená",
        rejected: t.reservationRejected || "Zamietnutá",
      };
      var statuses = [
        "",
        "pending",
        "assigned",
        "borrowed",
        "returned",
        "rejected",
      ];
      statuses.forEach(function (status) {
        filter.appendChild(
          el("option", { value: status }, [
            status ? statusLabels[status] : t.allStatuses || "Všetky stavy",
          ]),
        );
      });
      page.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: navigateToCatalog,
          },
          ["← " + (t.backToCatalog || "Späť do katalógu")],
        ),
      );
      page.appendChild(
        el("h2", { class: "km-editor-heading" }, [
          t.reservations || "Rezervácie",
        ]),
      );
      page.appendChild(
        el("div", { class: "km-reservation-toolbar" }, [filter, search]),
      );
      page.appendChild(summary);
      page.appendChild(msg);
      page.appendChild(list);
      reservationsHost.appendChild(page);
      wrap.classList.add("km-showing-reservations");

      function transition(reservation, status, values) {
        values = values || {};
        return apiFetch("/reservations/" + reservation.id + "/transition", {
          method: "POST",
          body: JSON.stringify({
            status: status,
            librarian_name: values.librarian_name || "",
            note: values.note || "",
          }),
        });
      }

      function openTransitionForm(reservation, status) {
        var isAssignment = status === "assigned";
        var content = el("form", { class: "km-reservation-form" });
        var input = el("input", {
          class: "km-input",
          type: "text",
          maxlength: "200",
          required: "required",
          placeholder: t.librarianName || "Meno knihovníka",
        });
        var note = el("textarea", {
          class: "km-input",
          rows: "3",
          maxlength: "500",
          placeholder: t.reservationNote || "Interná poznámka",
        });
        content.appendChild(
          el("h3", {}, [
            isAssignment ? t.takeReservation : t.rejectReservation,
          ]),
        );
        if (isAssignment) {
          content.appendChild(
            el("label", { class: "km-form-label" }, [
              t.librarianName || "Meno knihovníka",
              input,
            ]),
          );
        } else {
          content.appendChild(
            el("label", { class: "km-form-label" }, [
              t.reservationNote || "Interná poznámka",
              note,
            ]),
          );
        }
        var formMsg = el("p", { class: "km-form-msg" });
        var submit = el(
          "button",
          { class: "km-btn km-btn-editor", type: "submit" },
          [isAssignment ? t.takeReservation : t.rejectReservation],
        );
        content.appendChild(formMsg);
        content.appendChild(
          el("div", { class: "km-actions" }, [
            submit,
            el(
              "button",
              { class: "km-btn", type: "button", onclick: closeModal },
              [t.cancel || "Zrušiť"],
            ),
          ]),
        );
        content.addEventListener("submit", function (event) {
          event.preventDefault();
          var values = isAssignment
            ? { librarian_name: input.value.trim() }
            : { note: note.value.trim() };
          if (isAssignment && !values.librarian_name) {
            formMsg.textContent =
              t.librarianRequired || "Zadajte meno knihovníka.";
            formMsg.className = "km-form-msg km-error";
            return;
          }
          submit.disabled = true;
          transition(reservation, status, values)
            .then(function () {
              closeModal();
              loadRows();
              fetchBooks();
            })
            .catch(function (err) {
              submit.disabled = false;
              formMsg.textContent =
                err && err.message ? err.message : String(err);
              formMsg.className = "km-form-msg km-error";
            });
        });
        openModal(content);
        (isAssignment ? input : note).focus();
      }

      function renderAction(reservation) {
        var actions = [];
        function addAction(label, status, className, needsForm) {
          var button = el(
            "button",
            {
              class: "km-btn " + (className || "km-btn-editor"),
              type: "button",
            },
            [label],
          );
          button.addEventListener("click", function () {
            if (needsForm) {
              openTransitionForm(reservation, status);
              return;
            }
            button.disabled = true;
            transition(reservation, status)
              .then(function () {
                loadRows();
                fetchBooks();
              })
              .catch(function (err) {
                button.disabled = false;
                msg.textContent =
                  err && err.message ? err.message : String(err);
                msg.className = "km-form-msg km-error";
              });
          });
          actions.push(button);
        }
        if (reservation.status === "pending") {
          addAction(
            t.takeReservation || "Prevziať rezerváciu",
            "assigned",
            "km-btn-editor",
            true,
          );
          addAction(
            t.rejectReservation || "Zamietnuť rezerváciu",
            "rejected",
            "km-btn-danger",
            true,
          );
        } else if (reservation.status === "assigned") {
          addAction(t.markBorrowed || "Označiť ako vypožičanú", "borrowed");
          addAction(
            t.releaseReservation || "Uvoľniť",
            "pending",
            "km-btn-editor-outline",
          );
          addAction(
            t.rejectReservation || "Zamietnuť rezerváciu",
            "rejected",
            "km-btn-danger",
            true,
          );
        } else if (reservation.status === "borrowed") {
          addAction(t.markReturned || "Označiť ako vrátenú", "returned");
        }
        return el("div", { class: "km-res-actions" }, actions);
      }

      function loadRows() {
        var query = "/reservations";
        var params = [];
        if (filter.value)
          params.push("status=" + encodeURIComponent(filter.value));
        if (search.value.trim())
          params.push("q=" + encodeURIComponent(search.value.trim()));
        if (params.length) query += "?" + params.join("&");
        list.innerHTML = "";
        msg.textContent = "";
        return apiFetch(query)
          .then(function (rows) {
            if (requestToken !== navigationToken) return;
            var counts = {};
            rows.forEach(function (row) {
              counts[row.status] = (counts[row.status] || 0) + 1;
            });
            summary.innerHTML = "";
            statuses.slice(1).forEach(function (status) {
              summary.appendChild(
                el(
                  "span",
                  {
                    class:
                      "km-reservation-stat km-reservation-status-" + status,
                  },
                  [statusLabels[status] + ": " + (counts[status] || 0)],
                ),
              );
            });
            if (!rows.length) {
              list.appendChild(
                el("p", { class: "km-reservation-empty" }, [
                  t.reservationNoResults ||
                    "Pre tento filter sa nenašli žiadne rezervácie.",
                ]),
              );
              return;
            }
            rows.forEach(function (r) {
              var row = el("div", { class: "km-res-row" });
              row.appendChild(
                el("div", { class: "km-res-info" }, [
                  el("strong", {}, [r.book_title || "Kniha #" + r.book_id]),
                  el(
                    "span",
                    {
                      class: "km-res-status km-reservation-status-" + r.status,
                    },
                    [statusLabels[r.status] || r.status],
                  ),
                  el("p", {}, [
                    r.name + " — " + r.email + (r.phone ? " — " + r.phone : ""),
                  ]),
                  r.librarian_name
                    ? el("p", {}, [
                        (t.librarianName || "Knihovník") +
                          ": " +
                          r.librarian_name,
                      ])
                    : null,
                  r.note ? el("p", { class: "km-res-note" }, [r.note]) : null,
                ]),
              );
              row.appendChild(renderAction(r));
              list.appendChild(row);
            });
          })
          .catch(function (err) {
            if (requestToken !== navigationToken) return;
            msg.textContent = err && err.message ? err.message : String(err);
            msg.className = "km-form-msg km-error";
          });
      }

      filter.addEventListener("change", loadRows);
      search.addEventListener("input", loadRows);
      loadRows();
    }

    // Warn before closing/reloading/navigating away with unsaved form changes.
    window.addEventListener("beforeunload", function (e) {
      if (hasUnsavedChanges()) {
        e.preventDefault();
        e.returnValue = "";
      }
    });

    function applyUrlState() {
      var id = getBookIdFromUrl();
      var hasRestrictedView =
        getCategoriesViewFromUrl() ||
        getReservationsViewFromUrl() ||
        getArchiveViewFromUrl();
      if (hasRestrictedView && !cfg.isEditor) {
        var publicUrl = new URL(window.location.href);
        publicUrl.searchParams.delete("km_view");
        history.replaceState({}, "", publicUrl);
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        archiveHost.innerHTML = "";
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-archive");
        wrap.classList.remove("km-showing-reservations");
        wrap.classList.remove("km-showing-detail");
        detailHost.innerHTML = "";
        return;
      }
      if (getCategoriesViewFromUrl()) {
        showCategoriesView();
      } else if (getReservationsViewFromUrl()) {
        showReservationsView();
      } else if (getArchiveViewFromUrl()) {
        showArchiveView();
      } else if (id) {
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-reservations");
        wrap.classList.remove("km-showing-archive");
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        archiveHost.innerHTML = "";
        if (id === NEW_BOOK_ID) {
          showNewBookPage(true);
        } else {
          showDetail(id);
        }
      } else if (
        wrap.classList.contains("km-showing-detail") ||
        wrap.classList.contains("km-showing-categories") ||
        wrap.classList.contains("km-showing-reservations") ||
        wrap.classList.contains("km-showing-archive")
      ) {
        currentDetailBook = null;
        detailEdit = { bookId: null, editingKey: null, draft: {} };
        categoriesHost.innerHTML = "";
        reservationsHost.innerHTML = "";
        archiveHost.innerHTML = "";
        wrap.classList.remove("km-showing-categories");
        wrap.classList.remove("km-showing-reservations");
        applyViewTransition(function () {
          detailHost.innerHTML = "";
          wrap.classList.remove("km-showing-detail");
        }).then(function () {
          window.scrollTo(0, state.scrollY || 0);
        });
      }
    }

    window.addEventListener("popstate", function () {
      var targetUrl = window.location.href;
      if (!hasUnsavedChanges()) {
        activeUrl = targetUrl;
        applyUrlState();
        return;
      }

      history.replaceState({}, "", activeUrl);
      confirmDangerAction(
        t.unsavedChangesWarning ||
          "Máte neuložené zmeny. Naozaj chcete odísť bez uloženia?",
        t.leaveWithoutSaving || "Odísť bez uloženia",
        function () {
          detailEdit.draft = {};
          detailEdit.editingKey = null;
          clearDirtyForms();
          activeUrl = targetUrl;
          history.replaceState({}, "", targetUrl);
          applyUrlState();
        },
      );
    });

    function renderReservationForm(book) {
      var form = el("form", { class: "km-res-form", novalidate: "novalidate" });
      var msg = el("p", { class: "km-form-msg", role: "alert" });
      var idPrefix = "km-res-" + book.id + "-";

      form.appendChild(
        el("h3", { class: "km-detail-desc-heading" }, ["Rezervovať"]),
      );
      form.appendChild(el("p", {}, [cfg.introText || ""]));

      var validators = {
        name: function (value) {
          value = value.trim();
          if (!value) return t.errNameRequired || "Zadajte svoje meno.";
          if (value.length < 2)
            return t.errNameShort || "Meno je príliš krátke.";
          return "";
        },
        email: function (value) {
          value = value.trim();
          if (!value) return t.errEmailRequired || "Zadajte svoj email.";
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
            return (
              t.errEmailInvalid ||
              "Zadajte platný email, napríklad jana@example.sk."
            );
          }
          return "";
        },
        phone: function (value) {
          value = value.trim();
          if (!value) return t.errPhoneRequired || "Zadajte telefónne číslo.";
          var digits = value.replace(/\D/g, "").length;
          if (!/^\+?[0-9\s()\/-]+$/.test(value) || digits < 9 || digits > 15) {
            return (
              t.errPhoneInvalid ||
              "Zadajte platné telefónne číslo (9 až 15 číslic), napríklad +421 900 123 456."
            );
          }
          return "";
        },
        consent: function (checked) {
          return checked
            ? ""
            : t.errConsentRequired ||
                "Pre odoslanie je potrebný súhlas so spracovaním osobných údajov.";
        },
      };

      var fields = {};

      // Wires one input to its validator: error text, aria state and live re-validation.
      function registerField(key, input, wrapper, valueOf) {
        var errorEl = el("p", {
          class: "km-field-error",
          id: idPrefix + key + "-error",
          "aria-live": "polite",
        });
        input.setAttribute("aria-describedby", errorEl.id);
        wrapper.appendChild(errorEl);
        var field = { input: input, wrapper: wrapper, touched: false };
        field.show = function (message) {
          errorEl.textContent = message;
          input.setAttribute("aria-invalid", message ? "true" : "false");
          wrapper.classList.toggle("km-field--invalid", !!message);
          wrapper.classList.toggle(
            "km-field--valid",
            !message && field.touched,
          );
        };
        field.validate = function () {
          var message = validators[key](valueOf());
          field.show(message);
          return !message;
        };
        var isCheckbox = input.type === "checkbox";
        input.addEventListener(isCheckbox ? "change" : "blur", function () {
          field.touched = true;
          field.validate();
        });
        if (!isCheckbox) {
          input.addEventListener("input", function () {
            if (field.touched) field.validate();
          });
        }
        fields[key] = field;
        return field;
      }

      function textField(key, label, attrs) {
        attrs.id = idPrefix + key;
        attrs.name = key;
        attrs.class = "km-input";
        attrs.required = "required";
        var input = el("input", attrs);
        var wrapper = el("div", { class: "km-field" }, [
          el("label", { class: "km-form-label", for: attrs.id }, [label]),
          input,
        ]);
        registerField(key, input, wrapper, function () {
          return input.value;
        });
        return wrapper;
      }

      var nameWrap = textField("name", t.yourName || "Vaše meno", {
        type: "text",
        placeholder: "Ján Novák",
        autocomplete: "name",
      });
      var emailWrap = textField("email", t.yourEmail || "Váš email", {
        type: "email",
        placeholder: "jan@example.sk",
        autocomplete: "email",
      });
      var phoneWrap = textField(
        "phone",
        t.yourPhone || "Vaše telefónne číslo",
        {
          type: "tel",
          placeholder: "+421 900 123 456",
          autocomplete: "tel",
          inputmode: "tel",
        },
      );
      var phoneInput = fields.phone.input;
      phoneInput.addEventListener("input", function () {
        var cleaned = phoneInput.value.replace(/[^0-9+\s()\/-]/g, "");
        if (cleaned !== phoneInput.value) phoneInput.value = cleaned;
      });

      var consentInput = el("input", {
        type: "checkbox",
        id: idPrefix + "consent",
        required: "required",
      });
      var consentWrap = el("div", { class: "km-field km-field--consent" }, [
        el("label", { class: "km-checkbox km-checkbox--consent" }, [
          consentInput,
          " " + (cfg.gdprText || ""),
        ]),
      ]);
      registerField("consent", consentInput, consentWrap, function () {
        return consentInput.checked;
      });

      var honeypot = el("input", {
        type: "text",
        class: "km-honeypot",
        tabindex: "-1",
        autocomplete: "off",
      });
      var submitBtn = el(
        "button",
        { type: "submit", class: "km-btn km-btn-primary" },
        [t.send || "Send"],
      );

      form.appendChild(nameWrap);
      form.appendChild(emailWrap);
      form.appendChild(phoneWrap);
      form.appendChild(consentWrap);
      form.appendChild(honeypot);
      form.appendChild(submitBtn);
      form.appendChild(msg);

      form.addEventListener("input", function () {
        markFormDirty("reservation-" + book.id);
      });
      form.addEventListener("change", function () {
        markFormDirty("reservation-" + book.id);
      });

      form.addEventListener("submit", function (e) {
        e.preventDefault();
        msg.textContent = "";
        msg.className = "km-form-msg";
        var firstInvalid = null;
        ["name", "email", "phone", "consent"].forEach(function (key) {
          fields[key].touched = true;
          if (!fields[key].validate() && !firstInvalid) {
            firstInvalid = fields[key].input;
          }
        });
        if (firstInvalid) {
          firstInvalid.focus();
          return;
        }
        submitBtn.disabled = true;
        apiFetch("/reservations", {
          method: "POST",
          body: JSON.stringify({
            book_id: book.id,
            name: nameWrap.querySelector("input").value.trim(),
            email: emailWrap.querySelector("input").value.trim(),
            phone: phoneInput.value.trim(),
            consent: consentInput.checked,
            website: honeypot.value,
          }),
        })
          .then(function () {
            delete dirtyForms["reservation-" + book.id];
            form.innerHTML = "";
            form.appendChild(
              el("p", { class: "km-success" }, [t.reservationSent || "Sent"]),
            );
          })
          .catch(function (err) {
            submitBtn.disabled = false;
            var serverFields = err && err.fields;
            var focused = false;
            if (serverFields) {
              Object.keys(serverFields).forEach(function (key) {
                if (!fields[key]) return;
                fields[key].touched = true;
                fields[key].show(serverFields[key]);
                if (!focused) {
                  fields[key].input.focus();
                  focused = true;
                }
              });
            }
            msg.textContent = err.message;
            msg.className = "km-form-msg km-error";
          });
      });

      return form;
    }

    // Validates required title and sane copy counts before a new book is saved.
    function validateNewBookInputs(inputs) {
      if (!inputs.title.value.trim()) {
        return "Zadajte názov knihy.";
      }
      var total =
        inputs.total_copies.value === ""
          ? 0
          : Number(inputs.total_copies.value);
      var available =
        inputs.available_copies.value === ""
          ? 0
          : Number(inputs.available_copies.value);
      if (!Number.isInteger(total) || total < 0) {
        return "Zadajte platný celkový počet kópií (0 alebo viac).";
      }
      if (!Number.isInteger(available) || available < 0) {
        return "Zadajte platný počet dostupných kópií (0 alebo viac).";
      }
      if (available > total) {
        return "Dostupných kópií nemôže byť viac ako celkový počet.";
      }
      return null;
    }

    // Full-page "create book" view: empty detail-style page with a Save button,
    // replacing the old popup form.
    function renderNewBookView() {
      var page = el("div", { class: "km-detail-page km-new-book-page" });

      page.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: function () {
              navigateToCatalog();
            },
          },
          ["← " + (t.close || "Späť do katalógu")],
        ),
      );

      page.appendChild(
        el("h2", { class: "km-editor-heading" }, [t.addBook || "Pridať knihu"]),
      );

      var msg = el("p", { class: "km-form-msg" });

      var fields = [
        ["title", "Názov", "text", true],
        ["author", "Autor", "text"],
        ["co_authors", "Spoluautori", "text"],
        ["language", "Jazyk", "text"],
        [
          "main_category",
          "Hlavná kategória",
          "select",
          false,
          getCategoryOptions(),
        ],
        [
          "secondary_categories",
          "Vedľajšie kategórie",
          "multiselect",
          false,
          getCategoryOptions(),
        ],
        [
          "published_year",
          "Rok vydania",
          "select-number",
          false,
          getYearOptions(),
        ],
        ["edition", "Vydanie", "text"],
        ["series", "Edícia", "text"],
        ["isbn", "ISBN", "text"],
        ["page_count", "Počet strán", "number", false, null, 1],
        ["total_copies", t.total || "Celkovo kópií", "number", false, null, 0],
        [
          "available_copies",
          t.copiesAvailable || "Dostupné kópie",
          "number",
          false,
          null,
          0,
        ],
        ["image", "Obrázok (URL)", "text"],
      ];

      var metaGrid = el("div", { class: "km-detail-grid" });
      var inputs = {};
      fields.forEach(function (f) {
        var name = f[0],
          label = f[1],
          type = f[2],
          required = f[3],
          options = f[4],
          min = f[5];
        var input = makeFieldInput(null, type, options, min);
        if (name === "image") input.value = cfg.defaultImage || "";
        if (required) input.setAttribute("required", "required");
        inputs[name] = input;
        metaGrid.appendChild(
          el("div", { class: "km-detail-field" }, [
            el("span", { class: "km-detail-field-label" }, [label]),
            el(
              "span",
              { class: "km-detail-field-value km-detail-field-editing" },
              [input],
            ),
          ]),
        );
      });

      var descInput = el("textarea", { class: "km-input", rows: "4" });
      inputs.description = descInput;
      metaGrid.appendChild(
        el("div", { class: "km-detail-field km-detail-field-full" }, [
          el("span", { class: "km-detail-field-label" }, ["Popis"]),
          el(
            "span",
            { class: "km-detail-field-value km-detail-field-editing" },
            [descInput],
          ),
        ]),
      );

      page.appendChild(metaGrid);
      page.appendChild(msg);
      page.addEventListener("input", function () {
        markFormDirty("new-book");
      });
      page.addEventListener("change", function () {
        markFormDirty("new-book");
      });

      var saveBtn = el(
        "button",
        {
          class: "km-btn km-btn-editor",
          onclick: function () {
            var error = validateNewBookInputs(inputs);
            if (error) {
              msg.textContent = error;
              msg.className = "km-form-msg km-error";
              return;
            }
            var payload = {};
            Object.keys(inputs).forEach(function (key) {
              var input = inputs[key];
              payload[key] = Array.isArray(input._values)
                ? input._values
                : input.value;
            });
            saveBtn.setAttribute("disabled", "disabled");
            apiFetch("/books", {
              method: "POST",
              body: JSON.stringify(payload),
            })
              .then(function (res) {
                delete dirtyForms["new-book"];
                fetchBooks();
                var url = new URL(window.location.href);
                url.searchParams.set("kniha", res.id);
                history.replaceState({ kniha: res.id }, "", url);
                return showDetail(res.id, null, true);
              })
              .catch(function (err) {
                saveBtn.removeAttribute("disabled");
                msg.textContent = err.message;
                msg.className = "km-form-msg km-error";
              });
          },
        },
        [t.save || "Uložiť"],
      );
      var cancelBtn = el(
        "button",
        {
          class: "km-btn km-btn-editor-outline",
          onclick: function () {
            navigateToCatalog();
          },
        },
        [t.cancel || "Zrušiť"],
      );
      page.appendChild(
        el("div", { class: "km-actions" }, [saveBtn, cancelBtn]),
      );

      return page;
    }

    /* ---------------- Categories management page (editor-only, ?km_view=categories) ---------------- */

    function renderCategoriesView() {
      var page = el("div", { class: "km-categories-page" });
      var categoryMsgEl = el("p", { class: "km-form-msg" });
      page.addEventListener("input", function () {
        markFormDirty("categories");
      });
      page.addEventListener("change", function () {
        markFormDirty("categories");
      });

      function showCategoryError(err) {
        categoryMsgEl.textContent =
          err && err.message ? err.message : String(err);
        categoryMsgEl.className = "km-form-msg km-error";
      }

      function createCategoryRowError() {
        return el("p", {
          class: "km-category-row-error",
          role: "alert",
          hidden: "hidden",
        });
      }

      function clearCategoryRowError(errorEl) {
        errorEl.textContent = "";
        errorEl.hidden = true;
      }

      function showCategoryRowError(err, errorEl, fields) {
        errorEl.textContent = err && err.message ? err.message : String(err);
        errorEl.hidden = false;
        if (err && err.field && fields && fields[err.field]) {
          fields[err.field].focus();
        }
      }

      function validateCategoryName(value) {
        if (!value) return "Zadajte názov kategórie.";
        if (value.length > 150) {
          return "Názov kategórie môže mať najviac 150 znakov.";
        }
        return null;
      }

      function validateCategoryCode(value) {
        if (!value) return t.categoryCodeRequired || "Zadajte kód kategórie.";
        if (value.length < 2 || value.length > 20) {
          return (
            t.categoryCodeLength || "Kód kategórie musí mať 2 až 20 znakov."
          );
        }
        if (!/^[A-Z0-9]+$/.test(value)) {
          return (
            t.categoryCodeFormat ||
            "Kód môže obsahovať iba veľké písmená a číslice."
          );
        }
        return null;
      }

      function validateCategoryColor(value) {
        if (!/^#[0-9a-fA-F]{6}$/.test(value || "")) {
          return (
            t.categoryColorInvalid ||
            "Farba kategórie musí byť v tvare #RRGGBB."
          );
        }
        return null;
      }

      function showCategoryValidationError(message, input, errorEl) {
        showCategoryRowError(new Error(message), errorEl);
        if (input) input.focus();
      }

      function bindCategoryCodeInput(input) {
        input.addEventListener("input", function () {
          input.value = input.value.toUpperCase();
        });
      }

      function bindCategoryRowError(controls, errorEl) {
        controls.addEventListener("input", function () {
          clearCategoryRowError(errorEl);
        });
        controls.addEventListener("change", function () {
          clearCategoryRowError(errorEl);
        });
      }

      function createCategoryColorControl(value) {
        var colorInput = el("input", {
          type: "color",
          class: "km-category-color-input",
          title: t.categoryColor || "Farba pozadia",
          "aria-label": t.categoryColor || "Farba pozadia",
        });
        var swatch = el("span", { class: "km-category-color-swatch" });
        var control = el("label", { class: "km-category-color-control" }, [
          swatch,
          colorInput,
        ]);
        colorInput.value = value || "#cccccc";
        function updateSwatch() {
          swatch.style.backgroundColor = colorInput.value;
        }
        colorInput.addEventListener("input", updateSwatch);
        colorInput.addEventListener("change", updateSwatch);
        updateSwatch();
        return { control: control, input: colorInput };
      }

      page.appendChild(
        el(
          "button",
          {
            type: "button",
            class: "km-detail-back",
            onclick: navigateToCatalog,
          },
          ["← " + (t.backToCatalog || "Späť do katalógu")],
        ),
      );
      page.appendChild(
        el("h2", { class: "km-editor-heading" }, [
          t.categoriesTitle || "Správa kategórií",
        ]),
      );
      page.appendChild(categoryMsgEl);

      function renderCategoryRow(cat) {
        var row = el("div", { class: "km-categories-row" });
        var swatch = el("span", {
          class: "km-category-swatch",
          style: "background-color:" + (cat.color || "transparent") + ";",
        });
        var nameSpan = el("span", { class: "km-categories-name" }, [
          swatch,
          cat.name,
        ]);
        var editBtn = el(
          "button",
          {
            type: "button",
            class: "km-btn km-categories-edit-btn",
            onclick: enterEdit,
          },
          [t.edit || "Upraviť"],
        );
        var deleteBtn = el(
          "button",
          {
            type: "button",
            class: "km-btn km-btn-danger km-categories-delete-btn",
            onclick: function () {
              confirmDangerAction(
                t.confirmDeleteCategory ||
                  "Naozaj chcete odstrániť túto kategóriu?",
                t.deleteCategory || "Zmazať",
                function () {
                  apiFetch("/categories/" + encodeURIComponent(cat.id), {
                    method: "DELETE",
                  })
                    .then(refreshCategoriesView)
                    .catch(showCategoryError);
                },
              );
            },
          },
          [t.deleteCategory || "Zmazať"],
        );
        row.appendChild(nameSpan);
        row.appendChild(
          el("div", { class: "km-categories-actions" }, [editBtn, deleteBtn]),
        );

        function enterEdit() {
          var input = el("input", { type: "text", class: "km-input" });
          input.value = cat.name;
          var codeInput = el("input", {
            type: "text",
            class: "km-input km-category-code-input",
            placeholder: t.categoryCode || "Sk-rátka",
            maxlength: "20",
            autocomplete: "off",
            required: "required",
          });
          codeInput.value = cat.code || "";
          bindCategoryCodeInput(codeInput);
          var colorControl = createCategoryColorControl(cat.color);
          var colorInput = colorControl.input;
          var controls = el("div", { class: "km-categories-edit-controls" });
          var errorEl = createCategoryRowError();
          var confirmBtn = el(
            "button",
            {
              type: "button",
              class: "km-btn km-btn-editor km-categories-save-btn",
            },
            [t.save || "Uložiť"],
          );
          confirmBtn.onclick = function () {
            var newName = input.value.trim();
            var validationError = validateCategoryName(newName);
            if (validationError) {
              showCategoryValidationError(validationError, input, errorEl);
              return;
            }
            var newCode = codeInput.value.trim().toUpperCase();
            validationError = validateCategoryCode(newCode);
            if (validationError) {
              showCategoryValidationError(validationError, codeInput, errorEl);
              return;
            }
            validationError = validateCategoryColor(colorInput.value);
            if (validationError) {
              showCategoryValidationError(validationError, colorInput, errorEl);
              return;
            }
            confirmBtn.disabled = true;
            apiFetch("/categories/" + encodeURIComponent(cat.id), {
              method: "PUT",
              body: JSON.stringify({
                name: newName,
                code: newCode,
                color: colorInput.value,
              }),
            })
              .then(refreshCategoriesView)
              .catch(function (err) {
                confirmBtn.disabled = false;
                showCategoryRowError(err, errorEl, {
                  name: input,
                  code: codeInput,
                  color: colorInput,
                });
              });
          };
          var cancelBtn = el(
            "button",
            {
              type: "button",
              class: "km-btn km-btn-editor-outline km-categories-save-btn",
              onclick: function () {
                row.replaceWith(renderCategoryRow(cat));
              },
            },
            [t.cancel || "Zrušiť"],
          );
          row.innerHTML = "";
          controls.appendChild(input);
          controls.appendChild(codeInput);
          controls.appendChild(colorControl.control);
          controls.appendChild(confirmBtn);
          controls.appendChild(cancelBtn);
          bindCategoryRowError(controls, errorEl);
          row.classList.add("km-categories-row-editing");
          row.appendChild(controls);
          row.appendChild(errorEl);
        }

        return row;
      }

      function renderCategorySection() {
        var section = el("div", { class: "km-categories-section" });
        var list = el("div", { class: "km-categories-list" });
        (state.meta.categories || []).forEach(function (cat) {
          list.appendChild(renderCategoryRow(cat));
        });
        section.appendChild(list);

        var nameInput = el("input", {
          type: "text",
          class: "km-input",
          placeholder: t.namePlaceholder || "Názov",
        });
        var codeInput = el("input", {
          type: "text",
          class: "km-input km-category-code-input",
          placeholder: t.categoryCode || "Skratka",
          maxlength: "20",
          autocomplete: "off",
          required: "required",
        });
        bindCategoryCodeInput(codeInput);
        var addColorControl = createCategoryColorControl("#cccccc");
        var addColorInput = addColorControl.input;
        var addControls = el("div", { class: "km-categories-add-row" });
        var addErrorEl = createCategoryRowError();
        var addBtn = el(
          "button",
          {
            type: "button",
            class: "km-btn km-btn-editor",
            onclick: function () {
              var name = nameInput.value.trim();
              var validationError = validateCategoryName(name);
              if (validationError) {
                showCategoryValidationError(
                  validationError,
                  nameInput,
                  addErrorEl,
                );
                return;
              }
              var code = codeInput.value.trim().toUpperCase();
              validationError = validateCategoryCode(code);
              if (validationError) {
                showCategoryValidationError(
                  validationError,
                  codeInput,
                  addErrorEl,
                );
                return;
              }
              validationError = validateCategoryColor(addColorInput.value);
              if (validationError) {
                showCategoryValidationError(
                  validationError,
                  addColorInput,
                  addErrorEl,
                );
                return;
              }
              addBtn.disabled = true;
              apiFetch("/categories", {
                method: "POST",
                body: JSON.stringify({
                  name: name,
                  code: code,
                  color: addColorInput.value,
                }),
              })
                .then(function () {
                  return refreshCategoriesView();
                })
                .then(function () {
                  showToast(
                    t.categoryAdded || "Kategória bola pridaná.",
                    "success",
                    3000,
                  );
                })
                .catch(function (err) {
                  addBtn.disabled = false;
                  showCategoryRowError(err, addErrorEl, {
                    name: nameInput,
                    code: codeInput,
                    color: addColorInput,
                  });
                });
            },
          },
          [t.addNew || "Pridať"],
        );
        addControls.appendChild(nameInput);
        addControls.appendChild(codeInput);
        addControls.appendChild(addColorControl.control);
        addControls.appendChild(addBtn);
        bindCategoryRowError(addControls, addErrorEl);
        var addWrap = el("div", { class: "km-categories-add-wrap" });
        addWrap.appendChild(addControls);
        addWrap.appendChild(addErrorEl);
        section.appendChild(addWrap);
        return section;
      }

      page.appendChild(renderCategorySection());

      // Original categories are read-only: kept in the background, not assignable to books.
      var legacyCategories = state.meta.legacy_categories || [];
      if (legacyCategories.length) {
        var legacySection = el("details", { class: "km-categories-legacy" }, [
          el("summary", { class: "km-categories-legacy-summary" }, [
            "Pôvodné kategórie (" +
              legacyCategories.length +
              ") - len na čítanie",
          ]),
        ]);
        var legacyList = el("div", { class: "km-categories-list" });
        legacyCategories.forEach(function (cat) {
          legacyList.appendChild(
            el("div", { class: "km-categories-row" }, [
              el("span", { class: "km-categories-name" }, [
                el("span", {
                  class: "km-category-swatch",
                  style:
                    "background-color:" + (cat.color || "transparent") + ";",
                }),
                cat.name,
              ]),
            ]),
          );
        });
        legacySection.appendChild(legacyList);
        page.appendChild(legacySection);
      }
      return page;
    }

    fetchMeta().then(function () {
      fetchBooks();
      if (
        getCategoriesViewFromUrl() ||
        getReservationsViewFromUrl() ||
        getArchiveViewFromUrl()
      ) {
        if (cfg.isEditor) {
          if (getCategoriesViewFromUrl()) {
            showCategoriesView();
          } else if (getReservationsViewFromUrl()) {
            showReservationsView();
          } else {
            showArchiveView();
          }
        } else {
          var url = new URL(window.location.href);
          url.searchParams.delete("km_view");
          history.replaceState({}, "", url);
          activeUrl = url.href;
        }
        return;
      }
      var initialId = getBookIdFromUrl();
      if (initialId === NEW_BOOK_ID) {
        showNewBookPage(true);
      } else if (initialId) {
        showDetail(initialId, null, true);
      }
    });
  }

  /* ---------------- Login ---------------- */

  function initLogin(root) {
    if (root.getAttribute("data-logged-in") === "1") {
      var logoutBtn = el(
        "button",
        {
          class: "km-btn",
          onclick: function () {
            apiFetch("/logout", { method: "POST" }).then(function () {
              window.location.reload();
            });
          },
        },
        [t.logout || "Log out"],
      );
      root.appendChild(logoutBtn);
      return;
    }

    var form = el("form", { class: "km-login-form" });
    var msg = el("p", { class: "km-form-msg" });
    var name = el("input", {
      type: "text",
      class: "km-input",
      placeholder: t.username || "Name",
      required: "required",
    });
    var password = el("input", {
      type: "password",
      class: "km-input",
      placeholder: t.password || "Password",
      required: "required",
    });
    var submitBtn = el(
      "button",
      { type: "submit", class: "km-btn km-btn-primary" },
      [t.login || "Log in"],
    );

    form.appendChild(name);
    form.appendChild(password);
    form.appendChild(submitBtn);
    form.appendChild(msg);

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      submitBtn.disabled = true;
      apiFetch("/login", {
        method: "POST",
        body: JSON.stringify({
          username: name.value,
          password: password.value,
          _wpnonce: cfg.loginNonce,
        }),
      })
        .then(function () {
          window.location.reload();
        })
        .catch(function (err) {
          submitBtn.disabled = false;
          msg.textContent = err.message || t.loginFailed || "Login failed";
          msg.className = "km-form-msg km-error";
        });
    });

    root.appendChild(form);
  }

  document.addEventListener("DOMContentLoaded", function () {
    var catalogRoot = document.getElementById("km-catalog-root");
    if (catalogRoot) initCatalog(catalogRoot);

    var loginRoot = document.getElementById("km-login-root");
    if (loginRoot) initLogin(loginRoot);
  });
})();
