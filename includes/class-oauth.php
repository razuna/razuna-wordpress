<?php
/**
 * OAuth 2.0 client for Razuna (authorization-code + PKCE S256 + refresh).
 *
 * Flow:
 *   1. Dynamically register this site as an OAuth client (RFC 7591) — once.
 *   2. "Connect" redirects to Razuna /oauth/authorize with PKCE + a resource
 *      (audience) so Razuna issues a JWT access token + refresh token.
 *   3. The callback exchanges the code at /oauth/token; tokens are stored
 *      encrypted. The JWT authorizes /api/v1/files/* directly.
 *   4. Access tokens are short-lived (~10 min) and refreshed transparently.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Razuna OAuth connection, callback, token storage, and refresh.
 */
final class OAuth {

	const CLIENT_OPTION  = 'razuna_client'; // { client_id, redirect_uri, server_url }.
	const TX_TRANSIENT   = 'razuna_oauth_tx';
	const META_TRANSIENT = 'razuna_oauth_meta_'; // + md5(server) -> discovery doc.
	const SCOPE          = 'mcp:read mcp:write';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Last client-registration error, surfaced to the settings page.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Build the OAuth service.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register OAuth hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_razuna_oauth_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_razuna_oauth_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ) );
	}

	// Connect / callback / disconnect.

	/**
	 * Start the OAuth authorization flow.
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'razuna-dam' ) );
		}
		check_admin_referer( 'razuna_oauth_connect' );

		$server = $this->settings->get_server_url();
		if ( '' === $server ) {
			$this->redirect_with_error( __( 'Please set a valid Razuna server URL first.', 'razuna-dam' ) );
		}

		$client_id = $this->ensure_client();
		if ( '' === $client_id ) {
			$this->redirect_with_error( '' !== $this->last_error ? $this->last_error : __( 'Could not register this site with Razuna. Check the server URL and try again.', 'razuna-dam' ) );
		}

		// PKCE + CSRF state.
		$verifier  = $this->base64url( random_bytes( 48 ) );
		$challenge = $this->base64url( hash( 'sha256', $verifier, true ) );
		$state     = $this->base64url( random_bytes( 16 ) );

		set_transient(
			self::TX_TRANSIENT,
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'server'   => $server,
			),
			10 * MINUTE_IN_SECONDS
		);

		$authorize = $this->endpoint( 'authorization_endpoint', '/oauth/authorize' );
		$url       = add_query_arg(
			array(
				'client_id'             => rawurlencode( $client_id ),
				'response_type'         => 'code',
				'redirect_uri'          => rawurlencode( $this->redirect_uri() ),
				'scope'                 => rawurlencode( self::SCOPE ),
				'state'                 => rawurlencode( $state ),
				'resource'              => rawurlencode( $this->settings->get_resource() ),
				'code_challenge'        => rawurlencode( $challenge ),
				'code_challenge_method' => 'S256',
			),
			$authorize
		);

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirects to the configured external Razuna OAuth endpoint.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Detect and process the OAuth redirect back to the settings page.
	 */
	public function maybe_handle_callback(): void {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
		$callback = isset( $_GET['razuna_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['razuna_oauth'] ) ) : '';
		if ( 'razuna' !== $page || 'callback' !== $callback ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Provider-reported error.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
		if ( isset( $_GET['error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
			$desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : sanitize_text_field( wp_unslash( $_GET['error'] ) );
			$this->redirect_with_error( $desc );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback is validated with PKCE state.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$tx    = get_transient( self::TX_TRANSIENT );

		if ( '' === $code || empty( $tx['state'] ) || ! hash_equals( (string) $tx['state'], $state ) ) {
			$this->redirect_with_error( __( 'OAuth session expired or invalid. Please try connecting again.', 'razuna-dam' ) );
		}
		delete_transient( self::TX_TRANSIENT );

		$token = $this->post_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirect_uri(),
				'client_id'     => $this->client_id(),
				'code_verifier' => (string) $tx['verifier'],
			)
		);

		if ( is_wp_error( $token ) || empty( $token['access_token'] ) ) {
			$err = is_wp_error( $token ) ? $token->get_error_message() : __( 'Token exchange failed.', 'razuna-dam' );
			$this->redirect_with_error( $err );
		}

		$this->store_token_response( $token );
		$this->store_connection_info();

		wp_safe_redirect( add_query_arg( 'razuna_msg', 'connected', admin_url( 'options-general.php?page=razuna' ) ) );
		exit;
	}

	/**
	 * Disconnect the site from Razuna.
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'razuna-dam' ) );
		}
		check_admin_referer( 'razuna_oauth_disconnect' );

		$this->settings->clear_tokens();

		wp_safe_redirect( add_query_arg( 'razuna_msg', 'disconnected', admin_url( 'options-general.php?page=razuna' ) ) );
		exit;
	}

	// Access token lifecycle.

	/**
	 * Return a currently-valid access token, refreshing if needed. '' if not
	 * connected or refresh failed.
	 */
	public function get_access_token(): string {
		$tokens = $this->settings->get_tokens();
		if ( empty( $tokens ) ) {
			return '';
		}

		$expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
		if ( ! empty( $tokens['access_token'] ) && $expires_at > ( time() + 30 ) ) {
			return (string) $tokens['access_token'];
		}

		if ( ! empty( $tokens['refresh_token'] ) ) {
			return $this->refresh( (string) $tokens['refresh_token'] );
		}

		return ! empty( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '';
	}

	/**
	 * Refresh an access token.
	 *
	 * @param string $refresh_token Refresh token.
	 */
	private function refresh( string $refresh_token ): string {
		$token = $this->post_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $this->client_id(),
			)
		);

		if ( is_wp_error( $token ) || empty( $token['access_token'] ) ) {
			// Refresh failed — force a reconnect rather than loop.
			$this->settings->clear_tokens();
			return '';
		}

		$this->store_token_response( $token );
		return (string) $token['access_token'];
	}

	/**
	 * Persist a token endpoint response.
	 *
	 * @param array $token Token response.
	 */
	private function store_token_response( array $token ): void {
		$existing   = $this->settings->get_tokens();
		$expires_in = isset( $token['expires_in'] ) ? (int) $token['expires_in'] : 600;

		$this->settings->set_tokens(
			array(
				'access_token'  => (string) $token['access_token'],
				// Some grants (e.g. refresh) may omit a new refresh token: keep the old one.
				'refresh_token' => ! empty( $token['refresh_token'] ) ? (string) $token['refresh_token'] : ( isset( $existing['refresh_token'] ) ? $existing['refresh_token'] : '' ),
				'token_type'    => isset( $token['token_type'] ) ? (string) $token['token_type'] : 'Bearer',
				'scope'         => isset( $token['scope'] ) ? (string) $token['scope'] : self::SCOPE,
				'expires_at'    => time() + $expires_in,
			)
		);
	}

	/**
	 * Fetch the connected user's profile for the settings display.
	 */
	private function store_connection_info(): void {
		$access = $this->get_access_token();
		$info   = array(
			'server_url'   => $this->settings->get_server_url(),
			'connected_at' => time(),
		);

		if ( '' !== $access ) {
			$resp = wp_remote_get(
				$this->endpoint( 'userinfo_endpoint', '/oauth/userinfo' ),
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access,
						'Accept'        => 'application/json',
					),
				)
			);
			if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
				$user = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( is_array( $user ) ) {
					$info['user_email'] = isset( $user['email'] ) ? $user['email'] : '';
					$info['user_name']  = isset( $user['name'] ) ? $user['name'] : '';
				}
			}
		}

		$this->settings->set_connection( $info );
	}

	// Client registration + discovery.

	/**
	 * Return the registered client ID.
	 */
	private function client_id(): string {
		$client = get_option( self::CLIENT_OPTION, array() );
		return ( is_array( $client ) && ! empty( $client['client_id'] ) ) ? (string) $client['client_id'] : '';
	}

	/**
	 * Ensure a registered OAuth client exists for the current server + redirect
	 * URI. Re-registers if the server URL or redirect URI changed.
	 */
	private function ensure_client(): string {
		$client   = get_option( self::CLIENT_OPTION, array() );
		$server   = $this->settings->get_server_url();
		$redirect = $this->redirect_uri();

		if (
			is_array( $client )
			&& ! empty( $client['client_id'] )
			&& isset( $client['server_url'] ) && $client['server_url'] === $server
			&& isset( $client['redirect_uri'] ) && $client['redirect_uri'] === $redirect
		) {
			return (string) $client['client_id'];
		}

		$body = array(
			'client_name'                => $this->client_name(),
			'redirect_uris'              => array( $redirect ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none', // public client; PKCE protects it.
		);
		// client_uri must be HTTPS per the dynamic client registration spec, so
		// only send it on HTTPS sites — a local http:// dev site would be rejected.
		$home = home_url( '/' );
		if ( 0 === stripos( $home, 'https://' ) ) {
			$body['client_uri'] = $home;
		}

		$resp = wp_remote_post(
			$this->endpoint( 'registration_endpoint', '/oauth/register' ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			$this->last_error = sprintf(
				/* translators: 1: server URL, 2: error message */
				__( 'Could not reach Razuna at %1$s (%2$s).', 'razuna-dam' ),
				$server,
				$resp->get_error_message()
			);
			return '';
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ( 200 !== $code && 201 !== $code ) || empty( $data['client_id'] ) ) {
			$detail           = ( is_array( $data ) && ! empty( $data['error_description'] ) ) ? $data['error_description'] : sprintf( 'HTTP %d', $code );
			$this->last_error = sprintf(
				/* translators: %s: error detail from Razuna */
				__( 'Razuna rejected the app registration: %s', 'razuna-dam' ),
				$detail
			);
			return '';
		}

		update_option(
			self::CLIENT_OPTION,
			array(
				'client_id'    => (string) $data['client_id'],
				'redirect_uri' => $redirect,
				'server_url'   => $server,
			),
			false
		);

		return (string) $data['client_id'];
	}

	/**
	 * Build the OAuth client display name.
	 */
	private function client_name(): string {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return sprintf( 'WordPress – %s (%s)', $name ? $name : $host, $host );
	}

	/**
	 * Exact redirect URI used at register, authorize and token. Must be byte-identical.
	 */
	private function redirect_uri(): string {
		return admin_url( 'options-general.php?page=razuna&razuna_oauth=callback' );
	}

	/**
	 * Resolve an OAuth endpoint from the server's authorization-server metadata,
	 * falling back to the conventional /oauth/* path.
	 *
	 * @param string $key Metadata key.
	 * @param string $fallback_path Fallback endpoint path.
	 */
	private function endpoint( string $key, string $fallback_path ): string {
		$meta = $this->discovery();
		if ( ! empty( $meta[ $key ] ) ) {
			return (string) $meta[ $key ];
		}
		return $this->settings->get_server_url() . $fallback_path;
	}

	/**
	 * Return OAuth authorization-server metadata.
	 */
	private function discovery(): array {
		$server = $this->settings->get_server_url();
		if ( '' === $server ) {
			return array();
		}
		$cache_key = self::META_TRANSIENT . md5( $server );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$meta = array();
		$resp = wp_remote_get(
			$server . '/.well-known/oauth-authorization-server/oauth',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $data ) ) {
				$meta = $data;
			}
		}
		// userinfo isn't always advertised; add a sensible default.
		if ( empty( $meta['userinfo_endpoint'] ) ) {
			$meta['userinfo_endpoint'] = $server . '/oauth/userinfo';
		}

		set_transient( $cache_key, $meta, HOUR_IN_SECONDS );
		return $meta;
	}

	// Helpers.

	/**
	 * POST to the token endpoint (application/x-www-form-urlencoded).
	 *
	 * @param array $params Token request params.
	 * @return array|\WP_Error Decoded JSON token response, or WP_Error.
	 */
	private function post_token( array $params ) {
		$resp = wp_remote_post(
			$this->endpoint( 'token_endpoint', '/oauth/token' ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => $params, // WP url-encodes an array body.
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			$msg = ( is_array( $data ) && ! empty( $data['error_description'] ) ) ? $data['error_description'] : sprintf( 'Token endpoint returned %d', $code );
			return new \WP_Error( 'razuna_token', $msg );
		}
		return is_array( $data ) ? $data : new \WP_Error( 'razuna_token', __( 'Invalid token response.', 'razuna-dam' ) );
	}

	/**
	 * Base64url encode bytes without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes random PKCE/state bytes.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Redirect back to settings with an error message.
	 *
	 * @param string $message Error message.
	 */
	private function redirect_with_error( string $message ): void {
		wp_safe_redirect( add_query_arg( 'razuna_msg', rawurlencode( $message ), admin_url( 'options-general.php?page=razuna' ) ) );
		exit;
	}
}
