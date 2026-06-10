<?php
/**
 * Core plugin orchestrator.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

/**
 * Wires together settings, OAuth, the API client, the REST proxy and the editor
 * integrations (media-library tab + Gutenberg block).
 */
final class Plugin {

	/**
	 * Singleton plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * OAuth service.
	 *
	 * @var OAuth
	 */
	private $oauth;

	/**
	 * Razuna API client.
	 *
	 * @var Api
	 */
	private $api;

	/**
	 * REST proxy service.
	 *
	 * @var Rest
	 */
	private $rest;

	/**
	 * Block integration service.
	 *
	 * @var Block
	 */
	private $block;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build service objects.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->oauth    = new OAuth( $this->settings );
		$this->api      = new Api( $this->settings, $this->oauth );
		$this->rest     = new Rest( $this->settings, $this->oauth, $this->api );
		$this->block    = new Block( $this->settings, $this->api );

		$this->register_hooks();
	}

	/**
	 * Register plugin hooks.
	 */
	private function register_hooks(): void {
		// Settings page + connection status UI.
		$this->settings->register();

		// OAuth: connect/callback/disconnect handlers (admin_post + admin_init).
		$this->oauth->register();

		// REST proxy the editor JS talks to (keeps tokens server-side).
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		// Gutenberg block (register + server render).
		$this->block->register();

		// Editor assets: media-library "Razuna" tab + shared picker.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );

		// Settings link on the Plugins screen.
		add_filter(
			'plugin_action_links_' . RAZUNA_PLUGIN_BASENAME,
			array( $this, 'plugin_action_links' )
		);
	}

	/**
	 * Register (once) the shared picker script + admin style + the RazunaConfig
	 * object (same-origin REST routes + nonce only — never the token). Used by
	 * both the classic-editor media modal and the block editor.
	 */
	public static function register_picker_asset(): void {
		if ( wp_script_is( 'razuna-picker', 'registered' ) ) {
			return;
		}
		wp_register_style( 'razuna-admin', RAZUNA_PLUGIN_URL . 'assets/css/admin.css', array(), self::asset_version( 'assets/css/admin.css' ) );
		wp_register_script( 'razuna-picker', RAZUNA_PLUGIN_URL . 'assets/js/picker.js', array(), self::asset_version( 'assets/js/picker.js' ), true );
		wp_localize_script( 'razuna-picker', 'RazunaConfig', self::instance()->frontend_config() );
	}

	/**
	 * Enqueue the classic-editor "Add from Razuna" button + shared picker on the
	 * post-editor / upload screens. The picker talks only to the same-origin REST
	 * proxy, so no token is exposed to the browser. (Block editor assets are
	 * enqueued by Block::enqueue_editor via enqueue_block_editor_assets.)
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_editor_assets( string $hook ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'upload.php' ), true ) ) {
			return;
		}

		wp_enqueue_media();
		self::register_picker_asset();
		wp_enqueue_style( 'razuna-admin' );
		wp_enqueue_script( 'razuna-picker' );
		wp_enqueue_script(
			'razuna-media-modal',
			RAZUNA_PLUGIN_URL . 'assets/js/media-modal.js',
			array( 'razuna-picker' ),
			self::asset_version( 'assets/js/media-modal.js' ),
			true
		);
	}

	/**
	 * Version an asset from plugin version and file timestamp.
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 */
	private static function asset_version( string $relative_path ): string {
		$path = RAZUNA_PLUGIN_DIR . ltrim( $relative_path, '/' );

		if ( file_exists( $path ) ) {
			return RAZUNA_VERSION . '-' . filemtime( $path );
		}
		return RAZUNA_VERSION;
	}

	/**
	 * Config object shared with editor JS. Contains only same-origin REST routes
	 * and a nonce — never the Razuna token.
	 */
	public function frontend_config(): array {
		return array(
			'restBase'    => esc_url_raw( rest_url( 'razuna/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'connected'   => $this->settings->is_connected(),
			'settingsUrl' => esc_url_raw( admin_url( 'options-general.php?page=razuna' ) ),
			'i18n'        => array(
				'tabLabel'          => __( 'Razuna', 'razuna-dam' ),
				'searchPlaceholder' => __( 'Search your Razuna assets…', 'razuna-dam' ),
				'insert'            => __( 'Insert into post', 'razuna-dam' ),
				'notConnected'      => __( 'Connect your Razuna account in Settings → Razuna to browse your assets.', 'razuna-dam' ),
				'loading'           => __( 'Loading…', 'razuna-dam' ),
				'noResults'         => __( 'No assets found.', 'razuna-dam' ),
			),
		);
	}

	/**
	 * Add plugin row action links.
	 *
	 * @param array $links Existing action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=razuna' ) ),
			esc_html__( 'Settings', 'razuna-dam' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Return the settings service.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Return the API client.
	 */
	public function api(): Api {
		return $this->api;
	}

	/**
	 * Return the OAuth service.
	 */
	public function oauth(): OAuth {
		return $this->oauth;
	}

	/**
	 * Seed default options on activation.
	 */
	public static function on_activate(): void {
		if ( false === get_option( Settings::OPTION_KEY, false ) ) {
			add_option(
				Settings::OPTION_KEY,
				array(
					'region'     => 'us',
					'server_url' => '',
				)
			);
		}
	}

	/**
	 * Keep options on deactivation.
	 */
	public static function on_deactivate(): void {
		// Intentionally keep credentials so re-activating does not force a reconnect.
		// Full cleanup happens on uninstall (uninstall.php).
	}
}
