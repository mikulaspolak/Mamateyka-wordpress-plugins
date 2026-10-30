<?php

/**
 * Rozpis svätých omší - zjednotený shortcode [rozpis typ="horizontal|vertical|nastenka"]
 *
 * Nahrádza pôvodné samostatné súbory hotizontal.html a vertical.html.
 * Dátová/parsovacia logika (Google Calendar fetch, cache, mená, fotky, live/next stav)
 * je zdieľaná v jednom JS "engine" objekte (RozpisOmsiEngine). Frontend layout
 * (CSS aj render šablóny) ostáva zámerne oddelený podľa typu; nastenka zdieľa vertical renderer.
 *
 * Podporuje viacero instancií shortcode naraz na jednej stránke (aj rôzne typy,
 * aj ten istý typ viackrát) - CSS/JS bloky sa vytlačia len raz, dáta sa fetchujú
 * len raz a distribuujú sa do všetkých registrovaných instancií.
 *
 * Použitie: [rozpis typ="horizontal"], [rozpis typ="vertical"] alebo [rozpis typ="nastenka"]
 * Bez atribútu alebo s neplatnou hodnotou -> horizontal.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('rozpis_omsi_shortcode')) {

  function rozpis_omsi_register_rest_routes()
  {
    register_rest_route('rozpis-omsi/v1', '/events', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => 'rozpis_omsi_get_calendar_events',
      'permission_callback' => '__return_true',
    ));

    // Pre vloženie rozpisu do blokov, ktoré shortcodes nespracúvajú (napr. Breakdance Rich Text)
    register_rest_route('rozpis-omsi/v1', '/embed', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => 'rozpis_omsi_get_embed',
      'permission_callback' => '__return_true',
      'args' => array(
        'typ' => array(
          'type' => 'string',
          'default' => 'nastenka',
          'sanitize_callback' => 'sanitize_key',
        ),
      ),
    ));
  }
  add_action('rest_api_init', 'rozpis_omsi_register_rest_routes');

  function rozpis_omsi_get_embed($request)
  {
    $response = new WP_REST_Response(array(
      'html' => rozpis_omsi_shortcode(array('typ' => $request['typ'])),
    ));
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    return $response;
  }

  function rozpis_omsi_calendar_config()
  {
    return array(
      'calendarId' => 'c_551ed9b000b469f2116f7b136c8b7d0e9713c4367dcdb771ebcee614b6ad722c@group.calendar.google.com',
      'calendarApiKey' => 'AIzaSyAUxqbI8HtOjIUSnsaae7Uzq7m0LpCWFHA',
    );
  }

  function rozpis_omsi_cache_ttl()
  {
    return MINUTE_IN_SECONDS;
  }

  function rozpis_omsi_request_timeout()
  {
    return 10;
  }

  function rozpis_omsi_get_status()
  {
    $status = get_option('rozpis_omsi_status');
    return is_array($status) ? $status : array();
  }

  // Diagnostika sa zapisuje len pri reálnom volaní Google Calendar, nie pri cache hite
  function rozpis_omsi_update_status($changes, $log_entry = null)
  {
    $status = array_merge(rozpis_omsi_get_status(), $changes);

    if ($log_entry) {
      $log = isset($status['log']) && is_array($status['log']) ? $status['log'] : array();
      array_unshift($log, $log_entry);
      $status['log'] = array_slice($log, 0, 10);
    }

    update_option('rozpis_omsi_status', $status, false);
  }

  function rozpis_omsi_redact($text)
  {
    $api_key = rozpis_omsi_calendar_config()['calendarApiKey'];
    $text = str_replace($api_key, '[redacted]', (string) $text);
    return mb_substr(sanitize_text_field($text), 0, 500);
  }

  function rozpis_omsi_record_attempt()
  {
    rozpis_omsi_update_status(array('last_attempt_at' => current_time('mysql', true)));
  }

  function rozpis_omsi_record_error($code, $details, $http_status = null, $duration_ms = null)
  {
    $status = rozpis_omsi_get_status();
    $now = current_time('mysql', true);
    $http_status = $http_status ? (int) $http_status : null;

    rozpis_omsi_update_status(
      array(
        'last_failure_at' => $now,
        'last_http_status' => $http_status,
        'last_duration_ms' => $duration_ms,
        'consecutive_failures' => (int) ($status['consecutive_failures'] ?? 0) + 1,
      ),
      array(
        'at' => $now,
        'level' => 'error',
        'code' => sanitize_key($code),
        'http_status' => $http_status,
        'message' => rozpis_omsi_redact($details),
      )
    );
  }

  // Varovanie nemení stav "zdravia", len sa zapíše do logu
  function rozpis_omsi_record_warning($code, $details)
  {
    rozpis_omsi_update_status(array(), array(
      'at' => current_time('mysql', true),
      'level' => 'warning',
      'code' => sanitize_key($code),
      'http_status' => null,
      'message' => rozpis_omsi_redact($details),
    ));
  }

  function rozpis_omsi_record_success($http_status, $duration_ms, $stats)
  {
    rozpis_omsi_update_status(array_merge($stats, array(
      'last_success_at' => current_time('mysql', true),
      'last_http_status' => (int) $http_status,
      'last_duration_ms' => $duration_ms,
      'consecutive_failures' => 0,
    )));
    delete_option('rozpis_omsi_last_error');
  }

  function rozpis_omsi_utc_to_timestamp($utc_datetime)
  {
    if (empty($utc_datetime)) {
      return null;
    }

    $timestamp = strtotime($utc_datetime . ' UTC');
    return $timestamp === false ? null : $timestamp;
  }

  // Časy sa ukladajú v UTC, zobrazujú sa v časovom pásme WordPressu
  function rozpis_omsi_format_time($utc_datetime, $with_relative = true)
  {
    $timestamp = rozpis_omsi_utc_to_timestamp($utc_datetime);
    if ($timestamp === null) {
      return 'Zatiaľ nezaznamenané';
    }

    $text = wp_date('j. n. Y H:i:s T', $timestamp, wp_timezone());
    if ($with_relative) {
      $diff = time() - $timestamp;
      $text .= $diff >= 0 ? ' (pred ' . human_time_diff($timestamp) . ')' : ' (v budúcnosti)';
    }

    return $text;
  }

  function rozpis_omsi_get_health($status)
  {
    $success = rozpis_omsi_utc_to_timestamp($status['last_success_at'] ?? '');
    $failure = rozpis_omsi_utc_to_timestamp($status['last_failure_at'] ?? '');
    $attempt = rozpis_omsi_utc_to_timestamp($status['last_attempt_at'] ?? '');
    $latest_result = max((int) $success, (int) $failure);

    if ($attempt !== null && $attempt > $latest_result) {
      // Zámok sťahovania expiruje po 30 s, staršie nedokončené pokusy znamenajú timeout alebo fatálnu chybu
      if (time() - $attempt > 30) {
        return 'unfinished';
      }
      return 'running';
    }

    if ($failure !== null && ($success === null || $failure >= $success)) {
      return 'failing';
    }

    if ($success !== null) {
      return 'ok';
    }

    return 'unknown';
  }

  function rozpis_omsi_get_cache_diagnostics($timezone)
  {
    $start = new DateTimeImmutable('today', $timezone);
    $cache_key = 'rozpis_omsi_events_' . $start->format('Ymd');
    $events_by_day = get_transient($cache_key);
    $result = array(
      'key' => $cache_key,
      'present' => $events_by_day !== false,
      'valid' => false,
      'problem' => '',
      'days' => 0,
      'events' => 0,
      'from' => $start->format('Y-m-d'),
      'to' => $start->modify('+6 days')->format('Y-m-d'),
      'expires_at' => null,
    );

    if (!$result['present']) {
      return $result;
    }

    if (!wp_using_ext_object_cache()) {
      $timeout = (int) get_option('_transient_timeout_' . $cache_key);
      $result['expires_at'] = $timeout > 0 ? $timeout : null;
    }

    if (!is_array($events_by_day)) {
      $result['problem'] = 'Uložená hodnota nie je pole.';
      return $result;
    }

    $result['days'] = count($events_by_day);
    for ($offset = 0; $offset < 7; $offset++) {
      $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
      if (!isset($events_by_day[$date]) || !is_array($events_by_day[$date])) {
        $result['problem'] = 'Chýba deň ' . $date . '.';
        return $result;
      }

      foreach ($events_by_day[$date] as $event) {
        if (
          !is_array($event) ||
          empty($event['start']) ||
          !is_array($event['start']) ||
          (empty($event['start']['dateTime']) && empty($event['start']['date']))
        ) {
          $result['problem'] = 'Udalosť dňa ' . $date . ' nemá platný začiatok.';
          return $result;
        }
        $result['events']++;
      }
    }

    $result['valid'] = true;
    return $result;
  }

  function rozpis_omsi_register_admin_page()
  {
    add_menu_page(
      'Rozpis svätých omší',
      'Rozpis omší',
      'manage_options',
      'rozpis-omsi',
      'rozpis_omsi_render_admin_page',
      'dashicons-calendar-alt'
    );
  }
  add_action('admin_menu', 'rozpis_omsi_register_admin_page');

  function rozpis_omsi_admin_notice()
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (isset($_GET['page']) && $_GET['page'] === 'rozpis-omsi') {
      return;
    }

    $status = rozpis_omsi_get_status();
    $health = rozpis_omsi_get_health($status);
    if ($health !== 'failing' && $health !== 'unfinished') {
      return;
    }

    $detail = $health === 'unfinished'
      ? 'posledný pokus o načítanie nebol dokončený'
      : 'posledné načítanie zlyhalo (' . rozpis_omsi_format_time($status['last_failure_at'] ?? '') . ')';

    printf(
      '<div class="notice notice-error"><p><strong>Rozpis omší:</strong> %1$s. <a href="%2$s">Zobraziť diagnostiku</a></p></div>',
      esc_html($detail),
      esc_url(admin_url('admin.php?page=rozpis-omsi'))
    );
  }
  add_action('admin_notices', 'rozpis_omsi_admin_notice');

  // Hodnota riadku: text, array('code' => text) alebo array('badge' => text, 'class' => trieda)
  function rozpis_omsi_render_rows($rows)
  {
    echo '<dl class="rozpis-admin-list">';
    foreach ($rows as $label => $value) {
      echo '<div><dt>' . esc_html($label) . '</dt><dd>';
      if (is_array($value) && isset($value['code'])) {
        echo '<code>' . esc_html($value['code']) . '</code>';
      } elseif (is_array($value) && isset($value['badge'])) {
        echo '<span class="rozpis-admin-badge ' . esc_attr($value['class']) . '">' . esc_html($value['badge']) . '</span>';
      } else {
        echo esc_html($value);
      }
      echo '</dd></div>';
    }
    echo '</dl>';
  }

  function rozpis_omsi_render_admin_page()
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    $status = rozpis_omsi_get_status();
    $health = rozpis_omsi_get_health($status);
    $timezone = wp_timezone();
    $cache = rozpis_omsi_get_cache_diagnostics($timezone);
    $log = isset($status['log']) && is_array($status['log']) ? $status['log'] : array();
    $failures = (int) ($status['consecutive_failures'] ?? 0);
    $none = 'Zatiaľ nezaznamenané';

    $banners = array(
      'ok' => array('is-good', 'Rozpis sa načítava v poriadku', 'Posledné úspešné načítanie z Google Calendar: ' . rozpis_omsi_format_time($status['last_success_at'] ?? '')),
      'failing' => array('is-error', 'Posledné načítanie zlyhalo', 'Zlyhaní po sebe: ' . $failures . '. Posledný úspech: ' . (empty($status['last_success_at']) ? 'nikdy' : rozpis_omsi_format_time($status['last_success_at']))),
      'unfinished' => array('is-error', 'Posledný pokus o načítanie nebol dokončený', 'Pravdepodobne timeout alebo fatálna chyba PHP, skontrolujte PHP error log servera.'),
      'running' => array('is-neutral', 'Prebieha načítanie z Google Calendar', 'Stav sa aktualizuje po dokončení požiadavky.'),
      'unknown' => array('is-neutral', 'Zatiaľ nie je zaznamenané žiadne načítanie', 'Diagnostika sa plní pri prvom načítaní z Google Calendar po jej nasadení.'),
    );
    list($banner_class, $banner_title, $banner_text) = $banners[$health];

    if (!$cache['present']) {
      $cache_badge = array('badge' => 'Nie je uložená', 'class' => 'is-neutral');
    } elseif ($cache['valid']) {
      $cache_badge = array('badge' => 'Platná', 'class' => 'is-good');
    } else {
      $cache_badge = array('badge' => 'Neplatná', 'class' => 'is-error');
    }

    if ($cache['expires_at']) {
      $remaining = $cache['expires_at'] - time();
      $cache_expiry = wp_date('H:i:s T', $cache['expires_at'], $timezone) . ($remaining > 0 ? ' (o ' . $remaining . ' s)' : ' (už vypršala)');
    } else {
      $cache_expiry = $cache['present'] ? 'Nezistiteľné (externá object cache)' : '-';
    }

    $calendar_timezone = $status['calendar_timezone'] ?? '';
    $timezone_note = '';
    if ($calendar_timezone && $calendar_timezone !== $timezone->getName()) {
      $timezone_note = ' (líši sa od webu)';
    }

    $loading_rows = array(
      'Posledný pokus' => rozpis_omsi_format_time($status['last_attempt_at'] ?? ''),
      'Posledný úspech' => rozpis_omsi_format_time($status['last_success_at'] ?? ''),
      'Posledné zlyhanie' => rozpis_omsi_format_time($status['last_failure_at'] ?? ''),
      'Zlyhaní po sebe' => (string) $failures,
      'HTTP stav poslednej odpovede' => isset($status['last_http_status']) ? (string) $status['last_http_status'] : 'Bez odpovede / ' . $none,
      'Trvanie poslednej požiadavky' => isset($status['last_duration_ms']) ? $status['last_duration_ms'] . ' ms (limit ' . rozpis_omsi_request_timeout() . ' s)' : $none,
    );

    $data_rows = array(
      'Udalostí v odpovedi Google' => isset($status['items_total']) ? (string) $status['items_total'] : $none,
      'Použité v 7-dňovom rozpise' => isset($status['items_kept']) ? (string) $status['items_kept'] : $none,
      'Preskočené (bez začiatku)' => isset($status['items_skipped']) ? (string) $status['items_skipped'] : $none,
      'Mimo 7-dňového okna' => isset($status['items_out_of_range']) ? (string) $status['items_out_of_range'] : $none,
      'Časové pásmo kalendára' => $calendar_timezone ? $calendar_timezone . $timezone_note : $none,
      'Kalendár naposledy upravený' => !empty($status['calendar_updated']) ? rozpis_omsi_format_time(gmdate('Y-m-d H:i:s', (int) strtotime($status['calendar_updated']))) : $none,
    );

    $cache_rows = array(
      'Stav' => $cache_badge,
      'Obdobie' => $cache['from'] . ' až ' . $cache['to'],
      'Dni / udalosti' => $cache['days'] . ' / 7 dní, ' . $cache['events'] . ' udalostí',
      'Vyprší' => $cache_expiry,
      'Doba platnosti' => rozpis_omsi_cache_ttl() . ' s',
    );
    if ($cache['problem']) {
      $cache_rows['Problém'] = $cache['problem'];
    }

    $environment_rows = array(
      'Časové pásmo webu' => $timezone->getName(),
      'Lokálny čas' => wp_date('j. n. Y H:i:s T', time(), $timezone),
      'WordPress / PHP' => get_bloginfo('version') . ' / ' . PHP_VERSION,
      'Jazyk webu' => get_locale(),
      'Externá object cache' => wp_using_ext_object_cache() ? 'Áno' : 'Nie',
      'WP_DEBUG' => defined('WP_DEBUG') && WP_DEBUG ? 'Zapnuté' : 'Vypnuté',
    );

    $technical_rows = array(
      'REST endpoint' => array('code' => rest_url('rozpis-omsi/v1/events')),
      'ID kalendára' => array('code' => rozpis_omsi_calendar_config()['calendarId']),
      'Kľúč cache' => array('code' => $cache['key']),
      'Option so stavom' => array('code' => 'rozpis_omsi_status'),
    );
?>
    <div class="wrap rozpis-admin-page">
      <style>
        .rozpis-admin-page .rozpis-admin-intro {
          color: #646970;
          margin: 4px 0 16px;
        }

        .rozpis-admin-page .rozpis-admin-banner {
          background: #fff;
          border: 1px solid #c3c4c7;
          border-left-width: 4px;
          border-radius: 2px;
          margin: 0 0 16px;
          max-width: 1200px;
          padding: 12px 16px;
        }

        .rozpis-admin-page .rozpis-admin-banner.is-good {
          border-left-color: #00a32a;
        }

        .rozpis-admin-page .rozpis-admin-banner.is-error {
          border-left-color: #d63638;
        }

        .rozpis-admin-page .rozpis-admin-banner.is-neutral {
          border-left-color: #8c8f94;
        }

        .rozpis-admin-page .rozpis-admin-banner h2 {
          font-size: 15px;
          margin: 0 0 4px;
          padding: 0;
        }

        .rozpis-admin-page .rozpis-admin-banner p {
          margin: 0;
        }

        .rozpis-admin-page .rozpis-admin-badge {
          border-radius: 10px;
          display: inline-block;
          font-size: 12px;
          font-weight: 600;
          line-height: 1.7;
          padding: 0 10px;
        }

        .rozpis-admin-page .rozpis-admin-badge.is-good {
          background: #edfaef;
          color: #176b2c;
        }

        .rozpis-admin-page .rozpis-admin-badge.is-error {
          background: #fcf0f1;
          color: #b32d2e;
        }

        .rozpis-admin-page .rozpis-admin-badge.is-neutral {
          background: #f0f0f1;
          color: #50575e;
        }

        .rozpis-admin-page .rozpis-admin-badge.is-warning {
          background: #fcf9e8;
          color: #8a6100;
        }

        .rozpis-admin-page .rozpis-admin-grid {
          display: grid;
          gap: 16px;
          grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
          max-width: 1200px;
        }

        .rozpis-admin-page .postbox {
          margin: 0;
        }

        .rozpis-admin-page .postbox .hndle {
          font-size: 14px;
          padding: 12px 16px;
        }

        .rozpis-admin-page .postbox .inside {
          margin: 0;
          padding: 4px 16px 12px;
        }

        .rozpis-admin-page .rozpis-admin-list {
          margin: 0;
        }

        .rozpis-admin-page .rozpis-admin-list>div {
          border-bottom: 1px solid #f0f0f1;
          display: grid;
          gap: 16px;
          grid-template-columns: minmax(120px, 1fr) minmax(0, 1.5fr);
          padding: 9px 0;
        }

        .rozpis-admin-page .rozpis-admin-list>div:last-child {
          border-bottom: 0;
        }

        .rozpis-admin-page .rozpis-admin-list dt {
          color: #646970;
        }

        .rozpis-admin-page .rozpis-admin-list dd {
          margin: 0;
          overflow-wrap: anywhere;
          text-align: right;
        }

        .rozpis-admin-page .rozpis-admin-section {
          margin-top: 16px;
          max-width: 1200px;
        }

        .rozpis-admin-page .rozpis-admin-section summary {
          cursor: pointer;
          font-weight: 600;
          margin-bottom: 8px;
        }

        .rozpis-admin-page .rozpis-admin-log td {
          overflow-wrap: anywhere;
          vertical-align: top;
        }

        .rozpis-admin-page .rozpis-admin-raw {
          background: #f6f7f7;
          margin: 0;
          max-height: 320px;
          overflow: auto;
          padding: 12px;
          white-space: pre-wrap;
          word-break: break-word;
        }

        @media screen and (max-width: 782px) {
          .rozpis-admin-page .rozpis-admin-list>div {
            gap: 4px;
            grid-template-columns: 1fr;
          }

          .rozpis-admin-page .rozpis-admin-list dd {
            text-align: left;
          }
        }
      </style>

      <h1>Rozpis svätých omší</h1>
      <p class="rozpis-admin-intro">Diagnostika načítania rozpisu z Google Calendar. Všetky časy sú v časovom pásme webu. Zápis stavu prebieha len pri skutočnom volaní Google Calendar, nie pri zobrazení z cache.</p>

      <div class="rozpis-admin-banner <?php echo esc_attr($banner_class); ?>">
        <h2><?php echo esc_html($banner_title); ?></h2>
        <p><?php echo esc_html($banner_text); ?></p>
      </div>

      <div class="rozpis-admin-grid">
        <section class="postbox">
          <h2 class="hndle">Načítanie z Google Calendar</h2>
          <div class="inside"><?php rozpis_omsi_render_rows($loading_rows); ?></div>
        </section>
        <section class="postbox">
          <h2 class="hndle">Údaje pri poslednom úspechu</h2>
          <div class="inside"><?php rozpis_omsi_render_rows($data_rows); ?></div>
        </section>
        <section class="postbox">
          <h2 class="hndle">Cache rozpisu</h2>
          <div class="inside"><?php rozpis_omsi_render_rows($cache_rows); ?></div>
        </section>
        <section class="postbox">
          <h2 class="hndle">Prostredie webu</h2>
          <div class="inside"><?php rozpis_omsi_render_rows($environment_rows); ?></div>
        </section>
      </div>

      <section class="postbox rozpis-admin-section">
        <h2 class="hndle">Posledné záznamy (max. 10)</h2>
        <div class="inside">
          <?php if ($log) : ?>
            <table class="widefat striped rozpis-admin-log">
              <thead>
                <tr>
                  <th>Čas</th>
                  <th>Úroveň</th>
                  <th>Kód</th>
                  <th>HTTP</th>
                  <th>Správa</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($log as $entry) : ?>
                  <tr>
                    <td><?php echo esc_html(rozpis_omsi_format_time($entry['at'] ?? '', false)); ?></td>
                    <td><span class="rozpis-admin-badge <?php echo ($entry['level'] ?? '') === 'warning' ? 'is-warning' : 'is-error'; ?>"><?php echo ($entry['level'] ?? '') === 'warning' ? 'Varovanie' : 'Chyba'; ?></span></td>
                    <td><code><?php echo esc_html($entry['code'] ?? ''); ?></code></td>
                    <td><?php echo esc_html(!empty($entry['http_status']) ? (string) $entry['http_status'] : '-'); ?></td>
                    <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else : ?>
            <p>Zatiaľ nebola zaznamenaná žiadna chyba ani varovanie.</p>
          <?php endif; ?>
        </div>
      </section>

      <details class="rozpis-admin-section">
        <summary>Technické údaje</summary>
        <section class="postbox">
          <div class="inside">
            <?php rozpis_omsi_render_rows($technical_rows); ?>
            <p><strong>Surový uložený stav</strong> (vhodné skopírovať pri hlásení problému):</p>
            <pre class="rozpis-admin-raw"><?php echo esc_html(wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
          </div>
        </section>
      </details>
    </div>
    <?php
  }

  function rozpis_omsi_get_calendar_events()
  {
    $timezone = wp_timezone();
    $start = new DateTimeImmutable('today', $timezone);
    $cache_key = 'rozpis_omsi_events_' . $start->format('Ymd');
    $events_by_day = get_transient($cache_key);

    if ($events_by_day !== false) {
      return rozpis_omsi_events_response($events_by_day);
    }

    $lock_name = 'rozpis_omsi_events_fetch_lock';
    $lock_token = wp_generate_uuid4();
    $lock_value = array(
      'token' => $lock_token,
      'expires' => time() + 30,
    );
    $has_lock = add_option($lock_name, $lock_value, '', 'no');

    if (!$has_lock) {
      $existing_lock = get_option($lock_name);
      if (is_array($existing_lock) && !empty($existing_lock['expires']) && $existing_lock['expires'] < time()) {
        delete_option($lock_name);
        $has_lock = add_option($lock_name, $lock_value, '', 'no');
      }
    }

    if (!$has_lock) {
      for ($attempt = 0; $attempt < 20; $attempt++) {
        usleep(250000);
        $events_by_day = get_transient($cache_key);
        if ($events_by_day !== false) {
          return rozpis_omsi_events_response($events_by_day);
        }
      }

      rozpis_omsi_record_warning(
        'rozpis_omsi_refresh_in_progress',
        'Požiadavka vypršala pri čakaní na súbežné načítanie dát (HTTP 503). Skutočný výsledok zapíše požiadavka, ktorá načítanie vykonáva.'
      );
      return new WP_Error(
        'rozpis_omsi_refresh_in_progress',
        'Rozpis sa obnovuje. Skúste to o chvíľu znova.',
        array('status' => 503)
      );
    }

    try {
      $calendar_config = rozpis_omsi_calendar_config();
      $calendar_id = $calendar_config['calendarId'];
      $api_key = $calendar_config['calendarApiKey'];
      $end = $start->modify('+7 days');
      $time_min = $start->format(DateTime::RFC3339);
      $time_max = $end->format(DateTime::RFC3339);
      // add_query_arg() hodnoty neenkóduje, "+" z offsetu (+02:00) by Google prečítal ako medzeru (HTTP 400)
      $url = add_query_arg(
        array_map('rawurlencode', array(
          'key' => $api_key,
          'timeMin' => $time_min,
          'timeMax' => $time_max,
          'singleEvents' => 'true',
          'orderBy' => 'startTime',
          'maxResults' => '2500',
        )),
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events'
      );
      rozpis_omsi_record_attempt();
      $request_started = microtime(true);
      $response = wp_remote_get($url, array('timeout' => rozpis_omsi_request_timeout()));
      $duration_ms = (int) round((microtime(true) - $request_started) * 1000);

      if (is_wp_error($response)) {
        rozpis_omsi_record_error(
          $response->get_error_code(),
          'Požiadavka na Google Calendar zlyhala: ' . $response->get_error_message(),
          null,
          $duration_ms
        );
        error_log('Rozpis omsi: Google Calendar request failed (' . $response->get_error_code() . ').');
        return new WP_Error(
          'rozpis_omsi_calendar_unavailable',
          'Rozpis sa momentálne nepodarilo načítať.',
          array('status' => 502)
        );
      }

      $status = wp_remote_retrieve_response_code($response);
      $calendar_data = json_decode(wp_remote_retrieve_body($response), true);
      if (
        $status !== 200 ||
        !is_array($calendar_data) ||
        isset($calendar_data['error']) ||
        (isset($calendar_data['items']) && !is_array($calendar_data['items']))
      ) {
        $details = 'Google Calendar vrátil HTTP ' . (int) $status . ' (timeMin=' . $time_min . ', timeMax=' . $time_max . ').';
        if (isset($calendar_data['error']) && is_array($calendar_data['error'])) {
          $google_error = $calendar_data['error'];
          $parts = array();
          if (!empty($google_error['status'])) {
            $parts[] = sanitize_text_field($google_error['status']);
          }
          if (
            !empty($google_error['errors'][0]) &&
            is_array($google_error['errors'][0]) &&
            !empty($google_error['errors'][0]['reason'])
          ) {
            $parts[] = sanitize_text_field($google_error['errors'][0]['reason']);
          }
          if (!empty($google_error['message'])) {
            $parts[] = sanitize_text_field($google_error['message']);
          }
          if ($parts) {
            $details .= ' ' . implode(' — ', $parts);
          }
        } elseif (json_last_error() !== JSON_ERROR_NONE) {
          $details .= ' Odpoveď neobsahuje platný JSON.';
        } elseif (!is_array($calendar_data)) {
          $details .= ' Odpoveď Google Calendar má neplatný formát.';
        } else {
          $details .= ' Zoznam udalostí má neplatný formát.';
        }
        rozpis_omsi_record_error('rozpis_omsi_calendar_invalid_response', $details, $status, $duration_ms);
        error_log('Rozpis omsi: Google Calendar returned HTTP ' . (int) $status . ' or an invalid response.');
        return new WP_Error(
          'rozpis_omsi_calendar_invalid_response',
          'Rozpis sa momentálne nepodarilo načítať.',
          array('status' => 502)
        );
      }

      $events_by_day = array();
      for ($offset = 0; $offset < 7; $offset++) {
        $events_by_day[$start->modify('+' . $offset . ' days')->format('Y-m-d')] = array();
      }

      $items_total = 0;
      $items_kept = 0;
      $items_skipped = 0;
      $items_out_of_range = 0;
      foreach ($calendar_data['items'] ?? array() as $event) {
        $items_total++;
        if (empty($event['start']) || (empty($event['start']['dateTime']) && empty($event['start']['date']))) {
          $items_skipped++;
          continue;
        }

        if (!empty($event['start']['dateTime'])) {
          $event_date = (new DateTimeImmutable($event['start']['dateTime']))
            ->setTimezone($timezone)
            ->format('Y-m-d');
        } else {
          $event_date = substr($event['start']['date'], 0, 10);
        }
        if (isset($events_by_day[$event_date])) {
          $events_by_day[$event_date][] = $event;
          $items_kept++;
        } else {
          $items_out_of_range++;
        }
      }

      set_transient($cache_key, $events_by_day, rozpis_omsi_cache_ttl());
      rozpis_omsi_record_success($status, $duration_ms, array(
        'items_total' => $items_total,
        'items_kept' => $items_kept,
        'items_skipped' => $items_skipped,
        'items_out_of_range' => $items_out_of_range,
        'calendar_timezone' => sanitize_text_field((string) ($calendar_data['timeZone'] ?? '')),
        'calendar_updated' => sanitize_text_field((string) ($calendar_data['updated'] ?? '')),
      ));
      return rozpis_omsi_events_response($events_by_day);
    } finally {
      $current_lock = get_option($lock_name);
      if (is_array($current_lock) && isset($current_lock['token']) && $current_lock['token'] === $lock_token) {
        delete_option($lock_name);
      }
    }
  }

  function rozpis_omsi_events_response($events_by_day)
  {
    $response = new WP_REST_Response(array('days' => $events_by_day));
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    return $response;
  }

  function rozpis_omsi_engine_js()
  {
    return <<<'ENGINE_JS'
(function () {
  "use strict";

  if (window.RozpisOmsiEngine) {
    return;
  }

  const CONFIG = window.RozpisOmsiConfig || {};
  const EVENTS_URL = CONFIG.eventsUrl;
  const CALENDAR_ID = CONFIG.calendarId;
  const CALENDAR_API_KEY = CONFIG.calendarApiKey;
  const SITE_TIME_ZONE = CONFIG.timeZone || "Europe/Bratislava";
  const NUM_DAYS = 7;
  const REFRESH_INTERVAL = 5 * 60 * 1000;
  const LIVE_STATUS_TICK = 60 * 1000;
  const PHOTO_BASE_URL = "https://www.mamateyka.sk";
  const PHOTO_FILES = {
    "jozef skala": "wp-content/uploads/2026/08/Jozef-Skala.jpg",
    "bohus levko": "wp-content/uploads/2026/08/Bohus-Levko-1.jpg",
    "dusan vilhan": "wp-content/uploads/2026/08/Dusan-Vilhan.jpg",
    "marian valabek": "wp-content/uploads/2026/08/Marian-Valabek.jpg",
    "jozef postenyi": "wp-content/uploads/2026/08/Jozef-Postenyi.jpg",
    "jan capla": "wp-content/uploads/2026/08/Jan-Capla.png",
    "jan cvercko": "wp-content/uploads/2026/08/Jan-Cvercko.png",
    "robert flamik": "wp-content/uploads/2026/08/Robert-Flamik.png",
  };
  const PHOTO_ALIASES = {
    cvercko: "jan cvercko",
    capla: "jan capla",
    levko: "bohus levko",
    flamik: "robert flamik",
    postenyi: "jozef postenyi",
    skala: "jozef skala",
    vilhan: "dusan vilhan",
    valabek: "marian valabek",
  };
  // Katalóg všetkých rozpoznateľných mien (aj aliasov) pre extrakciu mena z textu s navyše slovami
  const KNOWN_NAME_CATALOG = Array.from(
    new Set([...Object.keys(PHOTO_FILES), ...Object.keys(PHOTO_ALIASES)]),
  )
    .map((name) => name.split(" "))
    .sort((a, b) => b.length - a.length);
  // Zjednotená (tolerantná, +-2 min) detekcia mládežka/detská/kočíková - spoločná pre oba layouty
  const MASS_LABELS = [
    { day: 3, hour: 19, minute: 0, label: "mládežka" }, // streda 19:00
    { day: 5, hour: 17, minute: 30, label: "detská" }, // piatok 17:30
    { day: 5, hour: 9, minute: 30, label: "kočíková" }, // piatok 9:30
    { day: 0, hour: 9, minute: 30, label: "mládežka" }, // nedeľa 9:30
    { day: 0, hour: 11, minute: 0, label: "detská" }, // nedeľa 11:00
  ];
  const MASS_LABEL_TOLERANCE_MIN = 2;

  // Posledné načítané udalosti dnešného dňa, pre prekreslenie Prebieha/Najbližšie bez volania API
  let todayEventsCache = [];
  let latestDataByIso = null;
  let activeFetch = null;
  let bootstrapped = false;
  const instances = [];
  const layouts = {};

  function getSiteDateTimeParts(date) {
    const fixedOffset = SITE_TIME_ZONE.match(/^([+-])(\d{2}):?(\d{2})$/);
    if (fixedOffset) {
      const sign = fixedOffset[1] === "+" ? 1 : -1;
      const offsetMinutes =
        sign * (Number(fixedOffset[2]) * 60 + Number(fixedOffset[3]));
      const shifted = new Date(date.getTime() + offsetMinutes * 60 * 1000);
      return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
        weekday: shifted.getUTCDay(),
        hour: shifted.getUTCHours(),
        minute: shifted.getUTCMinutes(),
      };
    }

    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: SITE_TIME_ZONE,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      weekday: "short",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const weekdays = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

    return {
      year: Number(values.year),
      month: Number(values.month),
      day: Number(values.day),
      weekday: weekdays[values.weekday],
      hour: Number(values.hour),
      minute: Number(values.minute),
    };
  }

  function stripDiacritics(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/[łŁ]/g, (ch) => (ch === "ł" ? "l" : "L"));
  }

  function normalizeName(value) {
    return stripDiacritics(String(value || ""))
      .toLowerCase()
      .replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, "")
      .replace(/[_\-/]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function buildNameVariants(value) {
    const raw = String(value || "").trim();
    if (!raw) return [];

    const variants = new Set();
    const cleaned = raw
      .replace(/^don\s+/i, "")
      .replace(/^sv\.?\s+/i, "")
      .trim();
    const forms = [
      raw,
      cleaned,
      stripDiacritics(raw),
      stripDiacritics(cleaned),
    ];

    forms.forEach((form) => {
      if (!form) return;
      const normalized = normalizeName(form);
      if (normalized) {
        variants.add(normalized);
      }

      const spaced = form
        .replace(/\s*[-–—]\s*/g, " ")
        .replace(/\s+/g, " ")
        .trim();
      if (spaced) {
        variants.add(normalizeName(spaced));
      }

      const parts = form.split(/\s+/).filter(Boolean);
      if (parts.length > 1) {
        variants.add(
          normalizeName(parts.slice(1).join(" ") + " " + parts[0]),
        );
      }
    });

    return Array.from(variants).filter(Boolean);
  }

  // WordPress generuje k originálu menšie varianty "<meno>-<šírka>x<výška>.<prípona>"
  const PHOTO_THUMB_SIZE = 150;
  const PHOTO_ZOOM_SIZE = 300;

  function photoVariantUrl(url, size) {
    if (!url || !size) return url || "";
    return url.replace(/(\.[a-z0-9]+)$/i, `-${size}x${size}$1`);
  }

  // Pri chybe (napr. chýbajúci variant) skúsi originál, až potom fotku odstráni
  document.addEventListener(
    "error",
    (e) => {
      const img = e.target;
      if (!img || img.tagName !== "IMG" || !img.classList.contains("avatar-img")) return;
      const full = img.dataset.full;
      if (full && img.getAttribute("src") !== full) {
        img.src = full;
        return;
      }
      const wrapper = img.closest(".photo-zoom") || img.closest(".avatar");
      if (wrapper) wrapper.remove();
    },
    true,
  );

  function resolvePhotoUrl(rawName) {
    const name = String(rawName || "").trim();
    if (!name) return "";

    const variants = buildNameVariants(name);
    for (const variant of variants) {
      const alias = PHOTO_ALIASES[variant];
      const candidate = alias ? alias : variant;
      const direct = PHOTO_FILES[candidate];
      if (direct) {
        return `${PHOTO_BASE_URL.replace(/\/$/, "")}/${direct.replace(/^\/+/, "")}`;
      }
    }

    return "";
  }

  // Ak text obsahuje presne jedno známe meno (napr. "Vilhan test"), oddelí ho od navyše slov
  function extractKnownNameAndExtra(rawText) {
    const raw = String(rawText || "").trim();
    if (!raw) return { name: raw, extra: "" };

    const words = raw.split(/\s+/).filter(Boolean);
    const normWords = words.map((w) => normalizeName(w));
    const matches = [];

    let i = 0;
    while (i < words.length) {
      let matchedLen = 0;
      for (const catalogTokens of KNOWN_NAME_CATALOG) {
        const len = catalogTokens.length;
        if (i + len > words.length) continue;
        let ok = true;
        for (let k = 0; k < len; k++) {
          if (normWords[i + k] !== catalogTokens[k]) {
            ok = false;
            break;
          }
        }
        if (ok) {
          matchedLen = len;
          break;
        }
      }

      if (matchedLen > 0) {
        matches.push({ start: i, end: i + matchedLen - 1 });
        i += matchedLen;
      } else {
        i += 1;
      }
    }

    // Nejednoznačné (0 alebo 2+ zhôd) - bez zmeny, celý text ostáva ako meno
    if (matches.length !== 1) {
      return { name: raw, extra: "" };
    }

    const { start, end } = matches[0];
    const name = words.slice(start, end + 1).join(" ");
    const extra = words
      .slice(0, start)
      .concat(words.slice(end + 1))
      .join(" ")
      .trim();

    return { name, extra };
  }

  function getIsoDate(offset) {
    const parts = getSiteDateTimeParts(new Date());
    const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + offset));
    return [
      date.getUTCFullYear(),
      String(date.getUTCMonth() + 1).padStart(2, "0"),
      String(date.getUTCDate()).padStart(2, "0"),
    ].join("-");
  }

  function getEventTimes(e) {
    const start = new Date(e.start.dateTime || e.start.date);
    const end =
      e.end && (e.end.dateTime || e.end.date)
        ? new Date(e.end.dateTime || e.end.date)
        : new Date(start.getTime() + 60 * 60 * 1000);
    return { start, end };
  }

  function getMassLabel(dt) {
    const parts = getSiteDateTimeParts(dt);
    const totalMin = parts.hour * 60 + parts.minute;
    const match = MASS_LABELS.find(
      (m) =>
        m.day === parts.weekday &&
        Math.abs(totalMin - (m.hour * 60 + m.minute)) <=
          MASS_LABEL_TOLERANCE_MIN,
    );
    return match ? match.label : "";
  }

  function formatEventTime(dt) {
    const parts = getSiteDateTimeParts(dt);
    return `${String(parts.hour).padStart(2, "0")}:${String(parts.minute).padStart(2, "0")}`;
  }

  // Spojí deň/čas label s navyše slovami vytiahnutými z mena (napr. "mládežka · test")
  function combineMassLabel(dayLabel, extra, eventTitle) {
    const normalizedLabel = stripDiacritics(dayLabel).toLowerCase().trim();
    const titleWords = stripDiacritics(eventTitle)
      .toLowerCase()
      .split(/[^a-z0-9]+/);
    const labelAlreadyInTitle =
      normalizedLabel && titleWords.includes(normalizedLabel);

    return [labelAlreadyInTitle ? "" : dayLabel, extra]
      .map((s) => String(s || "").trim())
      .filter(Boolean)
      .join(" · ");
  }

  function addDonPrefix(name) {
    return name.toLowerCase().includes("biskup") ? name : "don " + name;
  }

  function splitServiceTitle(value) {
    return value
      .split(/\s*(?:[-*\/,])\s*/)
      .map((part) => part.trim())
      .filter((part, index, parts) =>
        Boolean(part) || (index === 0 && parts.length > 1),
      );
  }

  // Rozobratie titulku eventu na celebranta / spovedníkov / fotky - zdieľané oboma layoutmi
  function parseEventTitle(title) {
    let omsiar = "",
      spovednici = [],
      hasServiceDetails = true,
      nameExtra = "",
      confessionOnly = false,
      specialServiceLabel = "";

    const normalizedTitle = title
      .trim()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLocaleLowerCase("sk-SK")
      .replace(/\s+/g, " ");

    if (normalizedTitle === "nemam cas") {
      hasServiceDetails = false;
    }

    const titleParts = splitServiceTitle(title);
    let priestPhotoUrl = "";
    let priestSourceName = "";

    if (titleParts.length > 1 && !titleParts[0]) {
      hasServiceDetails = false;
      confessionOnly = true;
      spovednici = titleParts.slice(1).filter(Boolean).map((s) => {
        return addDonPrefix(s);
      });
    } else if (
      /^\[[^\]]+\](?:\s+.+)?$/.test(titleParts[0])
    ) {
      const specialServiceMatch = titleParts[0].match(
        /^\[([^\]]+)\](?:\s+(.+))?$/,
      );
      if (specialServiceMatch) {
        specialServiceLabel = specialServiceMatch[1].trim();
        const specialCelebrantRaw = (specialServiceMatch[2] || "").trim();

        if (specialCelebrantRaw) {
          const { name, extra } = extractKnownNameAndExtra(
            specialCelebrantRaw,
          );
          priestSourceName = name;
          omsiar = addDonPrefix(name);
          nameExtra = extra;
        } else {
          hasServiceDetails = false;
          confessionOnly = true;
        }

        spovednici = titleParts.slice(1).filter(Boolean).map((s) => {
          return addDonPrefix(s);
        });
      }
    } else if (titleParts.length > 1) {
      const [omsiarNameRaw, ...spovednikNames] = titleParts;
      if (omsiarNameRaw) {
        const { name, extra } = extractKnownNameAndExtra(omsiarNameRaw);
        priestSourceName = name;
        omsiar = addDonPrefix(name);
        nameExtra = extra;
      } else {
        hasServiceDetails = false;
      }

      spovednici = spovednikNames.map((s) => {
        const spovednikName = s.trim();
        return spovednikName ? addDonPrefix(spovednikName) : "???";
      });
    } else if (hasServiceDetails) {
      const omsiarNameRaw = title.trim();
      if (omsiarNameRaw) {
        const { name, extra } = extractKnownNameAndExtra(omsiarNameRaw);
        priestSourceName = name;
        omsiar = addDonPrefix(name);
        nameExtra = extra;
      } else {
        hasServiceDetails = false;
      }
    }

    if (priestSourceName) {
      priestPhotoUrl = resolvePhotoUrl(priestSourceName);
    }

    if (!hasServiceDetails && !confessionOnly) {
      spovednici = [];
      nameExtra = "";
    }

    return {
      omsiar,
      spovednici,
      hasServiceDetails,
      hasCelebrant: Boolean(priestSourceName),
      priestPhotoUrl,
      priestSourceName,
      nameExtra,
      specialServiceLabel,
    };
  }

  // Filtrovanie/triedenie udalostí dňa + detekcia prebiehajúcej/najbližšej omše (len offset 0)
  function computeDaySchedule(offset, events) {
    const validEvents = (events || []).filter(
      (e) =>
        e.start &&
        (e.start.dateTime || e.start.date) &&
        (!e.summary || !e.summary.includes("(")),
    );

    const sorted = validEvents.sort((a, b) => {
      const tA = a.start.dateTime || a.start.date;
      const tB = b.start.dateTime || b.start.date;
      return new Date(tA) - new Date(tB);
    });

    let liveIdx = -1,
      nextIdx = -1;
    if (offset === 0) {
      const now = new Date();
      sorted.forEach((e, idx) => {
        const { start, end } = getEventTimes(e);
        if (now >= start && now < end) liveIdx = idx;
      });
      if (liveIdx === -1) {
        for (let idx = 0; idx < sorted.length; idx++) {
          if (getEventTimes(sorted[idx]).start > now) {
            nextIdx = idx;
            break;
          }
        }
      }
    }

    return { sorted, liveIdx, nextIdx };
  }

  function getEmptyStateMessage(offset) {
    const dayDate = new Date(`${getIsoDate(offset)}T12:00:00Z`);
    const isMonday = dayDate.getUTCDay() === 1;
    return isMonday
      ? "V pondelok sa u nás sväté omše neslávia."
      : "Rozpis na tento deň ešte nebol zverejnený.";
  }

  // Skeleton 7 dní je identický v oboch layoutoch, líši sa len obal okolo neho (PHP shell)
  function buildDayColumnsHtml() {
    const days = [0, 1, 2, 3, 4, 5, 6];
    return days
      .map((o) => {
        const d = new Date(`${getIsoDate(o)}T12:00:00Z`);
        const isToday = o === 0;
        const dayName = isToday
          ? "Dnes"
          : d.toLocaleDateString("sk-SK", {
              weekday: "long",
              timeZone: "UTC",
            });
        const dayDate = d.toLocaleDateString("sk-SK", {
          day: "numeric",
          month: "long",
          timeZone: "UTC",
        });

        return `
                <div class="day-column ${isToday ? "today" : ""}">
                    <div class="day-header">
                        <div class="day-name">${dayName}</div>
                        <div class="day-date">${dayDate}</div>
                    </div>
                    <div class="schedule-list" data-day-offset="${o}" aria-busy="true">
                        <div class="skeleton-card" aria-hidden="true">
                            <span class="skeleton-line skeleton-line--time"></span>
                            <span class="skeleton-line skeleton-line--name"></span>
                            <span class="skeleton-line skeleton-line--detail"></span>
                        </div>
                    </div>
                </div>
            `;
      })
      .join("");
  }

  function renderInstance(instance, dataByIso) {
    for (let o = 0; o < NUM_DAYS; o++) {
      const iso = getIsoDate(o);
      instance.layout.renderDay(instance.rootEl, o, dataByIso[iso] || []);
    }
    if (instance.layout.onBatchRendered) {
      instance.layout.onBatchRendered(instance.rootEl);
    }
  }

  function renderAllInstances(dataByIso) {
    instances.forEach((instance) => renderInstance(instance, dataByIso));
  }

  function showInitialLoadError() {
    instances.forEach((instance) => {
      instance.rootEl.querySelectorAll(".schedule-list").forEach((box) => {
        box.innerHTML =
          '<div class="empty-state">Rozpis sa nepodarilo načítať. Skúste to o chvíľu znova.</div>';
        box.removeAttribute("aria-busy");
      });
    });
  }

  async function fetchGoogleCalendarEvents() {
    const start = new Date();
    start.setUTCHours(0, 0, 0, 0);
    start.setUTCDate(start.getUTCDate() - 1);
    const end = new Date(start);
    end.setUTCDate(end.getUTCDate() + NUM_DAYS + 2);
    const params = new URLSearchParams({
      key: CALENDAR_API_KEY,
      timeMin: start.toISOString(),
      timeMax: end.toISOString(),
      singleEvents: "true",
      orderBy: "startTime",
      maxResults: "2500",
    });
    const url = `https://www.googleapis.com/calendar/v3/calendars/${encodeURIComponent(CALENDAR_ID)}/events?${params}`;
    const response = await fetch(url, { cache: "no-store" });
    if (!response.ok) {
      throw new Error(`Google Calendar returned HTTP ${response.status}`);
    }

    const calendarData = await response.json();
    if (!calendarData || !Array.isArray(calendarData.items)) {
      throw new Error("Google Calendar returned invalid data");
    }

    const days = {};
    for (let offset = 0; offset < NUM_DAYS; offset++) {
      days[getIsoDate(offset)] = [];
    }

    calendarData.items.forEach((event) => {
      if (!event.start || !(event.start.dateTime || event.start.date)) return;

      let eventDate;
      if (event.start.dateTime) {
        const parts = getSiteDateTimeParts(new Date(event.start.dateTime));
        eventDate = `${parts.year}-${String(parts.month).padStart(2, "0")}-${String(parts.day).padStart(2, "0")}`;
      } else {
        eventDate = event.start.date.slice(0, 10);
      }
      if (days[eventDate]) days[eventDate].push(event);
    });

    return { days };
  }

  function fetchAndCacheEvents() {
    if (activeFetch) return activeFetch;

    activeFetch = (async () => {
      try {
        let data;
        try {
          const res = await fetch(EVENTS_URL, {
            cache: "no-store",
            credentials: "same-origin",
            headers: { Accept: "application/json" },
          });
          if (!res.ok) {
            throw new Error(`Rozpis endpoint returned HTTP ${res.status}`);
          }
          data = await res.json();
          if (!data.days || typeof data.days !== "object") {
            throw new Error("Rozpis endpoint returned invalid data");
          }
        } catch (proxyError) {
          console.warn("Serverové načítanie rozpisu zlyhalo, skúšam Google Calendar priamo:", proxyError);
          data = await fetchGoogleCalendarEvents();
        }
        if (!data.days || typeof data.days !== "object") {
          throw new Error("Rozpis endpoint returned invalid data");
        }

        latestDataByIso = data.days;
        todayEventsCache = latestDataByIso[getIsoDate(0)] || [];
        renderAllInstances(latestDataByIso);
      } catch (err) {
        console.error("Nepodarilo sa obnoviť rozpis:", err);
        if (!latestDataByIso) showInitialLoadError();
      } finally {
        activeFetch = null;
      }
    })();

    return activeFetch;
  }

  function liveTick() {
    if (!latestDataByIso) return;

    instances.forEach((instance) => {
      instance.layout.renderDay(instance.rootEl, 0, todayEventsCache);
      if (instance.layout.onBatchRendered) {
        instance.layout.onBatchRendered(instance.rootEl);
      }
    });
  }

  // Spustí sa len raz (pri prvej registrovanej instancii), stará sa o zdieľaný fetch/cache/intervaly
  function bootstrap() {
    if (bootstrapped) return;
    bootstrapped = true;

    fetchAndCacheEvents();
    setInterval(fetchAndCacheEvents, REFRESH_INTERVAL);

    setInterval(liveTick, LIVE_STATUS_TICK);
    const displayedDate = getIsoDate(0);
    setInterval(() => {
      if (getIsoDate(0) !== displayedDate) location.reload();
    }, LIVE_STATUS_TICK);
  }

  function registerInstance(rootEl, type) {
    const layout = layouts[type];
    if (!rootEl || !layout) return;

    layout.buildSkeleton(rootEl);
    const instance = { rootEl, type, layout };
    instances.push(instance);

    if (!bootstrapped) {
      bootstrap();
      return;
    }

    if (latestDataByIso) {
      renderInstance(instance, latestDataByIso);
    } else {
      fetchAndCacheEvents();
    }
  }

  window.RozpisOmsiEngine = {
    core: {
      getIsoDate,
      getEventTimes,
      getMassLabel,
      formatEventTime,
      combineMassLabel,
      parseEventTitle,
      computeDaySchedule,
      getEmptyStateMessage,
      buildDayColumnsHtml,
      resolvePhotoUrl,
      photoVariantUrl,
      PHOTO_THUMB_SIZE,
      PHOTO_ZOOM_SIZE,
      addDonPrefix,
    },
    layouts,
    registerInstance,
  };
})();
ENGINE_JS;
  }

  function rozpis_omsi_vertical_js()
  {
    return <<<'VERTICAL_JS'
(function () {
  "use strict";

  if (!window.RozpisOmsiEngine) return;

  const core = window.RozpisOmsiEngine.core;

  function buildSkeleton(rootEl) {
    const wrapper = rootEl.querySelector(".rozpis-days-wrapper");
    if (wrapper) wrapper.innerHTML = core.buildDayColumnsHtml();
  }

  function renderDay(rootEl, offset, events) {
    const box = rootEl.querySelector(
      `.schedule-list[data-day-offset="${offset}"]`,
    );
    if (!box) return;
    box.removeAttribute("aria-busy");
    const column = box.closest(".day-column");

    const { sorted, liveIdx, nextIdx } = core.computeDaySchedule(
      offset,
      events,
    );

    if (!sorted.length) {
      if (column) column.style.display = "";
      box.innerHTML = `<div class="empty-state">${core.getEmptyStateMessage(offset)}</div>`;
      return;
    }

    if (column) column.style.display = "";

    box.innerHTML = sorted
      .map((e, idx) => {
        const startStr = e.start.dateTime || e.start.date;
        const startDate = new Date(startStr);
        const time = core.formatEventTime(startDate);

        const title = e.summary || "";
        const {
          omsiar,
          spovednici,
          hasServiceDetails,
          hasCelebrant,
          priestPhotoUrl,
          priestSourceName,
          nameExtra,
          specialServiceLabel,
        } = core.parseEventTitle(title);

        const dayLabel = hasServiceDetails
          ? core.getMassLabel(startDate)
          : "";
        const specialLabel = core.combineMassLabel(
          dayLabel,
          hasServiceDetails ? nameExtra : "",
          title,
        );

        const statusClass =
          idx === liveIdx
            ? " priest-card--live"
            : idx === nextIdx
              ? " priest-card--next"
              : "";
        const statusBadge =
          idx === liveIdx
            ? '<span class="status-badge status-badge--live">Prebieha</span>'
            : idx === nextIdx
              ? '<span class="status-badge status-badge--next">Najbližšie</span>'
              : "";

        const confessionMarkup = spovednici.length
          ? `
                <div class="confession-box">
                  <div class="confession-title">Spovedanie</div>
                  <div class="confession-names">
                    ${spovednici
                      .map((s) => {
                        const confessorName = String(s || "").replace(
                          /^don\s+/i,
                          "",
                        );
                        const confessorPhotoUrl =
                          core.resolvePhotoUrl(confessorName);
                        return `
                          <span class="confession-person">
                            ${confessorPhotoUrl ? `                            <span class="avatar avatar--small"><img class="avatar-img" src="${core.photoVariantUrl(confessorPhotoUrl, core.PHOTO_THUMB_SIZE)}" data-full="${confessorPhotoUrl}" alt="${confessorName || "Spovedník"}" decoding="async" /></span>` : ""}
                            <span class="confession-person-name">${s}</span>
                          </span>
                        `;
                      })
                      .join("")}
                  </div>
                </div>
              `
          : "";

        return `
                    <div class="priest-card${statusClass}">
                        ${statusBadge}
                        <div class="time-block">
                            <div class="service-tag-group">
                                ${hasServiceDetails ? '<span class="service-tag">Svätá omša</span>' : specialServiceLabel ? `<span class="service-tag">${specialServiceLabel}</span>` : ""}
                                ${specialLabel ? `<span class="service-special">${specialLabel}</span>` : ""}
                            </div>
                            <span class="time">${time}</span>
                        </div>
                        ${hasCelebrant ? `<div class="service-main">
                          ${priestPhotoUrl ? `<span class="avatar"><img class="avatar-img" src="${core.photoVariantUrl(priestPhotoUrl, core.PHOTO_THUMB_SIZE)}" data-full="${priestPhotoUrl}" alt="${priestSourceName || "Kňaz"}" decoding="async" /></span>` : ""}
                            <div class="service-copy">
                            <div class="name">${omsiar}</div>
                            </div>
                        </div>` : ""}
                        ${confessionMarkup}
                    </div>
                `;
      })
      .join("");
  }

  window.RozpisOmsiEngine.layouts.vertical = {
    buildSkeleton,
    renderDay,
  };
})();
VERTICAL_JS;
  }

  function rozpis_omsi_horizontal_js()
  {
    return <<<'HORIZONTAL_JS'
(function () {
  "use strict";

  if (!window.RozpisOmsiEngine) return;

  const core = window.RozpisOmsiEngine.core;

  // Panel je v <body> s position: fixed, aby ho neorezali overflow/transform rodičov (Breakdance, day-column)
  const ZOOM_GAP = 14;
  const ZOOM_MARGIN = 8;
  let zoomPanel = null;
  let zoomItem = null;

  function getZoomPanel() {
    if (!zoomPanel) {
      zoomPanel = document.createElement("div");
      zoomPanel.className = "rozpis-zoom-floating";
      zoomPanel.setAttribute("aria-hidden", "true");
      document.body.appendChild(zoomPanel);
      window.addEventListener("scroll", hideZoom, { capture: true, passive: true });
      window.addEventListener("resize", hideZoom);
      document.addEventListener("click", (e) => {
        if (!e.target.closest || !e.target.closest(".photo-zoom")) hideZoom();
      });
    }
    return zoomPanel;
  }

  function hideZoom() {
    zoomItem = null;
    if (zoomPanel) zoomPanel.classList.remove("is-visible");
  }

  function showZoom(item) {
    const source = item.querySelector(".photo-zoom__panel");
    if (!source) return;
    zoomItem = item;

    const panel = getZoomPanel();
    const clones = Array.from(source.children).map((child) => {
      const clone = child.cloneNode(true);
      clone.removeAttribute("onerror");
      return clone;
    });
    panel.replaceChildren(...clones);
    loadZoomImage(item, panel.querySelector(".photo-zoom__image"));

    const anchor = item.getBoundingClientRect();
    const width = panel.offsetWidth;
    const height = panel.offsetHeight;
    const viewportW = document.documentElement.clientWidth;
    const viewportH = document.documentElement.clientHeight;

    let left = anchor.right + ZOOM_GAP;
    if (left + width > viewportW - ZOOM_MARGIN) left = anchor.left - ZOOM_GAP - width;
    left = Math.max(ZOOM_MARGIN, Math.min(left, viewportW - width - ZOOM_MARGIN));

    const top = anchor.top + anchor.height / 2 - height / 2;
    panel.style.left = `${left}px`;
    panel.style.top = `${Math.max(ZOOM_MARGIN, Math.min(top, viewportH - height - ZOOM_MARGIN))}px`;
    panel.classList.add("is-visible");
  }

  // Pri false sa väčšie fotky sťahujú až pri hover/ťuknutí (menej dát, ale prvé zobrazenie chvíľu trvá)
  const PRELOAD_ZOOM_IMAGES = true;
  // Dekódované obrázky držíme v pamäti, aby sa ďalšie zobrazenie nenačítavalo znova
  const zoomImageCache = new Map();

  function preloadZoomImage(mediumSrc, fullSrc) {
    const key = mediumSrc || fullSrc;
    if (!key) return null;
    const cached = zoomImageCache.get(key);
    if (cached) return cached;

    const entry = { src: null, image: null, promise: null };
    const attempt = (src) =>
      new Promise((resolve, reject) => {
        const loader = new Image();
        entry.image = loader;
        loader.src = src;
        loader.decode().then(
          () => resolve(src),
          () => {
            if (src !== fullSrc && fullSrc) attempt(fullSrc).then(resolve, reject);
            else reject();
          },
        );
      });
    entry.promise = attempt(key).then(
      (src) => {
        entry.src = src;
        return src;
      },
      () => {
        zoomImageCache.delete(key);
        return null;
      },
    );
    zoomImageCache.set(key, entry);
    return entry;
  }

  // Hotový obrázok sa zobrazí hneď; inak je vidieť miniatúra a po načítaní sa vymení
  function loadZoomImage(item, img) {
    if (!img) return;
    const entry = preloadZoomImage(img.dataset.src, img.dataset.full);
    if (entry && entry.src) {
      img.src = entry.src;
      return;
    }
    const thumb = item.querySelector(".avatar-img");
    img.src = (thumb && (thumb.currentSrc || thumb.src)) || img.dataset.src || img.dataset.full;
    if (entry) {
      entry.promise.then((src) => {
        if (src && img.isConnected) img.src = src;
      });
    }
  }

  function preloadItemZoomImage(item) {
    const img = item.querySelector(".photo-zoom__image");
    if (img) preloadZoomImage(img.dataset.src, img.dataset.full);
  }

  function canPreloadZoomImages() {
    if (!PRELOAD_ZOOM_IMAGES) return false;
    const conn = navigator.connection;
    return !(conn && (conn.saveData || /(^|-)2g$|3g/.test(conn.effectiveType || "")));
  }

  // Viditeľné fotky sa dotiahnu vopred v pokoji, aby zväčšenie bolo okamžité
  let zoomPreloadObserver = null;
  function observeZoomPreload(item) {
    if (!canPreloadZoomImages() || !("IntersectionObserver" in window)) return;
    if (!zoomPreloadObserver) {
      zoomPreloadObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          zoomPreloadObserver.unobserve(entry.target);
          const run = () => preloadItemZoomImage(entry.target);
          if ("requestIdleCallback" in window) requestIdleCallback(run, { timeout: 3000 });
          else setTimeout(run, 1500);
        });
      });
    }
    zoomPreloadObserver.observe(item);
  }

  function bindPhotoZoom(rootEl) {
    // Prekreslenie môže odstrániť element, na ktorom visel panel (mouseleave sa nespustí)
    hideZoom();
    const isTouch = window.matchMedia("(pointer: coarse)").matches;

    rootEl.querySelectorAll(".photo-zoom").forEach((item) => {
      if (item.dataset.zoomBound === "true") return;

      observeZoomPreload(item);

      if (isTouch) {
        item.addEventListener("touchstart", () => preloadItemZoomImage(item), { passive: true });
        // Na dotyku nie je hover - ťuknutie prepína panel
        item.addEventListener("click", () => {
          if (zoomItem === item) hideZoom();
          else showZoom(item);
        });
      } else {
        item.addEventListener("mouseenter", () => showZoom(item));
        item.addEventListener("mouseleave", hideZoom);
      }

      item.dataset.zoomBound = "true";
    });
  }

  function getItemsVisible() {
    if (window.innerWidth > 1024) return 3;
    if (window.innerWidth > 768) return 2;
    return 1;
  }

  function getTrack(rootEl) {
    return rootEl.querySelector(".rozpis-days-wrapper");
  }

  function getTrackGapPx(rootEl) {
    const track = getTrack(rootEl);
    if (!track) return 0;
    const gap = parseFloat(
      window.getComputedStyle(track).gap ||
        window.getComputedStyle(track).columnGap,
    );
    return Number.isNaN(gap) ? 0 : gap;
  }

  function getVisibleColumns(rootEl) {
    const track = getTrack(rootEl);
    if (!track) return [];
    return Array.from(track.children).filter(
      (col) => col.style.display !== "none",
    );
  }

  function closestColumnIndex(rootEl, columns) {
    const track = getTrack(rootEl);
    if (!track || !columns.length) return 0;

    let closest = 0;
    let smallestDiff = Infinity;
    columns.forEach((col, idx) => {
      const diff = Math.abs(col.offsetLeft - track.scrollLeft);
      if (diff < smallestDiff) {
        smallestDiff = diff;
        closest = idx;
      }
    });
    return closest;
  }

  function updateArrowState(rootEl, columns) {
    const track = getTrack(rootEl);
    const prevArrow = rootEl.querySelector(".carousel-arrow--prev");
    const nextArrow = rootEl.querySelector(".carousel-arrow--next");
    if (!track || !prevArrow || !nextArrow || !columns.length) return;

    const isMobile = window.innerWidth <= 768;
    const atStart = track.scrollLeft <= 1;
    const atEnd =
      track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
    prevArrow.classList.toggle("is-hidden", isMobile || atStart);
    nextArrow.classList.toggle("is-hidden", isMobile || atEnd);
  }

  function updateActiveDot(rootEl, columns) {
    const dotsBox = rootEl.querySelector(".rozpis-dots");
    if (!dotsBox) return;
    const activeIdx = closestColumnIndex(rootEl, columns);
    dotsBox.querySelectorAll(".dot").forEach((dot, idx) => {
      dot.classList.toggle("active", idx === activeIdx);
    });
  }

  function buildDots(rootEl, columns) {
    const dotsBox = rootEl.querySelector(".rozpis-dots");
    if (!dotsBox) return;

    dotsBox.innerHTML = columns
      .map(
        (_, idx) =>
          `<button type="button" class="dot" data-index="${idx}" aria-label="Deň ${idx + 1}"></button>`,
      )
      .join("");

    dotsBox.querySelectorAll(".dot").forEach((dot) => {
      dot.addEventListener("click", () => {
        const idx = Number(dot.dataset.index);
        const track = getTrack(rootEl);
        const visibleColumns = getVisibleColumns(rootEl);
        if (track && visibleColumns[idx]) {
          track.scrollTo({
            left: visibleColumns[idx].offsetLeft,
            behavior: "smooth",
          });
        }
      });
    });
  }

  function layoutCarousel(rootEl) {
    const track = getTrack(rootEl);
    const shell = rootEl.querySelector(".carousel-shell");
    const prevArrow = rootEl.querySelector(".carousel-arrow--prev");
    const nextArrow = rootEl.querySelector(".carousel-arrow--next");
    const dotsBox = rootEl.querySelector(".rozpis-dots");
    if (!track || !shell || !prevArrow || !nextArrow || !dotsBox) return;

    const columns = getVisibleColumns(rootEl);
    const count = columns.length;
    const itemsVisible = getItemsVisible();

    if (!count) return;

    if (count <= itemsVisible) {
      const gapPx = getTrackGapPx(rootEl);
      const width =
        count > 1
          ? `calc((100% - ${gapPx * (count - 1)}px) / ${count})`
          : "100%";
      columns.forEach((col) => {
        col.style.flexBasis = width;
        col.style.width = width;
      });
      track.style.overflowX = "hidden";
      track.scrollLeft = 0;
      shell.classList.add("no-arrows");
      prevArrow.classList.add("is-hidden");
      nextArrow.classList.add("is-hidden");
      dotsBox.classList.add("is-hidden");
      return;
    }

    const peek = 0.2;
    const gapPx = getTrackGapPx(rootEl);
    const width = `calc((100% - ${gapPx * itemsVisible}px) / ${itemsVisible + peek})`;
    columns.forEach((col) => {
      col.style.flexBasis = width;
      col.style.width = width;
    });
    track.style.overflowX = "auto";
    shell.classList.remove("no-arrows");
    buildDots(rootEl, columns);
    dotsBox.classList.remove("is-hidden");
    updateArrowState(rootEl, columns);
    updateActiveDot(rootEl, columns);
  }

  function scrollByOneDay(rootEl, direction) {
    const track = getTrack(rootEl);
    if (!track) return;
    const columns = getVisibleColumns(rootEl);
    const currentIdx = closestColumnIndex(rootEl, columns);
    const targetIdx = Math.min(
      Math.max(currentIdx + direction, 0),
      columns.length - 1,
    );
    track.scrollTo({
      left: columns[targetIdx].offsetLeft,
      behavior: "smooth",
    });
  }

  function snapToClosestColumn(rootEl) {
    const track = getTrack(rootEl);
    if (!track) return;
    const columns = getVisibleColumns(rootEl);
    if (columns.length <= getItemsVisible()) return;
    const idx = closestColumnIndex(rootEl, columns);
    track.scrollTo({ left: columns[idx].offsetLeft, behavior: "smooth" });
  }

  // Drag/scroll/resize stav je zámerne v uzávere tejto funkcie - každá instancia má vlastný stav
  function bindCarouselInteractions(rootEl) {
    const track = getTrack(rootEl);
    const prevArrow = rootEl.querySelector(".carousel-arrow--prev");
    const nextArrow = rootEl.querySelector(".carousel-arrow--next");

    if (track) {
      track.addEventListener("scroll", () => {
        requestAnimationFrame(() => {
          const columns = getVisibleColumns(rootEl);
          if (columns.length > getItemsVisible()) {
            updateArrowState(rootEl, columns);
            updateActiveDot(rootEl, columns);
          }
        });
      });

      let isDragging = false;
      let dragStartX = 0;
      let dragStartScrollLeft = 0;

      track.addEventListener("mousedown", (e) => {
        const columns = getVisibleColumns(rootEl);
        if (columns.length <= getItemsVisible()) return;
        isDragging = true;
        dragStartX = e.pageX;
        dragStartScrollLeft = track.scrollLeft;
        track.classList.add("is-dragging");
      });

      window.addEventListener("mousemove", (e) => {
        if (!isDragging) return;
        e.preventDefault();
        track.scrollLeft = dragStartScrollLeft - (e.pageX - dragStartX);
      });

      function endDrag() {
        if (!isDragging) return;
        isDragging = false;
        track.classList.remove("is-dragging");
        snapToClosestColumn(rootEl);
      }

      window.addEventListener("mouseup", endDrag);
      window.addEventListener("mouseleave", endDrag);
    }

    if (prevArrow) {
      prevArrow.addEventListener("click", () => scrollByOneDay(rootEl, -1));
    }

    if (nextArrow) {
      nextArrow.addEventListener("click", () => scrollByOneDay(rootEl, 1));
    }

    let resizeTimeout;
    window.addEventListener("resize", () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => layoutCarousel(rootEl), 150);
    });
  }

  function buildSkeleton(rootEl) {
    const wrapper = getTrack(rootEl);
    if (wrapper) wrapper.innerHTML = core.buildDayColumnsHtml();
    bindPhotoZoom(rootEl);
    bindCarouselInteractions(rootEl);
    layoutCarousel(rootEl);
  }

  function renderDay(rootEl, offset, events) {
    const box = rootEl.querySelector(
      `.schedule-list[data-day-offset="${offset}"]`,
    );
    if (!box) return;
    box.removeAttribute("aria-busy");
    const column = box.closest(".day-column");

    const { sorted, liveIdx, nextIdx } = core.computeDaySchedule(
      offset,
      events,
    );

    if (!sorted.length) {
      if (column) column.style.display = "";
      box.innerHTML = `<div class="empty-state">${core.getEmptyStateMessage(offset)}</div>`;
      return;
    }

    if (column) column.style.display = "";

    box.innerHTML = sorted
      .map((e, idx) => {
        const startStr = e.start.dateTime || e.start.date;
        const startDt = new Date(startStr);
        const time = core.formatEventTime(startDt);

        const title = e.summary || "";
        const {
          omsiar,
          spovednici,
          hasServiceDetails,
          hasCelebrant,
          priestPhotoUrl,
          priestSourceName,
          nameExtra,
          specialServiceLabel,
        } = core.parseEventTitle(title);

        const massLabel = core.combineMassLabel(
          core.getMassLabel(startDt),
          hasServiceDetails ? nameExtra : "",
          title,
        );

        const statusClass =
          idx === liveIdx
            ? " priest-card--live"
            : idx === nextIdx
              ? " priest-card--next"
              : "";
        const statusBadge =
          idx === liveIdx
            ? '<span class="status-badge status-badge--live">Prebieha</span>'
            : idx === nextIdx
              ? '<span class="status-badge status-badge--next">Najbližšie</span>'
              : "";

        const confessionMarkup = spovednici.length
          ? `
                <div class="confession-box">
                  <div class="confession-title">Spovedanie</div>
                  <div class="confession-names">
                    ${spovednici
                      .map((s) => {
                        const confessorName = String(s || "").replace(
                          /^don\s+/i,
                          "",
                        );
                        const confessorPhotoUrl =
                          core.resolvePhotoUrl(confessorName);
                        const confessorDisplayName = s || "Spovedník";
                        return `
                          <span class="confession-person">
                            ${confessorPhotoUrl ? `<span class="photo-zoom photo-zoom--small"><span class="avatar avatar--small"><img class="avatar-img" src="${core.photoVariantUrl(confessorPhotoUrl, core.PHOTO_THUMB_SIZE)}" data-full="${confessorPhotoUrl}" alt="${confessorName || "Spovedník"}" decoding="async" /></span><span class="photo-zoom__panel" aria-hidden="true"><img class="photo-zoom__image" data-src="${core.photoVariantUrl(confessorPhotoUrl, core.PHOTO_ZOOM_SIZE)}" data-full="${confessorPhotoUrl}" alt="${confessorName || "Spovedník"}" /><span class="photo-zoom__name">${confessorDisplayName}</span></span></span>` : ""}
                            <span class="confession-person-name">${s}</span>
                          </span>
                        `;
                      })
                      .join("")}
                  </div>
                </div>
              `
          : "";

        return `
                    <div class="priest-card${statusClass}">
                        ${statusBadge}
                        <div class="time-block">
                            ${hasServiceDetails || specialServiceLabel ? `<div class="service-info">
                              <span class="service-tag">${specialServiceLabel || "Svätá omša"}</span>
                              ${hasCelebrant ? `<div class="service-main">
                                ${priestPhotoUrl ? `<span class="photo-zoom"><span class="avatar"><img class="avatar-img" src="${core.photoVariantUrl(priestPhotoUrl, core.PHOTO_THUMB_SIZE)}" data-full="${priestPhotoUrl}" alt="${priestSourceName || "Kňaz"}" decoding="async" /></span><span class="photo-zoom__panel" aria-hidden="true"><img class="photo-zoom__image" data-src="${core.photoVariantUrl(priestPhotoUrl, core.PHOTO_ZOOM_SIZE)}" data-full="${priestPhotoUrl}" alt="${priestSourceName || "Kňaz"}" /><span class="photo-zoom__name">${omsiar || (priestSourceName ? core.addDonPrefix(priestSourceName) : "Kňaz")}</span></span></span>` : ""}
                                    <div class="service-copy">
                                  <div class="name">${omsiar}</div>
                                    </div>
                                </div>` : ""}
                            </div>` : ""}
                            <div class="time-col">
                                <span class="time">${time}</span>
                                ${massLabel ? `<span class="mass-label">${massLabel}</span>` : ""}
                            </div>
                        </div>
                        ${confessionMarkup}
                    </div>
                `;
      })
      .join("");

    bindPhotoZoom(rootEl);
  }

  function onBatchRendered(rootEl) {
    layoutCarousel(rootEl);
  }

  window.RozpisOmsiEngine.layouts.horizontal = {
    buildSkeleton,
    renderDay,
    onBatchRendered,
  };
})();
HORIZONTAL_JS;
  }

  function rozpis_omsi_vertical_css()
  {
    return <<<'VERTICAL_CSS'
    .rozpis-omsi.rozpis-omsi--vertical,
    .rozpis-omsi.rozpis-omsi--vertical * {
      box-sizing: border-box;
    }

    .rozpis-omsi.rozpis-omsi--vertical {
      --primary-color: #1d283a;
      --secondary-color: #40647d;
      --accent-gold: #f1af57;
      --accent-strong: #bf8027;
      --card-bg: rgba(255, 255, 255, 0.8);
      --bg-light: #e7f0f4;
      --bg-light-2: #d5e4ed;
      --text-main: #1d283a;
      --text-muted: #5d6c75;
      --shadow: 0 10px 20px -12px rgba(29, 40, 58, 0.16);
      --space-xs: 6px;
      --space-sm: 10px;
      --space-md: 12px;
      --space-lg: 18px;
      --space-xl: 18px;

      font-family: "Fira Sans", sans-serif;
      font-size: 80%;
      background: transparent;
      color: var(--text-main);
      margin: 0;
      padding: 32px 18px 52px;
      min-height: 100vh;
      line-height: 1.3;
      zoom: 1.3;
    }

    .rozpis-omsi.rozpis-omsi--vertical .main-header {
      text-align: center;
      margin-bottom: 14px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .main-header h1 {
      margin: 0;
      display: inline-block;
      position: relative;
      font-size: clamp(1.76rem, 2.4vw, 2.56rem);
      font-weight: 800;
      color: var(--primary-color);
      letter-spacing: -0.04em;
    }

    .rozpis-omsi.rozpis-omsi--vertical .main-header h1::after {
      content: "";
      position: absolute;
      left: 18%;
      right: 18%;
      bottom: -12px;
      height: 4px;
      background: var(--accent-strong);
      border-radius: 999px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .main-subtitle {
      margin: 14px auto 0;
      font-size: 0.624rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--text-muted);
      opacity: 0.9;
    }

    .rozpis-omsi.rozpis-omsi--vertical .board-container {
      max-width: 1120px;
      margin: 0 auto;
      width: 100%;
    }

    .rozpis-omsi.rozpis-omsi--vertical .rozpis-days-wrapper {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-column {
      background: rgba(255, 255, 255, 0.65);
      border: 1px solid rgba(148, 169, 182, 0.35);
      border-radius: 18px;
      padding: 16px 18px 10px;
      box-shadow: var(--shadow);
      transition: all 0.2s ease;
      position: relative;
      overflow: hidden;
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-column.today {
      border-color: rgba(191, 128, 39, 0.7);
      background: rgba(255, 255, 255, 0.82);
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px;
      padding-bottom: 8px;
      border-bottom: 1px solid rgba(113, 141, 156, 0.28);
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-name {
      font-size: clamp(1.08rem, 1.28vw, 1.52rem);
      font-weight: 800;
      letter-spacing: -0.03em;
      color: var(--primary-color);
      text-transform: capitalize;
      line-height: 1.1;
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-column.today .day-name {
      color: var(--accent-strong);
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-date {
      color: var(--text-muted);
      font-size: 0.776rem;
      font-weight: 600;
      margin-left: auto;
      opacity: 0.9;
    }

    .rozpis-omsi.rozpis-omsi--vertical .schedule-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .priest-card {
      background: rgba(234, 243, 247, 0.9);
      border: 1px solid rgba(160, 182, 194, 0.4);
      border-radius: 14px;
      padding: 12px 14px;
      position: relative;
      display: grid;
      grid-template-columns: minmax(85px, 140px) minmax(0, 1fr) minmax(
          220px,
          420px
        );
      gap: 10px 12px;
      align-items: center;
      transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
    }

    .rozpis-omsi.rozpis-omsi--vertical .priest-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 24px -18px rgba(29, 40, 58, 0.32);
    }

    .rozpis-omsi.rozpis-omsi--vertical .day-column.today .priest-card {
      background: rgba(219, 232, 239, 0.9);
    }

    .rozpis-omsi.rozpis-omsi--vertical .priest-card--live {
      border-color: rgba(191, 128, 39, 0.7);
      box-shadow: inset 0 0 0 1px rgba(191, 128, 39, 0.22);
    }

    .rozpis-omsi.rozpis-omsi--vertical .priest-card--next {
      border-color: rgba(241, 175, 87, 0.7);
    }

    .rozpis-omsi.rozpis-omsi--vertical .status-badge {
      position: absolute;
      top: -9px;
      right: 14px;
      font-size: 0.496rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 3px 9px;
      border-radius: 999px;
      line-height: 1.2;
      box-shadow: var(--shadow);
    }

    .rozpis-omsi.rozpis-omsi--vertical .status-badge--live {
      background: var(--accent-strong);
      color: #fff;
    }

    .rozpis-omsi.rozpis-omsi--vertical .status-badge--next {
      background: var(--accent-gold);
      color: var(--primary-color);
    }

    .rozpis-omsi.rozpis-omsi--vertical .time-block {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      gap: 6px;
      min-width: 0;
      width: 100%;
      max-width: 140px;
      text-align: left;
      margin-right: auto;
    }

    .rozpis-omsi.rozpis-omsi--vertical .time {
      color: var(--primary-color);
      font-size: clamp(0.96rem, 1.36vw, 1.44rem);
      font-weight: 800;
      line-height: 1;
      font-variant-numeric: tabular-nums;
      min-width: 78px;
      text-align: center;
      width: 100%;
      display: block;
    }

    .rozpis-omsi.rozpis-omsi--vertical .service-tag-group {
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    .rozpis-omsi.rozpis-omsi--vertical .service-tag {
      display: block;
      width: 100%;
      text-align: center;
      font-size: 0.6rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8a97a1;
      background: transparent;
      border: none;
      border-radius: 0;
      padding: 0;
      white-space: nowrap;
    }

    .rozpis-omsi.rozpis-omsi--vertical .service-special {
      display: block;
      width: 100%;
      text-align: center;
      font-size: 0.576rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      color: #8a97a1;
      background: transparent;
      border: none;
      border-radius: 0;
      padding: 0;
      white-space: nowrap;
    }

    .rozpis-omsi.rozpis-omsi--vertical .service-main {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
      padding-right: 6px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
      position: relative;
      overflow: hidden;
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .rozpis-omsi.rozpis-omsi--vertical .avatar img,
    .rozpis-omsi.rozpis-omsi--vertical .avatar .avatar-img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
    }

    .rozpis-omsi.rozpis-omsi--vertical .avatar--small {
      width: 34px;
      height: 34px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .service-copy {
      min-width: 0;
      flex: 1;
    }

    .rozpis-omsi.rozpis-omsi--vertical .name {
      font-size: clamp(0.8rem, 0.96vw, 0.92rem);
      font-weight: 700;
      color: var(--text-main);
      line-height: 1.2;
      letter-spacing: -0.02em;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: normal;
      word-break: break-word;
    }

    .rozpis-omsi.rozpis-omsi--vertical .confession-box {
      padding: 0;
      margin: 0;
      background: transparent;
      border: none;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      min-width: 0;
      width: 100%;
      margin-top: 2px;
      grid-column: 3;
    }

    .rozpis-omsi.rozpis-omsi--vertical .confession-title {
      color: var(--accent-strong);
      font-size: 0.576rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 4px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .confession-names {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px 10px;
      color: var(--text-main);
      font-size: 0.72rem;
      line-height: 1.3;
      min-width: 0;
      width: 100%;
    }

    .rozpis-omsi.rozpis-omsi--vertical .confession-person {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      min-width: 0;
      background: rgba(255, 255, 255, 0.26);
      border-radius: 999px;
      padding: 3px 6px 3px 3px;
      border: 1px solid rgba(122, 142, 158, 0.18);
      white-space: nowrap;
    }

    .rozpis-omsi.rozpis-omsi--vertical .confession-person-name {
      white-space: normal;
      font-size: clamp(0.8rem, 0.96vw, 0.92rem);
      font-weight: 600;
      color: var(--text-main);
    }

    @media (max-width: 560px) {
      .rozpis-omsi.rozpis-omsi--vertical .confession-box {
        margin-top: 0;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-title {
        margin-bottom: 2px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-names {
        gap: 6px;
        align-items: stretch;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-person {
        display: flex;
        align-items: center;
        gap: 12px;
        background: transparent;
        border: none;
        border-radius: 0;
        padding: 0;
        white-space: normal;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-person-name {
        font-size: 0.768rem;
        font-weight: 700;
        line-height: 1.2;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-person .avatar--small {
        width: 38px;
        height: 38px;
      }
    }

    .rozpis-omsi.rozpis-omsi--vertical .empty-state {
      text-align: center;
      color: var(--text-muted);
      font-style: italic;
      font-size: 0.8rem;
      padding: 18px 20px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .skeleton-card {
      box-sizing: border-box;
      min-height: 64px;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      align-items: center;
      gap: 10px 12px;
      padding: 12px 14px;
      background: rgba(234, 243, 247, 0.9);
      border: 1px solid rgba(160, 182, 194, 0.4);
      border-radius: 14px;
    }

    .rozpis-omsi.rozpis-omsi--vertical .skeleton-line {
      display: block;
      height: 10px;
      border-radius: 999px;
      background: linear-gradient(
        90deg,
        rgba(160, 182, 194, 0.2) 25%,
        rgba(160, 182, 194, 0.42) 50%,
        rgba(160, 182, 194, 0.2) 75%
      );
      background-size: 200% 100%;
      animation: rozpis-skeleton-shimmer 1.4s ease-in-out infinite;
    }

    .rozpis-omsi.rozpis-omsi--vertical .skeleton-line--time {
      width: 45%;
    }

    .rozpis-omsi.rozpis-omsi--vertical .skeleton-line--name {
      width: 75%;
    }

    .rozpis-omsi.rozpis-omsi--vertical .skeleton-line--detail {
      width: 60%;
    }

    @keyframes rozpis-skeleton-shimmer {
      0% {
        background-position: 100% 0;
      }

      100% {
        background-position: -100% 0;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .rozpis-omsi.rozpis-omsi--vertical .skeleton-line {
        animation: none;
      }
    }

    @media (max-width: 980px) {
      .rozpis-omsi.rozpis-omsi--vertical .priest-card {
        grid-template-columns: minmax(80px, 120px) minmax(0, 1.1fr) minmax(
            180px,
            320px
          );
        gap: 10px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .time-block {
        max-width: 120px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-main {
        min-width: 0;
      }
    }

    @media (max-width: 840px) {
      .rozpis-omsi.rozpis-omsi--vertical .priest-card {
        grid-template-columns: minmax(0, 1fr) max-content;
        gap: 5px 5px;
        align-items: start;
      }

      /* flatten time-block/service-tag-group so their children become direct priest-card grid items */
      .rozpis-omsi.rozpis-omsi--vertical .time-block {
        display: contents;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-tag-group {
        display: contents;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-tag {
        grid-column: 1;
        grid-row: 1;
        width: auto;
        text-align: left;
        align-self: center;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-main {
        grid-column: 1;
        grid-row: 2;
        min-width: 0;
      }

      .rozpis-omsi.rozpis-omsi--vertical .time {
        grid-column: 2;
        grid-row: 1;
        min-width: 0;
        width: auto;
        text-align: right;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-special {
        grid-column: 2;
        grid-row: 2;
        min-width: 0;
        width: auto;
        text-align: right;
        font-size: 0.52rem;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-box {
        grid-column: 1 / -1;
        grid-row: 3;
        width: 100%;
        min-width: 0;
        margin-top: 0;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-title {
        margin-top: 5px;
      }
    }

    @media (max-width: 640px) {
      .rozpis-omsi.rozpis-omsi--vertical .service-main {
        min-width: 0;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-box {
        min-width: 0;
      }
    }

    @media (max-width: 560px) {
      .rozpis-omsi.rozpis-omsi--vertical {
        padding: 14px 8px 30px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .main-header {
        margin-bottom: 16px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .main-header h1 {
        font-size: clamp(1.24rem, 4.8vw, 1.76rem);
        letter-spacing: -0.03em;
      }

      .rozpis-omsi.rozpis-omsi--vertical .day-column {
        padding: 12px 10px 8px;
        border-radius: 14px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .day-header {
        margin-bottom: 10px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .day-name {
        font-size: 0.92rem;
      }

      .rozpis-omsi.rozpis-omsi--vertical .day-date {
        font-size: 0.64rem;
      }

      .rozpis-omsi.rozpis-omsi--vertical .priest-card {
        padding: 10px 10px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .time {
        font-size: 0.88rem;
      }

      .rozpis-omsi.rozpis-omsi--vertical .service-tag {
        font-size: 0.6rem;
        padding: 0;
        color: var(--accent-strong);
        letter-spacing: 0;
        font-weight: 700;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-title {
        font-size: 0.576rem;
        font-weight: 700;
        letter-spacing: 0;
      }

      .rozpis-omsi.rozpis-omsi--vertical .status-badge {
        font-size: 0.44rem;
        padding: 2px 7px;
        top: -7px;
        right: 10px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .name {
        font-size: 0.768rem;
      }

      .rozpis-omsi.rozpis-omsi--vertical .avatar {
        width: 38px;
        height: 38px;
      }

      .rozpis-omsi.rozpis-omsi--vertical .confession-names {
        gap: 6px;
      }
    }
VERTICAL_CSS;
  }

  function rozpis_omsi_nastenka_css()
  {
    $css = str_replace(
      'rozpis-omsi--vertical',
      'rozpis-omsi--nastenka',
      rozpis_omsi_vertical_css()
    );

    return $css . <<<'NASTENKA_CSS'
    .rozpis-omsi.rozpis-omsi--nastenka {
      --primary-color: #1d283a;
      --secondary-color: #40647d;
      --accent-gold: #f1af57;
      --accent-strong: #bf8027;
      --card-bg: rgba(255, 255, 255, 0.8);
      --bg-light: #e7f0f4;
      --bg-light-2: #d5e4ed;
      --text-main: #1d283a;
      --text-muted: #5d6c75;
      --shadow: 0 10px 20px -12px rgba(29, 40, 58, 0.16);

      font-family: "Fira Sans", sans-serif;
      font-size: 19px;
      padding: 6px 14px 18px;
      min-height: 0;
      zoom: 1;
      line-height: 1.3;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .main-header {
      margin-bottom: 12px;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .main-header h1 {
      font-size: 36px;
      letter-spacing: normal;
      color: var(--primary-color);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .main-subtitle {
      margin-top: 12px;
      font-size: 13px;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .board-container {
      max-width: none;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .rozpis-days-wrapper {
      gap: 12px;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column {
      padding: 14px 18px 12px;
      border: 1px solid rgba(148, 169, 182, 0.35);
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.65);
      box-shadow: var(--shadow);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column.today {
      border: 2px solid rgba(191, 128, 39, 0.7);
      border-left-width: 6px;
      background: rgba(255, 255, 255, 0.82);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-header {
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom-color: rgba(113, 141, 156, 0.28);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .day-header {
      margin: -14px -18px 12px;
      padding: 12px 18px;
      border-bottom: 0;
      border-radius: 7px 7px 0 0;
      background: transparent;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-name {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: normal;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .day-name {
      color: var(--accent-strong);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-date {
      font-size: 17px;
      font-weight: 500;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .day-date {
      color: var(--text-muted);
      opacity: 1;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .schedule-list {
      gap: 10px;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .priest-card {
      grid-template-columns: minmax(125px, 170px) minmax(0, 0.4fr) minmax(
          240px,
          1fr
        );
      gap: 10px 14px;
      padding: 12px 16px;
      border-color: rgba(160, 182, 194, 0.4);
      border-radius: 8px;
      background: rgba(234, 243, 247, 0.9);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .priest-card {
      background: rgba(219, 232, 239, 0.9);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .time {
      font-size: 26px;
      font-weight: 700;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .name,
    .rozpis-omsi.rozpis-omsi--nastenka .confession-person-name {
      font-size: 20px;
      font-weight: 600;
      letter-spacing: normal;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .service-tag,
    .rozpis-omsi.rozpis-omsi--nastenka .service-special,
    .rozpis-omsi.rozpis-omsi--nastenka .confession-title {
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .service-tag,
    .rozpis-omsi.rozpis-omsi--nastenka .service-special {
      color: #8a97a1;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .avatar,
    .rozpis-omsi.rozpis-omsi--nastenka .avatar img,
    .rozpis-omsi.rozpis-omsi--nastenka .avatar .avatar-img {
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .confession-person {
      background: rgba(255, 255, 255, 0.26);
      border-color: rgba(122, 142, 158, 0.18);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .status-badge {
      font-size: 14px;
      padding: 4px 12px;
      letter-spacing: 0;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .status-badge--live {
      background: var(--accent-strong);
      color: #fff;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .status-badge--next {
      background: #f1af57;
      color: #1d283a;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .empty-state {
      font-size: 18px;
      padding: 16px;
    }

    .rozpis-omsi.rozpis-omsi--nastenka .skeleton-card {
      min-height: 76px;
      background: rgba(234, 243, 247, 0.9);
      border-color: rgba(160, 182, 194, 0.4);
    }

    .rozpis-omsi.rozpis-omsi--nastenka .skeleton-line {
      background: linear-gradient(
        90deg,
        rgba(160, 182, 194, 0.2) 25%,
        rgba(160, 182, 194, 0.42) 50%,
        rgba(160, 182, 194, 0.2) 75%
      );
    }

    @media (max-width: 980px) {
      .rozpis-omsi.rozpis-omsi--nastenka {
        font-size: 16px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .rozpis-days-wrapper {
        gap: 10px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-column {
        padding: 12px 14px 10px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-header {
        margin-bottom: 10px;
        padding-bottom: 7px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .day-header {
        margin: -12px -14px 10px;
        padding: 10px 14px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-name {
        font-size: 22px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-date {
        font-size: 14px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .schedule-list {
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .priest-card {
        grid-template-columns: minmax(100px, 140px) minmax(0, 0.4fr) minmax(
            180px,
            1fr
          );
        gap: 8px 10px;
        padding: 10px 12px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .time {
        font-size: 20px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .name,
      .rozpis-omsi.rozpis-omsi--nastenka .confession-person-name {
        font-size: 17px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .service-tag,
      .rozpis-omsi.rozpis-omsi--nastenka .service-special,
      .rozpis-omsi.rozpis-omsi--nastenka .confession-title {
        font-size: 12px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .status-badge {
        font-size: 11px;
        padding: 3px 9px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .empty-state {
        font-size: 15px;
        padding: 14px;
      }
    }

    @media (max-width: 840px) {
      .rozpis-omsi.rozpis-omsi--nastenka .priest-card {
        grid-template-columns: minmax(0, 1fr) max-content;
        gap: 5px;
        align-items: start;
      }
    }

    @media (max-width: 560px) {
      .rozpis-omsi.rozpis-omsi--nastenka {
        font-size: 14px;
        padding: 12px 8px 20px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .rozpis-days-wrapper {
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-column {
        padding: 10px 8px 8px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-column.today .day-header {
        margin: -10px -8px 8px;
        padding: 8px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-name {
        font-size: 18px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .day-date {
        font-size: 12px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .schedule-list {
        gap: 6px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .priest-card {
        gap: 5px;
        padding: 8px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .time {
        font-size: 17px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .name,
      .rozpis-omsi.rozpis-omsi--nastenka .confession-person-name {
        font-size: 15px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .service-tag,
      .rozpis-omsi.rozpis-omsi--nastenka .service-special,
      .rozpis-omsi.rozpis-omsi--nastenka .confession-title {
        font-size: 11px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .status-badge {
        font-size: 10px;
        padding: 2px 7px;
      }

      .rozpis-omsi.rozpis-omsi--nastenka .empty-state {
        font-size: 14px;
        padding: 12px;
      }
    }
NASTENKA_CSS;
  }

  function rozpis_omsi_horizontal_css()
  {
    return <<<'HORIZONTAL_CSS'
    .rozpis-omsi.rozpis-omsi--horizontal,
    .rozpis-omsi.rozpis-omsi--horizontal * {
      box-sizing: border-box;
    }

    .rozpis-omsi.rozpis-omsi--horizontal {
      --primary-color: #1d283a;
      --secondary-color: #40647d;
      --accent-gold: #f1af57;
      --accent-strong: #bf8027;
      --card-bg: rgba(255, 255, 255, 0.8);
      --bg-light: #e7f0f4;
      --bg-light-2: #d5e4ed;
      --text-main: #1d283a;
      --text-muted: #5d6c75;
      --shadow: 0 10px 20px -12px rgba(29, 40, 58, 0.16);
      --space-xs: 10px;
      --space-sm: 15px;
      --space-md: 20px;
      --space-lg: 30px;
      --space-xl: 30px;

      font-family: "Fira Sans", sans-serif;
      background: transparent;
      color: var(--text-main);
      margin: 0;
      padding: 0;
      line-height: 1.4;
      zoom: 1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .main-header {
      text-align: center;
      margin-bottom: 14px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .main-header h1 {
      margin: 0;
      display: inline-block;
      position: relative;
      font-size: clamp(2.2rem, 3vw, 3.2rem);
      font-weight: 800;
      color: var(--primary-color);
      letter-spacing: -0.04em;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .main-header h1::after {
      content: "";
      position: absolute;
      left: 18%;
      right: 18%;
      bottom: -12px;
      height: 4px;
      background: var(--accent-strong);
      border-radius: 999px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .main-subtitle {
      margin: 14px auto 0;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--text-muted);
      opacity: 0.9;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .board-container {
      max-width: none;
      margin: 0;
      width: 100%;
      padding: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-shell {
      position: relative;
      padding: 0 44px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-shell.no-arrows {
      padding: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-days-wrapper {
      display: flex;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      gap: 18px;
      scrollbar-width: none;
      -ms-overflow-style: none;
      -webkit-overflow-scrolling: touch;
      cursor: grab;
      padding-bottom: 6px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-days-wrapper::-webkit-scrollbar {
      display: none;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-days-wrapper.is-dragging {
      cursor: grabbing;
      scroll-snap-type: none;
      user-select: none;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-arrow {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: 1px solid rgba(148, 169, 182, 0.45);
      background: rgba(255, 255, 255, 0.82);
      color: var(--primary-color);
      font-size: 18px;
      line-height: 1;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 2;
      box-shadow: var(--shadow);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-arrow--prev {
      left: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-arrow--next {
      right: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .carousel-arrow.is-hidden {
      display: none;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-dots {
      display: flex;
      justify-content: center;
      gap: 8px;
      margin-top: 12px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-dots.is-hidden {
      display: none;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-dots .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--primary-color);
      opacity: 0.3;
      border: none;
      padding: 0;
      cursor: pointer;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .rozpis-dots .dot.active {
      opacity: 1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-column {
      background: rgba(255, 255, 255, 0.65);
      border: 1px solid rgba(148, 169, 182, 0.35);
      border-radius: 8px;
      padding: 8px;
      box-shadow: var(--shadow);
      transition: all 0.2s ease;
      position: relative;
      overflow: hidden;
      flex: 0 0 auto;
      scroll-snap-align: start;
      width: min(320px, 100%);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-column.today {
      border-color: rgba(191, 128, 39, 0.7);
      background: rgba(255, 255, 255, 0.82);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
      padding-bottom: 4px;
      border-bottom: 1px solid rgba(113, 141, 156, 0.28);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-name {
      font-size: clamp(1rem, 1.5vw, 1.35rem);
      font-weight: 700;
      letter-spacing: -0.02em;
      color: var(--primary-color);
      text-transform: capitalize;
      line-height: 1.1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-column.today .day-name {
      color: var(--accent-strong);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-date {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 600;
      margin-left: auto;
      opacity: 0.9;
      line-height: 1.1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .schedule-list {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .priest-card {
      background: rgba(234, 243, 247, 0.9);
      border: 1px solid rgba(160, 182, 194, 0.4);
      border-radius: 8px;
      padding: 6px;
      position: relative;
      display: flex;
      flex-direction: column;
      gap: 7px;
      align-items: stretch;
      z-index: 1;
      transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .priest-card:hover {
      z-index: 4;
      transform: translateY(-1px);
      box-shadow: 0 10px 24px -18px rgba(29, 40, 58, 0.32);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .day-column.today .priest-card {
      background: rgba(219, 232, 239, 0.9);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .priest-card--live {
      border-color: rgba(191, 128, 39, 0.7);
      box-shadow: inset 0 0 0 1px rgba(191, 128, 39, 0.22);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .priest-card--next {
      border-color: rgba(241, 175, 87, 0.7);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .status-badge {
      position: absolute;
      top: -9px;
      right: 14px;
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 3px 9px;
      border-radius: 999px;
      line-height: 1.2;
      box-shadow: var(--shadow);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .status-badge--live {
      background: var(--accent-strong);
      color: #fff;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .status-badge--next {
      background: var(--accent-gold);
      color: var(--primary-color);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .time-block {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      min-width: 0;
      width: 100%;
      max-width: none;
      text-align: left;
      margin-right: 0;
      line-height: 1.2;
      padding-bottom: 2px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .service-info {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      min-width: 0;
      flex: 1 1 auto;
      gap: 4px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .time {
      color: var(--primary-color);
      font-size: clamp(1rem, 1.5vw, 1.35rem);
      font-weight: 800;
      line-height: 1.1;
      font-variant-numeric: tabular-nums;
      min-width: auto;
      text-align: right;
      width: auto;
      display: block;
      flex-shrink: 0;
      padding-top: 2px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .time-col {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      align-self: flex-start;
      margin-left: auto;
      flex-shrink: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .mass-label {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 500;
      opacity: 0.9;
      line-height: 1.1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .service-tag,
    .rozpis-omsi.rozpis-omsi--horizontal .confession-title {
      display: block;
      width: auto;
      text-align: left;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--accent-strong);
      background: transparent;
      border: none;
      border-radius: 0;
      padding: 0;
      white-space: nowrap;
      line-height: 1.2;
      margin-bottom: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .service-main {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
      padding-right: 0;
      width: 100%;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .service-header {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      min-width: 0;
      flex: 1;
      gap: 2px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
      border: none;
      position: relative;
      overflow: hidden;
      flex-shrink: 0;
      box-shadow: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .avatar img,
    .rozpis-omsi.rozpis-omsi--horizontal .avatar .avatar-img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
    }

    .rozpis-omsi.rozpis-omsi--horizontal .photo-zoom {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      cursor: zoom-in;
      z-index: 2;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .photo-zoom__panel {
      display: none;
    }

    .rozpis-zoom-floating {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 99999;
      box-sizing: border-box;
      width: 170px;
      padding: 8px 8px 10px;
      border-radius: 18px;
      pointer-events: none;
      opacity: 0;
      background: rgba(255, 255, 255, 0.96);
      border: 1px solid rgba(148, 169, 182, 0.35);
      box-shadow: 0 22px 40px -28px rgba(29, 40, 58, 0.38);
      backdrop-filter: blur(8px);
      transform: scale(0.96);
      transition:
        opacity 0.18s ease,
        transform 0.18s ease;
      font-family: "Fira Sans", sans-serif;
    }

    .rozpis-zoom-floating.is-visible {
      opacity: 1;
      transform: scale(1);
    }

    .rozpis-zoom-floating .photo-zoom__image {
      width: 100%;
      height: 150px;
      display: block;
      object-fit: cover;
      border-radius: 12px;
      background: linear-gradient(145deg, #edf4f7 0%, #c6d6df 100%);
    }

    .rozpis-zoom-floating .photo-zoom__name {
      display: block;
      margin-top: 8px;
      text-align: center;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      color: #1d283a;
      text-transform: capitalize;
      line-height: 1.2;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .avatar--small {
      width: 28px;
      height: 28px;
      border-width: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .service-copy {
      min-width: 0;
      flex: 1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .name {
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--text-main);
      line-height: 1.2;
      letter-spacing: -0.02em;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: normal;
      word-break: break-word;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
      padding: 0;
      margin: 0;
      background: transparent;
      border: none;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      min-width: 0;
      width: 100%;
      margin-top: 0;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-title {
      margin-bottom: 4px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-names {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 2px 8px;
      color: var(--text-main);
      font-size: 0.82rem;
      line-height: 1.15;
      min-width: 0;
      width: 100%;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-person {
      position: relative;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      min-width: 0;
      background: rgba(255, 255, 255, 0.26);
      border-radius: 999px;
      padding: 2px 6px 2px 3px;
      border: 1px solid rgba(122, 142, 158, 0.18);
      white-space: nowrap;
      z-index: 1;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-person:hover {
      z-index: 8;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .confession-person-name {
      white-space: normal;
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--text-main);
    }

    @media (max-width: 560px) {
      .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
        margin-top: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-title {
        margin-bottom: 2px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-names {
        gap: 2px;
        align-items: stretch;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-person {
        display: flex;
        align-items: center;
        gap: 6px;
        background: transparent;
        border: none;
        border-radius: 0;
        padding: 0;
        white-space: normal;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-person-name {
        font-size: 0.96rem;
        font-weight: 700;
        line-height: 1.2;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-person .avatar--small {
        width: 38px;
        height: 38px;
        border-width: 1px;
      }
    }

    .rozpis-omsi.rozpis-omsi--horizontal .empty-state {
      text-align: center;
      color: var(--text-muted);
      font-style: italic;
      font-size: 1rem;
      padding: 18px 20px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .skeleton-card {
      box-sizing: border-box;
      min-height: 76px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 10px;
      padding: 8px;
      background: rgba(234, 243, 247, 0.9);
      border: 1px solid rgba(160, 182, 194, 0.4);
      border-radius: 8px;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .skeleton-line {
      display: block;
      height: 8px;
      border-radius: 999px;
      background: linear-gradient(
        90deg,
        rgba(160, 182, 194, 0.2) 25%,
        rgba(160, 182, 194, 0.42) 50%,
        rgba(160, 182, 194, 0.2) 75%
      );
      background-size: 200% 100%;
      animation: rozpis-skeleton-shimmer 1.4s ease-in-out infinite;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .skeleton-line--time {
      width: 38%;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .skeleton-line--name {
      width: 72%;
    }

    .rozpis-omsi.rozpis-omsi--horizontal .skeleton-line--detail {
      width: 54%;
    }

    @keyframes rozpis-skeleton-shimmer {
      0% {
        background-position: 100% 0;
      }

      100% {
        background-position: -100% 0;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .rozpis-omsi.rozpis-omsi--horizontal .skeleton-line {
        animation: none;
      }
    }

    @media (max-width: 980px) {
      .rozpis-omsi.rozpis-omsi--horizontal .priest-card {
        gap: 7px;
      }
    }

    @media (max-width: 768px) {
      .rozpis-omsi.rozpis-omsi--horizontal {
        padding: 14px 8px 30px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .carousel-shell {
        padding: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .carousel-arrow {
        display: none;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .rozpis-days-wrapper {
        gap: 12px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .day-column {
        border-radius: 14px;
      }
    }

    @media (max-width: 840px) {
      .rozpis-omsi.rozpis-omsi--horizontal .priest-card {
        gap: 7px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .time-block {
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-main {
        min-width: 0;
        padding-left: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
        width: 100%;
        min-width: 0;
        margin-top: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-main .service-copy,
      .rozpis-omsi.rozpis-omsi--horizontal .confession-box .confession-names {
        padding-left: 0;
      }
    }

    @media (max-width: 640px) {
      .rozpis-omsi.rozpis-omsi--horizontal .priest-card {
        gap: 6px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-main {
        min-width: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
        min-width: 0;
      }
    }

    @media (max-width: 560px) {
      .rozpis-omsi.rozpis-omsi--horizontal {
        padding: 8px 6px 18px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .main-header {
        margin-bottom: 10px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-main,
      .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
        padding-left: 0;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-main {
        margin-left: 0;
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-person {
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .main-header h1 {
        font-size: clamp(1.55rem, 6vw, 2.2rem);
        letter-spacing: -0.03em;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .day-column {
        padding: 8px 8px 6px;
        border-radius: 12px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .day-header {
        margin-bottom: 8px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .day-name {
        font-size: 1.15rem;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .day-date {
        font-size: 0.8rem;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .priest-card {
        padding: 8px 8px;
        grid-template-columns: 1fr;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .time-block,
      .rozpis-omsi.rozpis-omsi--horizontal .service-main,
      .rozpis-omsi.rozpis-omsi--horizontal .confession-box {
        grid-column: 1;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .time-block {
        gap: 8px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .time {
        font-size: 1.1rem;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .service-tag,
      .rozpis-omsi.rozpis-omsi--horizontal .confession-title {
        font-size: 0.7rem;
        letter-spacing: 0.03em;
        padding: 0;
        color: var(--accent-strong);
        letter-spacing: 0;
        font-weight: 700;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .status-badge {
        font-size: 0.55rem;
        padding: 2px 7px;
        top: -7px;
        right: 10px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .name {
        font-size: 0.96rem;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .avatar {
        width: 38px;
        height: 38px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .avatar--small {
        width: 38px;
        height: 38px;
      }

      .rozpis-omsi.rozpis-omsi--horizontal .confession-names {
        gap: 2px;
      }
    }
HORIZONTAL_CSS;
  }

  function rozpis_omsi_shortcode($atts)
  {
    static $printed_engine = false;
    static $printed_css = array('vertical' => false, 'horizontal' => false, 'nastenka' => false);
    static $printed_js = array('vertical' => false, 'horizontal' => false);

    $atts = shortcode_atts(array('typ' => 'horizontal'), (array) $atts, 'rozpis');
    $type = strtolower(trim((string) $atts['typ']));
    if (!in_array($type, array('horizontal', 'vertical', 'nastenka'), true)) {
      $type = 'horizontal';
    }
    $layout_type = $type === 'nastenka' ? 'vertical' : $type;

    $instance_id = function_exists('wp_unique_id')
      ? wp_unique_id('rozpis-omsi-')
      : uniqid('rozpis-omsi-', false);

    ob_start();

    if (!$printed_engine) {
      $printed_engine = true;
      echo '<script>window.RozpisOmsiConfig = ' . wp_json_encode(array_merge(rozpis_omsi_calendar_config(), array(
        'eventsUrl' => rest_url('rozpis-omsi/v1/events'),
        'timeZone' => wp_timezone()->getName(),
      ))) . ';</script>';
      echo '<script>' . rozpis_omsi_engine_js() . '</script>';
    }

    if (!$printed_css[$type]) {
      $printed_css[$type] = true;
      if ($type === 'nastenka') {
        $css = rozpis_omsi_nastenka_css();
      } else {
        $css = $type === 'vertical' ? rozpis_omsi_vertical_css() : rozpis_omsi_horizontal_css();
      }
      echo '<style>' . $css . '</style>';
    }

    if (!$printed_js[$layout_type]) {
      $printed_js[$layout_type] = true;
      echo '<script>' . ($layout_type === 'vertical' ? rozpis_omsi_vertical_js() : rozpis_omsi_horizontal_js()) . '</script>';
    }

    if ($layout_type === 'vertical') {
    ?>
      <div id="<?php echo esc_attr($instance_id); ?>" class="rozpis-omsi rozpis-omsi--<?php echo esc_attr($type); ?>">
        <?php if ($type !== 'nastenka') : ?>
          <div class="main-header">
            <h1>Rozpis svätých omší</h1>
            <div class="main-subtitle">Mamateyka</div>
          </div>
        <?php endif; ?>
        <div class="board-container">
          <div class="rozpis-days-wrapper"></div>
        </div>
      </div>
    <?php
    } else {
    ?>
      <div id="<?php echo esc_attr($instance_id); ?>" class="rozpis-omsi rozpis-omsi--horizontal">
        <div class="board-container">
          <div class="carousel-shell">
            <button class="carousel-arrow carousel-arrow--prev is-hidden" type="button" aria-label="Predchádzajúce dni">‹</button>
            <div class="rozpis-days-wrapper"></div>
            <button class="carousel-arrow carousel-arrow--next is-hidden" type="button" aria-label="Ďalšie dni">›</button>
          </div>
          <div class="rozpis-dots is-hidden"></div>
        </div>
      </div>
    <?php
    }
    ?>
    <script>
      (function() {
        var rootEl = document.getElementById(<?php echo wp_json_encode($instance_id); ?>);
        if (rootEl && window.RozpisOmsiEngine) {
          window.RozpisOmsiEngine.registerInstance(rootEl, <?php echo wp_json_encode($layout_type); ?>);
        }
      })();
    </script>
<?php

    return ob_get_clean();
  }

  add_shortcode('rozpis', 'rozpis_omsi_shortcode');
}
