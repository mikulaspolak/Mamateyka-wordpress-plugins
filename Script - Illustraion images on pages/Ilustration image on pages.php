<?php

// Záložný ilustračný obrázok pre zdieľanie (Open Graph / X Card).
// Použije sa len ak stránka či článok nemá vlastný obrázok.
$mamateyka_fallback_share_image = 'https://mamateyka.sk/wp-content/uploads/2026/09/mamateyka-ilustracny-obrazok.png';

// Yoast SEO: beží po pridaní vlastného obrázka stránky (featured/social image),
// takže záložný obrázok pridáme len keď žiadny nie je. Yoast z og:image
// odvodí aj twitter:image a doplní rozmery a typ obrázka.
add_filter('wpseo_add_opengraph_additional_images', function ($images) use ($mamateyka_fallback_share_image) {
    if (!is_object($images) || !method_exists($images, 'has_images') || $images->has_images()) {
        return $images;
    }

    $url = $mamateyka_fallback_share_image;
    $id = get_transient('mamateyka_fallback_share_image_id');

    if ($id === false) {
        $id = (int) attachment_url_to_postid($url);
        set_transient('mamateyka_fallback_share_image_id', $id, DAY_IN_SECONDS);
    }

    if ($id > 0) {
        $images->add_image_by_id($id);
    } else {
        $images->add_image(['url' => $url]);
    }

    return $images;
});

// Bez Yoastu: vypíšeme značky sami, ale len ak ich negeneruje iný plugin či téma.
add_action('wp_head', function () use ($mamateyka_fallback_share_image) {
    if (is_admin() || defined('WPSEO_VERSION')) {
        return;
    }

    $image = $mamateyka_fallback_share_image;

    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
}, 1);
