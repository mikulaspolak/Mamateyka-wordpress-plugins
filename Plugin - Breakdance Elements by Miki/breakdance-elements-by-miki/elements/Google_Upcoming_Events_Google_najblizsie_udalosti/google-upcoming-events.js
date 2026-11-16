(function () {
  const LOCALE = 'sk-SK';
  const TIME_ZONE = 'Europe/Bratislava';
  const DAY_MS = 24 * 60 * 60 * 1000;
  const desktopQuery = window.matchMedia('(min-width: 1024px)');
  const isEditor = /[?&]breakdance(_iframe)?=/.test(window.location.search);
  const initialized = new WeakSet();
  const loaded = new Map();
  let scheduled = false;

  const formatters = {
    zonedWeekday: new Intl.DateTimeFormat(LOCALE, { weekday: 'long', timeZone: TIME_ZONE }),
    zonedDay: new Intl.DateTimeFormat(LOCALE, { day: '2-digit', timeZone: TIME_ZONE }),
    zonedMonth: new Intl.DateTimeFormat(LOCALE, { month: 'short', timeZone: TIME_ZONE }),
    zonedTime: new Intl.DateTimeFormat(LOCALE, { hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE }),
    utcWeekday: new Intl.DateTimeFormat(LOCALE, { weekday: 'long', timeZone: 'UTC' }),
    utcDay: new Intl.DateTimeFormat(LOCALE, { day: '2-digit', timeZone: 'UTC' }),
    utcDayShort: new Intl.DateTimeFormat(LOCALE, { day: 'numeric', timeZone: 'UTC' }),
    utcDayMonth: new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', timeZone: 'UTC' }),
    utcMonth: new Intl.DateTimeFormat(LOCALE, { month: 'short', timeZone: 'UTC' }),
  };

  function clampCount(value, fallback) {
    const number = Number.parseInt(value, 10);

    return Number.isFinite(number) ? Math.min(Math.max(number, 1), 20) : fallback;
  }

  // Timed events use the site time zone; all-day events carry plain dates, so they are read as UTC to avoid day shifts.
  function describe(event, fallbackTitle) {
    const timed = Boolean(event.start && event.start.dateTime);
    const start = new Date((event.start && (event.start.dateTime || event.start.date)) || NaN);

    if (Number.isNaN(start.getTime())) {
      return null;
    }

    const title = event.summary || fallbackTitle;

    if (timed) {
      return {
        day: formatters.zonedDay.format(start),
        month: formatters.zonedMonth.format(start),
        title,
        label: `${formatters.zonedWeekday.format(start)} • ${formatters.zonedTime.format(start)}`,
      };
    }

    const day = formatters.utcDay.format(start);
    const month = formatters.utcMonth.format(start);
    const rawEnd = event.end && event.end.date ? new Date(event.end.date) : null;
    const lastDay = rawEnd && !Number.isNaN(rawEnd.getTime()) ? new Date(rawEnd.getTime() - DAY_MS) : start;

    if (lastDay.getTime() > start.getTime()) {
      return {
        day,
        month,
        title,
        label: `${formatters.utcDayShort.format(start)} – ${formatters.utcDayMonth.format(lastDay)}`,
      };
    }

    return { day, month, title, label: `${formatters.utcWeekday.format(start)} • celý deň` };
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;

    return node;
  }

  function buildItem(item) {
    const row = document.createElement('div');
    row.className = 'miki-google-upcoming-events__item';

    const date = document.createElement('div');
    date.className = 'miki-google-upcoming-events__date';
    date.append(
      element('b', 'miki-google-upcoming-events__day', item.day),
      element('span', 'miki-google-upcoming-events__month', item.month)
    );

    const meta = document.createElement('div');
    meta.className = 'miki-google-upcoming-events__meta';
    meta.append(
      element('b', 'miki-google-upcoming-events__title', item.title),
      element('span', 'miki-google-upcoming-events__label', item.label)
    );

    row.append(date, meta);

    return row;
  }

  function render(container, items, fallbackTitle) {
    const limit = desktopQuery.matches
      ? clampCount(container.dataset.countDesktop, 6)
      : clampCount(container.dataset.countMobile, 4);
    const rows = items
      .map((event) => describe(event, fallbackTitle))
      .filter(Boolean)
      .slice(0, limit)
      .map(buildItem);

    if (rows.length === 0) {
      const message = document.createElement('p');
      message.textContent = container.dataset.emptyText || '';
      container.replaceChildren(message);

      return;
    }

    container.replaceChildren(...rows);
  }

  async function load(container) {
    const calendarId = container.dataset.calendarId || '';
    const apiKey = container.dataset.apiKey || '';
    const errorText = container.dataset.errorText || '';
    const fallbackTitle = container.dataset.fallbackTitle || '';

    if (!calendarId || !apiKey) {
      container.textContent = errorText;

      return;
    }

    const maxResults = Math.max(
      clampCount(container.dataset.countDesktop, 6),
      clampCount(container.dataset.countMobile, 4)
    );
    const params = new URLSearchParams({
      singleEvents: 'true',
      orderBy: 'startTime',
      maxResults: String(maxResults),
      timeMin: new Date().toISOString(),
      key: apiKey,
    });
    const url = `https://www.googleapis.com/calendar/v3/calendars/${encodeURIComponent(calendarId)}/events?${params}`;

    try {
      const response = await fetch(url);
      const data = await response.json();

      if (!response.ok || data.error) {
        throw new Error((data.error && data.error.message) || `HTTP ${response.status}`);
      }

      const items = Array.isArray(data.items) ? data.items : [];
      loaded.set(container, { items, fallbackTitle });
      render(container, items, fallbackTitle);
    } catch (error) {
      container.textContent = errorText;
      console.error(error);
    }
  }

  function scan() {
    scheduled = false;

    document.querySelectorAll('[data-miki-upcoming-events]').forEach((container) => {
      if (initialized.has(container)) {
        return;
      }

      initialized.add(container);
      load(container);
    });
  }

  function schedule() {
    if (!scheduled) {
      scheduled = true;
      window.requestAnimationFrame(scan);
    }
  }

  function boot() {
    scan();

    desktopQuery.addEventListener('change', () => {
      loaded.forEach((state, container) => {
        if (container.isConnected) {
          render(container, state.items, state.fallbackTitle);
        } else {
          loaded.delete(container);
        }
      });
    });

    // The editor replaces the element markup after every change.
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
