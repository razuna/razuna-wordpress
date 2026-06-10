<?php
/**
 * Server-side Razuna REST API client (OAuth Bearer, auto-refresh on 401).
 *
 * All calls run on the WordPress server with the connected user's OAuth token,
 * which is never exposed to the browser. Responses are normalized into a stable
 * shape for the editor picker.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

/**
 * Razuna API client for server-side editor and render requests.
 */
final class Api {

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
	 * Build the API client.
	 *
	 * @param Settings $settings Settings service.
	 * @param OAuth    $oauth OAuth service.
	 */
	public function __construct( Settings $settings, OAuth $oauth ) {
		$this->settings = $settings;
		$this->oauth    = $oauth;
	}

	// High-level operations.

	/**
	 * Return Razuna workspaces.
	 *
	 * @return array|\WP_Error List of { id, name } workspaces.
	 */
	public function get_workspaces() {
		$data = $this->request( 'GET', '/api/v1/files/workspaces/user' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$list = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : ( is_array( $data ) ? $data : array() );
		$out  = array();
		foreach ( $list as $ws ) {
			if ( ! is_array( $ws ) ) {
				continue;
			}
			$out[] = array(
				'id'         => isset( $ws['_id'] ) ? $ws['_id'] : ( isset( $ws['id'] ) ? $ws['id'] : '' ),
				'name'       => isset( $ws['name'] ) ? $ws['name'] : '',
				'is_default' => ! empty( $ws['is_default'] ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				$a_default = ! empty( $a['is_default'] ) || 0 === strcasecmp( (string) $a['name'], 'My workspace' );
				$b_default = ! empty( $b['is_default'] ) || 0 === strcasecmp( (string) $b['name'], 'My workspace' );

				if ( $a_default !== $b_default ) {
					return $a_default ? -1 : 1;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Flattened folder list for a workspace: { id, name, path, depth }.
	 *
	 * @param string $workspace_id Workspace ID.
	 * @return array|\WP_Error
	 */
	public function get_folders( string $workspace_id ) {
		$data = $this->request( 'GET', '/api/v1/files/workspace/getfoldertree/' . rawurlencode( $workspace_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$tree = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
		return $this->flatten_tree( $tree );
	}

	/**
	 * Files within a folder, normalized for the picker.
	 *
	 * @param string $workspace_id Workspace ID.
	 * @param string $folder_id Folder ID.
	 * @param int    $page Page number.
	 * @param int    $per_page Page size.
	 * @return array|\WP_Error
	 */
	public function get_folder_content(
		string $workspace_id,
		string $folder_id = '',
		int $page = 1,
		int $per_page = 25
	) {
		// folder_id is optional: an empty value lists the workspace root.
		$page     = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );
		$query    = array(
			'workspace_id' => $workspace_id,
			'page'         => $page,
			'limit'        => $per_page,
		);
		if ( '' !== $folder_id ) {
			$query['folder_id'] = $folder_id;
		}
		$data = $this->request( 'GET', '/api/v1/files/folder/content', array( 'query' => $query ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$files = $this->extract_files( $data );
		return $this->paged_result( $data, $files, $page, $per_page );
	}

	/**
	 * Saved image download formats for a file (the recipes created in Razuna).
	 * Each format's URL is the authenticated /transform endpoint, so it is only
	 * usable with a token — not a durable public link. Returned for completeness;
	 * durable embedding still uses the direct links from normalize_file().
	 *
	 * @param string $file_id File ID.
	 * @return array|\WP_Error
	 */
	public function get_file_formats( string $file_id ) {
		$data = $this->request( 'GET', '/api/v1/files/file/' . rawurlencode( $file_id ) . '/formats' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$formats = ( isset( $data['formats'] ) && is_array( $data['formats'] ) ) ? $data['formats'] : array();
		$out     = array();
		foreach ( $formats as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$dimensions = $this->format_dimensions( $f );
			$view       = isset( $f['view_url'] ) ? $this->absolute( (string) $f['view_url'] ) : '';
			$download   = isset( $f['download_url'] ) ? $this->absolute( (string) $f['download_url'] ) : '';
			$out[]      = array(
				'id'           => isset( $f['id'] ) ? $f['id'] : ( isset( $f['_id'] ) ? $f['_id'] : '' ),
				'name'         => isset( $f['name'] ) ? $f['name'] : '',
				'format'       => isset( $f['format'] ) ? $f['format'] : '',
				'width'        => $dimensions[0],
				'height'       => $dimensions[1],
				'view_url'     => '' !== $view ? $view : $download,
				'download_url' => '' !== $download ? $download : $view,
			);
		}
		return $out;
	}

	/**
	 * Semantic search across a workspace (optionally scoped to a folder).
	 *
	 * @param string $workspace_id Workspace ID.
	 * @param string $term Search term.
	 * @param string $folder_id Folder ID.
	 * @param int    $page Page number.
	 * @param int    $per_page Page size.
	 * @return array|\WP_Error
	 */
	public function search(
		string $workspace_id,
		string $term,
		string $folder_id = '',
		int $page = 1,
		int $per_page = 25
	) {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );
		$body     = array(
			'term'         => $term,
			'workspace_id' => $workspace_id,
			'page'         => $page,
			'per_page'     => $per_page,
			'limit'        => $per_page,
		);
		if ( '' !== $folder_id ) {
			$body['folder_id'] = $folder_id;
		}
		$data = $this->request( 'POST', '/api/v1/files/search/semantic', array( 'json' => $body ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$files = $this->extract_files( $data );
		return $this->paged_result( $data, $files, $page, $per_page );
	}

	/**
	 * Single file detail, normalized.
	 *
	 * @param string $file_id File ID.
	 * @return array|\WP_Error
	 */
	public function get_file( string $file_id ) {
		$data = $this->request( 'GET', '/api/v1/files/file/' . rawurlencode( $file_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$file = isset( $data['file'] ) && is_array( $data['file'] ) ? $data['file'] : ( is_array( $data ) ? $data : array() );
		return $this->normalize_file( $file );
	}

	/**
	 * Lightweight connectivity check used by the settings page / health.
	 *
	 * @return true|\WP_Error
	 */
	public function ping() {
		$data = $this->request( 'GET', '/api/v1/files/workspaces/user' );
		return is_wp_error( $data ) ? $data : true;
	}

	// Normalization.

	/**
	 * Map a raw Razuna file object to the stable picker shape. Picks durable
	 * direct links (direct_links || urls) so embedded URLs survive token expiry.
	 *
	 * @param mixed $f Raw file object.
	 */
	public function normalize_file( $f ): array {
		$f         = is_array( $f ) ? $f : array();
		$file_id   = isset( $f['_id'] ) ? $f['_id'] : ( isset( $f['id'] ) ? $f['id'] : '' );
		$file_name = $this->string_field( $f, array( 'filename', 'file_name', 'original_filename', 'original_name', 'display_name', 'title', 'name' ) );
		$links     = array();
		if ( isset( $f['direct_links'] ) && is_array( $f['direct_links'] ) ) {
			$links = $f['direct_links'];
		} elseif ( isset( $f['urls'] ) && is_array( $f['urls'] ) ) {
			$links = $f['urls'];
		}

		$content_type = isset( $f['content_type'] ) ? (string) $f['content_type'] : '';
		$is_image     = 0 === strpos( $content_type, 'image/' );

		$full  = $this->link( $links, array( 'url' ) );
		$large = $this->link( $links, array( 'url_tl', 'url' ) );
		$thumb = $this->link( $links, array( 'url_t', 'url_tl', 'url' ) );

		return array(
			'id'           => $file_id,
			'name'         => '' !== $file_name ? $file_name : (string) $file_id,
			'filename'     => '' !== $file_name ? $file_name : (string) $file_id,
			'extension'    => isset( $f['extension'] ) ? $f['extension'] : '',
			'content_type' => $content_type,
			'is_image'     => $is_image,
			'size'         => isset( $f['size'] ) ? (int) $f['size'] : 0,
			'width'        => $this->image_width( $f ),
			'height'       => $this->image_height( $f ),
			'description'  => isset( $f['description'] ) ? $f['description'] : '',
			'thumb_url'    => $thumb,
			'preview_url'  => $large,
			'full_url'     => $full,
			'download_url' => $this->link( $links, array( 'url_dl', 'url' ) ),
		);
	}

	/**
	 * First non-empty, absolute URL among the given keys.
	 *
	 * @param array $links Direct-link map.
	 * @param array $keys Candidate keys.
	 */
	private function link( array $links, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! empty( $links[ $key ] ) ) {
				return $this->absolute( (string) $links[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Extract image width from known metadata fields.
	 *
	 * @param array $data File metadata.
	 */
	private function image_width( array $data ): int {
		$width = $this->int_field( $data, array( 'width', 'image_width', 'original_width', 'ImageWidth', 'w' ) );
		if ( $width > 0 ) {
			return $width;
		}

		$pixels = $this->pixels_dimensions( $data );
		if ( $pixels[0] > 0 ) {
			return $pixels[0];
		}

		$raw = $this->raw_json_dimensions( $data );
		return $raw[0];
	}

	/**
	 * Extract image height from known metadata fields.
	 *
	 * @param array $data File metadata.
	 */
	private function image_height( array $data ): int {
		$height = $this->int_field( $data, array( 'height', 'image_height', 'original_height', 'ImageHeight', 'h' ) );
		if ( $height > 0 ) {
			return $height;
		}

		$pixels = $this->pixels_dimensions( $data );
		if ( $pixels[1] > 0 ) {
			return $pixels[1];
		}

		$raw = $this->raw_json_dimensions( $data );
		return $raw[1];
	}

	/**
	 * Extract saved-format dimensions.
	 *
	 * @param array $data Format metadata.
	 */
	private function format_dimensions( array $data ): array {
		$width  = $this->int_field( $data, array( 'width', 'image_width', 'format_width', 'target_width', 'resize_width', 'w' ) );
		$height = $this->int_field( $data, array( 'height', 'image_height', 'format_height', 'target_height', 'resize_height', 'h' ) );

		if ( $width > 0 && $height > 0 ) {
			return array( $width, $height );
		}

		foreach ( array( 'label', 'name' ) as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}
			if ( preg_match( '/(\\d+)\\s*[x×]\\s*(\\d+)/i', $data[ $key ], $matches ) ) {
				return array( (int) $matches[1], (int) $matches[2] );
			}
		}

		return array( $width, $height );
	}

	/**
	 * Parse a "123x456" pixels field.
	 *
	 * @param array $data File metadata.
	 */
	private function pixels_dimensions( array $data ): array {
		if ( empty( $data['pixels'] ) || ! is_string( $data['pixels'] ) ) {
			return array( 0, 0 );
		}
		if ( preg_match( '/(\\d+)\\s*[x×]\\s*(\\d+)/i', $data['pixels'], $matches ) ) {
			return array( (int) $matches[1], (int) $matches[2] );
		}
		return array( 0, 0 );
	}

	/**
	 * Extract dimensions from raw JSON metadata.
	 *
	 * @param array $data File metadata.
	 */
	private function raw_json_dimensions( array $data ): array {
		if ( empty( $data['raw_json'] ) || ! is_string( $data['raw_json'] ) ) {
			return array( 0, 0 );
		}
		$raw = json_decode( $data['raw_json'], true );
		if ( ! is_array( $raw ) ) {
			return array( 0, 0 );
		}
		$width  = $this->int_field( $raw, array( 'ImageWidth', 'image_width', 'width' ) );
		$height = $this->int_field( $raw, array( 'ImageHeight', 'image_height', 'height' ) );
		return array( $width, $height );
	}

	/**
	 * Return the first non-empty string field.
	 *
	 * @param array $data Source data.
	 * @param array $keys Candidate keys.
	 */
	private function string_field( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Return the first numeric integer field.
	 *
	 * @param array $data Source data.
	 * @param array $keys Candidate keys.
	 */
	private function int_field( array $data, array $keys ): int {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				return (int) $data[ $key ];
			}
		}
		return 0;
	}

	/**
	 * Convert a possibly relative Razuna URL to absolute.
	 *
	 * @param string $url URL.
	 */
	private function absolute( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return $this->settings->get_public_url() . '/' . ltrim( $url, '/' );
	}

	/**
	 * Extract raw file items from variant response shapes.
	 *
	 * @param array $data API response.
	 */
	private function extract_files( array $data ): array {
		if ( isset( $data['results']['files'] ) && is_array( $data['results']['files'] ) ) {
			return $data['results']['files'];
		}
		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			return $data['files'];
		}
		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			return $data['results'];
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build the normalized paged response.
	 *
	 * @param array $data API response.
	 * @param array $files Raw files.
	 * @param int   $page Page number.
	 * @param int   $per_page Page size.
	 */
	private function paged_result( array $data, array $files, int $page, int $per_page ): array {
		$total      = $this->extract_total( $data );
		$has_total  = null !== $total;
		$total      = $has_total ? $total : ( ( max( 1, $page ) - 1 ) * max( 1, $per_page ) ) + count( $files );
		$api_page   = isset( $data['results']['page'] )
			? (int) $data['results']['page']
			: ( isset( $data['page'] ) ? (int) $data['page'] : $page );
		$api_limit  = isset( $data['results']['per_page'] )
			? (int) $data['results']['per_page']
			: ( isset( $data['per_page'] ) ? (int) $data['per_page'] : $per_page );
		$safe_page  = max( 1, $api_page );
		$safe_limit = max( 1, $api_limit );
		$items      = array_map( array( $this, 'normalize_file' ), $files );
		$has_more   = $has_total
			? $total > ( $safe_page * $safe_limit )
			: count( $files ) === $safe_limit;

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $safe_page,
			'per_page' => $safe_limit,
			'has_more' => $has_more,
		);
	}

	/**
	 * Extract a total count from response metadata.
	 *
	 * @param array $data API response.
	 */
	private function extract_total( array $data ): ?int {
		if ( isset( $data['results']['total'] ) ) {
			return (int) $data['results']['total'];
		}
		if ( isset( $data['total'] ) ) {
			return (int) $data['total'];
		}
		if ( isset( $data['found'] ) ) {
			return (int) $data['found'];
		}
		return null;
	}

	/**
	 * Flatten a nested folder tree.
	 *
	 * @param array  $tree Folder tree.
	 * @param string $path Parent path.
	 * @param int    $depth Current depth.
	 */
	private function flatten_tree( array $tree, string $path = '', int $depth = 0 ): array {
		// Collect this level's folders and sort siblings alphabetically (the tree
		// API returns upper levels unsorted).
		$entries = array();
		foreach ( $tree as $folder_id => $node ) {
			if ( is_array( $node ) && ! empty( $node['name'] ) ) {
				$entries[] = array(
					'id'   => (string) $folder_id,
					'node' => $node,
				);
			}
		}
		usort(
			$entries,
			function ( $a, $b ) {
				return strcasecmp( (string) $a['node']['name'], (string) $b['node']['name'] );
			}
		);

		$out = array();
		foreach ( $entries as $entry ) {
			$node        = $entry['node'];
			$folder_path = '' === $path ? $node['name'] : $path . ' / ' . $node['name'];
			$out[]       = array(
				'id'    => $entry['id'],
				'name'  => (string) $node['name'],
				'path'  => $folder_path,
				'depth' => isset( $node['depth'] ) ? (int) $node['depth'] : $depth,
			);
			if ( ! empty( $node['subfolders'] ) && is_array( $node['subfolders'] ) ) {
				$out = array_merge( $out, $this->flatten_tree( $node['subfolders'], $folder_path, $depth + 1 ) );
			}
		}
		return $out;
	}

	// Transport.

	/**
	 * Make an authenticated request, refreshing the token once on 401.
	 *
	 * @param string $method GET|POST|PUT|DELETE.
	 * @param string $path   API path beginning with '/'.
	 * @param array  $args   { query?: array, json?: array }.
	 * @return array|\WP_Error
	 */
	public function request( string $method, string $path, array $args = array() ) {
		$result = $this->dispatch( $method, $path, $args, false );
		if ( is_wp_error( $result ) && 'razuna_unauthorized' === $result->get_error_code() ) {
			// Token may have just expired between check and use: refresh + retry once.
			$result = $this->dispatch( $method, $path, $args, true );
		}
		return $result;
	}

	/**
	 * Dispatch one HTTP request to Razuna.
	 *
	 * @param string $method HTTP method.
	 * @param string $path API path.
	 * @param array  $args Request arguments.
	 * @param bool   $force_refresh Whether to force token refresh.
	 * @return array|\WP_Error
	 */
	private function dispatch( string $method, string $path, array $args, bool $force_refresh ) {
		$token = $this->oauth->get_access_token();
		if ( '' === $token ) {
			return new \WP_Error( 'razuna_not_connected', __( 'Razuna is not connected.', 'razuna-dam' ), array( 'status' => 401 ) );
		}

		$url = $this->settings->get_server_url() . $path;
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		$request = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( isset( $args['json'] ) ) {
			$request['headers']['Content-Type'] = 'application/json';
			$request['body']                    = wp_json_encode( $args['json'] );
		}

		$resp = wp_remote_request( $url, $request );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );

		if ( 401 === $code && ! $force_refresh ) {
			return new \WP_Error( 'razuna_unauthorized', __( 'Unauthorized.', 'razuna-dam' ), array( 'status' => 401 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : ( ( is_array( $data ) && ! empty( $data['error'] ) ) ? $data['error'] : sprintf( 'Razuna API error (%d)', $code ) );
			return new \WP_Error( 'razuna_api', $msg, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
