(function () {
  "use strict";

  var widgets = document.querySelectorAll("[data-oznamy-intentions]");

  Array.prototype.forEach.call(widgets, function (widget) {
    var monthPicker = widget.querySelector(
      "[data-oznamy-intentions-month-picker]",
    );
    var monthTrigger =
      monthPicker &&
      monthPicker.querySelector("[data-oznamy-intentions-month-trigger]");
    var monthList =
      monthPicker && monthPicker.querySelector('[role="listbox"]');
    var monthOptions = monthList
      ? monthList.querySelectorAll("[data-oznamy-intentions-month-option]")
      : [];
    var panels = widget.querySelectorAll("[data-oznamy-intentions-panel]");
    var days = widget.querySelectorAll(".oznamy-intentions__day[data-date]");
    var searchInput = widget.querySelector("[data-oznamy-intentions-search]");
    var searchReset = widget.querySelector(
      "[data-oznamy-intentions-search-reset]",
    );
    var searchNoResults = widget.querySelector(
      "[data-oznamy-intentions-search-no-results]",
    );
    var searchStatus = widget.querySelector(
      "[data-oznamy-intentions-search-status]",
    );
    var currentHeading = widget.querySelector(
      "[data-oznamy-intentions-current-heading]",
    );
    var selectedMonth = null;
    var searchAnnouncementTimer = null;
    var dayRecords = Array.prototype.map.call(days, function (day) {
      var intentions = day.querySelectorAll(".oznamy-intentions__text");
      return {
        element: day,
        panel: day.closest("[data-oznamy-intentions-panel]"),
        intentions: Array.prototype.map.call(intentions, function (intention) {
          var text = intention.textContent || "";
          return {
            element: intention,
            text: text,
            normalized: normalizeSearchTextWithOffsets(text),
          };
        }),
      };
    });

    function normalizeSearchText(value) {
      return Array.from(value)
        .map(function (character) {
          return character
            .normalize("NFD")
            .replace(
              /[\u0300-\u036f\u1ab0-\u1aff\u1dc0-\u1dff\u20d0-\u20ff\ufe20-\ufe2f]/g,
              "",
            )
            .toLowerCase();
        })
        .join("");
    }

    function normalizeSearchTextWithOffsets(value) {
      var normalized = "";
      var starts = [];
      var ends = [];
      var offset = 0;

      Array.from(value).forEach(function (character) {
        var start = offset;
        offset += character.length;
        var normalizedCharacter = normalizeSearchText(character);
        if (!normalizedCharacter) {
          if (ends.length) {
            ends[ends.length - 1] = offset;
          }
          return;
        }

        normalized += normalizedCharacter;
        for (var index = 0; index < normalizedCharacter.length; index += 1) {
          starts.push(start);
          ends.push(offset);
        }
      });

      return { value: normalized, starts: starts, ends: ends };
    }

    function activeSearchQuery() {
      return searchInput ? searchInput.value.trim() : "";
    }

    function isMobileSearch() {
      return (
        window.matchMedia &&
        window.matchMedia("(max-width: 600px), (pointer: coarse)").matches
      );
    }

    function afterViewportSettles(callback) {
      var viewport = window.visualViewport;
      var timeout;

      function finish() {
        window.clearTimeout(timeout);
        if (viewport) {
          viewport.removeEventListener("resize", onResize);
        }
        callback();
      }

      function onResize() {
        window.clearTimeout(timeout);
        timeout = window.setTimeout(finish, 120);
      }

      if (viewport) {
        viewport.addEventListener("resize", onResize);
      }
      timeout = window.setTimeout(finish, viewport ? 450 : 300);
    }

    function scrollSearchInputIntoView() {
      var bounds = searchInput.getBoundingClientRect();
      var viewportHeight = window.visualViewport
        ? window.visualViewport.height
        : window.innerHeight;

      if (bounds.top >= 80 && bounds.bottom <= viewportHeight - 16) {
        return;
      }

      searchInput.scrollIntoView({ behavior: "auto", block: "start" });
    }

    function todayOnUserComputer() {
      var localDate = new Date();
      return [
        localDate.getFullYear(),
        String(localDate.getMonth() + 1).padStart(2, "0"),
        String(localDate.getDate()).padStart(2, "0"),
      ].join("-");
    }

    function findMonthOption(month) {
      var match = null;
      Array.prototype.some.call(monthOptions, function (option) {
        if (option.getAttribute("data-month") === month) {
          match = option;
          return true;
        }
        return false;
      });
      return match;
    }

    function selectedMonthOption() {
      var selected = null;
      Array.prototype.some.call(monthOptions, function (option) {
        if (option.getAttribute("aria-selected") === "true") {
          selected = option;
          return true;
        }
        return false;
      });
      return selected;
    }

    function showSelectedMonth(month) {
      if (!panels.length || !month || activeSearchQuery()) {
        return;
      }
      Array.prototype.forEach.call(panels, function (panel) {
        var isSelected =
          panel.getAttribute("data-oznamy-intentions-panel") === month;
        panel.hidden = !isSelected;
        panel.classList.toggle("is-active", isSelected);
      });
    }

    function setSelectedMonth(month) {
      var selectedOption = findMonthOption(month);
      if (!selectedOption) {
        return false;
      }

      selectedMonth = month;
      Array.prototype.forEach.call(monthOptions, function (option) {
        option.setAttribute(
          "aria-selected",
          option === selectedOption ? "true" : "false",
        );
      });

      var label = selectedOption.querySelector(
        ".oznamy-intentions__month-option-label",
      );
      var visibleLabel = monthTrigger.querySelector(
        ".oznamy-intentions__month-label",
      );
      if (label && visibleLabel) {
        visibleLabel.textContent = label.textContent;
      }

      showSelectedMonth(month);
      return true;
    }

    function renderHighlightedText(intention, query) {
      var matchIndex;
      var cursor = 0;
      var fragment = document.createDocumentFragment();
      var normalized = intention.normalized;

      intention.element.textContent = "";
      if (!query || !normalized.value) {
        intention.element.textContent = intention.text;
        return false;
      }

      matchIndex = normalized.value.indexOf(query);
      if (matchIndex < 0) {
        intention.element.textContent = intention.text;
        return false;
      }

      while (matchIndex >= 0) {
        var start = normalized.starts[matchIndex];
        var end = normalized.ends[matchIndex + query.length - 1];
        if (start >= cursor && end >= start) {
          if (start > cursor) {
            fragment.appendChild(
              document.createTextNode(intention.text.slice(cursor, start)),
            );
          }
          var highlight = document.createElement("mark");
          highlight.className = "oznamy-intentions__match";
          highlight.textContent = intention.text.slice(start, end);
          fragment.appendChild(highlight);
          cursor = end;
        }
        matchIndex = normalized.value.indexOf(query, matchIndex + query.length);
      }

      if (cursor < intention.text.length) {
        fragment.appendChild(
          document.createTextNode(intention.text.slice(cursor)),
        );
      }
      intention.element.appendChild(fragment);
      return true;
    }

    function updateSearch() {
      if (!searchInput) {
        return;
      }

      var query = activeSearchQuery();
      var normalizedQuery = normalizeSearchText(query);
      var isActive = query.length > 0;
      var matchedDays = 0;
      var matchedByPanel = [];

      Array.prototype.forEach.call(panels, function (panel) {
        matchedByPanel.push({ panel: panel, count: 0 });
      });

      Array.prototype.forEach.call(dayRecords, function (day) {
        var matched = false;

        Array.prototype.forEach.call(day.intentions, function (intention) {
          var intentionMatches =
            isActive &&
            normalizedQuery.length > 0 &&
            intention.normalized.value.indexOf(normalizedQuery) >= 0;
          if (intentionMatches) {
            matched = true;
          }
          renderHighlightedText(
            intention,
            intentionMatches ? normalizedQuery : "",
          );
        });

        day.element.hidden = isActive && !matched;
        if (isActive && matched) {
          matchedDays += 1;
          Array.prototype.some.call(matchedByPanel, function (panelResult) {
            if (panelResult.panel === day.panel) {
              panelResult.count += 1;
              return true;
            }
            return false;
          });
        }
      });

      if (isActive) {
        Array.prototype.forEach.call(matchedByPanel, function (panelResult) {
          var hasMatches = panelResult.count > 0;
          panelResult.panel.hidden = !hasMatches;
          panelResult.panel.classList.toggle("is-active", hasMatches);
          var heading = panelResult.panel.querySelector(
            "[data-oznamy-intentions-month-heading]",
          );
          if (heading) {
            heading.hidden = !hasMatches || Boolean(currentHeading);
          }
        });
      } else {
        if (selectedMonth) {
          showSelectedMonth(selectedMonth);
        }
        Array.prototype.forEach.call(panels, function (panel) {
          var heading = panel.querySelector(
            "[data-oznamy-intentions-month-heading]",
          );
          if (heading) {
            heading.hidden = true;
          }
        });
      }

      if (searchReset) {
        searchReset.hidden = !isActive;
      }
      if (searchNoResults) {
        searchNoResults.hidden = !isActive || matchedDays > 0;
        searchNoResults.textContent = isActive
          ? widget.getAttribute("data-search-no-results-label")
          : "";
      }

      if (searchStatus) {
        window.clearTimeout(searchAnnouncementTimer);
        searchStatus.hidden = !isActive;
        if (!isActive) {
          searchStatus.textContent = "";
        } else {
          searchAnnouncementTimer = window.setTimeout(function () {
            searchStatus.textContent =
              matchedDays > 0
                ? searchStatus.getAttribute("data-results-label") +
                  " " +
                  matchedDays
                : widget.getAttribute("data-search-no-results-label");
          }, 300);
        }
      }
    }

    function clearSearch() {
      if (!searchInput) {
        return;
      }
      searchInput.value = "";
      updateSearch();
      searchInput.focus();
    }

    function updateTodayHighlight() {
      var today = todayOnUserComputer();
      var todayLabel = widget.getAttribute("data-today-label") || "Dnes";

      Array.prototype.forEach.call(days, function (day) {
        var isToday = day.getAttribute("data-date") === today;
        day.classList.toggle("is-today", isToday);
        var existingLabel = day.querySelector(
          ".oznamy-intentions__today-label",
        );
        if (existingLabel) {
          existingLabel.remove();
        }
        if (isToday) {
          var label = document.createElement("span");
          label.className = "oznamy-intentions__today-label";
          label.textContent = todayLabel;
          var date = day.querySelector(".oznamy-intentions__date");
          if (date) {
            date.appendChild(label);
          }
        }
      });

      return today.slice(0, 7);
    }

    function closeMonthList(restoreFocus) {
      if (!monthList || !monthTrigger) {
        return;
      }
      monthList.hidden = true;
      monthTrigger.setAttribute("aria-expanded", "false");
      if (restoreFocus) {
        monthTrigger.focus();
      }
    }

    function openMonthList() {
      if (!monthList || !monthTrigger || !monthOptions.length) {
        return;
      }
      monthList.hidden = false;
      monthTrigger.setAttribute("aria-expanded", "true");
      var selectedOption = selectedMonthOption();
      (selectedOption || monthOptions[0]).focus();
    }

    function focusMonthOption(index) {
      if (!monthOptions.length) {
        return;
      }
      var normalizedIndex = (index + monthOptions.length) % monthOptions.length;
      monthOptions[normalizedIndex].focus();
    }

    if (monthTrigger && monthList) {
      monthTrigger.addEventListener("click", function () {
        if (monthList.hidden) {
          openMonthList();
        } else {
          closeMonthList(false);
        }
      });

      monthTrigger.addEventListener("keydown", function (event) {
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
          event.preventDefault();
          openMonthList();
        }
      });

      Array.prototype.forEach.call(monthOptions, function (option) {
        option.addEventListener("click", function () {
          if (setSelectedMonth(option.getAttribute("data-month"))) {
            closeMonthList(true);
          }
        });
      });

      monthList.addEventListener("keydown", function (event) {
        var activeIndex = -1;
        Array.prototype.some.call(monthOptions, function (option, index) {
          if (option === document.activeElement) {
            activeIndex = index;
            return true;
          }
          return false;
        });

        if (event.key === "ArrowDown") {
          event.preventDefault();
          focusMonthOption(activeIndex < 0 ? 0 : activeIndex + 1);
        } else if (event.key === "ArrowUp") {
          event.preventDefault();
          focusMonthOption(
            activeIndex < 0 ? monthOptions.length - 1 : activeIndex - 1,
          );
        } else if (event.key === "Home") {
          event.preventDefault();
          focusMonthOption(0);
        } else if (event.key === "End") {
          event.preventDefault();
          focusMonthOption(monthOptions.length - 1);
        } else if (
          event.key === "Enter" ||
          event.key === " " ||
          event.key === "Spacebar"
        ) {
          event.preventDefault();
          if (
            activeIndex >= 0 &&
            setSelectedMonth(
              monthOptions[activeIndex].getAttribute("data-month"),
            )
          ) {
            closeMonthList(true);
          }
        } else if (event.key === "Escape") {
          event.preventDefault();
          closeMonthList(true);
        } else if (event.key === "Tab") {
          closeMonthList(false);
        }
      });

      document.addEventListener("click", function (event) {
        if (!monthPicker.contains(event.target)) {
          closeMonthList(false);
        }
      });
    }

    if (searchInput) {
      searchInput.addEventListener("input", updateSearch);
      searchInput.addEventListener("focus", function () {
        if (!isMobileSearch()) {
          return;
        }

        afterViewportSettles(function () {
          if (document.activeElement === searchInput) {
            scrollSearchInputIntoView();
          }
        });
      });
      searchInput.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" || event.isComposing || !isMobileSearch()) {
          return;
        }

        event.preventDefault();
        searchInput.blur();
      });
    }
    if (searchReset) {
      searchReset.addEventListener("click", clearSearch);
    }

    var todayMonth = updateTodayHighlight();
    if (monthOptions.length) {
      var initialOption = findMonthOption(todayMonth) || selectedMonthOption();
      if (initialOption) {
        setSelectedMonth(initialOption.getAttribute("data-month"));
      }
    } else {
      Array.prototype.some.call(panels, function (panel) {
        if (panel.classList.contains("is-active")) {
          selectedMonth = panel.getAttribute("data-oznamy-intentions-panel");
          showSelectedMonth(selectedMonth);
          return true;
        }
        return false;
      });
    }
    updateSearch();
  });
})();
