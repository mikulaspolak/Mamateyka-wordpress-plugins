<?php
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Frontend login/logout that authenticates against standard WP credentials via wp_signon().
 */
class KM_REST_Auth
{

	const NS = 'knizica-mamateyka/v1';

	public static function register_routes()
	{
		register_rest_route(self::NS, '/login', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'login'),
			'permission_callback' => '__return_true',
		));

		register_rest_route(self::NS, '/logout', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'logout'),
			'permission_callback' => '__return_true',
		));

		register_rest_route(self::NS, '/me', array(
			'methods'             => 'GET',
			'callback'            => array(__CLASS__, 'me'),
			'permission_callback' => '__return_true',
		));
	}

	public static function login(WP_REST_Request $request)
	{
		$params = $request->get_json_params();

		if (! isset($params['_wpnonce']) || ! wp_verify_nonce($params['_wpnonce'], 'km_login')) {
			return new WP_Error('km_bad_nonce', __('Overenie zlyhalo, obnovte prosím stránku.', 'knizica-mamateyka'), array('status' => 403));
		}

		$username = sanitize_user(sanitize_text_field($params['username'] ?? ''));
		$password = (string) ($params['password'] ?? '');

		if ('' === $username || '' === $password) {
			return new WP_Error('km_invalid', __('Zadajte meno a heslo.', 'knizica-mamateyka'), array('status' => 400));
		}

		$user = wp_signon(array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		), is_ssl());

		if (is_wp_error($user)) {
			$known = get_user_by('login', $username);
			if (! $known) {
				$known = get_user_by('email', $username);
			}
			if ($known && self::is_librarian($known)) {
				self::record_login($known->ID, false, 'bad_credentials');
			}
			return new WP_Error('km_login_failed', __('Nesprávne meno alebo heslo.', 'knizica-mamateyka'), array('status' => 401));
		}

		if (! user_can($user, 'km_manage_books')) {
			self::record_login($user->ID, false, 'no_access');
			wp_logout();
			return new WP_Error('km_forbidden', __('Tento účet nemá prístup do editor rozhrania.', 'knizica-mamateyka'), array('status' => 403));
		}

		self::record_login($user->ID, true);
		wp_set_current_user($user->ID);

		return rest_ensure_response(array(
			'success'  => true,
			'display_name' => $user->display_name,
		));
	}

	private static function is_librarian(WP_User $user)
	{
		return in_array(KM_Roles::ROLE, (array) $user->roles, true)
			|| 'disabled' === get_user_meta($user->ID, '_km_librarian_access', true);
	}

	private static function record_login($user_id, $success, $reason = '')
	{
		if ($success) {
			update_user_meta($user_id, '_km_last_login_success', time());
			delete_user_meta($user_id, '_km_failed_login_count');
			delete_user_meta($user_id, '_km_last_login_fail_reason');
			return;
		}

		update_user_meta($user_id, '_km_last_login_failed', time());
		update_user_meta($user_id, '_km_last_login_fail_reason', $reason);
		update_user_meta($user_id, '_km_failed_login_count', (int) get_user_meta($user_id, '_km_failed_login_count', true) + 1);
	}

	public static function logout()
	{
		wp_logout();
		return rest_ensure_response(array('success' => true));
	}

	public static function me()
	{
		$user_id = get_current_user_id();
		if (! $user_id) {
			return rest_ensure_response(array('logged_in' => false));
		}
		$user = wp_get_current_user();
		return rest_ensure_response(array(
			'logged_in'   => true,
			'display_name' => $user->display_name,
			'is_editor'    => user_can($user, 'km_manage_books'),
		));
	}
}
