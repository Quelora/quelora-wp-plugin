<?php
// filepath: includes/class-quelora-sync.php

/**
 * Class Quelora_Sync
 *
 * Handles background synchronization of WordPress posts and users to the
 * Quelora backend via chained WP-Cron jobs and real-time event-driven hooks.
 * Uses the Global Integration Secret for Authorization and CID for multi-tenant routing.
 *
 * @package Quelora
 */
class Quelora_Sync {

	/**
	 * Number of items dispatched per batch in both the post and user sync processes.
	 *
	 * @var int
	 */
	const SYNC_BATCH_SIZE = 100;

	// =========================================================================
	// EVENT-DRIVEN (REAL-TIME) SYNC
	// =========================================================================

	/**
	 * Hooked to `save_post`. Pushes post updates to Quelora in real-time.
	 * Utilizes a non-blocking HTTP request to prevent editor latency.
	 *
	 * @param int      $post_id The ID of the post being saved.
	 * @param WP_Post  $post    The post object.
	 * @param bool     $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function handle_post_saved( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			return;
		}

		$is_active = get_post_meta( $post_id, '_quelora_active', true );
		if ( '' === $is_active ) {
			$is_active = get_option( 'quelora_default_active', false );
		}

		if ( ! $is_active ) {
			return;
		}

		$endpoint = trim( (string) get_option( 'quelora_sync_posts_endpoint', '' ) );
		if ( empty( $endpoint ) ) {
			return;
		}

		$payload = array( $this->format_post_payload( $post ) );
		$this->send_payload( $endpoint, $payload, false );
	}

	/**
	 * Hooked to `user_register` and `profile_update`. Pushes user updates to Quelora.
	 * Utilizes a non-blocking HTTP request.
	 *
	 * @param int   $user_id       The ID of the user being saved.
	 * @param mixed $old_user_data Optional. The old user data for profile updates.
	 * @return void
	 */
	public function handle_user_saved( $user_id, $old_user_data = null ) {
		$endpoint = trim( (string) get_option( 'quelora_sync_users_endpoint', '' ) );
		if ( empty( $endpoint ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$payload = array( $this->format_user_payload( $user ) );
		$this->send_payload( $endpoint, $payload, false );
	}

	// =========================================================================
	// BATCH SYNC (WP-CRON)
	// =========================================================================

	/**
	 * WP-Cron handler: processes one batch of Quelora-active posts.
	 *
	 * @return void
	 */
	public function process_posts_sync_batch() {
		if ( get_transient( 'quelora_posts_sync_lock' ) ) {
			return;
		}

		if ( 'aborted' === get_option( 'quelora_sync_posts_status' ) ) {
			wp_clear_scheduled_hook( 'quelora_sync_posts_batch' );
			return;
		}

		set_transient( 'quelora_posts_sync_lock', 1, 120 );

		$endpoint = trim( (string) get_option( 'quelora_sync_posts_endpoint', '' ) );
		
		if ( empty( $endpoint ) ) {
			$this->mark_sync_error( 'posts', 'Missing endpoint URL' );
			return;
		}

		$offset = (int) get_option( 'quelora_sync_posts_offset', 0 );
		$total  = (int) get_option( 'quelora_sync_posts_total', 0 );

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => self::SYNC_BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'all',
		);

		$query = new WP_Query( $args );
		$posts = $query->get_posts();

		if ( empty( $posts ) ) {
			$this->complete_sync( 'posts' );
			return;
		}

		$payload = array();
		foreach ( $posts as $post ) {
			$payload[] = $this->format_post_payload( $post );
		}

		$response = $this->send_payload( $endpoint, $payload, true );

		if ( is_wp_error( $response ) ) {
			$this->mark_sync_error( 'posts', $response->get_error_message() );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->mark_sync_error( 'posts', 'HTTP Error: ' . $status_code );
			return;
		}

		$new_offset = $offset + count( $posts );
		update_option( 'quelora_sync_posts_offset', $new_offset );
		update_option( 'quelora_sync_posts_synced', min( $new_offset, $total ) );
		update_option( 'quelora_sync_posts_last_run', time() );

		delete_transient( 'quelora_posts_sync_lock' );

		if ( $new_offset >= $total ) {
			$this->complete_sync( 'posts' );
		} else {
			wp_schedule_single_event( time() + 5, 'quelora_sync_posts_batch' );
		}
	}

	/**
	 * WP-Cron handler: processes one batch of users.
	 *
	 * @return void
	 */
	public function process_users_sync_batch() {
		if ( get_transient( 'quelora_users_sync_lock' ) ) {
			return;
		}

		if ( 'aborted' === get_option( 'quelora_sync_users_status' ) ) {
			wp_clear_scheduled_hook( 'quelora_sync_users_batch' );
			return;
		}

		set_transient( 'quelora_users_sync_lock', 1, 120 );

		$endpoint = trim( (string) get_option( 'quelora_sync_users_endpoint', '' ) );
		
		if ( empty( $endpoint ) ) {
			$this->mark_sync_error( 'users', 'Missing endpoint URL' );
			return;
		}

		$offset = (int) get_option( 'quelora_sync_users_offset', 0 );
		$total  = (int) get_option( 'quelora_sync_users_total', 0 );

		$args = array(
			'number'  => self::SYNC_BATCH_SIZE,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		$query = new WP_User_Query( $args );
		$users = $query->get_results();

		if ( empty( $users ) ) {
			$this->complete_sync( 'users' );
			return;
		}

		$payload = array();
		foreach ( $users as $user ) {
			$payload[] = $this->format_user_payload( $user );
		}

		$response = $this->send_payload( $endpoint, $payload, true );

		if ( is_wp_error( $response ) ) {
			$this->mark_sync_error( 'users', $response->get_error_message() );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->mark_sync_error( 'users', 'HTTP Error: ' . $status_code );
			return;
		}

		$new_offset = $offset + count( $users );
		update_option( 'quelora_sync_users_offset', $new_offset );
		update_option( 'quelora_sync_users_synced', min( $new_offset, $total ) );
		update_option( 'quelora_sync_users_last_run', time() );

		delete_transient( 'quelora_users_sync_lock' );

		if ( $new_offset >= $total ) {
			$this->complete_sync( 'users' );
		} else {
			wp_schedule_single_event( time() + 5, 'quelora_sync_users_batch' );
		}
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * AJAX handler to initialize and start the posts sync process.
	 * Processes the first batch synchronously to bypass local WP-Cron limitations
	 * and return immediate feedback to the SPA.
	 *
	 * @return void
	 */
	public function ajax_trigger_posts_sync() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$count_posts = wp_count_posts( 'post' );
		$total       = isset( $count_posts->publish ) ? (int) $count_posts->publish : 0;

		update_option( 'quelora_sync_posts_status', 'running' );
		update_option( 'quelora_sync_posts_total', $total );
		update_option( 'quelora_sync_posts_synced', 0 );
		update_option( 'quelora_sync_posts_offset', 0 );
		update_option( 'quelora_sync_posts_last_run', time() );
		delete_transient( 'quelora_posts_sync_lock' );

		wp_clear_scheduled_hook( 'quelora_sync_posts_batch' );

		// Execute first batch synchronously
		$this->process_posts_sync_batch();

		$status = get_option( 'quelora_sync_posts_status', '' );
		if ( strpos( $status, 'error:' ) === 0 ) {
			wp_send_json_error( array( 'message' => substr( $status, 6 ) ), 502 );
		}

		wp_send_json_success( array( 'message' => 'Post sync initiated successfully.' ) );
	}

	/**
	 * AJAX handler to initialize and start the users sync process.
	 * Processes the first batch synchronously to bypass local WP-Cron limitations
	 * and return immediate feedback to the SPA.
	 *
	 * @return void
	 */
	public function ajax_trigger_users_sync() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$result = count_users();
		$total  = isset( $result['total_users'] ) ? (int) $result['total_users'] : 0;

		update_option( 'quelora_sync_users_status', 'running' );
		update_option( 'quelora_sync_users_total', $total );
		update_option( 'quelora_sync_users_synced', 0 );
		update_option( 'quelora_sync_users_offset', 0 );
		update_option( 'quelora_sync_users_last_run', time() );
		delete_transient( 'quelora_users_sync_lock' );

		wp_clear_scheduled_hook( 'quelora_sync_users_batch' );

		// Execute first batch synchronously
		$this->process_users_sync_batch();

		$status = get_option( 'quelora_sync_users_status', '' );
		if ( strpos( $status, 'error:' ) === 0 ) {
			wp_send_json_error( array( 'message' => substr( $status, 6 ) ), 502 );
		}

		wp_send_json_success( array( 'message' => 'User sync initiated successfully.' ) );
	}

	/**
	 * AJAX handler to retrieve current synchronization status.
	 *
	 * @return void
	 */
	public function ajax_get_sync_status() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		wp_send_json_success(
			array(
				'posts' => array(
					'status'  => get_option( 'quelora_sync_posts_status', 'idle' ),
					'synced'  => (int) get_option( 'quelora_sync_posts_synced', 0 ),
					'total'   => (int) get_option( 'quelora_sync_posts_total', 0 ),
					'lastRun' => (int) get_option( 'quelora_sync_posts_last_run', 0 ),
				),
				'users' => array(
					'status'  => get_option( 'quelora_sync_users_status', 'idle' ),
					'synced'  => (int) get_option( 'quelora_sync_users_synced', 0 ),
					'total'   => (int) get_option( 'quelora_sync_users_total', 0 ),
					'lastRun' => (int) get_option( 'quelora_sync_users_last_run', 0 ),
				),
			)
		);
	}

	/**
	 * AJAX handler to cleanly abort a running synchronization process.
	 *
	 * @return void
	 */
	public function ajax_abort_sync() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! in_array( $type, array( 'posts', 'users' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid sync type.' ), 400 );
		}

		update_option( 'quelora_sync_' . $type . '_status', 'aborted' );
		wp_clear_scheduled_hook( 'quelora_sync_' . $type . '_batch' );
		delete_transient( 'quelora_' . $type . '_sync_lock' );

		wp_send_json_success( array( 'message' => ucfirst( $type ) . ' sync aborted successfully.' ) );
	}

	// =========================================================================
	// HELPER METHODS
	// =========================================================================

	/**
	 * Marks a synchronization process as completed.
	 *
	 * @param string $type The sync process type ('posts' or 'users').
	 * @return void
	 */
	private function complete_sync( $type ) {
		update_option( 'quelora_sync_' . $type . '_status', 'complete' );
		wp_clear_scheduled_hook( 'quelora_sync_' . $type . '_batch' );
		delete_transient( 'quelora_' . $type . '_sync_lock' );
	}

	/**
	 * Records a synchronization error and halts the process.
	 *
	 * @param string $type    The sync process type ('posts' or 'users').
	 * @param string $message The error message.
	 * @return void
	 */
	private function mark_sync_error( $type, $message ) {
		update_option( 'quelora_sync_' . $type . '_status', 'error: ' . $message );
		wp_clear_scheduled_hook( 'quelora_sync_' . $type . '_batch' );
		delete_transient( 'quelora_' . $type . '_sync_lock' );
	}

	/**
	 * Formats a WordPress post object into the Quelora payload schema.
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted post representation.
	 */
	private function format_post_payload( $post ) {
		return array(
			'nodeId'      => 'post-' . $post->ID,
			'title'       => get_the_title( $post->ID ),
			'link'        => get_permalink( $post->ID ),
			'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'tags'        => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
			'categories'  => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'language'    => get_locale(),
		);
	}

	/**
	 * Formats a WordPress user object into the Quelora payload schema.
	 *
	 * @param WP_User $user The user object.
	 * @return array Formatted user representation.
	 */
	private function format_user_payload( $user ) {
		return array(
			'author'      => hash( 'sha256', (string) $user->ID ),
			'name'        => $user->user_login,
			'given_name'  => get_user_meta( $user->ID, 'first_name', true ),
			'family_name' => get_user_meta( $user->ID, 'last_name', true ),
			'email'       => $user->user_email,
			'picture'     => get_avatar_url( $user->ID ),
		);
	}

	/**
	 * Authenticates and transmits a data payload to the Quelora backend.
	 * Now injects the X-Client-ID header globally to authorize endpoints properly.
	 *
	 * @param string $endpoint The destination API URL.
	 * @param array  $payload  The data payload to transmit.
	 * @param bool   $blocking Whether to wait for a response (true for cron batches, false for real-time events).
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	private function send_payload( $endpoint, $payload, $blocking = true ) {
		$secret = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );
		$cid    = trim( (string) get_option( 'quelora_client_id', '' ) );

		// Fallback for CID if not explicitly stored (Extract from the endpoint URL)
		if ( empty( $cid ) ) {
			preg_match( '/\/([A-Z0-9\-]{10,})\/sync\//i', $endpoint, $matches );
			$cid = isset( $matches[1] ) ? $matches[1] : '';
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => $blocking ? 15 : 0.01,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking'    => $blocking,
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $secret,
				'X-Client-ID'   => $cid,
			),
			'body'        => wp_json_encode( array( 'items' => $payload ) ),
		);

		return wp_remote_post( $endpoint, $args );
	}
}