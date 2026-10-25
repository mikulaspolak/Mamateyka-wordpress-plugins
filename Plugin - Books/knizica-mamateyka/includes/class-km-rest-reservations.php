<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints for public reservation requests and editor reservation management.
 */
class KM_REST_Reservations
{

    const NS = 'knizica-mamateyka/v1';

    public static function register_routes()
    {
        register_rest_route(self::NS, '/reservations', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'create_reservation'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'list_reservations'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));

        register_rest_route(self::NS, '/reservations/(?P<id>\d+)/transition', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'transition'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

    public static function can_manage()
    {
        return current_user_can('km_manage_reservations');
    }

    /**
     * Simple anti-spam: max N reservations per email within a rolling window, plus honeypot field.
     */
    private static function is_rate_limited($email)
    {
        $max     = (int) KM_Settings::get('max_reservations_per_email');
        $max     = $max > 0 ? $max : 10;
        $key     = 'km_res_count_' . md5(strtolower($email));
        $count   = (int) get_transient($key);
        if ($count >= $max) {
            return true;
        }
        set_transient($key, $count + 1, DAY_IN_SECONDS);
        return false;
    }

    private static function is_valid_phone($phone)
    {
        if (! preg_match('/^\+?[0-9\s()\/-]+$/', $phone)) {
            return false;
        }
        $digits = strlen(preg_replace('/\D/', '', $phone));
        return $digits >= 9 && $digits <= 15;
    }

    public static function create_reservation(WP_REST_Request $request)
    {
        global $wpdb;
        $params = $request->get_json_params();

        // Honeypot field must stay empty; bots that fill it are silently rejected.
        if (! empty($params['website'])) {
            return rest_ensure_response(array('success' => true));
        }

        $book_id = absint($params['book_id'] ?? 0);
        $name    = sanitize_text_field($params['name'] ?? '');
        $email   = sanitize_email($params['email'] ?? '');
        $phone   = sanitize_text_field($params['phone'] ?? '');
        $consent = ! empty($params['consent']);

        if (! $book_id) {
            return new WP_Error('km_invalid', __('Vyberte prosím knihu.', 'knizica-mamateyka'), array('status' => 400));
        }

        $errors = array();
        if ('' === $name) {
            $errors['name'] = __('Zadajte svoje meno.', 'knizica-mamateyka');
        } elseif (mb_strlen($name) < 2) {
            $errors['name'] = __('Meno je príliš krátke.', 'knizica-mamateyka');
        }
        if (! is_email($email)) {
            $errors['email'] = __('Zadajte platný email, napríklad jana@example.sk.', 'knizica-mamateyka');
        }
        if (! self::is_valid_phone($phone)) {
            $errors['phone'] = __('Zadajte platné telefónne číslo (9 až 15 číslic), napríklad +421 900 123 456.', 'knizica-mamateyka');
        }
        if (! $consent) {
            $errors['consent'] = __('Pre odoslanie je potrebný súhlas so spracovaním osobných údajov.', 'knizica-mamateyka');
        }
        if ($errors) {
            return new WP_Error('km_invalid', __('Skontrolujte prosím zvýraznené polia.', 'knizica-mamateyka'), array('status' => 400, 'fields' => $errors));
        }

        $book = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . KM_DB::table('books') . ' WHERE id = %d', $book_id), ARRAY_A);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        if ((int) $book['available_copies'] < 1) {
            return new WP_Error('km_unavailable', __('Kniha momentálne nie je dostupná.', 'knizica-mamateyka'), array('status' => 409));
        }
        if (self::is_rate_limited($email)) {
            return new WP_Error('km_rate_limited', __('Dosiahli ste limit počtu rezervácií. Skúste to prosím neskôr.', 'knizica-mamateyka'), array('status' => 429));
        }

        $inserted = $wpdb->insert(KM_DB::table('reservations'), array(
            'book_id' => $book_id,
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'status'  => 'pending',
        ));

        if (false === $inserted) {
            return new WP_Error('km_save_failed', __('Rezerváciu sa nepodarilo uložiť. Skúste to prosím znova.', 'knizica-mamateyka'), array('status' => 500));
        }

        self::notify_library($book, $name, $email, $phone);
        self::notify_reserver($book, $name, $email, $phone);

        return rest_ensure_response(array('success' => true));
    }

    private static function notify_library($book, $name, $email, $phone)
    {
        $to_raw = KM_Settings::get('notification_email');
        if (! $to_raw) {
            return;
        }
        $to = array_filter(array_map('trim', explode(';', $to_raw)), 'is_email');
        if (! $to) {
            return;
        }
        $subject = sprintf(__('Nová rezervácia knihy: %s', 'knizica-mamateyka'), $book['title']);
        $plain_body = sprintf(
            "Kniha: %s\nAutor: %s\nMeno: %s\nEmail: %s\nTelefón: %s\n",
            $book['title'],
            $book['author'],
            $name,
            $email,
            $phone
        );
        $html_body = self::render_email(
            __('Nová rezervácia', 'knizica-mamateyka'),
            sprintf(__('Na webe %s pribudla nová rezervácia.', 'knizica-mamateyka'), self::library_name()),
            array(
                __('Kniha', 'knizica-mamateyka') => array(
                    __('Názov', 'knizica-mamateyka') => $book['title'],
                    __('Autor', 'knizica-mamateyka') => $book['author'],
                ),
                __('Rezervujúci', 'knizica-mamateyka') => array(
                    __('Meno', 'knizica-mamateyka') => $name,
                    __('Email', 'knizica-mamateyka') => array($email, 'mailto:' . $email),
                    __('Telefón', 'knizica-mamateyka') => array($phone, 'tel:' . preg_replace('/[^\d+]/', '', $phone)),
                ),
            ),
            __('Čaká na vybavenie', 'knizica-mamateyka')
        );
        self::send_email($to, $subject, $html_body, $plain_body);
    }

    private static function notify_reserver($book, $name, $email, $phone)
    {
        $subject = sprintf(__('Potvrdenie rezervácie knihy: %s', 'knizica-mamateyka'), $book['title']);
        $plain_body = sprintf(
            "%s\n\nĎakujeme, vašu rezerváciu sme prijali. Ozveme sa vám podľa ďalšieho postupu knižnice.\n\nKniha: %s\nAutor: %s\nMeno: %s\nTelefón: %s\nStav: Čaká na vybavenie\n",
            self::library_name(),
            $book['title'],
            $book['author'],
            $name,
            $phone
        );
        $html_body = self::render_email(
            __('Rezervácia bola prijatá', 'knizica-mamateyka'),
            __('Ďakujeme, vašu rezerváciu sme prijali. Ozveme sa vám podľa ďalšieho postupu knižnice.', 'knizica-mamateyka'),
            array(
                __('Kniha', 'knizica-mamateyka') => array(
                    __('Názov', 'knizica-mamateyka') => $book['title'],
                    __('Autor', 'knizica-mamateyka') => $book['author'],
                ),
                __('Vaše údaje', 'knizica-mamateyka') => array(
                    __('Meno', 'knizica-mamateyka') => $name,
                    __('Telefón', 'knizica-mamateyka') => $phone,
                ),
            ),
            __('Čaká na vybavenie', 'knizica-mamateyka')
        );
        self::send_email($email, $subject, $html_body, $plain_body);
    }

    private static function library_name()
    {
        return 'Mamateyka';
    }

    private static function email_headers()
    {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $to_raw = KM_Settings::get('notification_email');
        $to     = array_filter(array_map('trim', explode(';', (string) $to_raw)), 'is_email');
        if ($to) {
            $headers[] = 'Reply-To: ' . $to[0];
        }
        return $headers;
    }

    private static function send_email($to, $subject, $html_body, $plain_body)
    {
        $configure_mailer = function ($phpmailer) use ($plain_body) {
            $phpmailer->AltBody = $plain_body;
        };

        add_action('phpmailer_init', $configure_mailer);
        $sent = wp_mail($to, $subject, $html_body, self::email_headers());
        remove_action('phpmailer_init', $configure_mailer);

        return $sent;
    }

    private static function render_email($heading, $intro, array $sections, $status)
    {
        $library_name = esc_html(self::library_name());
        $heading      = esc_html($heading);
        $intro        = esc_html($intro);
        $status       = esc_html($status);
        $details      = '';

        foreach ($sections as $section_title => $rows) {
            $details .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 14px;border:1px solid #d9e3e9;border-left:4px solid #537891;">'
                . '<tr><th colspan="2" align="left" style="padding:11px 14px;background:#f4f8fa;color:#3f5e73;font-size:14px;font-weight:700;">' . esc_html($section_title) . '</th></tr>';
            foreach ($rows as $label => $value) {
                $text = esc_html(is_array($value) ? $value[0] : $value);
                if (is_array($value)) {
                    $text = '<a href="' . esc_url($value[1]) . '" style="color:#3f5e73;">' . $text . '</a>';
                }
                $details .= '<tr><td style="padding:9px 14px;color:#687985;font-size:14px;vertical-align:top;width:34%;">' . esc_html($label) . '</td><td style="padding:9px 14px;color:#1d2630;font-size:14px;font-weight:600;vertical-align:top;">' . $text . '</td></tr>';
            }
            $details .= '</table>';
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f8fa;color:#1d2630;font-family:Arial,sans-serif;line-height:1.5;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f8fa;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #d9e3e9;">'
            . '<tr><td style="padding:22px 28px;border-top:5px solid #537891;"><div style="font-size:18px;font-weight:700;color:#161b23;">' . $library_name . '</div></td></tr>'
            . '<tr><td style="padding:4px 28px 28px;"><h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;color:#161b23;">' . $heading . '</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;color:#53616d;">' . $intro . '</p>'
            . '<div style="display:inline-block;margin-bottom:18px;padding:6px 10px;background:#e8eff3;color:#3f5e73;font-size:13px;font-weight:700;">' . $status . '</div>'
            . $details
            . '<p style="margin:24px 0 0;font-size:14px;color:#687985;">' . esc_html(sprintf(__('S pozdravom, %s', 'knizica-mamateyka'), self::library_name())) . '</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    public static function list_reservations(WP_REST_Request $request)
    {
        global $wpdb;
        $status = $request->get_param('status');
        $search = sanitize_text_field($request->get_param('q'));

        $sql    = 'SELECT r.*, b.title AS book_title, b.author AS book_author FROM ' . KM_DB::table('reservations') . ' r LEFT JOIN ' . KM_DB::table('books') . ' b ON b.id = r.book_id';
        $params = array();
        $where  = array();
        if ($status) {
            $where[]  = 'r.status = %s';
            $params[] = sanitize_text_field($status);
        }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s OR r.librarian_name LIKE %s OR b.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT 200';

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        return rest_ensure_response($rows);
    }

    public static function transition(WP_REST_Request $request)
    {
        global $wpdb;
        $id         = absint($request['id']);
        $params     = $request->get_json_params();
        $new_status = sanitize_key($params['status'] ?? '');
        $note       = sanitize_textarea_field($params['note'] ?? '');
        $librarian  = sanitize_text_field($params['librarian_name'] ?? '');
        $allowed    = array('pending', 'assigned', 'borrowed', 'returned', 'rejected');

        if (! in_array($new_status, $allowed, true)) {
            return new WP_Error('km_invalid_status', __('Neplatný stav rezervácie.', 'knizica-mamateyka'), array('status' => 400));
        }

        $reservation = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . KM_DB::table('reservations') . ' WHERE id = %d', $id), ARRAY_A);
        if (! $reservation) {
            return new WP_Error('km_not_found', __('Rezervácia nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $transitions = array(
            'pending'  => array('assigned', 'rejected'),
            'assigned' => array('pending', 'borrowed', 'rejected'),
            'borrowed' => array('returned'),
        );
        if (! in_array($new_status, $transitions[$reservation['status']] ?? array(), true)) {
            return new WP_Error('km_invalid_transition', __('Tento prechod rezervácie nie je povolený.', 'knizica-mamateyka'), array('status' => 409));
        }

        if ('assigned' === $new_status && '' === $librarian) {
            return new WP_Error('km_librarian_required', __('Zadajte meno knihovníka.', 'knizica-mamateyka'), array('status' => 400));
        }

        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');

        if ('borrowed' === $new_status) {
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . KM_DB::table('books') . ' SET available_copies = available_copies - 1 WHERE id = %d AND available_copies > 0',
                $reservation['book_id']
            ));
            if (1 !== (int) $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('km_unavailable', __('Kniha už nie je dostupná.', 'knizica-mamateyka'), array('status' => 409));
            }
        } elseif ('returned' === $new_status) {
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . KM_DB::table('books') . ' SET available_copies = LEAST(total_copies, available_copies + 1) WHERE id = %d',
                $reservation['book_id']
            ));
            if (1 !== (int) $updated) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('km_book_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
            }
        }

        $data = array(
            'status'     => $new_status,
            'handled_by' => get_current_user_id(),
            'handled_at' => $now,
        );
        $formats = array('%s', '%d', '%s');
        if ('assigned' === $new_status) {
            $data['librarian_name'] = $librarian;
            $data['assigned_at'] = $now;
            $formats[] = '%s';
            $formats[] = '%s';
        } elseif ('pending' === $new_status) {
            $data['librarian_name'] = null;
            $data['assigned_at'] = null;
            $formats[] = '%s';
            $formats[] = '%s';
        } elseif ('borrowed' === $new_status) {
            $data['borrowed_at'] = $now;
            $formats[] = '%s';
        } elseif ('returned' === $new_status) {
            $data['returned_at'] = $now;
            $formats[] = '%s';
        } elseif ('rejected' === $new_status && $note) {
            $data['note'] = $note;
            $formats[] = '%s';
        }

        $updated = $wpdb->update(KM_DB::table('reservations'), $data, array('id' => $id, 'status' => $reservation['status']), $formats, array('%d', '%s'));
        if (1 !== (int) $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('km_conflict', __('Rezervácia bola medzitým zmenená. Obnovte zoznam.', 'knizica-mamateyka'), array('status' => 409));
        }
        $wpdb->query('COMMIT');

        return rest_ensure_response(array('id' => $id, 'status' => $new_status));
    }
}
