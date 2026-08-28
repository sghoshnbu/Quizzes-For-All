<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save & resume, and the [qmc_user_dashboard] shortcode. Resume is scoped
 * to logged-in users only — a guest has no stable identity across visits
 * to safely resume against, so the option simply doesn't appear for them.
 */
class QFA_Progress {

	public static function init() {
		add_action( 'wp_ajax_qmc_save_progress', array( __CLASS__, 'ajax_save_progress' ) );
		add_shortcode( 'qmc_user_dashboard', array( __CLASS__, 'dashboard_shortcode' ) );
		// New-branding alias; original stays registered for existing content.
		add_shortcode( 'qfa_user_dashboard', array( __CLASS__, 'dashboard_shortcode' ) );
	}

	public static function allows_resume( $quiz_id ) {
		$val = get_post_meta( $quiz_id, '_qmc_allow_resume', true );
		return '' === $val ? true : (bool) $val; // Default on.
	}

	public static function ajax_save_progress() {
		check_ajax_referer( 'qmc_frontend_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'quizzis-for-all' ) ) );
		}

		$quiz_id  = intval( $_POST['quiz_id'] ?? 0 );
		$answers  = isset( $_POST['answers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answers'] ) ) : '{}';
		$index    = intval( $_POST['current_index'] ?? 0 );
		$elapsed  = intval( $_POST['time_elapsed'] ?? 0 );

		if ( ! $quiz_id || 'qmc_quiz' !== get_post_type( $quiz_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid quiz.', 'quizzis-for-all' ) ) );
		}

		$id = QFA_DB::upsert_progress( $quiz_id, get_current_user_id(), $answers, $index, $elapsed );
		wp_send_json_success( array( 'saved' => (bool) $id ) );
	}

	/* ------------------------------------------------------------------ *
	 *  User dashboard
	 * ------------------------------------------------------------------ */

	public static function dashboard_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to see your quiz dashboard.', 'quizzis-for-all' ) . '</p>';
		}
		$user_id     = get_current_user_id();
		$in_progress = QFA_DB::get_user_in_progress( $user_id );
		$history     = QFA_DB::get_user_history( $user_id, 30 );

		// When an attempt is being reviewed, show that instead of the list —
		// the review shortcode enforces ownership itself.
		if ( isset( $_GET['qmc_attempt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view; ownership enforced in QFA_Review.
			return '<p><a href="' . esc_url( remove_query_arg( 'qmc_attempt' ) ) . '">&larr; ' . esc_html__( 'Back to dashboard', 'quizzis-for-all' ) . '</a></p>'
				. QFA_Review::shortcode( array( 'id' => 0 ) );
		}

		ob_start();
		?>
		<div class="qmc-dashboard">
			<div class="qmc-dashboard-points"><?php echo QFA_Gamification::user_points_shortcode(); // phpcs:ignore -- already-escaped shortcode output. ?></div>

			<?php if ( ! empty( $in_progress ) ) : ?>
				<h3><?php esc_html_e( 'Continue where you left off', 'quizzis-for-all' ); ?></h3>
				<ul class="qmc-dashboard-list">
					<?php foreach ( $in_progress as $row ) :
						$permalink = get_permalink( $row->quiz_id );
						if ( ! $permalink ) {
							continue;
						}
						?>
						<li>
							<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $row->quiz_id ) ); ?></a>
							<span class="qmc-dashboard-meta"><?php esc_html_e( 'in progress', 'quizzis-for-all' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Quiz history', 'quizzis-for-all' ); ?></h3>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( "You haven't completed any quizzes yet.", 'quizzis-for-all' ); ?></p>
			<?php else : ?>
				<table class="qmc-dashboard-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Result', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Date', 'quizzis-for-all' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $row ) :
							$breakdown = json_decode( $row->question_breakdown, true );
							$personality = is_array( $breakdown ) && isset( $breakdown['_personality'] ) ? $breakdown['_personality'] : null;
							?>
							<tr>
								<td><?php echo esc_html( get_the_title( $row->quiz_id ) ?: '#' . $row->quiz_id ); ?></td>
								<td>
									<?php if ( $personality ) : ?>
										<?php echo esc_html( $personality['label'] ); ?>
									<?php else : ?>
										<?php echo esc_html( $row->percentage ); ?>% <?php echo $row->passed ? '✅' : '❌'; ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->completed_at ) ); ?></td>
								<td>
									<?php if ( QFA_Review::allows_review( $row->quiz_id ) ) : ?>
										<a href="<?php echo esc_url( QFA_Review::review_url( $row->id ) ); ?>"><?php esc_html_e( 'Review', 'quizzis-for-all' ); ?></a>
									<?php endif; ?>
									<?php if ( $row->certificate_token ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'qmc_view_certificate', 'token' => $row->certificate_token ), admin_url( 'admin-ajax.php' ) ) ); ?>" target="_blank"><?php esc_html_e( 'Certificate', 'quizzis-for-all' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
