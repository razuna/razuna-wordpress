<?php
/**
 * Settings storage + admin page (region picker, connection status, connect/disconnect).
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION_KEY     = 'razuna_settings';   // region + server_url + mcp_resource (plain).
	const TOKENS_KEY     = 'razuna_oauth';      // encrypted token bundle.
	const CONNECTION_KEY = 'razuna_connection'; // display-only connection info (plain).

	const REGIONS = array(
		'us' => array(
			'label'  => 'US (app.razuna.com)',
			'server' => 'https://app.razuna.com',
		),
		'eu' => array(
			'label'  => 'EU (app.razuna.eu)',
			'server' => 'https://app.razuna.eu',
		),
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_razuna_save_settings', array( $this, 'handle_save' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Razuna DAM', 'razuna-dam' ),
			__( 'Razuna', 'razuna-dam' ),
			'manage_options',
			'razuna',
			array( $this, 'render_page' )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Config accessors
	 * --------------------------------------------------------------------- */

	public function get( string $key, $default = '' ) {
		$opts = get_option( self::OPTION_KEY, array() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	public function region(): string {
		$region = (string) $this->get( 'region', 'us' );
		return in_array( $region, array( 'us', 'eu', 'custom' ), true ) ? $region : 'us';
	}

	/**
	 * Base URL of the Razuna app (OAuth + REST API live here). No trailing slash.
	 */
	public function get_server_url(): string {
		$region = $this->region();
		if ( 'custom' === $region ) {
			return $this->normalize_url( (string) $this->get( 'server_url', '' ) );
		}
		return self::REGIONS[ $region ]['server'];
	}

	/**
	 * The OAuth "resource" (token audience) to request. Razuna issues an API
	 * access token whose audience is the server itself, so this is simply the
	 * server URL — there is nothing extra for the user to configure.
	 */
	public function get_resource(): string {
		return $this->get_server_url();
	}

	/**
	 * Public-facing base used to build direct-link URLs returned by the API.
	 * Direct links already come back absolute from Razuna, so this is only a
	 * fallback for relative URLs; defaults to the server URL.
	 */
	public function get_public_url(): string {
		return $this->get_server_url();
	}

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		return untrailingslashit( $url );
	}

	/* --------------------------------------------------------------------- *
	 * Token persistence (encrypted)
	 * --------------------------------------------------------------------- */

	public function get_tokens(): array {
		$raw = get_option( self::TOKENS_KEY, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$json = Crypto::decrypt( $raw );
		if ( '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	public function set_tokens( array $tokens ): void {
		update_option( self::TOKENS_KEY, Crypto::encrypt( wp_json_encode( $tokens ) ), false );
	}

	public function clear_tokens(): void {
		delete_option( self::TOKENS_KEY );
		delete_option( self::CONNECTION_KEY );
	}

	public function is_connected(): bool {
		$tokens = $this->get_tokens();
		return ! empty( $tokens['refresh_token'] ) || ! empty( $tokens['access_token'] );
	}

	public function get_connection(): array {
		$conn = get_option( self::CONNECTION_KEY, array() );
		return is_array( $conn ) ? $conn : array();
	}

	public function set_connection( array $info ): void {
		update_option( self::CONNECTION_KEY, $info, false );
	}

	/* --------------------------------------------------------------------- *
	 * Admin page
	 * --------------------------------------------------------------------- */

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'razuna-dam' ) );
		}
		check_admin_referer( 'razuna_save_settings' );

		$region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us';
		if ( ! in_array( $region, array( 'us', 'eu', 'custom' ), true ) ) {
			$region = 'us';
		}

		$opts = array(
			'region'     => $region,
			'server_url' => isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '',
		);
		update_option( self::OPTION_KEY, $opts );

		wp_safe_redirect( add_query_arg( 'razuna_msg', 'saved', admin_url( 'options-general.php?page=razuna' ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$region     = $this->region();
		$server_url = (string) $this->get( 'server_url', '' );
		$connected  = $this->is_connected();
		$conn       = $this->get_connection();
		$msg        = isset( $_GET['razuna_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['razuna_msg'] ) ) : '';

		include RAZUNA_PLUGIN_DIR . 'views/settings-page.php';
	}
}
