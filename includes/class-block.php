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

/**
 * Registers and renders the Razuna Asset block.
 */
final class Block {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Razuna API client.
	 *
	 * @var Api
	 */
	private $api;

	/**
	 * Build the block service.
	 *
	 * @param Settings $settings Settings service.
	 * @param Api      $api Razuna API client.
	 */
	public function __construct( Settings $settings, Api $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Register block hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * Register the dynamic block type.
	 */
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
					'fileId'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'url'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'fullUrl'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'alt'            => array(
						'type'    => 'string',
						'default' => '',
					),
					'name'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'width'          => array(
						'type'    => 'number',
						'default' => 0,
					),
					'height'         => array(
						'type'    => 'number',
						'default' => 0,
					),
					'isImage'        => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'linkToOriginal' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'align'          => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
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
			$dims             = '';
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

	/**
	 * Determine final rendered image dimensions.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $url Image URL.
	 */
	private function image_dimensions( array $attributes, string $url ): array {
		$width  = ! empty( $attributes['width'] ) ? (int) $attributes['width'] : 0;
		$height = ! empty( $attributes['height'] ) ? (int) $attributes['height'] : 0;
		$format = $this->format_dimensions( $attributes, $url );

		if ( $format[0] > 0 || $format[1] > 0 ) {
			return $this->complete_dimensions( $format[0], $format[1], $width, $height );
		}

		$max = $this->direct_link_max_dimension( $url );

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

	/**
	 * Complete one missing dimension from the original aspect ratio.
	 *
	 * @param int $width Requested width.
	 * @param int $height Requested height.
	 * @param int $original_width Original width.
	 * @param int $original_height Original height.
	 */
	private function complete_dimensions( int $width, int $height, int $original_width, int $original_height ): array {
		if ( $width > 0 && $height > 0 ) {
			return array( $width, $height );
		}

		if ( $original_width <= 0 || $original_height <= 0 ) {
			return array( $width, $height );
		}

		if ( $width > 0 ) {
			return array( $width, max( 1, (int) round( $width * $original_height / $original_width ) ) );
		}
		if ( $height > 0 ) {
			return array( max( 1, (int) round( $height * $original_width / $original_height ) ), $height );
		}
		return array( 0, 0 );
	}

	/**
	 * Return dimensions for the selected saved format.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $url Image URL.
	 */
	private function format_dimensions( array $attributes, string $url ): array {
		static $formats_by_file = array();

		$params    = $this->direct_link_params( $url );
		$format_id = isset( $params['format_id'] ) ? trim( (string) $params['format_id'] ) : '';
		$file_id   = isset( $attributes['fileId'] ) ? trim( (string) $attributes['fileId'] ) : '';

		if ( '' === $format_id || '' === $file_id || ! $this->settings->is_connected() ) {
			return array( 0, 0 );
		}

		if ( ! array_key_exists( $file_id, $formats_by_file ) ) {
			$formats                     = $this->api->get_file_formats( $file_id );
			$formats_by_file[ $file_id ] = is_wp_error( $formats ) ? array() : $formats;
		}

		foreach ( $formats_by_file[ $file_id ] as $format ) {
			if ( ! is_array( $format ) || ! isset( $format['id'] ) || (string) $format['id'] !== $format_id ) {
				continue;
			}
			return array(
				! empty( $format['width'] ) ? (int) $format['width'] : 0,
				! empty( $format['height'] ) ? (int) $format['height'] : 0,
			);
		}

		return array( 0, 0 );
	}

	/**
	 * Infer max dimensions from direct-link parameters.
	 *
	 * @param string $url Direct-link URL.
	 */
	private function direct_link_max_dimension( string $url ): int {
		$params = $this->direct_link_params( $url );
		$type   = isset( $params['type'] ) ? strtolower( (string) $params['type'] ) : '';
		$format = isset( $params['f'] ) ? strtolower( (string) $params['f'] ) : '';

		if ( '' === $type && in_array( $format, array( 'tl', 'thumbnail_large' ), true ) ) {
			return 1200;
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

	/**
	 * Parse direct-link query parameters.
	 *
	 * @param string $url Direct-link URL.
	 */
	private function direct_link_params( string $url ): array {
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		$params = array();

		if ( is_string( $query ) && '' !== $query ) {
			wp_parse_str( $query, $params );
		}

		return $params;
	}
}
