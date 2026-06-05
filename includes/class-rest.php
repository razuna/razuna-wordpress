<?php
/**
 * Same-origin REST proxy the editor JS talks to.
 *
 * The browser never receives the Razuna OAuth token: it calls these WP routes
 * (authenticated by the logged-in editor + nonce), and the server forwards to
 * Razuna using the stored token.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'razuna/v1';

	/** @var Settings */
	private $settings;

	/** @var OAuth */
	private $oauth;

	/** @var Api */
	private $api;

	public function __construct( Settings $settings, OAuth $oauth, Api $api ) {
		$this->settings = $settings;
		$this->oauth    = $oauth;
		$this->api      = $api;
	}

	public function register_routes(): void {
		$args_ws = array(
			'methods'             => 'GET',
			'permission_callback' => array( $this, 'can_use' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'status' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workspaces',
			array_merge( $args_ws, array( 'callback' => array( $this, 'workspaces' ) ) )
		);

		register_rest_route(
			self::NAMESPACE,
			'/folders',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'folders' ),
				'args'                => array(
					'workspace_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/files',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'files' ),
				'args'                => array(
					'workspace_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'folder_id'    => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'page'         => array( 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page'     => array( 'required' => false, 'default' => 25, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/formats',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'formats' ),
				'args'                => array(
					'file_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'search' ),
				'args'                => array(
					'workspace_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'term'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'folder_id'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'page'         => array( 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page'     => array( 'required' => false, 'default' => 25, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Only logged-in users who can add media may browse Razuna. The REST cookie
	 * nonce is validated by core when X-WP-Nonce is present.
	 */
	public function can_use(): bool {
		return current_user_can( 'upload_files' );
	}

	public function status(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'connected' => $this->settings->is_connected(),
				'server'    => $this->settings->get_server_url(),
			)
		);
	}

	public function workspaces() {
		return $this->respond( $this->api->get_workspaces() );
	}

	public function folders( \WP_REST_Request $req ) {
		return $this->respond( $this->api->get_folders( (string) $req->get_param( 'workspace_id' ) ) );
	}

	public function files( \WP_REST_Request $req ) {
		return $this->respond(
			$this->api->get_folder_content(
				(string) $req->get_param( 'workspace_id' ),
				(string) $req->get_param( 'folder_id' ),
				(int) $req->get_param( 'page' ),
				(int) $req->get_param( 'per_page' )
			)
		);
	}

	public function formats( \WP_REST_Request $req ) {
		return $this->respond( $this->api->get_file_formats( (string) $req->get_param( 'file_id' ) ) );
	}

	public function search( \WP_REST_Request $req ) {
		return $this->respond(
			$this->api->search(
				(string) $req->get_param( 'workspace_id' ),
				(string) $req->get_param( 'term' ),
				(string) $req->get_param( 'folder_id' ),
				(int) $req->get_param( 'page' ),
				(int) $req->get_param( 'per_page' )
			)
		);
	}

	/**
	 * Convert an Api result (array|WP_Error) into a REST response, mapping the
	 * "not connected" case to a 409 so the UI can prompt to connect.
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 500 );
			if ( 'razuna_not_connected' === $result->get_error_code() ) {
				$status = 409;
			}
			return new \WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status ? $status : 500
			);
		}
		if ( is_array( $result ) && array_key_exists( 'items', $result ) ) {
			return rest_ensure_response( $result );
		}
		return rest_ensure_response( array( 'items' => $result ) );
	}
}
