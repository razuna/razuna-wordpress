<?php
/**
 * Uninstall cleanup: remove all plugin options (incl. stored OAuth tokens).
 *
 * @package Razuna
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'razuna_settings' );
delete_option( 'razuna_oauth' );
delete_option( 'razuna_connection' );
delete_option( 'razuna_client' );

delete_transient( 'razuna_oauth_tx' );
