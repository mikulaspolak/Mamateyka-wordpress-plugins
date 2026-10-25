<?php
add_action('rest_api_init', function () {
    register_rest_route('mamateyka/v1', '/support', array(
        'methods' => 'POST',
        'callback' => 'mamateyka_handle_support_form',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('mamateyka/v1', '/track', array(
        'methods' => 'POST',
        'callback' => 'mamateyka_handle_track_event',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('mamateyka/v1', '/stats', array(
        'methods' => 'GET',
        'callback' => 'mamateyka_handle_stats_view',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('mamateyka/v1', '/stats/reset', array(
        'methods' => 'POST',
        'callback' => 'mamateyka_handle_stats_reset',
        'permission_callback' => '__return_true',
    ));
});

// REST server by inak zabalil HTML vystup /stats do JSON retazca - tu ho vraciame surovo
add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
    if ($request->get_route() === '/mamateyka/v1/stats') {
        header('Content-Type: text/html; charset=UTF-8');
        echo $result->get_data();
        return true;
    }
    return $served;
}, 10, 4);

// nazov obsahuje nahodny retazec, aby ho nebolo mozne uhadnut - je to jedina ochrana verejne citatelneho logu
function mamateyka_stats_log_path()
{
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'mamateyka-stats-7f2c9e1a.ndjson';
}

function mamateyka_append_log_entry(array $entry)
{
    $entry['ts'] = current_time('mysql', true);
    $path = mamateyka_stats_log_path();
    $dir = dirname($path);
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    file_put_contents($path, wp_json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

function mamateyka_handle_track_event(WP_REST_Request $request)
{
    $data = $request->get_json_params();

    if (!is_array($data)) {
        $data = array();
    }

    $session_id = isset($data['session_id']) ? sanitize_text_field(wp_unslash($data['session_id'])) : '';
    $event = isset($data['event']) ? sanitize_text_field(wp_unslash($data['event'])) : '';

    $allowed_events = array('start', 'solved', 'finish', 'support', 'reset');

    if (empty($session_id) || strlen($session_id) > 64 || !preg_match('/^[a-zA-Z0-9-]+$/', $session_id)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Neplatné session_id.'), 400);
    }

    if (!in_array($event, $allowed_events, true)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Neplatná udalosť.'), 400);
    }

    $entry = array(
        'event' => $event,
        'session_id' => $session_id,
    );

    if ($event === 'solved') {
        $entry['hash'] = isset($data['hash']) ? sanitize_text_field(wp_unslash($data['hash'])) : '';
        $entry['poradie'] = isset($data['poradie']) ? absint($data['poradie']) : 0;
    }

    if ($event === 'finish') {
        $entry['duration_ms'] = isset($data['duration_ms']) ? absint($data['duration_ms']) : 0;
    }

    if ($event === 'reset') {
        $entry['duration_ms'] = isset($data['duration_ms']) ? absint($data['duration_ms']) : 0;
    }

    mamateyka_append_log_entry($entry);

    return new WP_REST_Response(array('success' => true), 200);
}

function mamateyka_read_log_entries()
{
    $path = mamateyka_stats_log_path();
    if (!file_exists($path)) {
        return array();
    }

    $entries = array();
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }
    return $entries;
}

function mamateyka_handle_stats_reset(WP_REST_Request $request)
{
    $path = mamateyka_stats_log_path();

    if (file_exists($path) && !unlink($path)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Nepodarilo sa vymazať štatistiky.',
        ), 500);
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Štatistiky boli úspešne resetované.',
    ), 200);
}

function mamateyka_format_duration($ms)
{
    $totalSeconds = max(0, (int) round($ms / 1000));
    $minutes = floor($totalSeconds / 60);
    $seconds = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $seconds);
}

function mamateyka_format_local_datetime($mysql_ts)
{
    if (empty($mysql_ts)) {
        return '—';
    }

    $utc = new DateTimeZone('UTC');
    $local = new DateTimeZone('Europe/Bratislava');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql_ts, $utc);

    if ($dt === false) {
        return '—';
    }

    return $dt->setTimezone($local)->format('d.m.Y H:i');
}

function mamateyka_median($numbers)
{
    if (empty($numbers)) {
        return 0;
    }
    sort($numbers);
    $count = count($numbers);
    $mid = (int) floor($count / 2);
    if ($count % 2 === 0) {
        return ($numbers[$mid - 1] + $numbers[$mid]) / 2;
    }
    return $numbers[$mid];
}

function mamateyka_handle_stats_view(WP_REST_Request $request)
{
    $entries = mamateyka_read_log_entries();

    // poradie 6 (posledna hadanka) nema samostatny 'solved' event, iba 'finish'
    $riddle_count = 6;
    $sessions = array();

    foreach ($entries as $entry) {
        $session_id = isset($entry['session_id']) ? $entry['session_id'] : '';
        if ($session_id === '') {
            continue;
        }
        if (!isset($sessions[$session_id])) {
            $sessions[$session_id] = array(
                'started' => false,
                'max_poradie' => 0,
                'finished' => false,
                'reset' => false,
                'duration_ms' => 0,
                'support' => false,
                'solved_count' => 0,
                'start_ts' => '',
                'end_ts' => '',
            );
        }
        $event = isset($entry['event']) ? $entry['event'] : '';
        $ts = isset($entry['ts']) ? $entry['ts'] : '';
        if ($event === 'start') {
            $sessions[$session_id]['started'] = true;
            $sessions[$session_id]['start_ts'] = $ts;
        } elseif ($event === 'solved') {
            $poradie = isset($entry['poradie']) ? (int) $entry['poradie'] : 0;
            $sessions[$session_id]['solved_count']++;
            if ($poradie > $sessions[$session_id]['max_poradie']) {
                $sessions[$session_id]['max_poradie'] = $poradie;
            }
        } elseif ($event === 'finish') {
            $sessions[$session_id]['finished'] = true;
            $sessions[$session_id]['duration_ms'] = isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : 0;
            $sessions[$session_id]['end_ts'] = $ts;
            if ($sessions[$session_id]['solved_count'] < $riddle_count) {
                $sessions[$session_id]['solved_count'] = $riddle_count;
            }
        } elseif ($event === 'reset') {
            $sessions[$session_id]['reset'] = true;
            $sessions[$session_id]['duration_ms'] = isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : 0;
            $sessions[$session_id]['end_ts'] = $ts;
        } elseif ($event === 'support') {
            $sessions[$session_id]['support'] = true;
        }
    }

    $total_started = 0;
    $total_finished = 0;
    $total_reset = 0;
    $total_support = 0;
    $durations = array();
    // index 0..5 = pocet hraczov, ktori sa zasekli na tejto hadanke (nikdy nedokoncili)
    $drop_off = array_fill(0, $riddle_count, 0);

    foreach ($sessions as $session) {
        if ($session['started']) {
            $total_started++;
        }
        if ($session['finished']) {
            $total_finished++;
            if ($session['duration_ms'] > 0) {
                $durations[] = $session['duration_ms'];
            }
        } elseif ($session['started']) {
            $drop_off[$session['max_poradie']]++;
        }
        if ($session['reset']) {
            $total_reset++;
        }
        if ($session['support']) {
            $total_support++;
        }
    }

    $completion_rate = $total_started > 0 ? round(($total_finished / $total_started) * 100, 1) : 0;
    $avg_duration = !empty($durations) ? array_sum($durations) / count($durations) : 0;
    $median_duration = mamateyka_median($durations);
    $min_duration = !empty($durations) ? min($durations) : 0;
    $max_duration = !empty($durations) ? max($durations) : 0;

    $drop_off_rows = '';
    for ($i = 0; $i < $riddle_count; $i++) {
        $label = $i === 0 ? 'Nevyriešili ani prvú hádanku' : 'Zasekli sa po hádanke ' . $i . '/' . $riddle_count;
        $drop_off_rows .= '<tr><td>' . esc_html($label) . '</td><td>' . (int) $drop_off[$i] . '</td></tr>';
    }

    // riadok pre kazdu misiu, ktora ma zaznamenany aspon zaciatok, zoradene podla casu zaciatku
    $timestamped_sessions = array_filter($sessions, function ($session) {
        return $session['start_ts'] !== '' || $session['end_ts'] !== '';
    });
    uasort($timestamped_sessions, function ($a, $b) {
        return strcmp($a['start_ts'], $b['start_ts']);
    });

    $session_rows = '';
    foreach ($timestamped_sessions as $session) {
        $start_label = $session['start_ts'] !== '' ? mamateyka_format_local_datetime($session['start_ts']) : '—';
        $end_label = $session['end_ts'] !== '' ? mamateyka_format_local_datetime($session['end_ts']) : '—';
        $solved_label = (int) $session['solved_count'];
        $duration_label = ($session['finished'] || $session['reset']) ? mamateyka_format_duration($session['duration_ms']) : '—';
        $status_icon = $session['finished'] ? '🏆' : ($session['reset'] ? '↺' : ($session['started'] ? '▶' : '—'));
        $session_rows .= '<tr><td>' . esc_html($start_label) . '</td><td>' . esc_html($end_label) . '</td><td>' . esc_html($solved_label) . '</td><td>' . esc_html($duration_label) . '</td><td>' . $status_icon . '</td></tr>';
    }
    if ($session_rows === '') {
        $session_rows = '<tr><td colspan="5">Zatiaľ žiadne dáta.</td></tr>';
    }

    $html = '<!doctype html><html lang="sk"><head><meta charset="UTF-8">'
        . '<title>Mamateyka - štatistiky misie</title>'
        . '<style>body{font-family:sans-serif;max-width:960px;margin:32px auto;padding:0 16px;color:#222}'
        . 'table{width:100%;border-collapse:collapse;margin-bottom:28px}'
        . 'th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #ddd;vertical-align:top}'
        . 'h1{font-size:20px}h2{font-size:16px;margin-top:32px}'
        . '.stats-actions{margin:18px 0 20px}'
        . '.reset-btn{background:#b42318;color:#fff;border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600}'
        . '.reset-btn:hover{background:#8f1d13}'
        . '</style></head><body>'
        . '<h1>Misia z budúcnosti &mdash; štatistiky</h1>'
        . '<p><strong>Miesto hrania:</strong> Bratislava, Slovensko</p>'
        . '<div class="stats-actions"><button type="button" class="reset-btn" id="reset-stats-btn">Resetovať štatistiky</button></div>'
        . '<script>'
        . 'document.getElementById("reset-stats-btn").addEventListener("click", function () {'
        . 'var confirmed = window.confirm("Naozaj chcete zmazať všetky štatistiky?");'
        . 'if (!confirmed) return;'
        . 'fetch("/wp-json/mamateyka/v1/stats/reset", {method: "POST", headers:{"Content-Type":"application/json","Accept":"application/json"}})'
        . '.then(function (response) { return response.json().then(function (data) { return {status: response.status, data: data}; }); })'
        . '.then(function (result) {'
        . 'if (result.status >= 400 || !result.data || !result.data.success) {'
        . 'throw new Error(result.data && result.data.message ? result.data.message : "Nepodarilo sa resetovať štatistiky.");'
        . '}'
        . 'window.alert("Štatistiky boli resetované.");'
        . 'window.location.reload();'
        . '})'
        . '.catch(function (error) {'
        . 'window.alert(error && error.message ? error.message : "Nepodarilo sa resetovať štatistiky.");'
        . '});'
        . '});'
        . '</script>'
        . '<h2>Prehľad</h2>'
        . '<table>'
        . '<tr><td>Počet začatých misií</td><td>' . (int) $total_started . '</td></tr>'
        . '<tr><td>Počet dokončených misií</td><td>' . (int) $total_finished . '</td></tr>'
        . '<tr><td>Úspešnosť dokončenia</td><td>' . $completion_rate . ' %</td></tr>'
        . '<tr><td>Počet resetovaných misií</td><td>' . (int) $total_reset . '</td></tr>'
        . '<tr><td>Odoslaných support formulárov</td><td>' . (int) $total_support . '</td></tr>'
        . '</table>'
        . '<h2>Čas dokončenia (len dokončené misie)</h2>'
        . '<table>'
        . '<tr><td>Priemer</td><td>' . esc_html(mamateyka_format_duration($avg_duration)) . '</td></tr>'
        . '<tr><td>Medián</td><td>' . esc_html(mamateyka_format_duration($median_duration)) . '</td></tr>'
        . '<tr><td>Najrýchlejšie</td><td>' . esc_html(mamateyka_format_duration($min_duration)) . '</td></tr>'
        . '<tr><td>Najpomalšie</td><td>' . esc_html(mamateyka_format_duration($max_duration)) . '</td></tr>'
        . '</table>'
        . '<h2>Kde ľudia prestali hrať</h2>'
        . '<table><tr><th>Miesto zaseknutia</th><th>Počet hráčov</th></tr>' . $drop_off_rows . '</table>'
        . '<h2>Zoznam misií (začiatok, koniec, trvanie)</h2>'
        . '<table><tr><th>Začiatok</th><th>Koniec</th><th>Vyriešené hádanky</th><th>Trvanie</th><th>Stav</th></tr>' . $session_rows . '</table>'
        . '</body></html>';

    return new WP_REST_Response($html, 200);
}

function mamateyka_handle_support_form(WP_REST_Request $request)
{
    $data = $request->get_json_params();

    if (!is_array($data)) {
        $data = array();
    }

    $name = isset($data['name']) ? sanitize_text_field(wp_unslash($data['name'])) : '';
    $email = isset($data['email']) ? sanitize_email(wp_unslash($data['email'])) : '';
    $help = isset($data['help']) ? $data['help'] : array();

    if (empty($name) || empty($email)) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Vyplňte meno a email.',
            ),
            400
        );
    }

    if (!is_email($email)) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Zadajte platný email.',
            ),
            400
        );
    }

    if (is_array($help)) {
        $help = array_map('sanitize_text_field', $help);
        $help = array_values(array_filter($help));
    } else {
        $help = array();
    }

    $subject = 'Mamateyka - ponuka pomoci';
    $message = "Meno: {$name}\n";
    $message .= "Email: {$email}\n\n";
    $message .= "Spôsob pomoci:\n";
    $message .= !empty($help) ? implode("\n", $help) : "-";

    $headers = array('Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email);

    $sent = wp_mail('polmiki@gmail.com', $subject, $message, $headers);

    if (!$sent) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Nepodarilo sa odoslať formulár.',
            ),
            500
        );
    }

    // do verejne citatelneho logu ukladame len anonymne session_id, nikdy meno/email
    $session_id = isset($data['session_id']) ? sanitize_text_field(wp_unslash($data['session_id'])) : '';
    if (!empty($session_id) && strlen($session_id) <= 64 && preg_match('/^[a-zA-Z0-9-]+$/', $session_id)) {
        mamateyka_append_log_entry(array('event' => 'support', 'session_id' => $session_id));
    }

    return new WP_REST_Response(
        array(
            'success' => true,
            'message' => 'Vďaka za odoslanie.',
        ),
        200
    );
}
