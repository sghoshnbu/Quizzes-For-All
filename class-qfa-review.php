<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-facing per-attempt review.
 *
 * Reached from the user dashboard ([qmc_user_dashboard]) or the
 * [qmc_attempt_review] shortcode. Access is deliberately strict: an
 * attempt can only be reviewed by the logged-in user who made it (guests
 * can't review at all, since there's no identity to check against), and
 * only if the quiz's own review policy allows it.
 *
 * The policy is per quiz because exam use and practice use want opposite
 * defaults — a practice quiz should show everything immediately, a real
 * exam usually shouldn't reveal correct answers at all, or only after
 * grading is complete.
 */
class QFA_Review {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_qmc_quiz', array( __CLASS__, 'save_settings' ) );
		add_shortcode( 'qmc_attempt_review', array( __CLASS__, 'shortcode' ) );
		// New-branding alias; original stays registered for existing content.
		add_shortcode( 'qfa_attempt_review', array( __CLASS__, 'shortcode' ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Per-quiz policy
	 * ------------------------------------------------------------------ */

	public static function get_policy( $quiz_id ) {
		$val = get_post_meta( $quiz_id, '_qmc_review_policy', true );
		return $val ? $val : 'full'; // Default: students may review fully.
	}

	public static function shows_correct_answers( $quiz_id, $attempt ) {
		switch ( self::get_policy( $quiz_id ) ) {
			case 'none':
				return false;
			case 'score_only':
				return false;
			case 'after_grading':
				return ! $attempt->needs_grading;
			case 'full':
			default:
				return true;
		}
	}

	public static function allows_review( $quiz_id ) {
		return 'none' !== self::get_policy( $quiz_id );
	}

	public static function meta_box() {
		add_meta_box( 'qmc_review_policy', __( 'Student Review', 'quizzis-for-all' ), array( __CLASS__, 'render_settings' ), 'qmc_quiz', 'side', 'default' );
	}

	public static function render_settings( $post ) {
		wp_nonce_field( 'qmc_save_review', 'qmc_review_nonce' );
		$policy = self::get_policy( $post->ID );
		?>
		<p><label for="qmc_review_policy"><?php esc_html_e( 'What can students see afterwards?', 'quizzis-for-all' ); ?></label></p>
		<select name="qmc_review_policy" id="qmc_review_policy" style="width:100%;">
			<option value="full" <?php selected( $policy, 'full' ); ?>><?php esc_html_e( 'Full review — answers + correct answers', 'quizzis-for-all' ); ?></option>
			<option value="after_grading" <?php selected( $policy, 'after_grading' ); ?>><?php esc_html_e( 'Full review, but only once graded', 'quizzis-for-all' ); ?></option>
			<option value="score_only" <?php selected( $policy, 'score_only' ); ?>><?php esc_html_e( 'Their answers and score, but not the correct answers', 'quizzis-for-all' ); ?></option>
			<option value="none" <?php selected( $policy, 'none' ); ?>><?php esc_html_e( 'No review at all (score only on the dashboard)', 'quizzis-for-all' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Use a stricter setting for real exams so answer keys do not leak between cohorts.', 'quizzis-for-all' ); ?></p>
		<?php
	}

	public static function save_settings( $post_id ) {
		if ( ! isset( $_POST['qmc_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_review_nonce'] ) ), 'qmc_save_review' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$policy = isset( $_POST['qmc_review_policy'] ) ? sanitize_key( wp_unslash( $_POST['qmc_review_policy'] ) ) : 'full';
		update_post_meta( $post_id, '_qmc_review_policy', in_array( $policy, array( 'full', 'after_grading', 'score_only', 'none' ), true ) ? $policy : 'full' );
	}

	/* ------------------------------------------------------------------ *
	 *  Shortcode
	 * ------------------------------------------------------------------ */

	public static function review_url( $attempt_id ) {
		// Land back on whatever page hosts the dashboard shortcode; the
		// shortcode itself reads qmc_attempt from the query string.
		return add_query_arg( 'qmc_attempt', (int) $attempt_id );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );

		$attempt_id = intval( $atts['id'] );
		if ( ! $attempt_id && isset( $_GET['qmc_attempt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view of the viewer's own attempt; ownership is enforced below.
			$attempt_id = intval( $_GET['qmc_attempt'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $attempt_id ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to review your attempt.', 'quizzis-for-all' ) . '</p>';
		}

		$attempt = QFA_DB::get_attempt( $attempt_id );

		// Ownership check: you may only ever review your own attempt.
		// Instructors use the admin screen, not this one.
		if ( ! $attempt || intval( $attempt->user_id ) !== get_current_user_id() ) {
			return '<p>' . esc_html__( 'Attempt not found.', 'quizzis-for-all' ) . '</p>';
		}

		if ( ! self::allows_review( $attempt->quiz_id ) ) {
			return '<p>' . esc_html__( 'Review is not available for this quiz.', 'quizzis-for-all' ) . '</p>';
		}

		wp_enqueue_style( 'qfa-frontend' );

		$show_correct = self::shows_correct_answers( $attempt->quiz_id, $attempt );
		$answers      = json_decode( $attempt->answers, true );
		$answers      = is_array( $answers ) ? $answers : array();
		$breakdown    = json_decode( $attempt->question_breakdown, true );
		$breakdown    = is_array( $breakdown ) ? $breakdown : array();
		$personality  = $breakdown['_personality'] ?? null;

		ob_start();
		?>
		<div class="qmc-review">
			<div class="qmc-review-head">
				<h2><?php echo esc_html( get_the_title( $attempt->quiz_id ) ); ?></h2>
				<?php if ( $personality ) : ?>
					<p class="qmc-review-score"><?php echo esc_html( $personality['label'] ); ?></p>
				<?php else : ?>
					<p class="qmc-review-score <?php echo $attempt->passed ? 'is-pass' : 'is-fail'; ?>">
						<?php echo esc_html( $attempt->percentage ); ?>%
						<span><?php echo esc_html( $attempt->score . ' / ' . $attempt->max_score ); ?></span>
					</p>
				<?php endif; ?>
				<p class="qmc-review-meta">
					<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $attempt->completed_at ) ); ?>
					· <?php echo esc_html( gmdate( 'i:s', (int) $attempt->time_taken ) ); ?>
					<?php if ( $attempt->needs_grading ) : ?>
						· <span class="qmc-review-pending"><?php esc_html_e( 'Some answers are still being graded', 'quizzis-for-all' ); ?></span>
					<?php endif; ?>
				</p>
			</div>

			<?php if ( 'score_only' === self::get_policy( $attempt->quiz_id ) ) : ?>
				<p class="qmc-review-note"><?php esc_html_e( 'Your answers are shown below. Correct answers are not released for this quiz.', 'quizzis-for-all' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $answers as $qid => $submitted ) {
				$qid = intval( $qid );
				if ( ! $qid || 'qmc_question' !== get_post_type( $qid ) ) {
					continue;
				}
				self::render_question( $qid, $submitted, $breakdown[ $qid ] ?? array(), $show_correct );
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function render_question( $qid, $submitted, array $info, $show_correct ) {
		$question = get_post( $qid );
		if ( ! $question ) {
			return;
		}
		$data       = QFA_Question_Types::get_question_data( $qid );
		$is_correct = $info['is_correct'] ?? null;
		$state      = null === $is_correct ? 'pending' : ( $is_correct ? 'correct' : 'incorrect' );
		?>
		<div class="qmc-review-q is-<?php echo esc_attr( $state ); ?>">
			<p class="qmc-review-q-title"><?php echo esc_html( $question->post_title ); ?></p>

			<div class="qmc-review-answer">
				<span class="qmc-review-label"><?php esc_html_e( 'Your answer', 'quizzis-for-all' ); ?></span>
				<?php echo esc_html( self::format_answer( $data, $submitted ) ); ?>
			</div>

			<?php if ( $show_correct && null !== $is_correct && ! $is_correct ) : ?>
				<div class="qmc-review-answer is-key">
					<span class="qmc-review-label"><?php esc_html_e( 'Correct answer', 'quizzis-for-all' ); ?></span>
					<?php echo esc_html( self::format_correct( $data ) ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $info['manual_feedback'] ) ) : ?>
				<div class="qmc-review-feedback">
					<span class="qmc-review-label"><?php esc_html_e( 'Instructor feedback', 'quizzis-for-all' ); ?></span>
					<?php echo esc_html( $info['manual_feedback'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $show_correct && ! empty( $data['explanation'] ) ) : ?>
				<div class="qmc-review-explanation"><?php echo wp_kses_post( $data['explanation'] ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Human-readable rendering of whatever the student submitted. */
	public static function format_answer( array $data, $submitted ) {
		switch ( $data['type'] ) {
			case 'radio':
			case 'checkbox':
				$selected = is_array( $submitted ) ? $submitted : array( $submitted );
				$labels   = array();
				foreach ( $data['options'] as $opt ) {
					if ( in_array( $opt['id'], $selected, true ) ) {
						$labels[] = $opt['text'];
					}
				}
				return $labels ? implode( ', ', $labels ) : __( 'No answer given', 'quizzis-for-all' );

			case 'matching':
				$parts = array();
				foreach ( (array) $submitted as $pair_id => $chosen ) {
					foreach ( $data['pairs'] as $pair ) {
						if ( $pair['id'] === $pair_id ) {
							$parts[] = $pair['left'] . ' → ' . $chosen;
						}
					}
				}
				return $parts ? implode( '; ', $parts ) : __( 'No answer given', 'quizzis-for-all' );

			case 'file_upload':
				return intval( $submitted ) ? __( 'File submitted', 'quizzis-for-all' ) : __( 'No file uploaded', 'quizzis-for-all' );

			case 'fill_blanks':
				return implode( ' | ', (array) $submitted ) ?: __( 'No answer given', 'quizzis-for-all' );

			default:
				$text = is_array( $submitted ) ? implode( ', ', $submitted ) : (string) $submitted;
				return '' !== trim( $text ) ? $text : __( 'No answer given', 'quizzis-for-all' );
		}
	}

	/** Human-readable rendering of the answer key for one question. */
	public static function format_correct( array $data ) {
		switch ( $data['type'] ) {
			case 'radio':
			case 'checkbox':
				$correct = is_array( $data['correct'] ) ? $data['correct'] : array( $data['correct'] );
				$labels  = array();
				foreach ( $data['options'] as $opt ) {
					if ( in_array( $opt['id'], $correct, true ) ) {
						$labels[] = $opt['text'];
					}
				}
				return implode( ', ', $labels );

			case 'true_false':
				return 'true' === $data['correct'] ? __( 'True', 'quizzis-for-all' ) : __( 'False', 'quizzis-for-all' );

			case 'short_text':
				return implode( __( ' or ', 'quizzis-for-all' ), (array) $data['correct'] );

			case 'number':
				$val = $data['correct']['value'] ?? 0;
				$tol = $data['correct']['tolerance'] ?? 0;
				return $tol ? $val . ' ± ' . $tol : (string) $val;

			case 'date':
				return (string) $data['correct'];

			case 'fill_blanks':
				return implode( ' | ', (array) $data['correct'] );

			case 'matching':
				$parts = array();
				foreach ( $data['pairs'] as $pair ) {
					$parts[] = $pair['left'] . ' → ' . $pair['right'];
				}
				return implode( '; ', $parts );

			default:
				return __( 'Graded manually', 'quizzis-for-all' );
		}
	}
}
