<?php
/**
 * Plugin Name: Razuna Local Dev Helpers
 * Description: LOCAL DEVELOPMENT ONLY. Relaxes SSL verification so WordPress can
 *              talk to a local Razuna dev server (self-signed / http r.lan), and
 *              surfaces outbound HTTP errors. Do NOT ship this to production.
 *
 * @package Razuna
 */

defined( 'ABSPATH' ) || exit;

// Allow http / self-signed TLS to the local Razuna host only.
add_filter(
	'http_request_args',
	function ( $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'r.lan', 'mcp.r.lan', 'localhost', '127.0.0.1' ), true ) ) {
			$args['sslverify'] = false;
			$args['reject_unsafe_urls'] = false;
		}
		return $args;
	},
	10,
	2
);

// Log outbound HTTP failures to debug.log to aid OAuth/API troubleshooting.
add_action(
	'http_api_debug',
	function ( $response, $context, $class, $args, $url ) {
		if ( is_wp_error( $response ) && false !== strpos( (string) $url, '/oauth' ) ) {
			error_log( '[razuna-dev] HTTP error for ' . $url . ': ' . $response->get_error_message() );
		}
	},
	10,
	5
);
