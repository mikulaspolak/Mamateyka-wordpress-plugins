(function () {
  "use strict";

  if (!window.OznamyArchive || !window.OznamyArchive.ajaxUrl) {
    return;
  }

  var textTargets = Array.prototype.slice.call(document.querySelectorAll("[data-oznamy-archive-text]"));
  var archiveBanners = Array.prototype.slice.call(document.querySelectorAll("[data-oznamy-archive-banner]"));
  var galleryTargets = Array.prototype.slice.call(document.querySelectorAll(".oznamy-archive-gallery"));
  var pickerTargets = Array.prototype.slice.call(document.querySelectorAll("[data-oznamy-date-picker]"));

  if (!textTargets.length && !galleryTargets.length && !pickerTargets.length && !archiveBanners.length) {
    return;
  }

  var originalText = textTargets.map(function (target) {
    return {
      html: target.innerHTML,
      className: target.className,
    };
  });

  var originalGalleries = galleryTargets.map(function (target) {
    var grid = target.querySelector(".oznamy-images");
    return grid ? grid.outerHTML : '<div class="oznamy-images"></div>';
  });

  var requestId = 0;
  var archiveApplied = false;

  function replaceGallery(html) {
    var template = document.createElement("template");
    template.innerHTML = html || '<div class="oznamy-images"></div>';
    var grid = template.content.querySelector(".oznamy-images");

    if (!grid) {
      grid = document.createElement("div");
      grid.className = "oznamy-images";
    }

    galleryTargets.forEach(function (target) {
      var currentGrid = target.querySelector(".oznamy-images");
      var replacement = grid.cloneNode(true);
      if (currentGrid) {
        currentGrid.replaceWith(replacement);
      } else {
        target.appendChild(replacement);
      }
    });
  }

  function restoreCurrent() {
    textTargets.forEach(function (target, index) {
      target.innerHTML = originalText[index].html;
      target.className = originalText[index].className;
    });
    galleryTargets.forEach(function (target, index) {
      var template = document.createElement("template");
      template.innerHTML = originalGalleries[index];
      var currentGrid = target.querySelector(".oznamy-images");
      var originalGrid = template.content.firstElementChild;
      if (currentGrid && originalGrid) {
        currentGrid.replaceWith(originalGrid);
      } else if (originalGrid) {
        target.appendChild(originalGrid);
      }
    });
  }

  function isValidDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return false;
    }
    var parsed = new Date(value + "T00:00:00Z");
    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
  }

  function isHistoricalDate(value, latestDate) {
    var defaultDate = latestDate || "";
    if (!defaultDate && pickerTargets.length) {
      defaultDate = pickerTargets[0].getAttribute("data-default-date") || "";
    }
    return !!defaultDate && value !== defaultDate;
  }

  function updatePickerLabel(date, label) {
    pickerTargets.forEach(function (picker) {
      var labelTarget = picker.querySelector("[data-oznamy-picker-label]");
      if (labelTarget && typeof label === "string") {
        labelTarget.textContent = label;
      }
      picker.setAttribute("data-active-date", date || "");
      var activeDate = date || picker.getAttribute("data-default-date") || "";
      picker.querySelectorAll("[data-oznamy-date-option]").forEach(function (link) {
        var optionDate = link.getAttribute("data-oznamy-date-option");
        var isCurrent =
          optionDate === "latest"
            ? activeDate === (picker.getAttribute("data-default-date") || "")
            : optionDate === activeDate;
        if (isCurrent) {
          link.setAttribute("aria-current", "page");
        } else {
          link.removeAttribute("aria-current");
        }
      });
    });
  }

  function updatePickerLinks() {
    archiveBanners.forEach(function (banner) {
      if (!banner) {
        return;
      }
      var latestLink = banner.querySelector("[data-oznamy-archive-latest]");
      if (latestLink) {
        var latestUrl = new URL(window.location.href);
        latestUrl.searchParams.delete("oznamy");
        latestLink.href = latestUrl.toString();
      }
    });

    pickerTargets.forEach(function (picker) {
      picker.querySelectorAll("[data-oznamy-date-option]").forEach(function (link) {
        var url = new URL(window.location.href);
        var date = link.getAttribute("data-oznamy-date-option");
        if (date === "latest") {
          url.searchParams.delete("oznamy");
        } else {
          url.searchParams.set("oznamy", date);
        }
        link.href = url.toString();
      });
    });
  }

  function resetPickerLabel() {
    if (!pickerTargets.length) {
      return;
    }
    updatePickerLabel(
      pickerTargets[0].getAttribute("data-default-date") || "",
      pickerTargets[0].getAttribute("data-default-date-label") || "",
    );
  }

  document.addEventListener("click", function (event) {
    pickerTargets.forEach(function (picker) {
      if (picker.open && !picker.contains(event.target)) {
        picker.open = false;
      }
    });
  });

  function setArchiveBanner(isVisible, dateLabel) {
    archiveBanners.forEach(function (banner) {
      if (!banner) {
        return;
      }
      var dateTarget = banner.querySelector("[data-oznamy-archive-date]");
      if (dateTarget) {
        dateTarget.textContent = isVisible ? dateLabel || "" : "";
      }
      banner.hidden = !isVisible;
    });
  }

  function setArchiveLoading(isLoading) {
    textTargets.concat(galleryTargets).forEach(function (target) {
      var loader = target.querySelector(".oznamy-archive-loading");
      if (!isLoading) {
        target.classList.remove("oznamy-is-loading");
        target.removeAttribute("aria-busy");
        if (loader) {
          loader.remove();
        }
        return;
      }

      if (loader) {
        return;
      }

      target.classList.add("oznamy-is-loading");
      target.setAttribute("aria-busy", "true");
      loader = document.createElement("div");
      loader.className = "oznamy-archive-loading";
      loader.setAttribute("role", "status");
      loader.setAttribute("aria-label", window.OznamyArchive.loadingLabel);

      for (var index = 0; index < 4; index += 1) {
        var skeleton = document.createElement("span");
        skeleton.setAttribute("aria-hidden", "true");
        loader.appendChild(skeleton);
      }

      target.appendChild(loader);
    });
  }

  function loadSelectedDate() {
    var selectedDate = new URL(window.location.href).searchParams.get("oznamy");
    var currentRequestId = ++requestId;
    updatePickerLinks();

    if (!selectedDate) {
      setArchiveLoading(false);
      if (archiveApplied) {
        restoreCurrent();
        archiveApplied = false;
      }
      setArchiveBanner(false, "");
      resetPickerLabel();
      return;
    }

    if (!isValidDate(selectedDate)) {
      setArchiveLoading(false);
      if (archiveApplied) {
        restoreCurrent();
        archiveApplied = false;
      }
      setArchiveBanner(false, "");
      resetPickerLabel();
      return;
    }

    setArchiveLoading(true);
    setArchiveBanner(false, "");

    var body = new URLSearchParams();
    body.set("action", "oznamy_archive_fetch");
    body.set("date", selectedDate);
    body.set("text", textTargets.length ? "1" : "0");
    body.set("images", galleryTargets.length ? "1" : "0");

    fetch(window.OznamyArchive.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    })
      .then(function (response) {
        var status = response.status;
        var contentType = response.headers.get("content-type") || "";
        return response.text().then(function (body) {
          try {
            return JSON.parse(body);
          } catch (error) {
            console.error("[Oznamy] Archive AJAX returned invalid JSON.", {
              status: status,
              contentType: contentType,
            });
            throw error;
          }
        });
      })
      .then(function (result) {
        if (currentRequestId !== requestId) {
          return;
        }

        if (!result || !result.data) {
          setArchiveLoading(false);
          setArchiveBanner(false, "");
          return;
        }

        setArchiveLoading(false);

        if (!result.success) {
          setArchiveBanner(false, "");
          if (result.data.code === "not_found" || result.data.code === "invalid_date") {
            if (archiveApplied) {
              restoreCurrent();
              archiveApplied = false;
            }
            setArchiveBanner(false, "");
            resetPickerLabel();
          }
          return;
        }

        textTargets.forEach(function (target) {
          var content =
            "modern" === target.getAttribute("data-oznamy-archive-text")
              ? result.data.modern_html
              : result.data.plain_html;
          if (typeof content === "string") {
            target.innerHTML = content;
            target.classList.toggle("oznamy-modern", "modern" === target.getAttribute("data-oznamy-archive-text"));
          }
        });

        if (galleryTargets.length && typeof result.data.images_html === "string") {
          replaceGallery(result.data.images_html);
        }
        var fallbackLabel = pickerTargets.length ? pickerTargets[0].getAttribute("data-default-date-label") : "";
        var dateLabel = result.data.date_label || fallbackLabel;
        updatePickerLabel(selectedDate, dateLabel);
        setArchiveBanner(isHistoricalDate(selectedDate, result.data.latest_date), dateLabel);
        archiveApplied = true;
      })
      .catch(function () {
        if (currentRequestId === requestId) {
          setArchiveLoading(false);
          setArchiveBanner(false, "");
        }
      });
  }

  window.addEventListener("popstate", loadSelectedDate);
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
      loadSelectedDate();
    }
  });

  loadSelectedDate();
})();
