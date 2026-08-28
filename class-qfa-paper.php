<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paper mode: render a quiz as a print-ready question paper (and,
 * separately, an answer key) for offline/invigilated use.
 *
 * Deliberately a standalone HTML page rather than a generated PDF — the
 * browser's own "Print → Save as PDF" produces a better result than a
 * bundled PDF library would, with no extra dependency, and instructors
 * can adjust margins/scale at print time.
 */
class QFA_Paper {

	public static function init() {
		add_action( 'admin_post_qmc_print_paper', array( __CLASS__, 'render' ) );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'editor_links' ) );
	}

	/** "Print question paper / answer key" links in the quiz publish box. */
	public static function editor_links( $post ) {
		if ( ! $post || 'qmc_quiz' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		$base = admin_url( 'admin-post.php?action=qmc_print_paper&quiz_id=' . $post->ID );
		?>
		<div class="misc-pub-section" style="border-top:1px solid #dcdcde;">
			<span class="dashicons dashicons-printer" style="color:#787c82;"></span>
			<a href="<?php echo esc_url( wp_nonce_url( $base . '&sheet=paper', 'qmc_print_paper' ) ); ?>" target="_blank"><?php esc_html_e( 'Print question paper', 'quizzis-for-all' ); ?></a>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( wp_nonce_url( $base . '&sheet=key', 'qmc_print_paper' ) ); ?>" target="_blank"><?php esc_html_e( 'Answer key', 'quizzis-for-all' ); ?></a>
		</div>
		<?php
	}

	public static function render() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qmc_print_paper' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}

		$quiz_id = isset( $_GET['quiz_id'] ) ? intval( $_GET['quiz_id'] ) : 0;
		if ( ! current_user_can( 'edit_post', $quiz_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}

		$sheet = isset( $_GET['sheet'] ) ? sanitize_key( wp_unslash( $_GET['sheet'] ) ) : 'paper';
		$is_key = 'key' === $sheet;

		$quiz = get_post( $quiz_id );
		if ( ! $quiz || 'qmc_quiz' !== $quiz->post_type ) {
			wp_die( esc_html__( 'Quiz not found.', 'quizzis-for-all' ) );
		}

		$question_ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
		$question_ids = is_array( $question_ids ) ? $question_ids : array();

		// Dynamic quizzes have no fixed paper; draw a representative set so
		// the instructor still gets something printable.
		if ( get_post_meta( $quiz_id, '_qmc_dynamic_enabled', true ) ) {
			$count = max( 1, intval( get_post_meta( $quiz_id, '_qmc_dynamic_count', true ) ) ?: 10 );
			$cats  = get_post_meta( $quiz_id, '_qmc_dynamic_categories', true );
			$args  = array(
				'post_type'      => 'qmc_question',
				'posts_per_page' => $count,
				'orderby'        => 'rand',
				'fields'         => 'ids',
			);
			if ( is_array( $cats ) && ! empty( $cats ) ) {
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- category-selective draw is the feature.
					array(
						'taxonomy' => 'qmc_question_category',
						'field'    => 'term_id',
						'terms'    => array_map( 'intval', $cats ),
					),
				);
			}
			$question_ids = get_posts( $args );
		}

		$total_marks = 0;
		foreach ( $question_ids as $qid ) {
			$total_marks += floatval( get_post_meta( $qid, '_qmc_points', true ) ?: 1 );
		}
		$timer = intval( get_post_meta( $quiz_id, '_qmc_timer_minutes', true ) );

		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $quiz->post_title . ( $is_key ? ' — ' . __( 'Answer Key', 'quizzis-for-all' ) : '' ) ); ?></title>
			<style>
				* { box-sizing: border-box; }
				body {
					font-family: "Times New Roman", Georgia, serif;
					font-size: 12pt;
					line-height: 1.5;
					color: #000;
					background: #f0f0f0;
					margin: 0;
					padding: 20px;
				}
				.sheet {
					background: #fff;
					max-width: 210mm;
					min-height: 297mm;
					margin: 0 auto;
					padding: 18mm 16mm;
					box-shadow: 0 2px 12px rgba(0,0,0,0.15);
				}
				.masthead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 6px; }
				.masthead .org { font-size: 11pt; letter-spacing: 2px; text-transform: uppercase; }
				.masthead h1 { font-size: 17pt; margin: 6px 0 4px; }
				.keytag { display: inline-block; border: 2px solid #b32d2e; color: #b32d2e; font-size: 10pt; letter-spacing: 2px; padding: 2px 10px; margin-top: 4px; text-transform: uppercase; }
				.examline { display: flex; justify-content: space-between; font-size: 10.5pt; padding: 6px 0; border-bottom: 1px solid #000; margin-bottom: 14px; }
				.candidate { display: flex; gap: 24px; font-size: 11pt; margin-bottom: 18px; }
				.candidate div { flex: 1; border-bottom: 1px dotted #555; padding-bottom: 2px; }
				.instructions { border: 1px solid #999; padding: 8px 12px; font-size: 10.5pt; margin-bottom: 18px; background: #fafafa; }
				.instructions strong { text-transform: uppercase; letter-spacing: 1px; font-size: 9.5pt; }
				.instructions ul { margin: 6px 0 0; padding-left: 18px; }
				.q { margin-bottom: 15px; page-break-inside: avoid; }
				.q-head { display: flex; justify-content: space-between; gap: 12px; font-weight: bold; }
				.q-num { min-width: 26px; }
				.q-text { flex: 1; }
				.marks { white-space: nowrap; font-weight: normal; font-size: 10.5pt; }
				.opts { margin: 6px 0 0 26px; }
				.opt { margin: 3px 0; }
				.opt .box { display: inline-block; width: 13px; height: 13px; border: 1px solid #000; margin-right: 8px; vertical-align: -2px; }
				.opt.round .box { border-radius: 50%; }
				.opt.is-answer { font-weight: bold; }
				.opt.is-answer .box { background: #000; }
				.rule { border-bottom: 1px solid #bbb; height: 20px; margin: 6px 0 0 26px; }
				.rule.short { width: 55%; }
				.blanklines { margin: 8px 0 0 26px; }
				.blanklines div { border-bottom: 1px solid #bbb; height: 22px; }
				.pairs { margin: 6px 0 0 26px; width: 100%; border-collapse: collapse; font-size: 11pt; }
				.pairs td { padding: 3px 6px; vertical-align: top; }
				.pairs .l { width: 45%; border-bottom: 1px dotted #777; }
				.pairs .r { width: 45%; border-bottom: 1px dotted #777; }
				.answer { margin: 5px 0 0 26px; font-weight: bold; color: #0a5c2e; }
				.answer .lbl { color: #555; font-weight: normal; font-size: 10pt; text-transform: uppercase; letter-spacing: 1px; margin-right: 6px; }
				.explain { margin: 3px 0 0 26px; font-size: 10.5pt; font-style: italic; color: #444; }
				.info { margin: 12px 0; padding: 8px 12px; border-left: 3px solid #000; background: #f4f4f4; font-style: italic; }
				.foot { margin-top: 26px; border-top: 1px solid #000; padding-top: 8px; text-align: center; font-size: 10pt; }
				.toolbar { max-width: 210mm; margin: 0 auto 14px; text-align: right; }
				.toolbar button, .toolbar a {
					background: #2271b1; color: #fff; border: none; border-radius: 4px;
					padding: 9px 20px; font-size: 14px; font-family: system-ui, sans-serif;
					cursor: pointer; text-decoration: none; display: inline-block; margin-left: 6px;
				}
				.toolbar a.alt { background: #50575e; }
				@media print {
					body { background: #fff; padding: 0; }
					.sheet { box-shadow: none; max-width: none; min-height: 0; padding: 0; margin: 0; }
					.toolbar { display: none; }
					@page { margin: 16mm; }
				}
			</style>
		</head>
		<body>
			<div class="toolbar">
				<?php
				$other = admin_url( 'admin-post.php?action=qmc_print_paper&quiz_id=' . $quiz_id . '&sheet=' . ( $is_key ? 'paper' : 'key' ) );
				?>
				<a class="alt" href="<?php echo esc_url( wp_nonce_url( $other, 'qmc_print_paper' ) ); ?>">
					<?php echo $is_key ? esc_html__( 'View question paper', 'quizzis-for-all' ) : esc_html__( 'View answer key', 'quizzis-for-all' ); ?>
				</a>
				<button onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'quizzis-for-all' ); ?></button>
			</div>

			<div class="sheet">
				<div class="masthead">
					<div class="org"><?php bloginfo( 'name' ); ?></div>
					<h1><?php echo esc_html( $quiz->post_title ); ?></h1>
					<?php if ( $is_key ) : ?>
						<div class="keytag"><?php esc_html_e( 'Answer Key — Not for distribution', 'quizzis-for-all' ); ?></div>
					<?php endif; ?>
				</div>

				<div class="examline">
					<span><?php
						/* translators: %s: Time allowed for the quiz, e.g. "30 minutes", or an em dash if untimed. */
						printf( esc_html__( 'Time allowed: %s', 'quizzis-for-all' ), $timer ? esc_html( $timer . ' ' . __( 'minutes', 'quizzis-for-all' ) ) : esc_html__( '—', 'quizzis-for-all' ) );
					?></span>
					<span><?php
						/* translators: %d: Number of questions in the quiz. */
						printf( esc_html__( 'Questions: %d', 'quizzis-for-all' ), count( $question_ids ) );
					?></span>
					<span><?php
						/* translators: %s: Maximum marks obtainable for the quiz. */
						printf( esc_html__( 'Maximum marks: %s', 'quizzis-for-all' ), esc_html( rtrim( rtrim( number_format( $total_marks, 2 ), '0' ), '.' ) ) );
					?></span>
				</div>

				<?php if ( ! $is_key ) : ?>
					<div class="candidate">
						<div><?php esc_html_e( 'Name:', 'quizzis-for-all' ); ?></div>
						<div><?php esc_html_e( 'Roll No.:', 'quizzis-for-all' ); ?></div>
						<div><?php esc_html_e( 'Date:', 'quizzis-for-all' ); ?></div>
					</div>
					<?php if ( trim( wp_strip_all_tags( $quiz->post_content ) ) ) : ?>
						<div class="instructions">
							<strong><?php esc_html_e( 'Instructions', 'quizzis-for-all' ); ?></strong>
							<div><?php echo wp_kses_post( wpautop( $quiz->post_content ) ); ?></div>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				$n = 0;
				foreach ( $question_ids as $qid ) {
					$q    = get_post( $qid );
					$data = QFA_Question_Types::get_question_data( $qid );
					if ( ! $q ) {
						continue;
					}

					if ( 'info' === $data['type'] ) {
						echo '<div class="info">' . esc_html( $q->post_title ) . '</div>';
						continue;
					}
					$n++;
					self::render_question( $n, $q, $data, $is_key );
				}
				?>

				<div class="foot">
					<?php echo $is_key ? esc_html__( '— End of answer key —', 'quizzis-for-all' ) : esc_html__( '— End of question paper —', 'quizzis-for-all' ); ?>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/** One printed question, in either paper or key form. */
	protected static function render_question( $n, $q, array $data, $is_key ) {
		$marks = floatval( $data['points'] );
		?>
		<div class="q">
			<div class="q-head">
				<span class="q-num"><?php echo (int) $n; ?>.</span>
				<span class="q-text"><?php echo esc_html( $q->post_title ); ?></span>
				<span class="marks">[<?php echo esc_html( rtrim( rtrim( number_format( $marks, 2 ), '0' ), '.' ) ); ?>]</span>
			</div>

			<?php
			switch ( $data['type'] ) {
				case 'radio':
				case 'checkbox':
					$correct = is_array( $data['correct'] ) ? $data['correct'] : array( $data['correct'] );
					$letters = range( 'A', 'Z' );
					echo '<div class="opts">';
					foreach ( $data['options'] as $i => $opt ) {
						$is_ans = $is_key && in_array( $opt['id'], $correct, true );
						printf(
							'<div class="opt %1$s %2$s"><span class="box"></span>(%3$s) %4$s</div>',
							'radio' === $data['type'] ? 'round' : '',
							$is_ans ? 'is-answer' : '',
							esc_html( $letters[ $i ] ?? $i + 1 ),
							esc_html( $opt['text'] )
						);
					}
					echo '</div>';
					break;

				case 'true_false':
					echo '<div class="opts">';
					printf(
						'<div class="opt round %1$s"><span class="box"></span>%2$s</div>',
						$is_key && 'true' === $data['correct'] ? 'is-answer' : '',
						esc_html__( 'True', 'quizzis-for-all' )
					);
					printf(
						'<div class="opt round %1$s"><span class="box"></span>%2$s</div>',
						$is_key && 'false' === $data['correct'] ? 'is-answer' : '',
						esc_html__( 'False', 'quizzis-for-all' )
					);
					echo '</div>';
					break;

				case 'short_text':
				case 'number':
				case 'date':
					if ( ! $is_key ) {
						echo '<div class="rule short"></div>';
					}
					break;

				case 'fill_blanks':
					if ( ! $is_key && ! empty( $data['blanks_text'] ) ) {
						echo '<div class="opts">' . esc_html( str_replace( '{blank}', ' __________ ', $data['blanks_text'] ) ) . '</div>';
					}
					break;

				case 'matching':
					echo '<table class="pairs">';
					$rights = wp_list_pluck( $data['pairs'], 'right' );
					if ( ! $is_key ) {
						shuffle( $rights );
					}
					foreach ( $data['pairs'] as $i => $pair ) {
						printf(
							'<tr><td class="l">%1$s</td><td class="r">%2$s</td></tr>',
							esc_html( $pair['left'] ),
							esc_html( $is_key ? $pair['right'] : ( $rights[ $i ] ?? '' ) )
						);
					}
					echo '</table>';
					break;

				case 'text':
					if ( ! $is_key ) {
						echo '<div class="blanklines">';
						for ( $i = 0; $i < 5; $i++ ) {
							echo '<div></div>';
						}
						echo '</div>';
					}
					break;

				case 'file_upload':
					if ( ! $is_key ) {
						echo '<div class="explain">' . esc_html__( '(Submission to be attached separately)', 'quizzis-for-all' ) . '</div>';
					}
					break;
			}

			if ( $is_key ) {
				$key = QFA_Review::format_correct( $data );
				if ( '' !== $key ) {
					echo '<div class="answer"><span class="lbl">' . esc_html__( 'Answer', 'quizzis-for-all' ) . '</span>' . esc_html( $key ) . '</div>';
				}
				if ( ! empty( $data['explanation'] ) ) {
					echo '<div class="explain">' . esc_html( wp_strip_all_tags( $data['explanation'] ) ) . '</div>';
				}
			}
			?>
		</div>
		<?php
	}
}
