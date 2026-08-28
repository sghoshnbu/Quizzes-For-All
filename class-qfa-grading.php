<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual grading + attempt review.
 *
 * Essay (text) and file-upload answers can't be auto-scored, so they are
 * excluded from max_score at submission time and the attempt is flagged
 * needs_grading. This screen is where an instructor works that queue:
 * read each answer, award points, leave feedback. Applying the grades
 * folds the awarded points into the attempt's score, recomputes the
 * percentage and pass flag, and clears the flag.
 *
 * The same screen doubles as a read-only review of any attempt (every
 * question, the answer given, whether it was right) — reached from the
 * Results table.
 */
class QFA_Grading {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 19 );
		add_action( 'admin_post_qmc_save_grades', array( __CLASS__, 'handle_save_grades' ) );
	}

	public static function admin_menu() {
		$pending = QFA_DB::count_pending_grading();
		$label   = __( 'Grading', 'quizzis-for-all' );
		if ( $pending > 0 ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>';
		}
		add_submenu_page( 'qmc_dashboard', __( 'Manual Grading', 'quizzis-for-all' ), $label, 'edit_posts', 'qmc_grading', array( __CLASS__, 'render_page' ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Screens
	 * ------------------------------------------------------------------ */

	public static function render_page() {
		// Read-only screen routing; no state change here.
		$attempt_id = isset( $_GET['attempt'] ) ? intval( $_GET['attempt'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap qmc-admin">';
		if ( $attempt_id ) {
			self::render_single_attempt( $attempt_id );
		} else {
			self::render_queue();
		}
		echo '</div>';
	}

	protected static function render_queue() {
		$pending = QFA_DB::get_pending_grading( 100 );
		?>
		<h1><?php esc_html_e( 'Manual Grading', 'quizzis-for-all' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Attempts containing essay or file-upload answers that still need to be scored. Auto-graded questions are already counted; the points you award here are added to the attempt total.', 'quizzis-for-all' ); ?></p>

		<?php if ( empty( $pending ) ) : ?>
			<div class="qmc-panel" style="margin-top:16px;">
				<div class="qmc-panel-body">
					<p class="qmc-empty"><?php esc_html_e( 'Nothing waiting to be graded — the queue is clear.', 'quizzis-for-all' ); ?></p>
				</div>
			</div>
		<?php else : ?>
			<div class="qmc-panel" style="margin-top:16px;">
				<h2>
					<span><?php esc_html_e( 'Awaiting grading', 'quizzis-for-all' ); ?></span>
					<span class="qmc-pill qmc-pill-warn"><?php echo (int) count( $pending ); ?></span>
				</h2>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Test-taker', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Auto-graded so far', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Submitted', 'quizzis-for-all' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pending as $a ) : ?>
							<tr>
								<td><strong><?php echo esc_html( get_the_title( $a->quiz_id ) ?: '#' . $a->quiz_id ); ?></strong></td>
								<td><?php echo esc_html( self::taker_name( $a ) ); ?></td>
								<td><?php echo esc_html( $a->score . ' / ' . $a->max_score ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $a->completed_at ) ); ?></td>
								<td>
									<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading&attempt=' . $a->id ) ); ?>"><?php esc_html_e( 'Grade', 'quizzis-for-all' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif;
	}

	protected static function render_single_attempt( $attempt_id ) {
		$attempt = QFA_DB::get_attempt( $attempt_id );
		if ( ! $attempt ) {
			echo '<h1>' . esc_html__( 'Attempt not found', 'quizzis-for-all' ) . '</h1>';
			return;
		}

		$answers   = json_decode( $attempt->answers, true );
		$answers   = is_array( $answers ) ? $answers : array();
		$breakdown = json_decode( $attempt->question_breakdown, true );
		$breakdown = is_array( $breakdown ) ? $breakdown : array();

		// The questions this attempt actually answered, in stored order.
		$question_ids = array_values(
			array_filter(
				array_map( 'intval', array_keys( $answers ) ),
				function ( $qid ) {
					return $qid && 'qmc_question' === get_post_type( $qid );
				}
			)
		);
		?>
		<h1>
			<?php esc_html_e( 'Attempt review', 'quizzis-for-all' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading' ) ); ?>"><?php esc_html_e( '← Back to queue', 'quizzis-for-all' ); ?></a>
		</h1>

		<div class="qmc-grade-attempt">
			<div class="qmc-grade-head">
				<div>
					<strong><?php echo esc_html( get_the_title( $attempt->quiz_id ) ?: '#' . $attempt->quiz_id ); ?></strong><br>
					<span class="description">
						<?php echo esc_html( self::taker_name( $attempt ) ); ?> ·
						<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $attempt->completed_at ) ); ?> ·
						<?php echo esc_html( gmdate( 'i:s', (int) $attempt->time_taken ) ); ?>
					</span>
				</div>
				<div>
					<span class="qmc-pill <?php echo $attempt->passed ? 'qmc-pill-ok' : 'qmc-pill-bad'; ?>">
						<?php echo esc_html( $attempt->score . ' / ' . $attempt->max_score . ' — ' . $attempt->percentage . '%' ); ?>
					</span>
					<?php if ( $attempt->needs_grading ) : ?>
						<span class="qmc-pill qmc-pill-warn"><?php esc_html_e( 'Needs grading', 'quizzis-for-all' ); ?></span>
					<?php endif; ?>
					<?php
					$report = json_decode( $attempt->integrity_report ?? '', true );
					if ( is_array( $report ) && QFA_Integrity::is_flagged( $report ) ) :
						?>
						<span class="qmc-pill qmc-pill-bad" title="<?php esc_attr_e( 'Browser-reported signals — advisory only, not proof of misconduct.', 'quizzis-for-all' ); ?>">
							⚠ <?php echo esc_html( QFA_Integrity::summarize( $report ) ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'qmc_save_grades_' . $attempt_id ); ?>
				<input type="hidden" name="action" value="qmc_save_grades">
				<input type="hidden" name="attempt_id" value="<?php echo (int) $attempt_id; ?>">

				<?php
				$has_manual = false;
				foreach ( $question_ids as $qid ) {
					$data     = QFA_Question_Types::get_question_data( $qid );
					$is_manual = QFA_Question_Types::requires_manual_grading( $data['type'] );
					if ( $is_manual ) {
						$has_manual = true;
					}
					self::render_question_row( $qid, $data, $answers[ $qid ] ?? null, $breakdown[ $qid ] ?? array(), $is_manual, $attempt );
				}
				?>

				<?php if ( $has_manual && $attempt->needs_grading ) : ?>
					<div class="qmc-grade-foot">
						<?php submit_button( __( 'Save grades & update score', 'quizzis-for-all' ), 'primary', 'submit', false ); ?>
						<label>
							<input type="checkbox" name="qmc_notify_taker" value="1">
							<?php esc_html_e( 'Email the test-taker their updated result', 'quizzis-for-all' ); ?>
						</label>
					</div>
				<?php elseif ( $has_manual ) : ?>
					<div class="qmc-grade-foot">
						<span class="qmc-pill qmc-pill-ok"><?php esc_html_e( 'Already graded', 'quizzis-for-all' ); ?></span>
						<?php submit_button( __( 'Re-save grades', 'quizzis-for-all' ), 'secondary', 'submit', false ); ?>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One question block: the prompt, the answer the test-taker gave, and
	 * — for manually-graded types — the score/feedback controls.
	 */
	protected static function render_question_row( $qid, array $data, $submitted, array $info, $is_manual, $attempt ) {
		$question = get_post( $qid );
		if ( ! $question ) {
			return;
		}
		$max_points = floatval( $data['points'] );
		?>
		<div class="qmc-grade-q">
			<p class="qmc-grade-q-title">
				<?php echo esc_html( $question->post_title ); ?>
				<span class="qmc-pill qmc-pill-info"><?php echo esc_html( QFA_Question_Types::get_types()[ $data['type'] ] ?? $data['type'] ); ?></span>
				<?php if ( ! $is_manual && isset( $info['is_correct'] ) ) : ?>
					<span class="qmc-pill <?php echo $info['is_correct'] ? 'qmc-pill-ok' : 'qmc-pill-bad'; ?>">
						<?php echo $info['is_correct'] ? esc_html__( 'Correct', 'quizzis-for-all' ) : esc_html__( 'Incorrect', 'quizzis-for-all' ); ?>
					</span>
				<?php endif; ?>
			</p>

			<?php self::render_answer( $data, $submitted ); ?>

			<?php if ( $is_manual ) : ?>
				<div class="qmc-grade-controls">
					<label for="qmc_score_<?php echo (int) $qid; ?>"><?php esc_html_e( 'Points awarded:', 'quizzis-for-all' ); ?></label>
					<input type="number" step="any" min="0" max="<?php echo esc_attr( $max_points ); ?>"
						   id="qmc_score_<?php echo (int) $qid; ?>"
						   name="qmc_score[<?php echo (int) $qid; ?>]"
						   value="<?php echo esc_attr( isset( $info['manual_score'] ) ? $info['manual_score'] : '' ); ?>"
						   placeholder="0">
					<span class="description"><?php
						/* translators: %s: Maximum points available for this question. */
						printf( esc_html__( 'out of %s', 'quizzis-for-all' ), esc_html( $max_points ) );
					?></span>
				</div>
				<textarea name="qmc_feedback[<?php echo (int) $qid; ?>]" rows="2"
						  class="qmc-grade-controls"
						  placeholder="<?php esc_attr_e( 'Optional feedback for the test-taker…', 'quizzis-for-all' ); ?>"><?php echo esc_textarea( $info['manual_feedback'] ?? '' ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render whatever the test-taker submitted, in a form appropriate to
	 * the question type (file answers become a link to the attachment).
	 */
	protected static function render_answer( array $data, $submitted ) {
		if ( 'file_upload' === $data['type'] ) {
			$attachment_id = intval( $submitted );
			$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
			if ( $url ) {
				printf(
					'<div class="qmc-answer-box qmc-file-answer"><a href="%1$s" target="_blank" rel="noopener"><span class="dashicons dashicons-media-default"></span>%2$s</a></div>',
					esc_url( $url ),
					esc_html( basename( get_attached_file( $attachment_id ) ?: __( 'Download submission', 'quizzis-for-all' ) ) )
				);
			} else {
				printf( '<div class="qmc-answer-box is-empty">%s</div>', esc_html__( 'No file was uploaded.', 'quizzis-for-all' ) );
			}
			return;
		}

		if ( is_array( $submitted ) ) {
			// Matching answers are keyed pairs; everything else is a list.
			$parts = array();
			foreach ( $submitted as $k => $v ) {
				$parts[] = is_string( $k ) && ! is_numeric( $k ) ? $k . ' → ' . $v : $v;
			}
			$submitted = implode( ', ', $parts );
		}

		$text = trim( (string) $submitted );
		if ( '' === $text ) {
			printf( '<div class="qmc-answer-box is-empty">%s</div>', esc_html__( 'No answer given.', 'quizzis-for-all' ) );
			return;
		}
		printf( '<div class="qmc-answer-box">%s</div>', esc_html( $text ) );
	}

	protected static function taker_name( $attempt ) {
		if ( $attempt->user_id ) {
			return get_the_author_meta( 'display_name', $attempt->user_id );
		}
		return $attempt->guest_name ?: __( 'Guest', 'quizzis-for-all' );
	}

	/* ------------------------------------------------------------------ *
	 *  Save handler
	 * ------------------------------------------------------------------ */

	public static function handle_save_grades() {
		$attempt_id = isset( $_POST['attempt_id'] ) ? intval( $_POST['attempt_id'] ) : 0;

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'qmc_save_grades_' . $attempt_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}

		$attempt = QFA_DB::get_attempt( $attempt_id );
		if ( ! $attempt ) {
			wp_die( esc_html__( 'Attempt not found.', 'quizzis-for-all' ) );
		}

		$scores   = isset( $_POST['qmc_score'] ) && is_array( $_POST['qmc_score'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['qmc_score'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() inspects type only; values unslashed and sanitized in the same expression.
		$feedback = isset( $_POST['qmc_feedback'] ) && is_array( $_POST['qmc_feedback'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['qmc_feedback'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- as above.

		$added_score = 0;
		$added_max   = 0;
		$detail      = array();

		foreach ( $scores as $qid => $raw_score ) {
			$qid = intval( $qid );
			if ( ! $qid || 'qmc_question' !== get_post_type( $qid ) ) {
				continue;
			}
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! QFA_Question_Types::requires_manual_grading( $data['type'] ) ) {
				continue; // Never let a posted score override an auto-graded question.
			}

			$max     = floatval( $data['points'] );
			$awarded = max( 0, min( $max, floatval( $raw_score ) ) ); // Clamp into range.

			$added_score += $awarded;
			$added_max   += $max;

			$detail[ $qid ] = array(
				'is_correct'      => $max > 0 ? ( $awarded >= $max ) : null,
				'manual_score'    => $awarded,
				'manual_max'      => $max,
				'manual_feedback' => $feedback[ $qid ] ?? '',
				'graded_by'       => get_current_user_id(),
				'graded_at'       => current_time( 'mysql' ),
			);
		}

		// Re-grading an already-graded attempt: strip the previously added
		// manual points/max back out first so totals don't compound.
		if ( ! $attempt->needs_grading ) {
			$old = json_decode( $attempt->question_breakdown, true );
			$old = is_array( $old ) ? $old : array();
			foreach ( $old as $qid => $info ) {
				if ( isset( $info['manual_score'] ) ) {
					$added_score -= floatval( $info['manual_score'] );
					$added_max   -= floatval( $info['manual_max'] ?? 0 );
				}
			}
		}

		$result = QFA_DB::apply_manual_grades( $attempt_id, $added_score, $added_max, $detail );

		// Issue a certificate if the manual points pushed them over the line.
		$certificate_url = '';
		if ( $result && $result['passed'] && empty( $attempt->certificate_token ) ) {
			$certificate_url = QFA_Certificates::maybe_issue( $attempt->quiz_id, $attempt_id, true );
		}

		if ( ! empty( $_POST['qmc_notify_taker'] ) && $result ) {
			$email = $attempt->user_id ? get_the_author_meta( 'user_email', $attempt->user_id ) : $attempt->guest_email;
			$name  = self::taker_name( $attempt );
			if ( $email ) {
				/* translators: 1: score percentage, 2: "Passed" or "Not passed" */
				$result_text = sprintf( __( '%1$s%% — %2$s (final, after grading)', 'quizzis-for-all' ), $result['percentage'], $result['passed'] ? __( 'Passed', 'quizzis-for-all' ) : __( 'Not passed', 'quizzis-for-all' ) );
				QFA_Notifications::notify_graded( $attempt->quiz_id, $email, $name, $result_text, $certificate_url );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=qmc_grading&graded=1' ) );
		exit;
	}
}
