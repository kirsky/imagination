<?php
/**
 * Deletes the plugin's options when it is uninstalled.
 *
 * @package Imagination
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$imagination_delete_options = static function (): void {
	delete_option( 'imagination_options' );
	delete_option( 'imagination_capabilities' );
	delete_option( 'imagination_errors' );
};

if ( ! is_multisite() ) {
	$imagination_delete_options();
	return;
}

$imagination_site_ids = get_sites(
	[
		'fields' => 'ids',
		'number' => 0,
	]
);

foreach ( $imagination_site_ids as $imagination_site_id ) {
	switch_to_blog( (int) $imagination_site_id );
	$imagination_delete_options();
	restore_current_blog();
}
