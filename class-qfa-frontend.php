<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QFA_Frontend {

	public static function init() {
		add_shortcode( 'qmc_quiz', array( __CLASS__, 'shortcode' ) );
		// New-branding alias; the original stays registered so shortcodes
		// already saved in posts and pages keep working after the rename.
		add_shortcode( 'qfa_quiz', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_qmc_submit_quiz', array( __CLASS__, 'ajax_submit_quiz' ) );
		add_action( 'wp_ajax_nopriv_qmc_submit_quiz', array( __CLASS__, 'ajax_submit_quiz' ) );
	}

	public static function assets() {
		wp_register_style( 'qfa-frontend', QFA_PLUGIN_URL . 'assets/css/qfa-frontend.css', array(), QFA_VERSION );
		wp_register_script( 'qfa-frontend', QFA_PLUGIN_URL . 'assets/js/qfa-frontend.js', array(), QFA_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		$quiz_id = intval( $atts['id'] );
		$quiz    = get_post( $quiz_id );

		if ( ! $quiz || 'qmc_quiz' !== $quiz->post_type ) {
			return '<p>' . esc_html__( 'Quiz not found.', 'quizzis-for-all' ) . '</p>';
		}

		$require_login = get_post_meta( $quiz_id, '_qmc_require_login', true );
		if ( $require_login && ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to take this quiz.', 'quizzis-for-all' ) . '</p>';
		}

		if ( ! QFA_Quiz_Types::prerequisite_met( $quiz_id, get_current_user_id() ) ) {
			$prereq = QFA_Quiz_Types::get_prerequisite_quiz( $quiz_id );
			return '<p>' . sprintf(
				/* translators: %s: prerequisite quiz title */
				esc_html__( 'You need to pass "%s" before taking this quiz.', 'quizzis-for-all' ),
				esc_html( $prereq ? $prereq->post_title : '' )
			) . '</p>';
		}

		$max_attempts = intval( get_post_meta( $quiz_id, '_qmc_max_attempts', true ) );
		if ( $max_attempts && is_user_logged_in() ) {
			$used = QFA_DB::count_attempts( $quiz_id, get_current_user_id() );
			if ( $used >= $max_attempts ) {
				return '<p>' . esc_html__( 'You have used all your attempts for this quiz.', 'quizzis-for-all' ) . '</p>';
			}
		}

		$integrity = QFA_Integrity::get_settings( $quiz_id );

		// One-attempt-in-flight: an in-progress row that wasn't started in
		// this browser session means the quiz is already open elsewhere.
		if ( $integrity['single_session'] && is_user_logged_in() ) {
			$live = QFA_DB::get_in_progress( $quiz_id, get_current_user_id() );
			if ( $live && empty( $_COOKIE[ 'qmc_session_' . $quiz_id ] ) ) {
				return '<p>' . esc_html__( 'This quiz is already open in another tab or device. Finish or close that attempt before starting again.', 'quizzis-for-all' ) . '</p>';
			}
		}

		wp_enqueue_style( 'qfa-frontend' );
		wp_enqueue_script( 'qfa-frontend' );
		wp_localize_script(
			'qfa-frontend',
			'QFA_Data',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'qmc_frontend_nonce' ),
			)
		);

		$question_ids = self::get_render_question_ids( $quiz_id );

		if ( get_post_meta( $quiz_id, '_qmc_randomize_questions', true ) ) {
			shuffle( $question_ids );
		}

		$timer_minutes  = intval( get_post_meta( $quiz_id, '_qmc_timer_minutes', true ) );
		$show_progress  = get_post_meta( $quiz_id, '_qmc_show_progress_bar', true );
		$per_page       = intval( get_post_meta( $quiz_id, '_qmc_questions_per_page', true ) );
		$is_popup       = QFA_Quiz_Types::is_popup( $quiz_id );
		$h5p_id         = intval( get_post_meta( $quiz_id, '_qmc_h5p_content_id', true ) );
		$allow_resume   = QFA_Progress::allows_resume( $quiz_id ) && is_user_logged_in();
		$saved_progress = $allow_resume ? QFA_DB::get_in_progress( $quiz_id, get_current_user_id() ) : null;

		ob_start();

		if ( $h5p_id && shortcode_exists( 'h5p' ) ) {
			echo '<div class="qmc-h5p-embed">' . do_shortcode( '[h5p id="' . (int) $h5p_id . '"]' ) . '</div>';
		}

		if ( $is_popup ) {
			printf(
				'<button type="button" class="qmc-btn qmc-popup-trigger" data-target="qmc-quiz-%1$d">%2$s</button>
				 <div class="qmc-popup-overlay" id="qmc-quiz-%1$d-overlay" style="display:none;">
				 <div class="qmc-popup-modal"><button type="button" class="qmc-popup-close">&times;</button>',
				(int) $quiz_id,
				esc_html__( 'Take the Quiz', 'quizzis-for-all' )
			);
		}
		?>
		<div class="qmc-quiz" id="qmc-quiz-<?php echo (int) $quiz_id; ?>"
			 data-quiz-id="<?php echo (int) $quiz_id; ?>"
			 data-timer="<?php echo (int) $timer_minutes; ?>"
			 data-per-page="<?php echo (int) $per_page; ?>"
			 data-allow-resume="<?php echo $allow_resume ? '1' : '0'; ?>"
			 data-quiz-mode="<?php echo esc_attr( QFA_Quiz_Types::get_mode( $quiz_id ) ); ?>"
			 data-copy-protect="<?php echo $integrity['copy_protect'] ? '1' : '0'; ?>"
			 data-tab-warn="<?php echo $integrity['tab_warn'] ? '1' : '0'; ?>"
			 data-tab-limit="<?php echo (int) $integrity['tab_limit']; ?>"
			 data-log-events="<?php echo $integrity['log_events'] ? '1' : '0'; ?>"
			 <?php
				$accent = get_post_meta( $quiz_id, '_qmc_accent_color', true );
				if ( $accent ) {
					echo 'style="--qmc-accent:' . esc_attr( $accent ) . ';"';
				}
				?>>

			<div class="qmc-intro">
				<h2><?php echo esc_html( get_the_title( $quiz_id ) ); ?></h2>
				<?php echo wp_kses_post( apply_filters( 'the_content', $quiz->post_content ) ); ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of questions */
						esc_html__( 'This quiz has %d question(s).', 'quizzis-for-all' ),
						count( $question_ids )
					);
					if ( $timer_minutes ) {
						/* translators: %d: time limit in minutes */
						printf( ' ' . esc_html__( 'Time limit: %d minute(s).', 'quizzis-for-all' ), (int) $timer_minutes );
					}
					?>
				</p>
				<button type="button" class="qmc-btn qmc-start-btn"><?php esc_html_e( 'Start Quiz', 'quizzis-for-all' ); ?></button>
				<?php if ( $saved_progress ) : ?>
					<button type="button" class="qmc-btn qmc-btn-secondary qmc-resume-btn"><?php esc_html_e( 'Resume where you left off', 'quizzis-for-all' ); ?></button>
				<?php endif; ?>
			</div>

			<form class="qmc-form" style="display:none;">
				<?php if ( $timer_minutes ) : ?>
					<div class="qmc-timer">⏱ <span class="qmc-timer-display"></span></div>
				<?php endif; ?>
				<?php if ( $show_progress ) : ?>
					<div class="qmc-progress-outer"><div class="qmc-progress-inner" style="width:0%"></div></div>
				<?php endif; ?>

				<?php if ( $integrity['honeypot'] ) : ?>
					<div class="qmc-hp" aria-hidden="true">
						<label><?php esc_html_e( 'Leave this field empty', 'quizzis-for-all' ); ?>
						<input type="text" name="qmc_hp" tabindex="-1" autocomplete="off"></label>
					</div>
				<?php endif; ?>

				<div class="qmc-questions">
					<?php
					$index = 0;
					foreach ( $question_ids as $qid ) :
						$q = get_post( $qid );
						if ( ! $q ) {
							continue;
						}
						$data = QFA_Question_Types::get_question_data( $qid );
						if ( ! empty( $data['options'] ) && get_post_meta( $quiz_id, '_qmc_randomize_answers', true ) ) {
							shuffle( $data['options'] );
						}
						$page = $per_page > 0 ? intval( $index / $per_page ) : 0;
						?>
						<div class="qmc-question" data-page="<?php echo (int) $page; ?>" data-qid="<?php echo (int) $qid; ?>" data-required="<?php echo $data['required'] ? '1' : '0'; ?>" data-type="<?php echo esc_attr( $data['type'] ); ?>">
							<?php if ( 'info' !== $data['type'] ) : ?>
								<p class="qmc-q-title"><?php echo esc_html( ( $index + 1 ) . '. ' . $q->post_title ); ?><?php echo $data['required'] ? ' <span class="qmc-required">*</span>' : ''; ?></p>
							<?php else : ?>
								<div class="qmc-info-banner"><?php echo wp_kses_post( $q->post_title ); ?></div>
							<?php endif; ?>

							<div class="qmc-q-field">
								<?php QFA_Question_Types::render_frontend_field( $qid, $data, $index ); ?>
							</div>

							<?php if ( ! empty( $data['hint'] ) && get_post_meta( $quiz_id, '_qmc_show_hints', true ) ) : ?>
								<p class="qmc-hint-toggle"><a href="#"><?php esc_html_e( 'Show hint', 'quizzis-for-all' ); ?></a>
								<span class="qmc-hint-text" style="display:none;"><?php echo esc_html( $data['hint'] ); ?></span></p>
							<?php endif; ?>

							<div class="qmc-feedback" style="display:none;"></div>
						</div>
						<?php
						$index++;
					endforeach;
					?>
				</div>

				<div class="qmc-nav">
					<button type="button" class="qmc-btn qmc-prev-btn" style="display:none;"><?php esc_html_e( 'Previous', 'quizzis-for-all' ); ?></button>
					<button type="button" class="qmc-btn qmc-next-btn"><?php esc_html_e( 'Next', 'quizzis-for-all' ); ?></button>
					<button type="submit" class="qmc-btn qmc-submit-btn" style="display:none;"><?php esc_html_e( 'Submit Quiz', 'quizzis-for-all' ); ?></button>
				</div>
			</form>

			<div class="qmc-results" style="display:none;"></div>
			<?php if ( $saved_progress ) : ?>
				<script type="application/json" class="qmc-saved-progress"><?php
					$bd = json_decode( $saved_progress->question_breakdown, true );
					echo wp_json_encode(
						array(
							'answers'       => json_decode( $saved_progress->answers ?: '{}' ),
							'current_index' => is_array( $bd ) ? intval( $bd['current_index'] ?? 0 ) : 0,
							'time_elapsed'  => intval( $saved_progress->time_taken ),
						)
					);
					?></script>
			<?php endif; ?>
		</div>
		<?php
		if ( $is_popup ) {
			echo '</div></div>';
		}
		return ob_get_clean();
	}

	/**
	 * The question set to render. Normally the quiz's fixed, ordered list;
	 * in dynamic (category-selective) mode, a fresh random draw from the
	 * question bank — optionally restricted to chosen categories — on
	 * every attempt, so no two attempts need see the same paper.
	 */
	protected static function get_render_question_ids( $quiz_id ) {
		if ( ! get_post_meta( $quiz_id, '_qmc_dynamic_enabled', true ) ) {
			$ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
			return is_array( $ids ) ? $ids : array();
		}

		$count = max( 1, intval( get_post_meta( $quiz_id, '_qmc_dynamic_count', true ) ) ?: 10 );
		$cats  = get_post_meta( $quiz_id, '_qmc_dynamic_categories', true );
		$cats  = is_array( $cats ) ? array_map( 'intval', $cats ) : array();

		$args = array(
			'post_type'      => 'qmc_question',
			'posts_per_page' => $count,
			'orderby'        => 'rand',
			'fields'         => 'ids',
		);
		if ( ! empty( $cats ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- category-selective quizzes are the feature; the bank is an editor-curated post type, not user content at scale.
				array(
					'taxonomy' => 'qmc_question_category',
					'field'    => 'term_id',
					'terms'    => $cats,
				),
			);
		}
		return get_posts( $args );
	}

	/**
	 * The question set to grade. For fixed quizzes this is the stored
	 * list; for dynamic quizzes the rendered set differed per attempt, so
	 * grade exactly the (validated) question IDs the submission answered.
	 */
	protected static function get_grading_question_ids( $quiz_id, array $answers ) {
		if ( ! get_post_meta( $quiz_id, '_qmc_dynamic_enabled', true ) ) {
			$ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
			return is_array( $ids ) ? $ids : array();
		}

		$count = max( 1, intval( get_post_meta( $quiz_id, '_qmc_dynamic_count', true ) ) ?: 10 );
		$cats  = get_post_meta( $quiz_id, '_qmc_dynamic_categories', true );
		$cats  = is_array( $cats ) ? array_map( 'intval', $cats ) : array();

		$ids = array();
		foreach ( array_keys( $answers ) as $qid ) {
			$qid = intval( $qid );
			if ( ! $qid || 'qmc_question' !== get_post_type( $qid ) || 'publish' !== get_post_status( $qid ) ) {
				continue; // Not a real bank question — ignore.
			}
			if ( ! empty( $cats ) && ! has_term( $cats, 'qmc_question_category', $qid ) ) {
				continue; // Outside the quiz's configured categories — ignore.
			}
			$ids[] = $qid;
			if ( count( $ids ) >= $count ) {
				break; // Never grade more questions than the quiz serves.
			}
		}
		return $ids;
	}

	/**
	 * AJAX: grade a submitted quiz, handle any file-upload answers, and
	 * store the attempt (including a per-question breakdown used for
	 * reporting regardless of whether it's shown to the test-taker).
	 */
	public static function ajax_submit_quiz() {
		check_ajax_referer( 'qmc_frontend_nonce', 'nonce' );

		$quiz_id    = intval( $_POST['quiz_id'] ?? 0 );
		// The raw JSON string can't be run through sanitize_text_field()
		// without corrupting its structure (quotes/backslashes are
		// meaningful JSON syntax); wp_unslash() alone is correct here.
		// json_decode() safely returns null on malformed input, and every
		// individual value pulled out of the decoded array is validated
		// and sanitized per-question-type in QFA_Question_Types::grade().
		$answers    = isset( $_POST['answers'] ) ? json_decode( wp_unslash( $_POST['answers'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$answers    = is_array( $answers ) ? $answers : array();
		$time_taken = intval( $_POST['time_taken'] ?? 0 );
		$user_id    = get_current_user_id();

		$quiz = get_post( $quiz_id );
		if ( ! $quiz || 'qmc_quiz' !== $quiz->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid quiz.', 'quizzis-for-all' ) ) );
		}

		// Server-enforced integrity checks (honeypot, minimum duration).
		$integrity_error = QFA_Integrity::validate_submission( $quiz_id, $time_taken, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validate_submission only tests emptiness of its own honeypot field; no value is stored or echoed.
		if ( '' !== $integrity_error ) {
			wp_send_json_error( array( 'message' => $integrity_error ) );
		}

		$integrity_report = QFA_Integrity::sanitize_report(
			isset( $_POST['integrity'] ) ? wp_unslash( $_POST['integrity'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_report() casts every field to int; raw JSON can't be pre-sanitized without corrupting it.
		);

		$max_attempts = intval( get_post_meta( $quiz_id, '_qmc_max_attempts', true ) );
		if ( $max_attempts && $user_id ) {
			$used = QFA_DB::count_attempts( $quiz_id, $user_id );
			if ( $used >= $max_attempts ) {
				wp_send_json_error( array( 'message' => __( 'No attempts remaining.', 'quizzis-for-all' ) ) );
			}
		}

		$question_ids = self::get_grading_question_ids( $quiz_id, $answers );
		$mode         = QFA_Quiz_Types::get_mode( $quiz_id );

		$score        = 0;
		$max_score    = 0;
		$per_question = array();
		$needs_manual = false;

		foreach ( $question_ids as $qid ) {
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! QFA_Question_Types::is_scoreable( $data['type'] ) ) {
				continue;
			}

			// File-upload answers arrive as $_FILES, not in $answers.
			if ( 'file_upload' === $data['type'] ) {
				$submitted = self::handle_file_answer( $qid, $data );
				$answers[ $qid ] = $submitted ? $submitted : '';
				$needs_manual = true;
			} else {
				$submitted = $answers[ $qid ] ?? null;
			}

			list( $is_correct, $points_awarded ) = QFA_Question_Types::grade( $data, $submitted );

			// Personality quizzes don't use points at all — scored/manual
			// grading is still recorded for reporting, but never affects
			// the outcome.
			if ( 'personality' !== $mode ) {
				if ( QFA_Question_Types::requires_manual_grading( $data['type'] ) ) {
					$needs_manual = true;
				} else {
					$max_score += floatval( $data['points'] );
					$score     += $points_awarded;
				}
			}

			$per_question[ $qid ] = array(
				'is_correct'  => $is_correct,
				'explanation' => $data['explanation'],
			);
		}

		$personality_result = null;
		$percentage = 0;
		$passed     = false;
		$pass_mark  = 0;

		if ( 'personality' === $mode ) {
			$personality_result = QFA_Quiz_Types::grade_personality( $quiz_id, $question_ids, $answers );
			$per_question['_personality'] = $personality_result;
		} else {
			$percentage = $max_score > 0 ? round( ( $score / $max_score ) * 100, 2 ) : 0;
			$pass_mark  = floatval( get_post_meta( $quiz_id, '_qmc_pass_mark', true ) );
			$passed     = $percentage >= $pass_mark;
		}

		$attempt_data = array(
			'quiz_id'            => $quiz_id,
			'user_id'            => $user_id,
			'guest_name'         => sanitize_text_field( wp_unslash( $_POST['guest_name'] ?? '' ) ),
			'guest_email'        => sanitize_email( wp_unslash( $_POST['guest_email'] ?? '' ) ),
			'score'              => $score,
			'max_score'          => $max_score,
			'percentage'         => $percentage,
			'passed'             => $passed ? 1 : 0,
			'answers'            => wp_json_encode( $answers ),
			'question_breakdown' => wp_json_encode( $per_question ),
			'time_taken'         => $time_taken,
			// Flagged so the Grading queue can find this attempt with an
			// indexed lookup instead of scanning stored JSON.
			'needs_grading'      => $needs_manual ? 1 : 0,
			// Client-reported and advisory only — recorded for an
			// instructor to interpret, never used to auto-fail.
			'integrity_report'   => $integrity_report ? wp_json_encode( $integrity_report ) : '',
		);

		// If this finishes a previously saved (in-progress) attempt, turn
		// that same row into the completed record instead of inserting a
		// second one.
		$existing = $user_id ? QFA_DB::get_in_progress( $quiz_id, $user_id ) : null;
		if ( $existing ) {
			QFA_DB::complete_attempt( $existing->id, $attempt_data );
			$attempt_id = $existing->id;
		} else {
			$attempt_id = QFA_DB::insert_attempt( $attempt_data );
		}

		$new_badges = array();
		if ( $user_id ) {
			$new_badges = QFA_Gamification::process_attempt( $user_id, $quiz_id, $score, $percentage, $passed );
		}

		$certificate_url = QFA_Certificates::maybe_issue( $quiz_id, $attempt_id, $passed );

		$next_quiz = null;
		if ( $passed ) {
			$next = QFA_Quiz_Types::get_next_quiz( $quiz_id );
			if ( $next ) {
				$next_quiz = array(
					'title' => $next->post_title,
					'url'   => get_permalink( $next ),
				);
			}
		}

		// Fire notification emails, if the quiz has them enabled.
		$recipient_email = $user_id ? wp_get_current_user()->user_email : sanitize_email( wp_unslash( $_POST['guest_email'] ?? '' ) );
		$recipient_name  = $user_id ? wp_get_current_user()->display_name : sanitize_text_field( wp_unslash( $_POST['guest_name'] ?? '' ) );
		$result_text      = $personality_result
			/* translators: %s: personality outcome label */
			? sprintf( __( 'Result: %s', 'quizzis-for-all' ), $personality_result['label'] )
			/* translators: 1: score percentage, 2: "Passed" or "Not passed" */
			: sprintf( __( '%1$s%% — %2$s', 'quizzis-for-all' ), $percentage, $passed ? __( 'Passed', 'quizzis-for-all' ) : __( 'Not passed', 'quizzis-for-all' ) );
		QFA_Notifications::notify( $quiz_id, $recipient_email, $recipient_name, $result_text, $certificate_url );

		$show_correct = get_post_meta( $quiz_id, '_qmc_show_correct_answers', true );

		wp_send_json_success(
			array(
				'attempt_id'   => $attempt_id,
				'mode'         => $mode,
				'score'        => $score,
				'max_score'    => $max_score,
				'percentage'   => $percentage,
				'passed'       => $passed,
				'pass_mark'    => $pass_mark,
				'personality'  => $personality_result,
				'per_question' => $show_correct ? $per_question : array(),
				'show_correct' => (bool) $show_correct,
				'needs_manual' => $needs_manual,
				'new_badges'   => $new_badges,
				'certificate_url' => $certificate_url,
				'next_quiz'    => $next_quiz,
			)
		);
	}

	/**
	 * Validate and store an uploaded file-answer as a media library
	 * attachment. Returns the attachment ID, or '' if nothing/invalid was
	 * uploaded. Validation failures are silently skipped (treated as no
	 * answer) rather than fatal-ing the whole quiz submission.
	 *
	 * Note: this is only ever called from ajax_submit_quiz(), which
	 * verifies the request nonce via check_ajax_referer() before any
	 * $_POST/$_FILES data (including this method's $_FILES read) is
	 * touched — so no separate nonce check is needed here.
	 */
	protected static function handle_file_answer( $question_id, array $data ) {
		$field_key = 'qmc_file_' . $question_id;
		if ( empty( $_FILES[ $field_key ] ) || empty( $_FILES[ $field_key ]['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in the calling ajax_submit_quiz().
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified by the caller; individual fields are validated/sanitized just below (extension allow-list, size cap) before any use.
		$file      = wp_unslash( $_FILES[ $field_key ] );
		$file_name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext       = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		$allowed   = array_map( 'trim', explode( ',', strtolower( $data['allowed_types'] ) ) );
		$max_bytes = floatval( $data['max_file_size_mb'] ) * 1024 * 1024;
		$size      = isset( $file['size'] ) ? intval( $file['size'] ) : 0;
		$error     = isset( $file['error'] ) ? intval( $file['error'] ) : 0;

		if ( ! in_array( $ext, $allowed, true ) || $size > $max_bytes || 0 !== $error ) {
			return '';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( $field_key, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return '';
		}

		return $attachment_id;
	}
}
