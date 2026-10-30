<?php

add_action(
    'send_headers',
    static function () {
        if (is_admin()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (is_ssl()) {
            // Po overení, že všetko funguje, zvýšiť na 31536000 (1 rok)
            header('Strict-Transport-Security: max-age=300');
        }
    }
);
