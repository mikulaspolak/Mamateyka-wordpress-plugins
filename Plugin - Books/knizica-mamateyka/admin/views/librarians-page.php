<?php
if (! defined('ABSPATH')) {
	exit;
}

$active_librarians = get_users(array('role' => KM_Roles::ROLE));
$disabled_librarians = get_users(array(
	'meta_key'   => '_km_librarian_access',
	'meta_value' => 'disabled',
));
$librarians_by_id = array();
foreach (array_merge($active_librarians, $disabled_librarians) as $librarian) {
	$librarians_by_id[$librarian->ID] = $librarian;
}
$librarians = array_values($librarians_by_id);
usort($librarians, static function ($left, $right) {
	return strcasecmp($left->display_name, $right->display_name);
});
?>
<div class="wrap">
	<h1><?php esc_html_e('Knihovníčky', 'knizica-mamateyka'); ?></h1>

	<?php if (isset($_GET['km_created'])) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e('Účet bol vytvorený a heslo bolo odoslané na email.', 'knizica-mamateyka'); ?></p>
		</div>
	<?php endif; ?>
	<?php if (isset($_GET['km_librarian_status'])) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ('enabled' === $_GET['km_librarian_status']) {
					esc_html_e('Prístup knihovníčky bol obnovený.', 'knizica-mamateyka');
				} else {
					esc_html_e('Prístup knihovníčky bol deaktivovaný. Účet a jeho história zostali zachované.', 'knizica-mamateyka');
				}
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if (! empty($_GET['km_error'])) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html(wp_unslash($_GET['km_error'])); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e('Pridať novú knihovníčku', 'knizica-mamateyka'); ?></h2>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="km_create_librarian" />
		<?php wp_nonce_field('km_create_librarian'); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="name"><?php esc_html_e('Meno', 'knizica-mamateyka'); ?></label></th>
				<td>
					<input type="text" id="name" name="name" class="regular-text" required />
					<p class="description"><?php esc_html_e('Toto meno sa použije aj pri prihlasovaní.', 'knizica-mamateyka'); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="email"><?php esc_html_e('Email', 'knizica-mamateyka'); ?></label></th>
				<td><input type="email" id="email" name="email" class="regular-text" required /></td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e('Heslo sa vygeneruje automaticky a pošle na zadaný email spolu s odkazom na nastavenie hesla.', 'knizica-mamateyka'); ?></p>
		<?php submit_button(__('Vytvoriť účet', 'knizica-mamateyka')); ?>
	</form>

	<h2><?php esc_html_e('Existujúce knihovníčky', 'knizica-mamateyka'); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e('Meno', 'knizica-mamateyka'); ?></th>
				<th><?php esc_html_e('Email', 'knizica-mamateyka'); ?></th>
				<th><?php esc_html_e('Stav', 'knizica-mamateyka'); ?></th>
				<th><?php esc_html_e('Posledné prihlásenie', 'knizica-mamateyka'); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if (! $librarians) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e('Zatiaľ žiadne knihovníčky.', 'knizica-mamateyka'); ?></td>
				</tr>
			<?php endif; ?>
			<?php foreach ($librarians as $user) : ?>
				<tr>
					<td><?php echo esc_html($user->display_name); ?></td>
					<td><?php echo esc_html($user->user_email); ?></td>
					<td>
						<?php $is_active = in_array(KM_Roles::ROLE, $user->roles, true); ?>
						<?php if ($is_active) : ?>
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e('Aktívna', 'knizica-mamateyka'); ?>
						<?php else : ?>
							<span class="dashicons dashicons-minus" aria-hidden="true"></span><?php esc_html_e('Deaktivovaná', 'knizica-mamateyka'); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$last_ok     = (int) get_user_meta($user->ID, '_km_last_login_success', true);
						$last_failed = (int) get_user_meta($user->ID, '_km_last_login_failed', true);
						$fail_count  = (int) get_user_meta($user->ID, '_km_failed_login_count', true);
						$fail_reason = (string) get_user_meta($user->ID, '_km_last_login_fail_reason', true);
						$fail_labels = array(
							'bad_credentials' => __('nesprávne heslo', 'knizica-mamateyka'),
							'no_access'       => __('účet bez prístupu', 'knizica-mamateyka'),
						);
						$fail_is_newer = $last_failed > $last_ok;
						?>
						<?php if ($last_ok) : ?>
							<div title="<?php echo esc_attr(wp_date('j. n. Y H:i', $last_ok)); ?>">
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<?php echo esc_html(sprintf(__('Úspešné: pred %s', 'knizica-mamateyka'), human_time_diff($last_ok, time()))); ?>
							</div>
						<?php else : ?>
							<div><?php esc_html_e('Zatiaľ sa neprihlásila', 'knizica-mamateyka'); ?></div>
						<?php endif; ?>
						<?php if ($last_failed) : ?>
							<div<?php echo $fail_is_newer ? ' style="color:#b32d2e"' : ''; ?> title="<?php echo esc_attr(wp_date('j. n. Y H:i', $last_failed)); ?>">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<?php echo esc_html(sprintf(__('Neúspešné: pred %s', 'knizica-mamateyka'), human_time_diff($last_failed, time()))); ?>
								<?php if (isset($fail_labels[$fail_reason])) : ?>
									(<?php echo esc_html($fail_labels[$fail_reason]); ?><?php echo $fail_count > 1 ? esc_html(' ' . $fail_count . '×') : ''; ?>)
								<?php endif; ?>
</div>
<?php endif; ?>
</td>
<td>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js($is_active ? __('Deaktivovať prístup tejto knihovníčke?', 'knizica-mamateyka') : __('Obnoviť prístup tejto knihovníčke?', 'knizica-mamateyka')); ?>');">
		<input type="hidden" name="action" value="km_toggle_librarian" />
		<input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>" />
		<input type="hidden" name="enable" value="<?php echo $is_active ? '0' : '1'; ?>" />
		<?php wp_nonce_field('km_toggle_librarian'); ?>
		<button type="submit" class="button-link<?php echo $is_active ? ' button-link-delete' : ''; ?>"><?php echo $is_active ? esc_html__('Deaktivovať prístup', 'knizica-mamateyka') : esc_html__('Obnoviť prístup', 'knizica-mamateyka'); ?></button>
	</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>