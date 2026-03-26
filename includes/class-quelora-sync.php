<?php
// filepath: includes/class-quelora-sync.php

/**
 * Class Quelora_Sync
 *
 * Handles background synchronization of WordPress posts and users to the
 * Quelora backend via chained WP-Cron jobs. Includes mechanisms for batching,
 * live status polling, and graceful interruption (abort).
 * * Uses the Global Integration Secret for Authorization.
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
	// POST DATA SYNC
	// =========================================================================

	/**
	 * WP-Cron handler: processes one batch of Quelora-active posts.
	 *
	 * @return void
	 */
	public function process_posts_sync_batch() {
		// Prevent concurrent executions
		if ( get_transient( 'quelora_posts_sync_lock' ) ) {
			return;
		}

		// Check if the process was aborted manually
		if ( 'aborted' === get_option( 'quelora_sync_posts_status' ) ) {
			wp_clear_scheduled_hook( 'quelora_sync_posts_batch' );
			return;
		}

		set_transient( 'quelora_posts_sync_lock', 1, 120 );

		$endpoint = trim( (string) get_option( 'quelora_sync_posts_endpoint', '' ) );
		$secret   = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );

		if ( empty( $endpoint ) ) {
			$this->halt_sync_with_error( 'posts', 'no_endpoint' );
			return;
		}

		if ( empty( $secret ) ) {
			$this->halt_sync_with_error( 'posts', 'no_secret_key' );
			return;
		}

		$offset         = (int) get_option( 'quelora_sync_posts_offset', 0 );
		$default_active = (bool) get_option( 'quelora_default_active', false );

		if ( $default_active ) {
			$meta_query = array(
				'relation' => 'OR',
				array( 'key' => '_quelora_active', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_quelora_active', 'value' => '1', 'compare' => '=' ),
			);
		} else {
			$meta_query = array(
				array( 'key' => '_quelora_active', 'value' => '1', 'compare' => '=' ),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => self::SYNC_BATCH_SIZE,
				'offset'                 => $offset,
				'meta_query'             => $meta_query,
				'no_found_rows'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			$this->complete_sync( 'posts' );
			return;
		}

		$language = get_locale();
		$items    = array();

		foreach ( $query->posts as $post ) {
			$pid       = (int) $post->ID;
			$node_id   = substr( hash( 'sha256', 'post-' . $pid ), 0, 24 );
			$title     = $post->post_title;
			$excerpt   = wp_strip_all_tags( $post->post_excerpt );
			$permalink = (string) get_permalink( $pid );

			$description = ! empty( $excerpt )
				? substr( $excerpt, 0, 1000 )
				: substr( $title . ' ' . $title, 0, 1000 );

			$tags = array();
			$tag_terms = get_the_terms( $pid, 'post_tag' );
			if ( is_array( $tag_terms ) ) {
				$tags = array_values( wp_list_pluck( $tag_terms, 'name' ) );
			}

			$categories = array();
			$cat_terms  = get_the_terms( $pid, 'category' );
			if ( is_array( $cat_terms ) ) {
				$categories = array_values( wp_list_pluck( $cat_terms, 'name' ) );
			}

			$items[] = array(
				'nodeId'      => $node_id,
				'title'       => $title,
				'description' => $description,
				'tags'        => $tags,
				'categories'  => $categories,
				'language'    => $language,
				'link'        => $permalink,
			);
		}

		$total      = (int) $query->found_posts;
		$new_offset = $offset + count( $query->posts );

		$result = $this->send_sync_batch( $endpoint, $items );

		update_option( 'quelora_sync_posts_total',    $total );
		update_option( 'quelora_sync_posts_offset',   $new_offset );
		update_option( 'quelora_sync_posts_synced',   $new_offset );
		update_option( 'quelora_sync_posts_last_run', time() );

		delete_transient( 'quelora_posts_sync_lock' );

		if ( is_wp_error( $result ) ) {
			$this->halt_sync_with_error( 'posts', $result->get_error_message() );
			return;
		}

		if ( $new_offset < $total && 'aborted' !== get_option( 'quelora_sync_posts_status' ) ) {
			update_option( 'quelora_sync_posts_status', 'running' );
			wp_schedule_single_event( time() + 5, 'quelora_sync_posts_batch' );
		} else {
			$this->complete_sync( 'posts' );
		}
	}

	// =========================================================================
	// USER DATA SYNC
	// =========================================================================

	/**
	 * WP-Cron handler: processes one batch of registered WordPress users.
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
		$secret   = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );

		if ( empty( $endpoint ) ) {
			$this->halt_sync_with_error( 'users', 'no_endpoint' );
			return;
		}

		if ( empty( $secret ) ) {
			$this->halt_sync_with_error( 'users', 'no_secret_key' );
			return;
		}

		$offset = (int) get_option( 'quelora_sync_users_offset', 0 );

		$users = get_users(
			array(
				'number'  => self::SYNC_BATCH_SIZE,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);

		if ( empty( $users ) ) {
			$this->complete_sync( 'users' );
			return;
		}

		$items = array();

		foreach ( $users as $user ) {
			$author_id  = hash( 'sha256', (string) $user->ID . wp_salt( 'auth' ) );
			$avatar_url = get_avatar_url( $user->ID, array( 'size' => 96 ) );

			$item = array(
				'author' => $author_id,
				'name'   => $user->user_login,
			);

			if ( ! empty( $user->first_name ) ) {
				$item['given_name'] = $user->first_name;
			}

			if ( ! empty( $user->last_name ) ) {
				$item['family_name'] = $user->last_name;
			}

			if ( ! empty( $user->user_email ) ) {
				$item['email'] = $user->user_email;
			}

			if ( ! empty( $avatar_url ) ) {
				$item['picture'] = $avatar_url;
			}

			$items[] = $item;
		}

		$new_offset = $offset + count( $users );
		$total      = (int) get_option( 'quelora_sync_users_total', 0 );

		$result = $this->send_sync_batch( $endpoint, $items );

		update_option( 'quelora_sync_users_offset',   $new_offset );
		update_option( 'quelora_sync_users_synced',   $new_offset );
		update_option( 'quelora_sync_users_last_run', time() );

		delete_transient( 'quelora_users_sync_lock' );

		if ( is_wp_error( $result ) ) {
			$this->halt_sync_with_error( 'users', $result->get_error_message() );
			return;
		}

		if ( $new_offset < $total && 'aborted' !== get_option( 'quelora_sync_users_status' ) ) {
			update_option( 'quelora_sync_users_status', 'running' );
			wp_schedule_single_event( time() + 5, 'quelora_sync_users_batch' );
		} else {
			$this->complete_sync( 'users' );
		}
	}

	// =========================================================================
	// HTTP TRANSPORT & STATUS HELPERS
	// =========================================================================

	/**
	 * Dispatches a batch payload to a Quelora sync endpoint via HTTP POST.
	 *
	 * @param  string  $endpoint Absolute URL of the sync endpoint.
	 * @param  array[] $items    Indexed array of item arrays to dispatch.
	 * @return true|WP_Error     True on a 2xx response; WP_Error on transport failure.
	 */
	private function send_sync_batch( $endpoint, array $items ) {
		// Use the Global Integration Secret
		$api_key = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );
		$headers = array( 'Content-Type' => 'application/json' );

		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'method'    => 'POST',
				'headers'   => $headers,
				'body'      => wp_json_encode( array( 'items' => array_values( $items ) ) ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'quelora_sync_http_error', sprintf( 'HTTP %d', $code ) );
		}

		return true;
	}

	private function halt_sync_with_error( $type, $error_msg ) {
		update_option( "quelora_sync_{$type}_status",  'error:' . $error_msg );
		update_option( "quelora_sync_{$type}_running", false );
		delete_transient( "quelora_{$type}_sync_lock" );
		wp_clear_scheduled_hook( "quelora_sync_{$type}_batch" );
	}

	private function complete_sync( $type ) {
		// Only mark complete if not explicitly aborted during the last run
		if ( 'aborted' !== get_option( "quelora_sync_{$type}_status" ) ) {
			update_option( "quelora_sync_{$type}_status",  'complete' );
		}
		update_option( "quelora_sync_{$type}_running", false );
		delete_transient( "quelora_{$type}_sync_lock" );
		wp_clear_scheduled_hook( "quelora_sync_{$type}_batch" );
	}

	// =========================================================================
	// AJAX ACTIONS
	// =========================================================================

	public function ajax_trigger_posts_sync() {
		check_ajax_referer( 'quelora_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$secret   = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );
		$endpoint = trim( (string) get_option( 'quelora_sync_posts_endpoint', '' ) );

		if ( empty( $secret ) || empty( $endpoint ) ) {
			wp_send_json_error( array( 'message' => 'Missing Secret Key or Endpoint URL.' ), 400 );
		}

		update_option( 'quelora_sync_posts_offset',  0 );
		update_option( 'quelora_sync_posts_synced',  0 );
		update_option( 'quelora_sync_posts_total',   0 );
		update_option( 'quelora_sync_posts_status',  'running' );
		update_option( 'quelora_sync_posts_running', true );

		wp_clear_scheduled_hook( 'quelora_sync_posts_batch' );
		delete_transient( 'quelora_posts_sync_lock' );
		wp_schedule_single_event( time(), 'quelora_sync_posts_batch' );

		$this->spawn_cron_if_allowed();

		wp_send_json_success( array( 'message' => 'Post sync queued.' ) );
	}

	public function ajax_trigger_users_sync() {
		check_ajax_referer( 'quelora_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$secret   = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );
		$endpoint = trim( (string) get_option( 'quelora_sync_users_endpoint', '' ) );

		if ( empty( $secret ) || empty( $endpoint ) ) {
			wp_send_json_error( array( 'message' => 'Missing Secret Key or Endpoint URL.' ), 400 );
		}

		$user_counts = count_users();
		$total       = isset( $user_counts['total_users'] ) ? (int) $user_counts['total_users'] : 0;

		update_option( 'quelora_sync_users_offset',  0 );
		update_option( 'quelora_sync_users_synced',  0 );
		update_option( 'quelora_sync_users_total',   $total );
		update_option( 'quelora_sync_users_status',  'running' );
		update_option( 'quelora_sync_users_running', true );

		wp_clear_scheduled_hook( 'quelora_sync_users_batch' );
		delete_transient( 'quelora_users_sync_lock' );
		wp_schedule_single_event( time(), 'quelora_sync_users_batch' );

		$this->spawn_cron_if_allowed();

		wp_send_json_success( array( 'message' => 'User sync queued.' ) );
	}

	public function ajax_abort_sync() {
		check_ajax_referer( 'quelora_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! in_array( $type, array( 'posts', 'users' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid sync type.' ), 400 );
		}

		update_option( "quelora_sync_{$type}_status",  'aborted' );
		update_option( "quelora_sync_{$type}_running", false );
		wp_clear_scheduled_hook( "quelora_sync_{$type}_batch" );
		delete_transient( "quelora_{$type}_sync_lock" );

		wp_send_json_success( array( 'message' => ucfirst( $type ) . ' sync aborted successfully.' ) );
	}

	public function ajax_get_sync_status() {
		check_ajax_referer( 'quelora_sync_nonce', 'nonce' );

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
	 * Triggers immediate WP-Cron execution asynchronously.
	 *
	 * @return void
	 */
	private function spawn_cron_if_allowed() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		wp_remote_post(
			add_query_arg( 'doing_wp_cron', '1', site_url( 'wp-cron.php' ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'body'      => array(),
				'cookies'   => array(),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}
}