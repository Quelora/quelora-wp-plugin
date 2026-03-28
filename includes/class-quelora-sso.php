<?php
// filepath: includes/class-quelora-sso.php

/**
 * Class Quelora_SSO
 *
 * Handles the Single Sign-On (SSO) integration, including cryptographic
 * signing of HS256 JWT tokens and frontend injection for seamless authentication
 * with the distributed backend.
 *
 * @package Quelora
 */
class Quelora_SSO {

	/**
	 * Encodes data to Base64URL format as required by the JWT specification.
	 *
	 * @param  string $data Raw binary or string data to encode.
	 * @return string Base64URL-encoded string.
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Generates a signed HS256 JWT with a Google SSO-equivalent payload.
	 *
	 * @return string|null Signed JWT string, or null when preconditions are not met.
	 */
	private function generate_sso_token() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		if ( ! (bool) get_option( 'quelora_sso_enabled', Quelora_Admin::DEFAULT_SSO_ENABLED ) ) {
			return null;
		}

		$secret_key = trim( wp_unslash( (string) get_option( 'quelora_sso_secret_key', '' ) ) );

		if ( empty( $secret_key ) ) {
			return null;
		}

		$user          = wp_get_current_user();
		$ttl           = (int) get_option( 'quelora_sso_token_ttl', Quelora_Admin::DEFAULT_TOKEN_TTL );
		$issued_at     = time();
		$expires_at    = $issued_at + max( 60, $ttl );
		$api_url   = rtrim( trim( (string) get_option( 'quelora_api_url', '' ) ), '/' );
		$author_id = hash( 'sha256', (string) $user->ID );
		$flags     = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		$header = $this->base64url_encode(
			wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ), $flags )
		);

		$payload = $this->base64url_encode(
			wp_json_encode(
				array(
					'iss'         => $api_url,
					'sub'         => $author_id,
					'aud'         => $api_url,
					'iat'         => $issued_at,
					'exp'         => $expires_at,
					'email'       => $user->user_email,
					'given_name'  => $user->first_name,
					'family_name' => $user->last_name,
					'picture'     => get_avatar_url( $user->ID ),
					'locale'      => get_locale(),
					'author'      => $author_id,
				),
				$flags
			)
		);

		$signature = $this->base64url_encode(
			hash_hmac( 'sha256', $header . '.' . $payload, $secret_key, true )
		);

		return $header . '.' . $payload . '.' . $signature;
	}

	/**
	 * Injects the SSO token into both sessionStorage and localStorage at priority 15.
	 *
	 * When SSO is disabled or the user is not logged in, the script clears any
	 * previously stored Quelora session keys so stale tokens cannot persist.
	 *
	 * @return void
	 */
	public function inject_sso_token() {
		$token = $this->generate_sso_token();

		if ( null === $token ) {
			?>
			<script id="quelora-sso">
			(function () {
				var keys   = [ 'ql_sso_token', 'ql_sso_token_expires' ];
				var stores = [ sessionStorage, localStorage ];
				stores.forEach( function ( s ) {
					keys.forEach( function ( k ) { try { s.removeItem( k ); } catch (e) {} } );
				} );
			}());
			</script>
			<?php
			return;
		}

		$ttl        = (int) get_option( 'quelora_sso_token_ttl', Quelora_Admin::DEFAULT_TOKEN_TTL );
		$expires_ms = ( time() + max( 60, $ttl ) ) * 1000;
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$nonce      = wp_create_nonce( 'quelora_refresh_token' );

		?>
		<script id="quelora-sso">
		(function () {
			var token     = <?php echo wp_json_encode( $token ); ?>;
			var expiresAt = <?php echo (int) $expires_ms; ?>;
			var ajaxUrl   = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce     = <?php echo wp_json_encode( $nonce ); ?>;

			function storeToken( t, exp ) {
				// sessionStorage: raw strings (widget reads via getSessionItem → raw string)
				try {
					sessionStorage.setItem( 'ql_sso_token',         t );
					sessionStorage.setItem( 'ql_sso_token_expires', String( exp ) );
				} catch (e) {}
				// localStorage: {value, expiry} envelope (widget reads via getLocalItem → parses envelope)
				try {
					localStorage.setItem( 'ql_sso_token',         JSON.stringify( { value: t,           expiry: null } ) );
					localStorage.setItem( 'ql_sso_token_expires', JSON.stringify( { value: String( exp ), expiry: null } ) );
				} catch (e) {}
			}

			function getStoredExpiry() {
				// sessionStorage: raw timestamp string
				try {
					var raw = sessionStorage.getItem( 'ql_sso_token_expires' );
					if ( raw ) {
						var exp = parseInt( raw, 10 );
						if ( exp > 0 ) { return exp; }
					}
				} catch (e) {}
				// localStorage: {value, expiry} envelope
				try {
					var lsRaw = localStorage.getItem( 'ql_sso_token_expires' );
					if ( lsRaw ) {
						var parsed = JSON.parse( lsRaw );
						var lsExp = parseInt( parsed.value, 10 );
						if ( lsExp > 0 ) { return lsExp; }
					}
				} catch (e) {}
				return 0;
			}

			var storedExpiry = getStoredExpiry();
			var stillValid   = storedExpiry > 0 && ( storedExpiry - Date.now() > 30000 );

			if ( ! stillValid ) { storeToken( token, expiresAt ); }

			function scheduleRenewal( expiryMs ) {
				var delay = expiryMs - Date.now() - ( 5 * 60 * 1000 );
				setTimeout( function () {
					var xhr = new XMLHttpRequest();
					xhr.open( 'POST', ajaxUrl );
					xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
					xhr.onload = function () {
						if ( xhr.status !== 200 ) { return; }
						try {
							var resp = JSON.parse( xhr.responseText );
							if ( ! resp.success ) { return; }
							storeToken( resp.data.token, resp.data.expiresAt );
							scheduleRenewal( resp.data.expiresAt );
						} catch (e) {}
					};
					xhr.send( 'action=quelora_refresh_token&nonce=' + encodeURIComponent( nonce ) );
				}, Math.max( 30000, delay ) );
			}

			scheduleRenewal( stillValid ? storedExpiry : expiresAt );
		}());
		</script>
		<?php
	}

	/**
	 * WordPress AJAX handler for SSO token renewal.
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_refresh_token() {
		check_ajax_referer( 'quelora_refresh_token', 'nonce' );

		$token = $this->generate_sso_token();

		if ( null === $token ) {
			wp_send_json_error( array( 'message' => 'SSO token generation is disabled or unavailable.' ), 403 );
			return;
		}

		$ttl        = (int) get_option( 'quelora_sso_token_ttl', Quelora_Admin::DEFAULT_TOKEN_TTL );
		$expires_ms = ( time() + max( 60, $ttl ) ) * 1000;

		wp_send_json_success( array( 'token' => $token, 'expiresAt' => $expires_ms ) );
	}
}