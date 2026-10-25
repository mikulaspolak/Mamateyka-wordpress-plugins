<?php
if (! defined('ABSPATH')) {
    exit;
}

$settings = KM_Settings::get_all();
?>
<div class="wrap">
    <h1><?php esc_html_e('Knižnica - nastavenia', 'knizica-mamateyka'); ?></h1>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Nastavenia boli uložené.', 'knizica-mamateyka'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="km_save_settings" />
        <?php wp_nonce_field('km_save_settings'); ?>

        <h2><?php esc_html_e('Rezervácie', 'knizica-mamateyka'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="notification_email"><?php esc_html_e('Email pre rezervácie', 'knizica-mamateyka'); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="notification_email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>" required />
                    <p class="description"><?php esc_html_e('Viac emailov oddeľte bodkočiarkou (;), napr. kniznica@email.sk;druhy@email.sk.', 'knizica-mamateyka'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="max_reservations_per_email"><?php esc_html_e('Max. rezervácií na email / deň', 'knizica-mamateyka'); ?></label></th>
                <td>
                    <input type="number" min="1" id="max_reservations_per_email" name="max_reservations_per_email" value="<?php echo esc_attr($settings['max_reservations_per_email']); ?>" />
                    <p class="description"><?php esc_html_e('Koľko rezervácií môže vytvoriť jeden email v priebehu 24 hodín (ochrana proti zneužitiu formulára).', 'knizica-mamateyka'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Uložiť nastavenia', 'knizica-mamateyka')); ?>
    </form>
</div>