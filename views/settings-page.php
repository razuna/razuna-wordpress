<?php
/**
 * Settings page markup.
 *
 * @package Razuna
 *
 * @var string $region
 * @var string $server_url
 * @var bool   $connected
 * @var array  $conn
 * @var string $msg
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap razuna-settings">
	<h1><?php esc_html_e( 'Razuna DAM', 'razuna' ); ?></h1>

	<?php if ( 'saved' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'razuna' ); ?></p></div>
	<?php elseif ( 'connected' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to Razuna.', 'razuna' ); ?></p></div>
	<?php elseif ( 'disconnected' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Disconnected from Razuna.', 'razuna' ); ?></p></div>
	<?php elseif ( '' !== $msg ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( '1. Choose your Razuna server', 'razuna' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="razuna_save_settings" />
		<?php wp_nonce_field( 'razuna_save_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="razuna-region"><?php esc_html_e( 'Region', 'razuna' ); ?></label></th>
				<td>
					<select id="razuna-region" name="region">
						<option value="us" <?php selected( $region, 'us' ); ?>><?php esc_html_e( 'US (app.razuna.com)', 'razuna' ); ?></option>
						<option value="eu" <?php selected( $region, 'eu' ); ?>><?php esc_html_e( 'EU (app.razuna.eu)', 'razuna' ); ?></option>
						<option value="custom" <?php selected( $region, 'custom' ); ?>><?php esc_html_e( 'Custom / dedicated server', 'razuna' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="razuna-custom-row">
				<th scope="row"><label for="razuna-server-url"><?php esc_html_e( 'Server URL', 'razuna' ); ?></label></th>
				<td>
					<input type="url" id="razuna-server-url" name="server_url" class="regular-text" value="<?php echo esc_attr( $server_url ); ?>" placeholder="https://dam.example.com" />
					<p class="description"><?php esc_html_e( 'Only for a custom / dedicated Razuna server.', 'razuna' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save server settings', 'razuna' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( '2. Connect your account', 'razuna' ); ?></h2>
	<?php if ( $connected ) : ?>
		<p class="razuna-status razuna-status--connected">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php
			printf(
				/* translators: %s: connected user email */
				esc_html__( 'Connected as %s', 'razuna' ),
				'<strong>' . esc_html( isset( $conn['user_email'] ) ? $conn['user_email'] : __( 'your Razuna account', 'razuna' ) ) . '</strong>'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="razuna_oauth_disconnect" />
			<?php wp_nonce_field( 'razuna_oauth_disconnect' ); ?>
			<?php submit_button( __( 'Disconnect', 'razuna' ), 'delete', 'submit', false ); ?>
		</form>
	<?php else : ?>
		<p class="razuna-status"><?php esc_html_e( 'Not connected yet.', 'razuna' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="razuna_oauth_connect" />
			<?php wp_nonce_field( 'razuna_oauth_connect' ); ?>
			<?php submit_button( __( 'Connect Razuna', 'razuna' ), 'primary', 'submit', false ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'You will be sent to Razuna to sign in and approve access, then returned here.', 'razuna' ); ?></p>
	<?php endif; ?>
</div>

<script>
( function () {
	var region = document.getElementById( 'razuna-region' );
	function toggleCustom() {
		var show = region && 'custom' === region.value;
		document.querySelectorAll( '.razuna-custom-row' ).forEach( function ( row ) {
			row.style.display = show ? '' : 'none';
		} );
	}
	if ( region ) {
		region.addEventListener( 'change', toggleCustom );
		toggleCustom();
	}
} )();
</script>
