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

		register_rest_route(
			self::NAMESPACE,
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'import' ),
				'args'                => array(
					'file_id'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'variant'     => array( 'required' => false, 'default' => 'full', 'sanitize_callback' => 'sanitize_text_field' ),
					'format_id'   => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'as_download' => array( 'required' => false, 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'post_id'     => array( 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
					'reuse'       => array( 'required' => false, 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => array( $this, 'imports' ),
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

	public function import( \WP_REST_Request $req ) {
		$result = $this->import_item(
			array(
				'file_id'     => (string) $req->get_param( 'file_id' ),
				'variant'     => (string) $req->get_param( 'variant' ),
				'format_id'   => (string) $req->get_param( 'format_id' ),
				'as_download' => (bool) $req->get_param( 'as_download' ),
				'post_id'     => (int) $req->get_param( 'post_id' ),
				'reuse'       => (bool) $req->get_param( 'reuse' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return rest_ensure_response( $result );
	}

	public function imports( \WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $req->get_params();
		}

		$items   = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$reuse   = ! array_key_exists( 'reuse', $params ) || rest_sanitize_boolean( $params['reuse'] );
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		$out     = array();
		$errors  = array();

		foreach ( $items as $index => $item ) {
			$item = is_array( $item ) ? $item : array();
			$result = $this->import_item(
				array(
					'file_id'     => isset( $item['file_id'] ) ? sanitize_text_field( (string) $item['file_id'] ) : '',
					'variant'     => isset( $item['variant'] ) ? sanitize_text_field( (string) $item['variant'] ) : 'full',
					'format_id'   => isset( $item['format_id'] ) ? sanitize_text_field( (string) $item['format_id'] ) : '',
					'as_download' => ! empty( $item['as_download'] ),
					'post_id'     => isset( $item['post_id'] ) ? absint( $item['post_id'] ) : $post_id,
					'reuse'       => array_key_exists( 'reuse', $item ) ? rest_sanitize_boolean( $item['reuse'] ) : $reuse,
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'file_id' => isset( $item['file_id'] ) ? (string) $item['file_id'] : '',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$out[] = $result;
		}

		return rest_ensure_response(
			array(
				'items'  => $out,
				'errors' => $errors,
			)
		);
	}

	private function import_item( array $args ) {
		$file_id = isset( $args['file_id'] ) ? trim( (string) $args['file_id'] ) : '';
		if ( '' === $file_id ) {
			return new \WP_Error( 'razuna_missing_file_id', __( 'Missing Razuna file ID.', 'razuna-dam' ), array( 'status' => 400 ) );
		}

		$file = $this->api->get_file( $file_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$variant = isset( $args['variant'] ) ? trim( (string) $args['variant'] ) : 'full';
		$variant = '' !== $variant ? $variant : 'full';
		$format_id = isset( $args['format_id'] ) ? trim( (string) $args['format_id'] ) : '';
		$as_download = ! empty( $args['as_download'] );
		$variant_key = $this->variant_key( $variant, $format_id, $as_download );

		if ( ! empty( $args['reuse'] ) ) {
			$existing = $this->find_existing_import( $file_id, $variant_key );
			if ( $existing > 0 ) {
				return $this->attachment_payload( $existing, true );
			}
		}

		$source = $this->source_for_import( $file, $variant, $format_id, $as_download );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$attachment_id = $this->sideload_file( $file, $source['url'], (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_razuna_file_id', $file_id );
		update_post_meta( $attachment_id, '_razuna_variant', $variant_key );
		update_post_meta( $attachment_id, '_razuna_source_url', esc_url_raw( $source['url'] ) );
		update_post_meta( $attachment_id, '_razuna_full_url', isset( $file['full_url'] ) ? esc_url_raw( (string) $file['full_url'] ) : '' );
		update_post_meta( $attachment_id, '_razuna_original_name', isset( $file['name'] ) ? sanitize_text_field( (string) $file['name'] ) : '' );
		update_post_meta( $attachment_id, '_razuna_content_type', isset( $file['content_type'] ) ? sanitize_text_field( (string) $file['content_type'] ) : '' );
		update_post_meta( $attachment_id, '_razuna_server_url', esc_url_raw( $this->settings->get_server_url() ) );
		update_post_meta( $attachment_id, '_razuna_imported_at', gmdate( 'c' ) );

		return $this->attachment_payload( $attachment_id, false );
	}

	private function variant_key( string $variant, string $format_id, bool $as_download ): string {
		$key = 'format' === $variant && '' !== $format_id ? 'format:' . $format_id : $variant;
		if ( $as_download ) {
			$key .= ':download';
		}
		return sanitize_key( str_replace( ':', '-', $key ) );
	}

	private function find_existing_import( string $file_id, string $variant_key ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_razuna_file_id',
						'value' => $file_id,
					),
					array(
						'key'   => '_razuna_variant',
						'value' => $variant_key,
					),
				),
			)
		);

		return ! empty( $posts[0] ) ? (int) $posts[0] : 0;
	}

	private function source_for_import( array $file, string $variant, string $format_id, bool $as_download ) {
		if ( 'format' === $variant && '' !== $format_id ) {
			$formats = $this->api->get_file_formats( (string) $file['id'] );
			if ( is_wp_error( $formats ) ) {
				return $formats;
			}
			foreach ( $formats as $format ) {
				if ( ! is_array( $format ) || (string) ( $format['id'] ?? '' ) !== $format_id ) {
					continue;
				}
				$download_url = (string) ( $format['download_url'] ?? '' );
				$view_url     = (string) ( $format['view_url'] ?? '' );
				if ( $as_download ) {
					$url = '' !== $download_url ? $download_url : $view_url;
				} else {
					$url = '' !== $view_url ? $view_url : $download_url;
				}
				if ( '' !== $url ) {
					return array( 'url' => $url );
				}
			}
			return new \WP_Error( 'razuna_format_not_found', __( 'The selected Razuna format was not found.', 'razuna-dam' ), array( 'status' => 404 ) );
		}

		if ( $as_download && ! empty( $file['download_url'] ) ) {
			return array( 'url' => (string) $file['download_url'] );
		}
		if ( 'thumb' === $variant && ! empty( $file['thumb_url'] ) ) {
			return array( 'url' => (string) $file['thumb_url'] );
		}
		if ( 'large' === $variant && ! empty( $file['preview_url'] ) ) {
			return array( 'url' => (string) $file['preview_url'] );
		}
		if ( ! empty( $file['full_url'] ) ) {
			return array( 'url' => (string) $file['full_url'] );
		}
		if ( ! empty( $file['preview_url'] ) ) {
			return array( 'url' => (string) $file['preview_url'] );
		}
		if ( ! empty( $file['download_url'] ) ) {
			return array( 'url' => (string) $file['download_url'] );
		}
		return new \WP_Error( 'razuna_no_import_url', __( 'Razuna did not return an importable URL for this file.', 'razuna-dam' ), array( 'status' => 422 ) );
	}

	private function sideload_file( array $file, string $source_url, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $source_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = isset( $file['filename'] ) && '' !== $file['filename'] ? (string) $file['filename'] : (string) ( $file['name'] ?? $file['id'] );
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			$name = sanitize_file_name( (string) $file['id'] );
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$post_data = array(
			'post_title'   => sanitize_text_field( preg_replace( '/\.[^.]+$/', '', (string) ( $file['name'] ?? $name ) ) ),
			'post_content' => isset( $file['description'] ) ? wp_kses_post( (string) $file['description'] ) : '',
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
		}
		return $attachment_id;
	}

	private function attachment_payload( int $attachment_id, bool $reused ): array {
		$payload = wp_prepare_attachment_for_js( $attachment_id );
		if ( ! is_array( $payload ) ) {
			$payload = array(
				'id'    => $attachment_id,
				'url'   => wp_get_attachment_url( $attachment_id ),
				'title' => get_the_title( $attachment_id ),
			);
		}
		$payload['reused'] = $reused;
		$payload['razuna'] = array(
			'file_id'    => (string) get_post_meta( $attachment_id, '_razuna_file_id', true ),
			'variant'    => (string) get_post_meta( $attachment_id, '_razuna_variant', true ),
			'source_url' => (string) get_post_meta( $attachment_id, '_razuna_source_url', true ),
		);
		return $payload;
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
