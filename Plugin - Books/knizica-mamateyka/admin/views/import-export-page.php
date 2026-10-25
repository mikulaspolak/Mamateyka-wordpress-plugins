<?php
if (! defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e('Import / Export kníh', 'knizica-mamateyka'); ?></h1>

	<?php if (isset($_GET['km_imported'])) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: books 2: categories 3: original categories 4: original category groups */
					esc_html__('Import dokončený: %1$d kníh, %2$d kategórií, %3$d pôvodných kategórií, %4$d pôvodných skupín.', 'knizica-mamateyka'),
					(int) ($_GET['books'] ?? 0),
					(int) ($_GET['categories'] ?? 0),
					(int) ($_GET['legacy_categories'] ?? 0),
					(int) ($_GET['groups'] ?? 0)
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if (isset($_GET['km_deleted'])) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e('Databáza knižnice bola zmazaná.', 'knizica-mamateyka'); ?></p>
		</div>
	<?php endif; ?>
	<?php if (! empty($_GET['km_error'])) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html(wp_unslash($_GET['km_error'])); ?></p>
		</div>
	<?php endif; ?>
	<h2><?php esc_html_e('Import', 'knizica-mamateyka'); ?></h2>
	<p><?php esc_html_e('Nahrajte JSON súbor z exportu aktuálnej verzie knižnice (format "knizica-mamateyka", version 3). Exporty zo starších verzií sa nedajú importovať. Import pridá alebo aktualizuje údaje zo súboru; lokálne údaje, ktoré v súbore chýbajú, zostanú zachované.', 'knizica-mamateyka'); ?></p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="km_import" />
		<?php wp_nonce_field('km_import'); ?>
		<input type="file" name="import_file" accept="application/json" required />
		<?php submit_button(__('Importovať', 'knizica-mamateyka')); ?>
	</form>

	<h2><?php esc_html_e('Export', 'knizica-mamateyka'); ?></h2>
	<p><?php esc_html_e('Stiahne aktuálne dáta knižnice v rovnakom JSON formáte.', 'knizica-mamateyka'); ?></p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="km_export" />
		<?php wp_nonce_field('km_export'); ?>
		<?php submit_button(__('Exportovať JSON', 'knizica-mamateyka'), 'secondary'); ?>
	</form>

	<h2><?php esc_html_e('Delete database', 'knizica-mamateyka'); ?></h2>
	<p><strong><?php esc_html_e('Natrvalo zmaže všetky knihy, kategórie, rezervácie a históriu. Akciu nie je možné vrátiť späť - najprv si spravte export. Nastavenia a knihovníci zostanú zachovaní.', 'knizica-mamateyka'); ?></strong></p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="km-delete-db-form">
		<input type="hidden" name="action" value="km_delete_database" />
		<?php wp_nonce_field('km_delete_database'); ?>
		<p>
			<label for="km-delete-confirm"><?php esc_html_e('Na potvrdenie napíšte DELETE:', 'knizica-mamateyka'); ?></label><br />
			<input type="text" id="km-delete-confirm" name="confirm_text" autocomplete="off" />
		</p>
		<?php submit_button(__('Zmazať databázu', 'knizica-mamateyka'), 'delete', 'submit', true, array('id' => 'km-delete-db-submit', 'disabled' => 'disabled')); ?>
	</form>
	<script>
		(function() {
			var form = document.getElementById('km-delete-db-form');
			var input = document.getElementById('km-delete-confirm');
			var button = document.getElementById('km-delete-db-submit');
			input.addEventListener('input', function() {
				button.disabled = input.value.trim() !== 'DELETE';
			});
			form.addEventListener('submit', function(e) {
				if (!window.confirm(<?php echo wp_json_encode(__('Naozaj chcete natrvalo zmazať celú databázu knižnice?', 'knizica-mamateyka')); ?>)) {
					e.preventDefault();
				}
			});
		})();
	</script>
</div>