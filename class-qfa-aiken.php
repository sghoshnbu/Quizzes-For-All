<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aiken is a simple plain-text quiz format (used by Moodle and others):
 *
 *   What is the capital of France?
 *   A. London
 *   B. Paris
 *   C. Berlin
 *   D. Madrid
 *   ANSWER: B
 *
 * Blocks are separated by a blank line. This class imports .txt files in
 * that format into the question bank (as Radio questions), and exports a
 * quiz's Radio / True-False questions back out to Aiken.
 */
class QFA_Aiken {

	/**
	 * Parse raw Aiken text into an array of
	 * [ 'question' => string, 'options' => [ 'A' => text, ... ], 'answer' => 'B' ].
	 */
	public static function parse( $text ) {
		$text   = str_replace( "\r\n", "\n", $text );
		$blocks = preg_split( "/\n\s*\n/", trim( $text ) );
		$parsed = array();

		foreach ( $blocks as $block ) {
			$lines = array_values( array_filter( array_map( 'rtrim', explode( "\n", trim( $block ) ) ), 'strlen' ) );
			if ( count( $lines ) < 3 ) {
				continue; // Not enough lines for a question + at least 2 options + answer.
			}

			$question_lines = array();
			$options        = array();
			$answer         = '';

			foreach ( $lines as $line ) {
				if ( preg_match( '/^ANSWER\s*:\s*([A-Za-z0-9])\s*$/i', $line, $m ) ) {
					$answer = strtoupper( $m[1] );
				} elseif ( preg_match( '/^([A-Za-z0-9])[.)]\s+(.*)$/', $line, $m ) ) {
					$options[ strtoupper( $m[1] ) ] = trim( $m[2] );
				} else {
					$question_lines[] = $line;
				}
			}

			if ( empty( $question_lines ) || empty( $options ) || '' === $answer ) {
				continue; // Malformed block — skip it rather than fail the whole import.
			}

			$parsed[] = array(
				'question' => trim( implode( ' ', $question_lines ) ),
				'options'  => $options,
				'answer'   => $answer,
			);
		}

		return $parsed;
	}

	/**
	 * Create qmc_question posts (type=radio) from parsed Aiken blocks.
	 * Returns array of new question post IDs.
	 */
	public static function import( array $parsed, $category_id = 0 ) {
		$new_ids = array();

		foreach ( $parsed as $item ) {
			$question_id = wp_insert_post(
				array(
					'post_type'   => 'qmc_question',
					'post_title'  => wp_strip_all_tags( $item['question'] ),
					'post_status' => 'publish',
				)
			);
			if ( is_wp_error( $question_id ) || ! $question_id ) {
				continue;
			}

			$options       = array();
			$letter_to_id  = array();
			$i             = 0;
			foreach ( $item['options'] as $letter => $text ) {
				$opt_id                 = 'opt_' . $i;
				$options[]              = array( 'id' => $opt_id, 'text' => $text );
				$letter_to_id[ $letter ] = $opt_id;
				$i++;
			}

			update_post_meta( $question_id, '_qmc_type', 'radio' );
			update_post_meta( $question_id, '_qmc_options', $options );
			update_post_meta( $question_id, '_qmc_correct', $letter_to_id[ $item['answer'] ] ?? '' );
			update_post_meta( $question_id, '_qmc_points', 1 );

			if ( $category_id ) {
				wp_set_object_terms( $question_id, array( (int) $category_id ), 'qmc_question_category' );
			}

			$new_ids[] = $question_id;
		}

		return $new_ids;
	}

	/**
	 * Build Aiken-format text for a quiz's Radio / True-False questions.
	 * (Other question types have no Aiken equivalent and are skipped —
	 * noted in the returned $skipped count.)
	 */
	public static function export_quiz( $quiz_id ) {
		$question_ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
		$question_ids = is_array( $question_ids ) ? $question_ids : array();

		$lines   = array();
		$skipped = 0;
		$letters = range( 'A', 'Z' );

		foreach ( $question_ids as $qid ) {
			$q    = get_post( $qid );
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! $q ) {
				continue;
			}

			if ( 'radio' === $data['type'] && ! empty( $data['options'] ) ) {
				$lines[] = $q->post_title;
				$idx     = 0;
				$correct_letter = '';
				foreach ( $data['options'] as $opt ) {
					$letter = $letters[ $idx ];
					$lines[] = $letter . '. ' . $opt['text'];
					if ( $opt['id'] === $data['correct'] ) {
						$correct_letter = $letter;
					}
					$idx++;
				}
				$lines[] = 'ANSWER: ' . $correct_letter;
				$lines[] = '';
			} elseif ( 'true_false' === $data['type'] ) {
				$lines[] = $q->post_title;
				$lines[] = 'A. True';
				$lines[] = 'B. False';
				$lines[] = 'ANSWER: ' . ( 'true' === $data['correct'] ? 'A' : 'B' );
				$lines[] = '';
			} else {
				$skipped++;
			}
		}

		return array(
			'text'    => implode( "\n", $lines ),
			'skipped' => $skipped,
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Admin page + handlers
	 * ------------------------------------------------------------------ */

	public static function render_import_page() {
		$quizzes = get_posts( array( 'post_type' => 'qmc_quiz', 'posts_per_page' => -1 ) );
		$cats    = get_terms( array( 'taxonomy' => 'qmc_question_category', 'hide_empty' => false ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Questions — Aiken format', 'quizzis-for-all' ); ?></h1>
			<p><?php esc_html_e( 'Upload a .txt file in Aiken format. Each question becomes a Radio (single choice) question in your question bank. You can optionally add every imported question straight to a quiz.', 'quizzis-for-all' ); ?></p>
			<?php // Post-redirect success/error notices from the nonce-verified handle_import(); read-only display, no processing. ?>
			<?php if ( isset( $_GET['imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p>
					<?php
					// Read-only success notice after a nonce-verified redirect from
					// handle_import() below — nothing is being processed here.
					/* translators: %d: number of questions imported */
					printf( esc_html__( 'Imported %d question(s).', 'quizzis-for-all' ), intval( $_GET['imported'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No valid Aiken-format questions were found in that file.', 'quizzis-for-all' ); ?></p></div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'qmc_import_aiken' ); ?>
				<input type="hidden" name="action" value="qmc_import_aiken">
				<table class="form-table">
					<tr>
						<th><label for="qmc_aiken_file"><?php esc_html_e( 'Aiken .txt file', 'quizzis-for-all' ); ?></label></th>
						<td><input type="file" name="qmc_aiken_file" id="qmc_aiken_file" accept=".txt" required></td>
					</tr>
					<tr>
						<th><label for="qmc_target_quiz"><?php esc_html_e( 'Add imported questions to quiz (optional)', 'quizzis-for-all' ); ?></label></th>
						<td>
							<select name="qmc_target_quiz" id="qmc_target_quiz">
								<option value=""><?php esc_html_e( '— none, just add to question bank —', 'quizzis-for-all' ); ?></option>
								<?php foreach ( $quizzes as $q ) : ?>
									<option value="<?php echo (int) $q->ID; ?>"><?php echo esc_html( $q->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="qmc_target_category"><?php esc_html_e( 'Assign category (optional)', 'quizzis-for-all' ); ?></label></th>
						<td>
							<select name="qmc_target_category" id="qmc_target_category">
								<option value=""><?php esc_html_e( '— none —', 'quizzis-for-all' ); ?></option>
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import', 'quizzis-for-all' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Example Aiken format', 'quizzis-for-all' ); ?></h2>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:15px;max-width:600px;">What is the capital of France?
A. London
B. Paris
C. Berlin
D. Madrid
ANSWER: B</pre>
		</div>
		<?php
	}

	public static function handle_import() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'qmc_import_aiken' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}
		if ( empty( $_FILES['qmc_aiken_file']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded.', 'quizzis-for-all' ) );
		}

		// $_FILES['tmp_name'] is a server-generated temp path (not raw user
		// input), but sanitize/unslash it anyway before use, per WPCS.
		$tmp_name = sanitize_text_field( wp_unslash( $_FILES['qmc_aiken_file']['tmp_name'] ) );
		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file, not a remote URL; WP_Filesystem offers no advantage here.
		$parsed   = self::parse( $contents );

		if ( empty( $parsed ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=qmc_import&error=1' ) );
			exit;
		}

		$category_id = intval( $_POST['qmc_target_category'] ?? 0 );
		$new_ids     = self::import( $parsed, $category_id );

		$target_quiz = intval( $_POST['qmc_target_quiz'] ?? 0 );
		if ( $target_quiz && get_post( $target_quiz ) ) {
			$existing = get_post_meta( $target_quiz, '_qmc_question_ids', true );
			$existing = is_array( $existing ) ? $existing : array();
			update_post_meta( $target_quiz, '_qmc_question_ids', array_values( array_unique( array_merge( $existing, $new_ids ) ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=qmc_import&imported=' . count( $new_ids ) ) );
		exit;
	}

	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qmc_export_aiken' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		$quiz_id = intval( $_GET['quiz_id'] ?? 0 );
		if ( ! current_user_can( 'edit_post', $quiz_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}

		$result = self::export_quiz( $quiz_id );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=quiz-' . $quiz_id . '-aiken.txt' );
		echo $result['text']; // phpcs:ignore -- plain text file output, not HTML.
		exit;
	}
}
