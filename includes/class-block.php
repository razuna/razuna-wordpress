<?php
/**
 * "Razuna Asset" dynamic block: server registration + render.
 *
 * Renders the stored durable direct-link URL. If the stored URL is empty but a
 * file id is present (and we are still connected), the URL is refreshed from the
 * API — useful if a link is regenerated.
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

final class Block {

	/** @var Settings */
	private $settings;

	/** @var Api */
	private $api;

	public function __construct( Settings $settings, Api $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'razuna/asset',
			array(
				'api_version'     => 2,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'fileId'         => array( 'type' => 'string', 'default' => '' ),
					'url'            => array( 'type' => 'string', 'default' => '' ),
					'fullUrl'        => array( 'type' => 'string', 'default' => '' ),
					'alt'            => array( 'type' => 'string', 'default' => '' ),
					'name'           => array( 'type' => 'string', 'default' => '' ),
					'width'          => array( 'type' => 'number', 'default' => 0 ),
					'height'         => array( 'type' => 'number', 'default' => 0 ),
					'isImage'        => array( 'type' => 'boolean', 'default' => true ),
					'linkToOriginal' => array( 'type' => 'boolean', 'default' => false ),
					'align'          => array( 'type' => 'string' ),
				),
			)
		);
	}

	public function enqueue_editor(): void {
		Plugin::register_picker_asset();

		wp_enqueue_style( 'razuna-admin' );
		wp_enqueue_script( 'razuna-picker' );

		wp_enqueue_script(
			'razuna-block',
			RAZUNA_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'razuna-picker' ),
			RAZUNA_VERSION,
			true
		);
	}

	/**
	 * Server render.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$url        = isset( $attributes['url'] ) ? (string) $attributes['url'] : '';
		$alt        = isset( $attributes['alt'] ) ? (string) $attributes['alt'] : '';
		$file_id    = isset( $attributes['fileId'] ) ? (string) $attributes['fileId'] : '';
		$is_image   = ! isset( $attributes['isImage'] ) || $attributes['isImage'];
		$full_url   = isset( $attributes['fullUrl'] ) ? (string) $attributes['fullUrl'] : '';

		// Refresh a missing URL from the API when possible.
		if ( '' === $url && '' !== $file_id && $this->settings->is_connected() ) {
			$file = $this->api->get_file( $file_id );
			if ( ! is_wp_error( $file ) ) {
				$url      = ! empty( $file['preview_url'] ) ? $file['preview_url'] : ( ! empty( $file['full_url'] ) ? $file['full_url'] : '' );
				$full_url = ! empty( $file['full_url'] ) ? $file['full_url'] : $full_url;
				$alt      = '' !== $alt ? $alt : ( isset( $file['name'] ) ? $file['name'] : '' );
				$is_image = isset( $file['is_image'] ) ? (bool) $file['is_image'] : $is_image;
			}
		}

		if ( '' === $url ) {
			return '';
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'razuna-asset' ) )
			: 'class="razuna-asset"';

		if ( $is_image ) {
			$dims = '';
			$image_dimensions = $this->image_dimensions( $attributes, $url );
			if ( $image_dimensions[0] > 0 ) {
				$dims .= ' width="' . $image_dimensions[0] . '"';
			}
			if ( $image_dimensions[1] > 0 ) {
				$dims .= ' height="' . $image_dimensions[1] . '"';
			}
			$inner = sprintf(
				'<img src="%s" alt="%s"%s loading="lazy" />',
				esc_url( $url ),
				esc_attr( $alt ),
				$dims // already integer-cast.
			);
			if ( ! empty( $attributes['linkToOriginal'] ) && '' !== $full_url ) {
				$inner = sprintf( '<a href="%s">%s</a>', esc_url( $full_url ), $inner );
			}
		} else {
			$label = '' !== $alt ? $alt : ( isset( $attributes['name'] ) ? (string) $attributes['name'] : $url );
			$inner = sprintf( '<a href="%s">%s</a>', esc_url( $full_url ? $full_url : $url ), esc_html( $label ) );
		}

		return sprintf( '<figure %s>%s</figure>', $wrapper, $inner );
	}

	private function image_dimensions( array $attributes, string $url ): array {
		$width  = ! empty( $attributes['width'] ) ? (int) $attributes['width'] : 0;
		$height = ! empty( $attributes['height'] ) ? (int) $attributes['height'] : 0;
		$max    = $this->direct_link_max_dimension( $url );

		if ( $max <= 0 || $width <= 0 || $height <= 0 ) {
			return array( $width, $height );
		}

		$longest = max( $width, $height );
		if ( $longest <= $max ) {
			return array( $width, $height );
		}

		$scale = $max / $longest;
		return array(
			max( 1, (int) round( $width * $scale ) ),
			max( 1, (int) round( $height * $scale ) ),
		);
	}

	private function direct_link_max_dimension( string $url ): int {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$type  = '';

		if ( is_string( $query ) && '' !== $query ) {
			$params = array();
			wp_parse_str( $query, $params );
			$type = isset( $params['type'] ) ? strtolower( (string) $params['type'] ) : '';
		}

		if ( '' === $type ) {
			return 0;
		}

		$type_key = str_replace( array( '-', '_', ' ' ), '', $type );
		if ( in_array( $type_key, array( 'large', 'thumbnaillarge', 'largethumbnail', 'preview', 'tl' ), true ) ) {
			return 1200;
		}
		if ( in_array( $type_key, array( 'thumbnail', 'thumb', 'small', 't' ), true ) ) {
			return 400;
		}
		if ( false !== strpos( $type_key, 'large' ) ) {
			return 1200;
		}
		if ( false !== strpos( $type_key, 'thumbnail' ) || false !== strpos( $type_key, 'thumb' ) ) {
			return 400;
		}
		return 0;
	}
}
