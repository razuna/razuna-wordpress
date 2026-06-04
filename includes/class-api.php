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

final class Api {

	/** @var Settings */
	private $settings;

	/** @var OAuth */
	private $oauth;

	public function __construct( Settings $settings, OAuth $oauth ) {
		$this->settings = $settings;
		$this->oauth    = $oauth;
	}

	/* --------------------------------------------------------------------- *
	 * High-level operations
	 * --------------------------------------------------------------------- */

	/**
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
				'id'   => isset( $ws['_id'] ) ? $ws['_id'] : ( isset( $ws['id'] ) ? $ws['id'] : '' ),
				'name' => isset( $ws['name'] ) ? $ws['name'] : '',
			);
		}
		return $out;
	}

	/**
	 * Flattened folder list for a workspace: { id, name, path, depth }.
	 *
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
	 * @return array|\WP_Error
	 */
	public function get_folder_content( string $workspace_id, string $folder_id = '' ) {
		// folder_id is optional: an empty value lists the workspace root.
		$query = array( 'workspace_id' => $workspace_id );
		if ( '' !== $folder_id ) {
			$query['folder_id'] = $folder_id;
		}
		$data = $this->request( 'GET', '/api/v1/files/folder/content', array( 'query' => $query ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$files = $this->extract_files( $data );
		return array_map( array( $this, 'normalize_file' ), $files );
	}

	/**
	 * Saved image download formats for a file (the recipes created in Razuna).
	 * Each format's URL is the authenticated /transform endpoint, so it is only
	 * usable with a token — not a durable public link. Returned for completeness;
	 * durable embedding still uses the direct links from normalize_file().
	 *
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
			$view     = isset( $f['view_url'] ) ? $this->absolute( (string) $f['view_url'] ) : '';
			$download = isset( $f['download_url'] ) ? $this->absolute( (string) $f['download_url'] ) : '';
			$out[]    = array(
				'id'           => isset( $f['id'] ) ? $f['id'] : ( isset( $f['_id'] ) ? $f['_id'] : '' ),
				'name'         => isset( $f['name'] ) ? $f['name'] : '',
				'format'       => isset( $f['format'] ) ? $f['format'] : '',
				'width'        => isset( $f['width'] ) ? (int) $f['width'] : 0,
				'height'       => isset( $f['height'] ) ? (int) $f['height'] : 0,
				'view_url'     => '' !== $view ? $view : $download,
				'download_url' => '' !== $download ? $download : $view,
			);
		}
		return $out;
	}

	/**
	 * Semantic search across a workspace (optionally scoped to a folder).
	 *
	 * @return array|\WP_Error
	 */
	public function search( string $workspace_id, string $term, string $folder_id = '' ) {
		$body = array(
			'term'         => $term,
			'workspace_id' => $workspace_id,
		);
		if ( '' !== $folder_id ) {
			$body['folder_id'] = $folder_id;
		}
		$data = $this->request( 'POST', '/api/v1/files/search/semantic', array( 'json' => $body ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$files = $this->extract_files( $data );
		return array_map( array( $this, 'normalize_file' ), $files );
	}

	/**
	 * Single file detail, normalized.
	 *
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

	/* --------------------------------------------------------------------- *
	 * Normalization
	 * --------------------------------------------------------------------- */

	/**
	 * Map a raw Razuna file object to the stable picker shape. Picks durable
	 * direct links (direct_links || urls) so embedded URLs survive token expiry.
	 */
	public function normalize_file( $f ): array {
		$f     = is_array( $f ) ? $f : array();
		$links = array();
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
			'id'           => isset( $f['_id'] ) ? $f['_id'] : ( isset( $f['id'] ) ? $f['id'] : '' ),
			'name'         => ! empty( $f['name'] ) ? $f['name'] : ( ! empty( $f['original_name'] ) ? $f['original_name'] : ( isset( $f['_id'] ) ? (string) $f['_id'] : '' ) ),
			'extension'    => isset( $f['extension'] ) ? $f['extension'] : '',
			'content_type' => $content_type,
			'is_image'     => $is_image,
			'size'         => isset( $f['size'] ) ? (int) $f['size'] : 0,
			'width'        => isset( $f['width'] ) ? (int) $f['width'] : 0,
			'height'       => isset( $f['height'] ) ? (int) $f['height'] : 0,
			'description'  => isset( $f['description'] ) ? $f['description'] : '',
			'thumb_url'    => $thumb,
			'preview_url'  => $large,
			'full_url'     => $full,
			'download_url' => $this->link( $links, array( 'url_dl', 'url' ) ),
		);
	}

	/**
	 * First non-empty, absolute URL among the given keys.
	 */
	private function link( array $links, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! empty( $links[ $key ] ) ) {
				return $this->absolute( (string) $links[ $key ] );
			}
		}
		return '';
	}

	private function absolute( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return $this->settings->get_public_url() . '/' . ltrim( $url, '/' );
	}

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

	private function flatten_tree( array $tree, string $path = '', int $depth = 0 ): array {
		// Collect this level's folders and sort siblings alphabetically (the tree
		// API returns upper levels unsorted).
		$entries = array();
		foreach ( $tree as $folder_id => $node ) {
			if ( is_array( $node ) && ! empty( $node['name'] ) ) {
				$entries[] = array( 'id' => (string) $folder_id, 'node' => $node );
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

	/* --------------------------------------------------------------------- *
	 * Transport
	 * --------------------------------------------------------------------- */

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
