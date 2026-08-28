<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for question types. Each type provides:
 *  - a label
 *  - whether it is auto-gradable
 *  - a callback that renders its admin edit fields
 *  - a callback that renders its frontend answer fields
 *  - a callback that grades a submitted answer against the stored answer key
 *
 * Adding a new question type later (Matching, Upload File, ...) means adding
 * one entry here plus its render callbacks — nothing else in the plugin
 * needs to change, since the quiz builder, grading engine, and results
 * screen all read this registry.
 */
class QFA_Question_Types {

	public static function get_types() {
		return array(
			'radio'      => __( 'Radio (Single Choice)', 'quizzis-for-all' ),
			'checkbox'   => __( 'Checkbox (Multiple Choice)', 'quizzis-for-all' ),
			'true_false' => __( 'True / False', 'quizzis-for-all' ),
			'short_text' => __( 'Short Text', 'quizzis-for-all' ),
			'text'       => __( 'Text (Essay / Manually Graded)', 'quizzis-for-all' ),
			'number'     => __( 'Number', 'quizzis-for-all' ),
			'date'       => __( 'Date', 'quizzis-for-all' ),
			'fill_blanks' => __( 'Fill in the Blanks', 'quizzis-for-all' ),
			'matching'   => __( 'Matching', 'quizzis-for-all' ),
			'file_upload' => __( 'Upload File', 'quizzis-for-all' ),
			'info'       => __( 'Info Banner (no question)', 'quizzis-for-all' ),
		);
	}

	public static function requires_manual_grading( $type ) {
		return in_array( $type, array( 'text', 'file_upload' ), true );
	}

	public static function is_scoreable( $type ) {
		return 'info' !== $type;
	}

	/* ------------------------------------------------------------------ *
	 *  ADMIN: read/write question meta
	 * ------------------------------------------------------------------ */

	public static function get_question_data( $question_id ) {
		return array(
			'type'           => get_post_meta( $question_id, '_qmc_type', true ) ?: 'radio',
			'options'        => get_post_meta( $question_id, '_qmc_options', true ) ?: array(),
			'correct'        => get_post_meta( $question_id, '_qmc_correct', true ),
			'explanation'    => get_post_meta( $question_id, '_qmc_explanation', true ),
			'hint'           => get_post_meta( $question_id, '_qmc_hint', true ),
			'points'         => get_post_meta( $question_id, '_qmc_points', true ) ?: 1,
			'required'       => (bool) get_post_meta( $question_id, '_qmc_required', true ),
			'case_sensitive' => (bool) get_post_meta( $question_id, '_qmc_case_sensitive', true ),
			'blanks_text'    => get_post_meta( $question_id, '_qmc_blanks_text', true ),
			'pairs'          => get_post_meta( $question_id, '_qmc_pairs', true ) ?: array(),
			'allowed_types'  => get_post_meta( $question_id, '_qmc_allowed_types', true ) ?: 'pdf,doc,docx,jpg,jpeg,png',
			'max_file_size_mb' => get_post_meta( $question_id, '_qmc_max_file_size_mb', true ) ?: 5,
		);
	}

	public static function save_question_data( $question_id, array $posted ) {
		$type = sanitize_key( $posted['qmc_type'] ?? 'radio' );
		update_post_meta( $question_id, '_qmc_type', $type );
		update_post_meta( $question_id, '_qmc_explanation', wp_kses_post( $posted['qmc_explanation'] ?? '' ) );
		update_post_meta( $question_id, '_qmc_hint', sanitize_text_field( $posted['qmc_hint'] ?? '' ) );
		update_post_meta( $question_id, '_qmc_points', max( 0, floatval( $posted['qmc_points'] ?? 1 ) ) );
		update_post_meta( $question_id, '_qmc_required', ! empty( $posted['qmc_required'] ) ? 1 : 0 );
		update_post_meta( $question_id, '_qmc_case_sensitive', ! empty( $posted['qmc_case_sensitive'] ) ? 1 : 0 );

		// Options (radio / checkbox): array of {id,text,trait} built client-side.
		$options = array();
		if ( ! empty( $posted['qmc_option_text'] ) && is_array( $posted['qmc_option_text'] ) ) {
			foreach ( $posted['qmc_option_text'] as $i => $text ) {
				$text = sanitize_text_field( wp_unslash( $text ) );
				if ( '' === trim( $text ) ) {
					continue;
				}
				$options[] = array(
					'id'    => 'opt_' . $i,
					'text'  => $text,
					'trait' => isset( $posted['qmc_option_trait'][ $i ] ) ? sanitize_key( wp_unslash( $posted['qmc_option_trait'][ $i ] ) ) : '',
				);
			}
		}
		update_post_meta( $question_id, '_qmc_options', $options );

		// Correct answer, shape depends on type.
		switch ( $type ) {
			case 'radio':
				$correct = sanitize_text_field( $posted['qmc_correct_radio'] ?? '' );
				break;
			case 'checkbox':
				$correct = isset( $posted['qmc_correct_checkbox'] ) && is_array( $posted['qmc_correct_checkbox'] )
					? array_map( 'sanitize_text_field', $posted['qmc_correct_checkbox'] )
					: array();
				break;
			case 'true_false':
				$correct = ( 'true' === ( $posted['qmc_correct_tf'] ?? '' ) ) ? 'true' : 'false';
				break;
			case 'short_text':
				// Comma-separated list of acceptable answers.
				$raw     = sanitize_text_field( $posted['qmc_correct_text'] ?? '' );
				$correct = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
				break;
			case 'number':
				$correct = array(
					'value'     => floatval( $posted['qmc_correct_number'] ?? 0 ),
					'tolerance' => floatval( $posted['qmc_number_tolerance'] ?? 0 ),
				);
				break;
			case 'date':
				$correct = sanitize_text_field( $posted['qmc_correct_date'] ?? '' );
				break;
			case 'fill_blanks':
				$blanks_text = wp_kses_post( $posted['qmc_blanks_text'] ?? '' );
				update_post_meta( $question_id, '_qmc_blanks_text', $blanks_text );
				$raw     = $posted['qmc_correct_blanks'] ?? '';
				$correct = array_map( 'trim', explode( '|', sanitize_text_field( $raw ) ) );
				break;
			case 'matching':
				// Pairs built client-side: qmc_pair_left[] / qmc_pair_right[].
				$pairs = array();
				$lefts  = $posted['qmc_pair_left'] ?? array();
				$rights = $posted['qmc_pair_right'] ?? array();
				if ( is_array( $lefts ) ) {
					foreach ( $lefts as $i => $left ) {
						$left  = sanitize_text_field( wp_unslash( $left ) );
						$right = isset( $rights[ $i ] ) ? sanitize_text_field( wp_unslash( $rights[ $i ] ) ) : '';
						if ( '' === trim( $left ) || '' === trim( $right ) ) {
							continue;
						}
						$pairs[] = array(
							'id'    => 'pair_' . $i,
							'left'  => $left,
							'right' => $right,
						);
					}
				}
				update_post_meta( $question_id, '_qmc_pairs', $pairs );
				$correct = ''; // Grading reads the pairs meta directly, not _qmc_correct.
				break;

			case 'file_upload':
				update_post_meta( $question_id, '_qmc_allowed_types', sanitize_text_field( $posted['qmc_allowed_types'] ?? 'pdf,doc,docx,jpg,jpeg,png' ) );
				update_post_meta( $question_id, '_qmc_max_file_size_mb', max( 1, intval( $posted['qmc_max_file_size_mb'] ?? 5 ) ) );
				$correct = '';
				break;

			case 'text':
			case 'info':
			default:
				$correct = '';
				break;
		}
		update_post_meta( $question_id, '_qmc_correct', $correct );
	}

	/* ------------------------------------------------------------------ *
	 *  FRONTEND: render an answer field for a question
	 * ------------------------------------------------------------------ */

	public static function render_frontend_field( $question_id, array $data, $index ) {
		$name = 'qmc_answer_' . $question_id;
		switch ( $data['type'] ) {

			case 'radio':
				foreach ( $data['options'] as $opt ) {
					printf(
						'<label class="qmc-option"><input type="radio" name="%1$s" value="%2$s" data-qidx="%3$d"> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $opt['id'] ),
						(int) $index,
						esc_html( $opt['text'] )
					);
				}
				break;

			case 'checkbox':
				foreach ( $data['options'] as $opt ) {
					printf(
						'<label class="qmc-option"><input type="checkbox" name="%1$s[]" value="%2$s" data-qidx="%3$d"> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $opt['id'] ),
						(int) $index,
						esc_html( $opt['text'] )
					);
				}
				break;

			case 'true_false':
				printf(
					'<label class="qmc-option"><input type="radio" name="%1$s" value="true" data-qidx="%2$d"> %3$s</label>
					 <label class="qmc-option"><input type="radio" name="%1$s" value="false" data-qidx="%2$d"> %4$s</label>',
					esc_attr( $name ),
					(int) $index,
					esc_html__( 'True', 'quizzis-for-all' ),
					esc_html__( 'False', 'quizzis-for-all' )
				);
				break;

			case 'short_text':
				printf(
					'<input type="text" class="qmc-input" name="%1$s" data-qidx="%2$d" autocomplete="off">',
					esc_attr( $name ),
					(int) $index
				);
				break;

			case 'text':
				printf(
					'<textarea class="qmc-textarea" name="%1$s" data-qidx="%2$d" rows="5"></textarea>',
					esc_attr( $name ),
					(int) $index
				);
				break;

			case 'number':
				printf(
					'<input type="number" step="any" class="qmc-input" name="%1$s" data-qidx="%2$d">',
					esc_attr( $name ),
					(int) $index
				);
				break;

			case 'date':
				printf(
					'<input type="date" class="qmc-input" name="%1$s" data-qidx="%2$d">',
					esc_attr( $name ),
					(int) $index
				);
				break;

			case 'fill_blanks':
				$parts = explode( '{blank}', $data['blanks_text'] );
				foreach ( $parts as $i => $part ) {
					echo wp_kses_post( $part );
					if ( $i < count( $parts ) - 1 ) {
						printf(
							'<input type="text" class="qmc-input qmc-blank" name="%1$s[]" data-qidx="%2$d">',
							esc_attr( $name ),
							(int) $index
						);
					}
				}
				break;

			case 'matching':
				$right_texts = wp_list_pluck( $data['pairs'], 'right' );
				shuffle( $right_texts );
				echo '<table class="qmc-matching-table">';
				foreach ( $data['pairs'] as $pair ) {
					printf(
						'<tr><td class="qmc-match-left">%1$s</td><td>%2$s
						 <select name="%3$s[%4$s]" data-qidx="%5$d">
						 <option value="">%6$s</option>',
						esc_html( $pair['left'] ),
						'&rarr;',
						esc_attr( $name ),
						esc_attr( $pair['id'] ),
						(int) $index,
						esc_html__( '— choose —', 'quizzis-for-all' )
					);
					foreach ( $right_texts as $rt ) {
						printf( '<option value="%1$s">%1$s</option>', esc_attr( $rt ) );
					}
					echo '</select></td></tr>';
				}
				echo '</table>';
				break;

			case 'file_upload':
				printf(
					'<input type="file" class="qmc-input" name="%1$s" data-qidx="%2$d" accept="%3$s">
					 <p class="qmc-file-hint">%4$s</p>',
					esc_attr( $name ),
					(int) $index,
					esc_attr( implode( ',', array_map( function ( $ext ) { return '.' . trim( $ext ); }, explode( ',', $data['allowed_types'] ) ) ) ),
					sprintf(
						/* translators: 1: allowed file types, 2: max size in MB */
						esc_html__( 'Allowed: %1$s — max %2$s MB. Reviewed manually by the instructor.', 'quizzis-for-all' ),
						esc_html( $data['allowed_types'] ),
						esc_html( $data['max_file_size_mb'] )
					)
				);
				break;

			case 'info':
				// No answer field — informational only.
				break;
		}
	}

	/* ------------------------------------------------------------------ *
	 *  GRADING
	 * ------------------------------------------------------------------ */

	/**
	 * Grade one answer. Returns array( is_correct(bool|null), points_awarded ).
	 * is_correct is null for manually-graded / non-scoreable types.
	 */
	public static function grade( array $data, $submitted ) {
		$points = floatval( $data['points'] );

		switch ( $data['type'] ) {

			case 'radio':
				$is_correct = ( (string) $submitted === (string) $data['correct'] );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'checkbox':
				$submitted = is_array( $submitted ) ? array_map( 'strval', $submitted ) : array();
				$correct   = is_array( $data['correct'] ) ? array_map( 'strval', $data['correct'] ) : array();
				sort( $submitted );
				sort( $correct );
				$is_correct = ( $submitted === $correct ) && ! empty( $correct );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'true_false':
				$is_correct = ( (string) $submitted === (string) $data['correct'] );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'short_text':
				$accepted = is_array( $data['correct'] ) ? $data['correct'] : array();
				$val      = trim( (string) $submitted );
				if ( ! $data['case_sensitive'] ) {
					$val      = mb_strtolower( $val );
					$accepted = array_map( 'mb_strtolower', $accepted );
				}
				$is_correct = in_array( $val, $accepted, true );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'number':
				$target    = isset( $data['correct']['value'] ) ? floatval( $data['correct']['value'] ) : 0;
				$tolerance = isset( $data['correct']['tolerance'] ) ? floatval( $data['correct']['tolerance'] ) : 0;
				$val       = floatval( $submitted );
				$is_correct = ( abs( $val - $target ) <= $tolerance );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'date':
				$is_correct = ( (string) $submitted === (string) $data['correct'] );
				return array( $is_correct, $is_correct ? $points : 0 );

			case 'fill_blanks':
				$submitted = is_array( $submitted ) ? array_map( 'trim', $submitted ) : array();
				$correct   = is_array( $data['correct'] ) ? array_map( 'trim', $data['correct'] ) : array();
				$cs        = $data['case_sensitive'];
				$all_ok    = ! empty( $correct );
				foreach ( $correct as $i => $ans ) {
					$sub = isset( $submitted[ $i ] ) ? $submitted[ $i ] : '';
					if ( ! $cs ) {
						$sub = mb_strtolower( $sub );
						$ans = mb_strtolower( $ans );
					}
					if ( $sub !== $ans ) {
						$all_ok = false;
						break;
					}
				}
				return array( $all_ok, $all_ok ? $points : 0 );

			case 'matching':
				$submitted = is_array( $submitted ) ? $submitted : array();
				$pairs     = is_array( $data['pairs'] ) ? $data['pairs'] : array();
				$all_ok    = ! empty( $pairs );
				foreach ( $pairs as $pair ) {
					$sub = isset( $submitted[ $pair['id'] ] ) ? trim( (string) $submitted[ $pair['id'] ] ) : '';
					if ( $sub !== trim( $pair['right'] ) ) {
						$all_ok = false;
						break;
					}
				}
				return array( $all_ok, $all_ok ? $points : 0 );

			case 'text':
			case 'file_upload':
				// Manually graded — no automatic score.
				return array( null, 0 );

			case 'info':
			default:
				return array( null, 0 );
		}
	}
}
