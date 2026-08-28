<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Richer question interchange formats, alongside the existing Aiken
 * support in QFA_Aiken:
 *
 *  - GIFT       : Moodle's plain-text format. Handles multiple choice,
 *                 true/false, short answer, numerical and matching — the
 *                 closest text format to this plugin's own question model.
 *  - CSV        : spreadsheet round-trip, for authors who'd rather work
 *                 in Excel/Sheets than a text format.
 *  - Moodle XML : the format Moodle itself exports, so a question bank
 *                 can move between Moodle and WordPress in either
 *                 direction without retyping.
 *
 * All three are import *and* export, and all map onto the same internal
 * question meta the manual editor writes, so an imported question is
 * indistinguishable from a hand-built one.
 */
class QFA_Formats {

	const CSV_HEADER = array( 'type', 'question', 'options', 'correct', 'points', 'category', 'hint', 'explanation' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 18 );
		add_action( 'admin_post_qmc_import_formats', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_qmc_export_formats', array( __CLASS__, 'handle_export' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'qmc_dashboard', __( 'Import / Export', 'quizzis-for-all' ), __( 'Import / Export', 'quizzis-for-all' ), 'edit_posts', 'qmc_formats', array( __CLASS__, 'render_page' ) );
	}

	/* ================================================================== *
	 *  GIFT
	 * ================================================================== */

	/**
	 * Parse GIFT text into the plugin's neutral question array shape.
	 * Supported: ::title:: prompt {…} blocks with =correct / ~wrong /
	 * #feedback / %50% partial-credit markers, TRUE/FALSE shorthand,
	 * numeric answers (#42:2), and matching (=left -> right).
	 */
	public static function parse_gift( $text ) {
		$text  = str_replace( "\r\n", "\n", $text );
		$out   = array();

		// Split on blank lines that sit outside an answer block.
		$blocks = preg_split( '/\n\s*\n/', trim( $text ) );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block || 0 === strpos( $block, '//' ) ) {
				continue; // Blank or comment.
			}

			// Optional ::Title:: prefix.
			$title = '';
			if ( preg_match( '/^::(.*?)::/s', $block, $m ) ) {
				$title = trim( $m[1] );
				$block = trim( substr( $block, strlen( $m[0] ) ) );
			}

			// Split prompt from the { answer block }.
			if ( ! preg_match( '/^(.*?)\{(.*)\}\s*$/s', $block, $m ) ) {
				continue; // No answer block — skip (could be a description).
			}
			$prompt = trim( self::strip_gift_escapes( $m[1] ) );
			$body   = trim( $m[2] );

			if ( '' === $prompt ) {
				$prompt = $title;
			}
			if ( '' === $prompt ) {
				continue;
			}

			$question = array(
				'title'       => $prompt,
				'points'      => 1,
				'hint'        => '',
				'explanation' => '',
			);

			// --- True / False ---
			if ( preg_match( '/^(TRUE|FALSE|T|F)\b/i', $body, $tf ) ) {
				$val                  = strtoupper( $tf[1] );
				$question['type']     = 'true_false';
				$question['correct']  = ( 'TRUE' === $val || 'T' === $val ) ? 'true' : 'false';
				$out[]                = $question;
				continue;
			}

			// --- Numerical:  #42:2  or  #42..44 ---
			if ( preg_match( '/^#\s*([-\d.]+)\s*(?:\.\.\s*([-\d.]+)|:\s*([-\d.]+))?/', $body, $num ) ) {
				$question['type'] = 'number';
				if ( ! empty( $num[2] ) ) {
					// Range form: midpoint ± half-width.
					$low  = floatval( $num[1] );
					$high = floatval( $num[2] );
					$question['correct'] = array(
						'value'     => ( $low + $high ) / 2,
						'tolerance' => abs( $high - $low ) / 2,
					);
				} else {
					$question['correct'] = array(
						'value'     => floatval( $num[1] ),
						'tolerance' => isset( $num[3] ) ? floatval( $num[3] ) : 0,
					);
				}
				$out[] = $question;
				continue;
			}

			// --- Matching: every answer is "=left -> right" ---
			if ( preg_match_all( '/=\s*([^=~#\n]+?)\s*->\s*([^=~#\n]+)/', $body, $pairs, PREG_SET_ORDER ) && count( $pairs ) > 1 ) {
				$question['type']  = 'matching';
				$question['pairs'] = array();
				foreach ( $pairs as $i => $p ) {
					$question['pairs'][] = array(
						'id'    => 'pair_' . $i,
						'left'  => self::strip_gift_escapes( trim( $p[1] ) ),
						'right' => self::strip_gift_escapes( trim( $p[2] ) ),
					);
				}
				$out[] = $question;
				continue;
			}

			// --- Multiple choice / short answer ---
			// Tokenize on = (correct), ~ (wrong), %n% (weighted), # (feedback).
			preg_match_all( '/([=~])\s*(?:%(-?\d+(?:\.\d+)?)%)?\s*((?:[^=~#\\\\]|\\\\.)*)(?:#\s*((?:[^=~\\\\]|\\\\.)*))?/', $body, $tokens, PREG_SET_ORDER );

			$options   = array();
			$correct   = array();
			$has_wrong = false;
			$idx       = 0;

			foreach ( $tokens as $t ) {
				$marker = $t[1];
				$weight = isset( $t[2] ) && '' !== $t[2] ? floatval( $t[2] ) : null;
				$label  = self::strip_gift_escapes( trim( $t[3] ) );
				if ( '' === $label ) {
					continue;
				}
				if ( '~' === $marker ) {
					$has_wrong = true;
				}

				$opt_id    = 'opt_' . $idx;
				$options[] = array( 'id' => $opt_id, 'text' => $label, 'trait' => '' );

				// '=' is correct; '~%50%' style positive weights also count
				// as correct answers in a multi-select.
				if ( '=' === $marker || ( null !== $weight && $weight > 0 ) ) {
					$correct[] = $opt_id;
				}
				$idx++;
			}

			if ( empty( $options ) ) {
				continue;
			}

			if ( ! $has_wrong && count( $correct ) === count( $options ) ) {
				// Every answer is an accepted string → short answer.
				$question['type']    = 'short_text';
				$question['correct'] = wp_list_pluck( $options, 'text' );
			} elseif ( count( $correct ) > 1 ) {
				$question['type']    = 'checkbox';
				$question['options'] = $options;
				$question['correct'] = $correct;
			} else {
				$question['type']    = 'radio';
				$question['options'] = $options;
				$question['correct'] = $correct[0] ?? '';
			}

			$out[] = $question;
		}

		return $out;
	}

	protected static function strip_gift_escapes( $s ) {
		return trim( preg_replace( '/\\\\([=~#{}:])/', '$1', $s ) );
	}

	/** Build GIFT text from a set of question IDs. */
	public static function build_gift( array $question_ids ) {
		$lines = array();

		foreach ( $question_ids as $qid ) {
			$q    = get_post( $qid );
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! $q ) {
				continue;
			}
			$prompt = self::escape_gift( $q->post_title );

			switch ( $data['type'] ) {
				case 'radio':
					$body = '';
					foreach ( $data['options'] as $opt ) {
						$body .= ( $opt['id'] === $data['correct'] ? '=' : '~' ) . self::escape_gift( $opt['text'] ) . "\n\t";
					}
					$lines[] = $prompt . ' {' . "\n\t" . rtrim( $body ) . "\n}";
					break;

				case 'checkbox':
					$correct = is_array( $data['correct'] ) ? $data['correct'] : array();
					$n       = max( 1, count( $correct ) );
					$body    = '';
					foreach ( $data['options'] as $opt ) {
						$pct   = in_array( $opt['id'], $correct, true ) ? round( 100 / $n, 5 ) : -100;
						$body .= '~%' . $pct . '%' . self::escape_gift( $opt['text'] ) . "\n\t";
					}
					$lines[] = $prompt . ' {' . "\n\t" . rtrim( $body ) . "\n}";
					break;

				case 'true_false':
					$lines[] = $prompt . ' {' . ( 'true' === $data['correct'] ? 'TRUE' : 'FALSE' ) . '}';
					break;

				case 'short_text':
					$accepted = is_array( $data['correct'] ) ? $data['correct'] : array();
					$body     = '';
					foreach ( $accepted as $a ) {
						$body .= '=' . self::escape_gift( $a ) . "\n\t";
					}
					$lines[] = $prompt . ' {' . "\n\t" . rtrim( $body ) . "\n}";
					break;

				case 'number':
					$val = $data['correct']['value'] ?? 0;
					$tol = $data['correct']['tolerance'] ?? 0;
					$lines[] = $prompt . ' {#' . $val . ( $tol ? ':' . $tol : '' ) . '}';
					break;

				case 'matching':
					$body = '';
					foreach ( $data['pairs'] as $pair ) {
						$body .= '=' . self::escape_gift( $pair['left'] ) . ' -> ' . self::escape_gift( $pair['right'] ) . "\n\t";
					}
					$lines[] = $prompt . ' {' . "\n\t" . rtrim( $body ) . "\n}";
					break;

				default:
					// Essay / file upload / info have no GIFT equivalent.
					continue 2;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	protected static function escape_gift( $s ) {
		return str_replace( array( '=', '~', '#', '{', '}', ':' ), array( '\\=', '\\~', '\\#', '\\{', '\\}', '\\:' ), wp_strip_all_tags( $s ) );
	}

	/* ================================================================== *
	 *  CSV
	 * ================================================================== */

	/**
	 * CSV columns: type, question, options (| separated), correct, points,
	 * category, hint, explanation. "correct" holds a 1-based option index
	 * (or several, | separated) for choice types, or the literal answer
	 * for text/number types.
	 */
	public static function parse_csv( $path ) {
		$out = array();

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- reading a just-uploaded temp file line by line; WP_Filesystem has no streaming CSV equivalent.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return $out;
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			return $out;
		}
		$header = array_map( function ( $h ) {
			return strtolower( trim( $h ) );
		}, $header );

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}
			$r = array_combine( array_slice( $header, 0, count( $row ) ), $row );

			$type   = sanitize_key( $r['type'] ?? 'radio' );
			$prompt = trim( $r['question'] ?? '' );
			if ( '' === $prompt ) {
				continue;
			}

			$question = array(
				'title'       => $prompt,
				'type'        => $type,
				'points'      => floatval( $r['points'] ?? 1 ) ?: 1,
				'category'    => trim( $r['category'] ?? '' ),
				'hint'        => trim( $r['hint'] ?? '' ),
				'explanation' => trim( $r['explanation'] ?? '' ),
			);

			$raw_options = array_values( array_filter( array_map( 'trim', explode( '|', $r['options'] ?? '' ) ), 'strlen' ) );
			$raw_correct = array_values( array_filter( array_map( 'trim', explode( '|', $r['correct'] ?? '' ) ), 'strlen' ) );

			switch ( $type ) {
				case 'radio':
				case 'checkbox':
					$options = array();
					foreach ( $raw_options as $i => $text ) {
						$options[] = array( 'id' => 'opt_' . $i, 'text' => $text, 'trait' => '' );
					}
					$question['options'] = $options;

					$ids = array();
					foreach ( $raw_correct as $c ) {
						if ( is_numeric( $c ) ) {
							$i = intval( $c ) - 1; // 1-based in the sheet.
							if ( isset( $options[ $i ] ) ) {
								$ids[] = $options[ $i ]['id'];
							}
						} else {
							// Allow matching by literal option text too.
							foreach ( $options as $o ) {
								if ( strcasecmp( $o['text'], $c ) === 0 ) {
									$ids[] = $o['id'];
								}
							}
						}
					}
					$question['correct'] = 'checkbox' === $type ? $ids : ( $ids[0] ?? '' );
					break;

				case 'true_false':
					$v                   = strtolower( $raw_correct[0] ?? 'true' );
					$question['correct'] = in_array( $v, array( 'true', 't', '1', 'yes' ), true ) ? 'true' : 'false';
					break;

				case 'short_text':
					$question['correct'] = $raw_correct;
					break;

				case 'number':
					$question['correct'] = array(
						'value'     => floatval( $raw_correct[0] ?? 0 ),
						'tolerance' => floatval( $raw_correct[1] ?? 0 ),
					);
					break;

				case 'matching':
					$pairs = array();
					foreach ( $raw_options as $i => $pair ) {
						$bits = array_map( 'trim', explode( '->', $pair ) );
						if ( count( $bits ) < 2 ) {
							continue;
						}
						$pairs[] = array( 'id' => 'pair_' . $i, 'left' => $bits[0], 'right' => $bits[1] );
					}
					$question['pairs'] = $pairs;
					break;

				case 'fill_blanks':
					$question['blanks_text'] = $prompt;
					$question['correct']     = $raw_correct;
					break;

				default:
					$question['correct'] = '';
					break;
			}

			$out[] = $question;
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $out;
	}

	/** Stream questions out as CSV (same columns parse_csv accepts). */
	public static function output_csv( array $question_ids, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a request-scoped stream, not a filesystem path.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::CSV_HEADER );

		foreach ( $question_ids as $qid ) {
			$q    = get_post( $qid );
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! $q ) {
				continue;
			}

			$options = '';
			$correct = '';

			switch ( $data['type'] ) {
				case 'radio':
				case 'checkbox':
					$options   = implode( ' | ', wp_list_pluck( $data['options'], 'text' ) );
					$correct_i = array();
					$selected  = is_array( $data['correct'] ) ? $data['correct'] : array( $data['correct'] );
					foreach ( $data['options'] as $i => $o ) {
						if ( in_array( $o['id'], $selected, true ) ) {
							$correct_i[] = $i + 1; // 1-based for humans.
						}
					}
					$correct = implode( ' | ', $correct_i );
					break;

				case 'true_false':
					$correct = $data['correct'];
					break;

				case 'short_text':
					$correct = implode( ' | ', (array) $data['correct'] );
					break;

				case 'number':
					$correct = ( $data['correct']['value'] ?? 0 ) . ' | ' . ( $data['correct']['tolerance'] ?? 0 );
					break;

				case 'matching':
					$bits = array();
					foreach ( $data['pairs'] as $p ) {
						$bits[] = $p['left'] . ' -> ' . $p['right'];
					}
					$options = implode( ' | ', $bits );
					break;

				case 'fill_blanks':
					$correct = implode( ' | ', (array) $data['correct'] );
					break;
			}

			$terms    = wp_get_object_terms( $qid, 'qmc_question_category', array( 'fields' => 'names' ) );
			$category = is_wp_error( $terms ) ? '' : implode( ', ', $terms );

			fputcsv(
				$out,
				array(
					$data['type'],
					$q->post_title,
					$options,
					$correct,
					$data['points'],
					$category,
					$data['hint'],
					wp_strip_all_tags( $data['explanation'] ),
				)
			);
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ================================================================== *
	 *  Moodle XML
	 * ================================================================== */

	public static function parse_moodle_xml( $xml_string ) {
		$out = array();

		// Guard against XXE: disable external entity loading for this parse.
		$previous = libxml_disable_entity_loader( true );
		$prior    = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_disable_entity_loader( $previous );
		libxml_use_internal_errors( $prior );

		if ( ! $xml ) {
			return $out;
		}

		foreach ( $xml->question as $q ) {
			$mtype = (string) $q['type'];
			if ( 'category' === $mtype ) {
				continue;
			}

			$prompt = trim( wp_strip_all_tags( (string) $q->questiontext->text ) );
			if ( '' === $prompt ) {
				continue;
			}

			$question = array(
				'title'       => $prompt,
				'points'      => isset( $q->defaultgrade ) ? floatval( $q->defaultgrade ) : 1,
				'explanation' => isset( $q->generalfeedback->text ) ? trim( wp_strip_all_tags( (string) $q->generalfeedback->text ) ) : '',
				'hint'        => isset( $q->hint->text ) ? trim( wp_strip_all_tags( (string) $q->hint->text ) ) : '',
			);

			switch ( $mtype ) {
				case 'multichoice':
					$single  = isset( $q->single ) ? 'true' === strtolower( trim( (string) $q->single ) ) : true;
					$options = array();
					$correct = array();
					$i       = 0;
					foreach ( $q->answer as $ans ) {
						$options[] = array(
							'id'    => 'opt_' . $i,
							'text'  => trim( wp_strip_all_tags( (string) $ans->text ) ),
							'trait' => '',
						);
						if ( floatval( $ans['fraction'] ) > 0 ) {
							$correct[] = 'opt_' . $i;
						}
						$i++;
					}
					$question['type']    = $single ? 'radio' : 'checkbox';
					$question['options'] = $options;
					$question['correct'] = $single ? ( $correct[0] ?? '' ) : $correct;
					break;

				case 'truefalse':
					$question['type']    = 'true_false';
					$question['correct'] = 'false';
					foreach ( $q->answer as $ans ) {
						if ( floatval( $ans['fraction'] ) > 0 ) {
							$question['correct'] = 'true' === strtolower( trim( (string) $ans->text ) ) ? 'true' : 'false';
						}
					}
					break;

				case 'shortanswer':
					$accepted = array();
					foreach ( $q->answer as $ans ) {
						if ( floatval( $ans['fraction'] ) > 0 ) {
							$accepted[] = trim( wp_strip_all_tags( (string) $ans->text ) );
						}
					}
					$question['type']    = 'short_text';
					$question['correct'] = $accepted;
					break;

				case 'numerical':
					$value = 0;
					$tol   = 0;
					foreach ( $q->answer as $ans ) {
						if ( floatval( $ans['fraction'] ) > 0 ) {
							$value = floatval( (string) $ans->text );
							$tol   = isset( $ans->tolerance ) ? floatval( (string) $ans->tolerance ) : 0;
							break;
						}
					}
					$question['type']    = 'number';
					$question['correct'] = array( 'value' => $value, 'tolerance' => $tol );
					break;

				case 'matching':
					$pairs = array();
					$i     = 0;
					foreach ( $q->subquestion as $sub ) {
						$left  = trim( wp_strip_all_tags( (string) $sub->text ) );
						$right = trim( wp_strip_all_tags( (string) $sub->answer->text ) );
						if ( '' === $left || '' === $right ) {
							continue;
						}
						$pairs[] = array( 'id' => 'pair_' . $i, 'left' => $left, 'right' => $right );
						$i++;
					}
					$question['type']  = 'matching';
					$question['pairs'] = $pairs;
					break;

				case 'essay':
					$question['type'] = 'text';
					break;

				default:
					continue 2; // Unsupported Moodle type — skip cleanly.
			}

			$out[] = $question;
		}

		return $out;
	}

	public static function build_moodle_xml( array $question_ids ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";

		foreach ( $question_ids as $qid ) {
			$q    = get_post( $qid );
			$data = QFA_Question_Types::get_question_data( $qid );
			if ( ! $q ) {
				continue;
			}
			$prompt = self::cdata( $q->post_title );
			$grade  = floatval( $data['points'] );

			switch ( $data['type'] ) {
				case 'radio':
				case 'checkbox':
					$single = 'radio' === $data['type'];
					$xml   .= "  <question type=\"multichoice\">\n";
					$xml   .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml   .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml   .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					$xml   .= '    <single>' . ( $single ? 'true' : 'false' ) . "</single>\n";
					$selected = is_array( $data['correct'] ) ? $data['correct'] : array( $data['correct'] );
					$n        = max( 1, count( $selected ) );
					foreach ( $data['options'] as $opt ) {
						$is  = in_array( $opt['id'], $selected, true );
						$frac = $is ? round( 100 / $n, 5 ) : 0;
						$xml .= '    <answer fraction="' . $frac . '"><text>' . self::cdata( $opt['text'] ) . "</text></answer>\n";
					}
					$xml .= "  </question>\n";
					break;

				case 'true_false':
					$xml .= "  <question type=\"truefalse\">\n";
					$xml .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					$xml .= '    <answer fraction="' . ( 'true' === $data['correct'] ? 100 : 0 ) . "\"><text>true</text></answer>\n";
					$xml .= '    <answer fraction="' . ( 'false' === $data['correct'] ? 100 : 0 ) . "\"><text>false</text></answer>\n";
					$xml .= "  </question>\n";
					break;

				case 'short_text':
					$xml .= "  <question type=\"shortanswer\">\n";
					$xml .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					foreach ( (array) $data['correct'] as $acc ) {
						$xml .= '    <answer fraction="100"><text>' . self::cdata( $acc ) . "</text></answer>\n";
					}
					$xml .= "  </question>\n";
					break;

				case 'number':
					$xml .= "  <question type=\"numerical\">\n";
					$xml .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					$xml .= '    <answer fraction="100"><text>' . floatval( $data['correct']['value'] ?? 0 ) . '</text><tolerance>' . floatval( $data['correct']['tolerance'] ?? 0 ) . "</tolerance></answer>\n";
					$xml .= "  </question>\n";
					break;

				case 'matching':
					$xml .= "  <question type=\"matching\">\n";
					$xml .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					foreach ( $data['pairs'] as $pair ) {
						$xml .= "    <subquestion format=\"html\">\n";
						$xml .= '      <text>' . self::cdata( $pair['left'] ) . "</text>\n";
						$xml .= '      <answer><text>' . self::cdata( $pair['right'] ) . "</text></answer>\n";
						$xml .= "    </subquestion>\n";
					}
					$xml .= "  </question>\n";
					break;

				case 'text':
					$xml .= "  <question type=\"essay\">\n";
					$xml .= '    <name><text>' . esc_html( wp_trim_words( $q->post_title, 8 ) ) . "</text></name>\n";
					$xml .= "    <questiontext format=\"html\"><text>$prompt</text></questiontext>\n";
					$xml .= '    <defaultgrade>' . $grade . "</defaultgrade>\n";
					$xml .= "  </question>\n";
					break;
			}
		}

		return $xml . '</quiz>';
	}

	protected static function cdata( $s ) {
		return '<![CDATA[' . wp_strip_all_tags( $s ) . ']]>';
	}

	/* ================================================================== *
	 *  Shared: turn parsed questions into real posts
	 * ================================================================== */

	public static function create_questions( array $parsed, $category_id = 0 ) {
		$new_ids = array();

		foreach ( $parsed as $item ) {
			$question_id = wp_insert_post(
				array(
					'post_type'   => 'qmc_question',
					'post_title'  => wp_strip_all_tags( $item['title'] ),
					'post_status' => 'publish',
				)
			);
			if ( is_wp_error( $question_id ) || ! $question_id ) {
				continue;
			}

			update_post_meta( $question_id, '_qmc_type', $item['type'] );
			update_post_meta( $question_id, '_qmc_points', floatval( $item['points'] ?? 1 ) ?: 1 );
			update_post_meta( $question_id, '_qmc_options', $item['options'] ?? array() );
			update_post_meta( $question_id, '_qmc_correct', $item['correct'] ?? '' );
			update_post_meta( $question_id, '_qmc_hint', $item['hint'] ?? '' );
			update_post_meta( $question_id, '_qmc_explanation', $item['explanation'] ?? '' );

			if ( ! empty( $item['pairs'] ) ) {
				update_post_meta( $question_id, '_qmc_pairs', $item['pairs'] );
			}
			if ( ! empty( $item['blanks_text'] ) ) {
				update_post_meta( $question_id, '_qmc_blanks_text', $item['blanks_text'] );
			}

			// A per-row category name from CSV wins; otherwise the
			// screen-wide category selection applies.
			if ( ! empty( $item['category'] ) ) {
				$term = term_exists( $item['category'], 'qmc_question_category' );
				if ( ! $term ) {
					$term = wp_insert_term( $item['category'], 'qmc_question_category' );
				}
				if ( ! is_wp_error( $term ) ) {
					wp_set_object_terms( $question_id, array( intval( $term['term_id'] ) ), 'qmc_question_category' );
				}
			} elseif ( $category_id ) {
				wp_set_object_terms( $question_id, array( (int) $category_id ), 'qmc_question_category' );
			}

			$new_ids[] = $question_id;
		}

		return $new_ids;
	}

	/* ================================================================== *
	 *  Admin screen
	 * ================================================================== */

	public static function render_page() {
		$quizzes = get_posts( array( 'post_type' => 'qmc_quiz', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$cats    = get_terms( array( 'taxonomy' => 'qmc_question_category', 'hide_empty' => false ) );
		?>
		<div class="wrap qmc-admin">
			<h1><?php esc_html_e( 'Import / Export Questions', 'quizzis-for-all' ); ?></h1>

			<?php
			// Read-only post-redirect notices; the handlers below verify nonces.
			$imported = isset( $_GET['imported'] ) ? intval( $_GET['imported'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $imported > 0 ) :
				?>
				<div class="notice notice-success"><p>
					<?php
					/* translators: %d: number of questions imported */
					printf( esc_html__( 'Imported %d question(s) into the bank.', 'quizzis-for-all' ), (int) $imported );
					?>
				</p></div>
			<?php elseif ( 0 === $imported ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No valid questions were found in that file. Check the format and try again.', 'quizzis-for-all' ); ?></p></div>
			<?php endif; ?>

			<div class="qmc-format-grid">
				<div class="qmc-format-card">
					<h3>📄 <?php esc_html_e( 'GIFT', 'quizzis-for-all' ); ?></h3>
					<p><?php esc_html_e( "Moodle's plain-text format. Supports multiple choice, true/false, short answer, numerical and matching.", 'quizzis-for-all' ); ?></p>
					<code>Who wrote Hamlet? {
	=Shakespeare
	~Marlowe
	~Jonson
}

The sky is blue. {TRUE}

What is 2+2? {#4:0}</code>
				</div>
				<div class="qmc-format-card">
					<h3>📊 <?php esc_html_e( 'CSV', 'quizzis-for-all' ); ?></h3>
					<p><?php esc_html_e( 'Author questions in Excel or Google Sheets. Correct answers are 1-based option numbers (or the literal text).', 'quizzis-for-all' ); ?></p>
					<code>type,question,options,correct,points,category
radio,Capital of France?,London | Paris | Rome,2,1,Geography
true_false,Water boils at 100C,,true,1,Science</code>
				</div>
				<div class="qmc-format-card">
					<h3>🎓 <?php esc_html_e( 'Moodle XML', 'quizzis-for-all' ); ?></h3>
					<p><?php esc_html_e( 'The format Moodle itself exports. Move a question bank between Moodle and WordPress in either direction — multichoice, truefalse, shortanswer, numerical, matching and essay.', 'quizzis-for-all' ); ?></p>
					<code>&lt;quiz&gt;
  &lt;question type="multichoice"&gt;
    …
  &lt;/question&gt;
&lt;/quiz&gt;</code>
				</div>
			</div>

			<div class="qmc-panels">
				<div class="qmc-panel">
					<h2><?php esc_html_e( 'Import', 'quizzis-for-all' ); ?></h2>
					<div class="qmc-panel-body">
						<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'qmc_import_formats' ); ?>
							<input type="hidden" name="action" value="qmc_import_formats">
							<table class="form-table">
								<tr>
									<th><label for="qmc_format"><?php esc_html_e( 'Format', 'quizzis-for-all' ); ?></label></th>
									<td>
										<select name="qmc_format" id="qmc_format">
											<option value="gift"><?php esc_html_e( 'GIFT (.txt)', 'quizzis-for-all' ); ?></option>
											<option value="csv"><?php esc_html_e( 'CSV (.csv)', 'quizzis-for-all' ); ?></option>
											<option value="moodlexml"><?php esc_html_e( 'Moodle XML (.xml)', 'quizzis-for-all' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="qmc_file"><?php esc_html_e( 'File', 'quizzis-for-all' ); ?></label></th>
									<td><input type="file" name="qmc_file" id="qmc_file" accept=".txt,.csv,.xml" required></td>
								</tr>
								<tr>
									<th><label for="qmc_target_quiz"><?php esc_html_e( 'Also add to quiz', 'quizzis-for-all' ); ?></label></th>
									<td>
										<select name="qmc_target_quiz" id="qmc_target_quiz">
											<option value=""><?php esc_html_e( '— bank only —', 'quizzis-for-all' ); ?></option>
											<?php foreach ( $quizzes as $q ) : ?>
												<option value="<?php echo (int) $q->ID; ?>"><?php echo esc_html( $q->post_title ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="qmc_target_category"><?php esc_html_e( 'Assign category', 'quizzis-for-all' ); ?></label></th>
									<td>
										<select name="qmc_target_category" id="qmc_target_category">
											<option value=""><?php esc_html_e( '— none —', 'quizzis-for-all' ); ?></option>
											<?php foreach ( $cats as $cat ) : ?>
												<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'CSV rows with their own category column override this.', 'quizzis-for-all' ); ?></p>
									</td>
								</tr>
							</table>
							<?php submit_button( __( 'Import questions', 'quizzis-for-all' ) ); ?>
						</form>
					</div>
				</div>

				<div class="qmc-panel">
					<h2><?php esc_html_e( 'Export', 'quizzis-for-all' ); ?></h2>
					<div class="qmc-panel-body">
						<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'qmc_export_formats' ); ?>
							<input type="hidden" name="action" value="qmc_export_formats">
							<table class="form-table">
								<tr>
									<th><label for="qmc_export_format"><?php esc_html_e( 'Format', 'quizzis-for-all' ); ?></label></th>
									<td>
										<select name="qmc_format" id="qmc_export_format">
											<option value="gift"><?php esc_html_e( 'GIFT (.txt)', 'quizzis-for-all' ); ?></option>
											<option value="csv"><?php esc_html_e( 'CSV (.csv)', 'quizzis-for-all' ); ?></option>
											<option value="moodlexml"><?php esc_html_e( 'Moodle XML (.xml)', 'quizzis-for-all' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="qmc_export_source"><?php esc_html_e( 'What to export', 'quizzis-for-all' ); ?></label></th>
									<td>
										<select name="qmc_source" id="qmc_export_source">
											<option value="all"><?php esc_html_e( 'Entire question bank', 'quizzis-for-all' ); ?></option>
											<optgroup label="<?php esc_attr_e( 'A quiz', 'quizzis-for-all' ); ?>">
												<?php foreach ( $quizzes as $q ) : ?>
													<option value="quiz:<?php echo (int) $q->ID; ?>"><?php echo esc_html( $q->post_title ); ?></option>
												<?php endforeach; ?>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'A category', 'quizzis-for-all' ); ?>">
												<?php foreach ( $cats as $cat ) : ?>
													<option value="cat:<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										</select>
									</td>
								</tr>
							</table>
							<?php submit_button( __( 'Download export', 'quizzis-for-all' ), 'secondary' ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Essay and file-upload questions are skipped by GIFT and CSV exports (no equivalent in those formats); Moodle XML keeps essays.', 'quizzis-for-all' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 *  Handlers
	 * ------------------------------------------------------------------ */

	public static function handle_import() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'qmc_import_formats' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}
		if ( empty( $_FILES['qmc_file']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded.', 'quizzis-for-all' ) );
		}

		$format   = sanitize_key( wp_unslash( $_POST['qmc_format'] ?? 'gift' ) );
		$tmp_name = sanitize_text_field( wp_unslash( $_FILES['qmc_file']['tmp_name'] ) );

		switch ( $format ) {
			case 'csv':
				$parsed = self::parse_csv( $tmp_name );
				break;
			case 'moodlexml':
				$parsed = self::parse_moodle_xml( file_get_contents( $tmp_name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file, not a remote URL.
				break;
			case 'gift':
			default:
				$parsed = self::parse_gift( file_get_contents( $tmp_name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- as above.
				break;
		}

		if ( empty( $parsed ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=qmc_formats&imported=0' ) );
			exit;
		}

		$category_id = intval( $_POST['qmc_target_category'] ?? 0 );
		$new_ids     = self::create_questions( $parsed, $category_id );

		$target_quiz = intval( $_POST['qmc_target_quiz'] ?? 0 );
		if ( $target_quiz && get_post( $target_quiz ) ) {
			$existing = get_post_meta( $target_quiz, '_qmc_question_ids', true );
			$existing = is_array( $existing ) ? $existing : array();
			update_post_meta( $target_quiz, '_qmc_question_ids', array_values( array_unique( array_merge( $existing, $new_ids ) ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=qmc_formats&imported=' . count( $new_ids ) ) );
		exit;
	}

	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qmc_export_formats' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}

		$format = sanitize_key( wp_unslash( $_GET['qmc_format'] ?? 'gift' ) );
		$source = sanitize_text_field( wp_unslash( $_GET['qmc_source'] ?? 'all' ) );

		// Resolve the question set.
		if ( 0 === strpos( $source, 'quiz:' ) ) {
			$quiz_id      = intval( substr( $source, 5 ) );
			$question_ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
			$question_ids = is_array( $question_ids ) ? $question_ids : array();
			$slug         = 'quiz-' . $quiz_id;
		} elseif ( 0 === strpos( $source, 'cat:' ) ) {
			$term_id      = intval( substr( $source, 4 ) );
			$question_ids = get_posts(
				array(
					'post_type'      => 'qmc_question',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- exporting a chosen category is the feature.
						array(
							'taxonomy' => 'qmc_question_category',
							'field'    => 'term_id',
							'terms'    => array( $term_id ),
						),
					),
				)
			);
			$slug = 'category-' . $term_id;
		} else {
			$question_ids = get_posts(
				array(
					'post_type'      => 'qmc_question',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			$slug = 'question-bank';
		}

		switch ( $format ) {
			case 'csv':
				self::output_csv( $question_ids, 'qmc-' . $slug . '.csv' );
				break;

			case 'moodlexml':
				nocache_headers();
				header( 'Content-Type: text/xml; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=qmc-' . $slug . '.xml' );
				echo self::build_moodle_xml( $question_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document body, escaped/CDATA-wrapped during construction.
				exit;

			case 'gift':
			default:
				nocache_headers();
				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=qmc-' . $slug . '-gift.txt' );
				echo self::build_gift( $question_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text file body, escaped during construction.
				exit;
		}
	}
}
