<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the three "quiz type" variants layered on top of the standard
 * scored quiz: Personality (trait-tally result instead of a score),
 * Chained (requires a prerequisite quiz to be passed first, and surfaces
 * a "next quiz" link), and Popup (renders in a modal instead of inline).
 *
 * These are implemented as quiz-level settings rather than separate post
 * types, since a personality/chained/popup quiz still uses the exact same
 * question bank, question types, and admin UI as a standard quiz — only
 * the scoring and the shell around it change.
 */
class QFA_Quiz_Types {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_qmc_quiz', array( __CLASS__, 'save_outcomes' ) );
	}

	public static function meta_boxes() {
		add_meta_box( 'qmc_quiz_type', __( 'Quiz Type & Flow', 'quizzis-for-all' ), array( __CLASS__, 'render_type_settings' ), 'qmc_quiz', 'normal', 'high' );
		add_meta_box( 'qmc_personality_outcomes', __( 'Personality Outcomes (used in Personality mode)', 'quizzis-for-all' ), array( __CLASS__, 'render_outcomes' ), 'qmc_quiz', 'normal', 'default' );
	}

	/* ------------------------------------------------------------------ *
	 *  Admin UI
	 * ------------------------------------------------------------------ */

	public static function render_type_settings( $post ) {
		wp_nonce_field( 'qmc_save_quiz_type', 'qmc_quiz_type_nonce' );
		$mode         = get_post_meta( $post->ID, '_qmc_quiz_mode', true ) ?: 'standard';
		$popup        = get_post_meta( $post->ID, '_qmc_popup_display', true );
		$prerequisite = get_post_meta( $post->ID, '_qmc_prerequisite_quiz', true );
		$h5p_id       = get_post_meta( $post->ID, '_qmc_h5p_content_id', true );

		// 'exclude' here just removes the current quiz from an admin
		// dropdown of quizzes (a small, editor-curated post type, not
		// user-generated content at scale), so the usual "exclude is slow
		// at scale" concern doesn't really apply.
		$other_quizzes = get_posts(
			array(
				'post_type'      => 'qmc_quiz',
				'posts_per_page' => -1,
				'exclude'        => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="qmc_quiz_mode"><?php esc_html_e( 'Quiz mode', 'quizzis-for-all' ); ?></label></th>
				<td>
					<select name="qmc_quiz_mode" id="qmc_quiz_mode">
						<option value="standard" <?php selected( $mode, 'standard' ); ?>><?php esc_html_e( 'Standard — scored, right/wrong', 'quizzis-for-all' ); ?></option>
						<option value="personality" <?php selected( $mode, 'personality' ); ?>><?php esc_html_e( 'Personality — matches the test-taker to an outcome, not a score', 'quizzis-for-all' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Personality mode tallies the "trait" tag on each chosen radio/checkbox option (set per-question) and shows the outcome with the most matches. Define outcomes below.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="qmc_prerequisite_quiz"><?php esc_html_e( 'Prerequisite quiz (chained)', 'quizzis-for-all' ); ?></label></th>
				<td>
					<select name="qmc_prerequisite_quiz" id="qmc_prerequisite_quiz">
						<option value=""><?php esc_html_e( '— none —', 'quizzis-for-all' ); ?></option>
						<?php foreach ( $other_quizzes as $q ) : ?>
							<option value="<?php echo (int) $q->ID; ?>" <?php selected( $prerequisite, $q->ID ); ?>><?php echo esc_html( $q->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'If set, a test-taker must pass the selected quiz before they can take this one. The prerequisite quiz will automatically show a "Next quiz" link to this one once passed.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Display', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_popup_display" <?php checked( $popup, 1 ); ?>> <?php esc_html_e( 'Show as a popup — the shortcode renders a button that opens the quiz in a modal instead of inline', 'quizzis-for-all' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="qmc_h5p_content_id"><?php esc_html_e( 'H5P content ID (optional)', 'quizzis-for-all' ); ?></label></th>
				<td>
					<input type="number" min="0" name="qmc_h5p_content_id" id="qmc_h5p_content_id" value="<?php echo esc_attr( $h5p_id ); ?>">
					<p class="description"><?php esc_html_e( 'If the H5P plugin is active, this content (e.g. an interactive video or presentation) is embedded above the quiz intro — useful for a learning module the quiz then tests.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_outcomes( $post ) {
		$outcomes = get_post_meta( $post->ID, '_qmc_personality_outcomes', true );
		$outcomes = ! empty( $outcomes ) ? $outcomes : array( array( 'trait' => '', 'label' => '', 'description' => '' ) );
		?>
		<div id="qmc-outcomes-wrap">
			<?php foreach ( $outcomes as $o ) : ?>
				<div class="qmc-outcome-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:flex-start;">
					<input type="text" name="qmc_outcome_trait[]" value="<?php echo esc_attr( $o['trait'] ); ?>" placeholder="<?php esc_attr_e( 'trait key (e.g. A)', 'quizzis-for-all' ); ?>" style="width:140px;">
					<input type="text" name="qmc_outcome_label[]" value="<?php echo esc_attr( $o['label'] ); ?>" placeholder="<?php esc_attr_e( 'Outcome title', 'quizzis-for-all' ); ?>" style="width:220px;">
					<input type="text" name="qmc_outcome_description[]" value="<?php echo esc_attr( $o['description'] ); ?>" placeholder="<?php esc_attr_e( 'Description shown to the test-taker', 'quizzis-for-all' ); ?>" style="flex:1;">
					<a href="#" class="qmc-remove-outcome">&times;</a>
				</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="qmc-add-outcome"><?php esc_html_e( '+ Add outcome', 'quizzis-for-all' ); ?></button>
		<script>
		( function () {
			document.getElementById( 'qmc-add-outcome' ).addEventListener( 'click', function () {
				var wrap = document.getElementById( 'qmc-outcomes-wrap' );
				var row = document.createElement( 'div' );
				row.className = 'qmc-outcome-row';
				row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:flex-start;';
				row.innerHTML = '<input type="text" name="qmc_outcome_trait[]" placeholder="trait key (e.g. A)" style="width:140px;">' +
					'<input type="text" name="qmc_outcome_label[]" placeholder="Outcome title" style="width:220px;">' +
					'<input type="text" name="qmc_outcome_description[]" placeholder="Description shown to the test-taker" style="flex:1;">' +
					'<a href="#" class="qmc-remove-outcome">&times;</a>';
				wrap.appendChild( row );
			} );
			document.getElementById( 'qmc-outcomes-wrap' ).addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'qmc-remove-outcome' ) ) {
					e.preventDefault();
					e.target.closest( '.qmc-outcome-row' ).remove();
				}
			} );
		} )();
		</script>
		<?php
	}

	public static function save_outcomes( $post_id ) {
		if ( isset( $_POST['qmc_quiz_type_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_quiz_type_nonce'] ) ), 'qmc_save_quiz_type' ) && current_user_can( 'edit_post', $post_id ) ) {
			$mode = isset( $_POST['qmc_quiz_mode'] ) ? sanitize_key( wp_unslash( $_POST['qmc_quiz_mode'] ) ) : '';
			update_post_meta( $post_id, '_qmc_quiz_mode', in_array( $mode, array( 'standard', 'personality' ), true ) ? $mode : 'standard' );
			update_post_meta( $post_id, '_qmc_prerequisite_quiz', intval( $_POST['qmc_prerequisite_quiz'] ?? 0 ) );
			update_post_meta( $post_id, '_qmc_popup_display', ! empty( $_POST['qmc_popup_display'] ) ? 1 : 0 );
			update_post_meta( $post_id, '_qmc_h5p_content_id', intval( $_POST['qmc_h5p_content_id'] ?? 0 ) );
		}

		// Outcomes have no separate nonce field of their own; they live in
		// the same metabox area saved alongside the main post form.
		if ( isset( $_POST['qmc_outcome_label'] ) && current_user_can( 'edit_post', $post_id ) ) {
			$outcomes = array();
			// wp_unslash() handles arrays recursively, and array_map() with a
			// sanitizing callback cleans every element — the canonical WPCS
			// pattern for array inputs. Descriptions use wp_kses_post since
			// they may legitimately contain limited HTML. The is_array()
			// checks only inspect the variable's *type* (guarding against a
			// crafted scalar fataling array_map); the value itself is
			// unslashed and sanitized within the same expression.
			$traits = isset( $_POST['qmc_outcome_trait'] ) && is_array( $_POST['qmc_outcome_trait'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['qmc_outcome_trait'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$labels = isset( $_POST['qmc_outcome_label'] ) && is_array( $_POST['qmc_outcome_label'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['qmc_outcome_label'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$descs  = isset( $_POST['qmc_outcome_description'] ) && is_array( $_POST['qmc_outcome_description'] ) ? array_map( 'wp_kses_post', wp_unslash( $_POST['qmc_outcome_description'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			foreach ( $labels as $i => $label ) {
				if ( '' === trim( $label ) ) {
					continue;
				}
				$outcomes[] = array(
					'trait'       => $traits[ $i ] ?? '',
					'label'       => $label,
					'description' => $descs[ $i ] ?? '',
				);
			}
			update_post_meta( $post_id, '_qmc_personality_outcomes', $outcomes );
		}
	}

	/* ------------------------------------------------------------------ *
	 *  Scoring / flow logic used by the frontend
	 * ------------------------------------------------------------------ */

	public static function get_mode( $quiz_id ) {
		return get_post_meta( $quiz_id, '_qmc_quiz_mode', true ) ?: 'standard';
	}

	public static function is_popup( $quiz_id ) {
		return (bool) get_post_meta( $quiz_id, '_qmc_popup_display', true );
	}

	/**
	 * Tally the trait of every selected option across all answered
	 * radio/checkbox questions and return the winning outcome (or null if
	 * no outcomes are configured / nothing was tallied).
	 */
	public static function grade_personality( $quiz_id, array $question_ids, array $answers ) {
		$tally = array();

		foreach ( $question_ids as $qid ) {
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! in_array( $data['type'], array( 'radio', 'checkbox' ), true ) || empty( $data['options'] ) ) {
				continue;
			}
			$submitted = $answers[ $qid ] ?? null;
			$selected_ids = is_array( $submitted ) ? $submitted : array( $submitted );

			foreach ( $data['options'] as $opt ) {
				if ( in_array( $opt['id'], $selected_ids, true ) && ! empty( $opt['trait'] ) ) {
					$tally[ $opt['trait'] ] = ( $tally[ $opt['trait'] ] ?? 0 ) + 1;
				}
			}
		}

		if ( empty( $tally ) ) {
			return null;
		}
		arsort( $tally );
		$winning_trait = array_key_first( $tally );

		$outcomes = get_post_meta( $quiz_id, '_qmc_personality_outcomes', true );
		$outcomes = is_array( $outcomes ) ? $outcomes : array();
		foreach ( $outcomes as $o ) {
			if ( $o['trait'] === $winning_trait ) {
				return array(
					'trait'       => $winning_trait,
					'label'       => $o['label'],
					'description' => $o['description'],
					'tally'       => $tally,
				);
			}
		}

		// No outcome configured for the winning trait — surface the raw
		// trait key so it's at least visible rather than silently empty.
		return array(
			'trait'       => $winning_trait,
			'label'       => $winning_trait,
			'description' => '',
			'tally'       => $tally,
		);
	}

	/**
	 * Chained flow: has this user passed the prerequisite quiz (if any)?
	 * Always true when there is no prerequisite or the user isn't logged in
	 * and prerequisites can't be evaluated for guests.
	 */
	public static function prerequisite_met( $quiz_id, $user_id ) {
		$prereq_id = intval( get_post_meta( $quiz_id, '_qmc_prerequisite_quiz', true ) );
		if ( ! $prereq_id ) {
			return true;
		}
		if ( ! $user_id ) {
			return false;
		}
		$pass_mark = floatval( get_post_meta( $prereq_id, '_qmc_pass_mark', true ) );
		$best      = QFA_DB::get_best_percentage( $prereq_id, $user_id );
		return $best >= $pass_mark;
	}

	public static function get_prerequisite_quiz( $quiz_id ) {
		$id = intval( get_post_meta( $quiz_id, '_qmc_prerequisite_quiz', true ) );
		return $id ? get_post( $id ) : null;
	}

	/** Reverse lookup: the first quiz (if any) that lists $quiz_id as its prerequisite. */
	public static function get_next_quiz( $quiz_id ) {
		// A meta_key/meta_value lookup is the simplest correct way to find
		// "which quiz points back to this one" without adding a dedicated
		// relationship table for what is a small, editor-curated post type.
		$found = get_posts(
			array(
				'post_type'      => 'qmc_quiz',
				'posts_per_page' => 1,
				'meta_key'       => '_qmc_prerequisite_quiz', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $quiz_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return ! empty( $found ) ? $found[0] : null;
	}
}
