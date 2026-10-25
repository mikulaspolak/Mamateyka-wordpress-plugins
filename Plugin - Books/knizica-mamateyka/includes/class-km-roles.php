<?php
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Custom role for librarians (editors of the catalog) and shared capabilities.
 */
class KM_Roles
{

	const ROLE = 'library_editor';
	const MANAGE_BOOKS = 'km_manage_books';
	const MANAGE_RESERVATIONS = 'km_manage_reservations';

	public static function capabilities()
	{
		return array(
			'read'                  => true,
			'km_manage_books'       => true,
			'km_manage_reservations' => true,
		);
	}

	public static function add_roles()
	{
		$role = get_role(self::ROLE);
		if (! $role) {
			$role = add_role(self::ROLE, __('Knihovníčka', 'knizica-mamateyka'), self::capabilities());
		}

		if ($role) {
			foreach (array_keys(self::capabilities()) as $cap) {
				if (! $role->has_cap($cap)) {
					$role->add_cap($cap);
				}
			}
		}

		// Administrators keep full access to books/reservations, settings rely on manage_options.
		$admin = get_role('administrator');
		if ($admin) {
			foreach (array_keys(self::capabilities()) as $cap) {
				if (! $admin->has_cap($cap)) {
					$admin->add_cap($cap);
				}
			}
		}
	}

	public static function remove_roles()
	{
		remove_role(self::ROLE);

		$admin = get_role('administrator');
		if ($admin) {
			foreach (array_keys(self::capabilities()) as $cap) {
				if ('read' !== $cap) {
					$admin->remove_cap($cap);
				}
			}
		}
	}
}
