<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the two notification emails a quiz can trigger on completion: a
 * results summary to the test-taker, and an alert to the instructor/admin.
 * Both are opt-in per quiz (default off) via the "Email Notifications"
 * meta box.
 */
class QFA_Notifications {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_qmc_quiz', array( __CLASS__, 'save_settings' ) );
	}

	public static function meta_box() {
		add_meta_box( 'qmc_notifications', __( 'Email Notifications', 'quizzis-for-all' ), array( __CLASS__, 'render_settings' ), 'qmc_quiz', 'side', 'default' );
	}

	public static function render_settings( $post ) {
		wp_nonce_field( 'qmc_save_notifications', 'qmc_notifications_nonce' );
		$to_user  = get_post_meta( $post->ID, '_qmc_email_user_enabled', true );
		$to_admin = get_post_meta( $post->ID, '_qmc_email_admin_enabled', true );
		$override = get_post_meta( $post->ID, '_qmc_notify_email', true );
		?>
		<p><label><input type="checkbox" name="qmc_email_user_enabled" <?php checked( $to_user, 1 ); ?>> <?php esc_html_e( 'Email results to the test-taker', 'quizzis-for-all' ); ?></label></p>
		<p><label><input type="checkbox" name="qmc_email_admin_enabled" <?php checked( $to_admin, 1 ); ?>> <?php esc_html_e( 'Email a notification to the admin', 'quizzis-for-all' ); ?></label></p>
		<p><label><?php esc_html_e( 'Admin notification address (optional — defaults to site admin email)', 'quizzis-for-all' ); ?><br>
		<input type="email" name="qmc_notify_email" style="width:100%;" value="<?php echo esc_attr( $override ); ?>"></label></p>
		<?php
	}

	public static function save_settings( $post_id ) {
		if ( ! isset( $_POST['qmc_notifications_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_notifications_nonce'] ) ), 'qmc_save_notifications' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_qmc_email_user_enabled', ! empty( $_POST['qmc_email_user_enabled'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_email_admin_enabled', ! empty( $_POST['qmc_email_admin_enabled'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_notify_email', sanitize_email( wp_unslash( $_POST['qmc_notify_email'] ?? '' ) ) );
	}

	/**
	 * Fire both notification emails (whichever are enabled) for a
	 * just-completed attempt. $result_text is a short human-readable
	 * summary line — e.g. "82% — Passed" for standard quizzes, or the
	 * outcome label for personality quizzes — built by the caller so this
	 * class doesn't need to know about quiz-mode branching.
	 */
	public static function notify( $quiz_id, $recipient_email, $recipient_name, $result_text, $certificate_url = '' ) {
		$quiz_title = get_the_title( $quiz_id );
		$site_name  = get_bloginfo( 'name' );

		if ( get_post_meta( $quiz_id, '_qmc_email_user_enabled', true ) && $recipient_email ) {
			/* translators: 1: quiz title, 2: site name */
			$subject = sprintf( __( 'Your results for "%1$s" — %2$s', 'quizzis-for-all' ), $quiz_title, $site_name );
			/* translators: %s: recipient's name */
			$body  = sprintf( __( 'Hi %s,', 'quizzis-for-all' ), $recipient_name ) . "\n\n";
			/* translators: 1: quiz title, 2: result summary, e.g. "82% — Passed" */
			$body .= sprintf( __( 'Your result for "%1$s": %2$s', 'quizzis-for-all' ), $quiz_title, $result_text ) . "\n\n";
			if ( $certificate_url ) {
				$body .= __( 'View / print your certificate:', 'quizzis-for-all' ) . ' ' . $certificate_url . "\n\n";
			}
			/* translators: %s: site name */
			$body .= sprintf( __( '— %s', 'quizzis-for-all' ), $site_name );
			wp_mail( $recipient_email, $subject, $body );
		}

		if ( get_post_meta( $quiz_id, '_qmc_email_admin_enabled', true ) ) {
			$admin_email = get_post_meta( $quiz_id, '_qmc_notify_email', true ) ?: get_option( 'admin_email' );
			/* translators: %s: quiz title */
			$subject     = sprintf( __( 'New quiz attempt: "%s"', 'quizzis-for-all' ), $quiz_title );
			/* translators: 1: test-taker's name (or "A guest"), 2: quiz title */
			$body        = sprintf( __( '%1$s just completed "%2$s".', 'quizzis-for-all' ), $recipient_name ?: __( 'A guest', 'quizzis-for-all' ), $quiz_title ) . "\n";
			/* translators: %s: result summary, e.g. "82% — Passed" */
			$body       .= sprintf( __( 'Result: %s', 'quizzis-for-all' ), $result_text ) . "\n\n";
			$body       .= __( 'View full results and reports:', 'quizzis-for-all' ) . ' ' . admin_url( 'admin.php?page=qmc_results&quiz_id=' . $quiz_id );
			wp_mail( $admin_email, $subject, $body );
		}
	}

	/**
	 * Sent when an instructor finishes manually grading an attempt. Unlike
	 * notify(), this always sends when called (the instructor explicitly
	 * ticked the box on the grading screen) rather than depending on the
	 * quiz's on-completion email setting.
	 */
	public static function notify_graded( $quiz_id, $recipient_email, $recipient_name, $result_text, $certificate_url = '' ) {
		$quiz_title = get_the_title( $quiz_id );
		$site_name  = get_bloginfo( 'name' );

		/* translators: 1: quiz title, 2: site name */
		$subject = sprintf( __( 'Your graded results for "%1$s" — %2$s', 'quizzis-for-all' ), $quiz_title, $site_name );
		/* translators: %s: recipient's name */
		$body  = sprintf( __( 'Hi %s,', 'quizzis-for-all' ), $recipient_name ) . "\n\n";
		$body .= __( 'Your submission has now been reviewed and graded.', 'quizzis-for-all' ) . "\n\n";
		/* translators: 1: quiz title, 2: final result summary */
		$body .= sprintf( __( 'Final result for "%1$s": %2$s', 'quizzis-for-all' ), $quiz_title, $result_text ) . "\n\n";
		if ( $certificate_url ) {
			$body .= __( 'View / print your certificate:', 'quizzis-for-all' ) . ' ' . $certificate_url . "\n\n";
		}
		/* translators: %s: site name */
		$body .= sprintf( __( '— %s', 'quizzis-for-all' ), $site_name );

		wp_mail( $recipient_email, $subject, $body );
	}
}
