<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reporting dashboards. Kept separate from the simple attempt list in
 * QFA_Admin (Quiz Master → Results, which is a raw per-attempt table) —
 * this page is aggregate/analytical: totals, trends, and per-question
 * difficulty so an instructor can see which questions are too easy/hard.
 */
class QFA_Reports {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'qmc_dashboard', __( 'Reports', 'quizzis-for-all' ), __( 'Reports', 'quizzis-for-all' ), 'edit_posts', 'qmc_reports', array( __CLASS__, 'render_page' ) );
	}

	public static function assets( $hook ) {
		if ( empty( $_GET['page'] ) || 'qmc_reports' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen id check, not a state change.
			return;
		}
		// Bundled locally (assets/vendor) rather than loaded from a CDN —
		// WordPress.org plugin guidelines disallow offloading scripts to
		// external/remote servers.
		wp_enqueue_script( 'qfa-chartjs', QFA_PLUGIN_URL . 'assets/vendor/chart.umd.min.js', array(), '4.4.0', true );
	}

	public static function render_page() {
		$overview     = QFA_DB::get_overview_stats();
		$per_quiz     = QFA_DB::get_per_quiz_stats();
		$trend        = QFA_DB::get_attempts_by_day( 30 );
		// Read-only screen filter (which quiz's breakdown to show) — no
		// state change, so a nonce isn't required here.
		$selected_qid = isset( $_GET['quiz_id'] ) ? intval( $_GET['quiz_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$pass_rate = $overview->total_attempts > 0 ? round( ( $overview->total_passed / $overview->total_attempts ) * 100, 1 ) : 0;

		$trend_labels = wp_list_pluck( $trend, 'day' );
		$trend_values = array_map( 'intval', wp_list_pluck( $trend, 'total' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'quizzis-for-all' ); ?></h1>

			<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
				<?php
				self::stat_card( $overview->total_attempts ?: 0, __( 'Total attempts', 'quizzis-for-all' ) );
				self::stat_card( round( $overview->avg_percentage ?: 0, 1 ) . '%', __( 'Average score', 'quizzis-for-all' ) );
				self::stat_card( $pass_rate . '%', __( 'Pass rate', 'quizzis-for-all' ) );
				self::stat_card( $overview->distinct_users ?: 0, __( 'Unique test-takers', 'quizzis-for-all' ) );
				self::stat_card( $overview->quizzes_attempted ?: 0, __( 'Quizzes with attempts', 'quizzis-for-all' ) );
				?>
			</div>

			<h2><?php esc_html_e( 'Attempts — last 30 days', 'quizzis-for-all' ); ?></h2>
			<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:900px;">
				<canvas id="qmc-trend-chart" height="80"></canvas>
			</div>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Per-quiz performance', 'quizzis-for-all' ); ?></h2>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'quizzis-for-all' ); ?></th>
						<th><?php esc_html_e( 'Avg. score', 'quizzis-for-all' ); ?></th>
						<th><?php esc_html_e( 'Pass rate', 'quizzis-for-all' ); ?></th>
						<th><?php esc_html_e( 'Avg. time', 'quizzis-for-all' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $per_quiz ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No attempts recorded yet.', 'quizzis-for-all' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $per_quiz as $row ) :
						$title = get_the_title( $row->quiz_id ) ?: '#' . $row->quiz_id;
						$qpass = $row->attempts > 0 ? round( ( $row->passed_count / $row->attempts ) * 100, 1 ) : 0;
						?>
						<tr>
							<td><?php echo esc_html( $title ); ?></td>
							<td><?php echo (int) $row->attempts; ?></td>
							<td><?php echo esc_html( round( $row->avg_percentage, 1 ) ); ?>%</td>
							<td><?php echo esc_html( $qpass ); ?>%</td>
							<td><?php echo esc_html( gmdate( 'i:s', (int) $row->avg_time ) ); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_reports&quiz_id=' . $row->quiz_id ) ); ?>"><?php esc_html_e( 'Question breakdown', 'quizzis-for-all' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $selected_qid ) : ?>
				<h2 style="margin-top:30px;">
					<?php
					printf(
						/* translators: %s: quiz title */
						esc_html__( 'Question difficulty — %s', 'quizzis-for-all' ),
						esc_html( get_the_title( $selected_qid ) )
					);
					?>
				</h2>
				<?php self::render_question_breakdown( $selected_qid ); ?>
			<?php endif; ?>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			if ( typeof Chart === 'undefined' ) { return; }
			var ctx = document.getElementById('qmc-trend-chart');
			if ( ctx ) {
				new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode( $trend_labels ); ?>,
						datasets: [{
							label: '<?php echo esc_js( __( 'Attempts', 'quizzis-for-all' ) ); ?>',
							data: <?php echo wp_json_encode( $trend_values ); ?>,
							borderColor: '#2271b1',
							backgroundColor: 'rgba(34,113,177,0.1)',
							tension: 0.25,
							fill: true
						}]
					},
					options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
				});
			}
			<?php if ( $selected_qid ) : ?>
			var qctx = document.getElementById('qmc-question-chart');
			if ( qctx ) {
				new Chart(qctx, {
					type: 'bar',
					data: {
						labels: <?php echo wp_json_encode( self::$question_chart_labels ); ?>,
						datasets: [{
							label: '<?php echo esc_js( __( '% answered correctly', 'quizzis-for-all' ) ); ?>',
							data: <?php echo wp_json_encode( self::$question_chart_values ); ?>,
							backgroundColor: '#2271b1'
						}]
					},
					options: {
						indexAxis: 'y',
						scales: { x: { beginAtZero: true, max: 100 } }
					}
				});
			}
			<?php endif; ?>
		});
		</script>
		<?php
	}

	protected static function stat_card( $value, $label ) {
		printf(
			'<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;min-width:140px;">
				<div style="font-size:26px;font-weight:600;">%s</div>
				<div style="color:#666;">%s</div>
			</div>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	// Populated by render_question_breakdown() and read by the inline <script> above.
	protected static $question_chart_labels = array();
	protected static $question_chart_values = array();

	/**
	 * Decodes every stored per-question breakdown for a quiz and tallies
	 * correct/incorrect counts per question, so instructors can spot
	 * questions that are miscalibrated (too easy/hard) or confusing.
	 */
	protected static function render_question_breakdown( $quiz_id ) {
		$rows = QFA_DB::get_breakdowns_for_quiz( $quiz_id );

		$question_ids = get_post_meta( $quiz_id, '_qmc_question_ids', true );
		$question_ids = is_array( $question_ids ) ? $question_ids : array();

		$tally = array(); // qid => [correct, incorrect, manual]
		foreach ( $question_ids as $qid ) {
			$tally[ $qid ] = array( 'correct' => 0, 'incorrect' => 0, 'manual' => 0 );
		}

		foreach ( $rows as $raw ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $qid => $info ) {
				if ( '_personality' === $qid || ! isset( $tally[ $qid ] ) ) {
					continue;
				}
				if ( null === $info['is_correct'] ) {
					$tally[ $qid ]['manual']++;
				} elseif ( $info['is_correct'] ) {
					$tally[ $qid ]['correct']++;
				} else {
					$tally[ $qid ]['incorrect']++;
				}
			}
		}

		echo '<table class="widefat striped" style="max-width:900px;">';
		echo '<thead><tr><th>' . esc_html__( 'Question', 'quizzis-for-all' ) . '</th><th>' . esc_html__( 'Correct', 'quizzis-for-all' ) . '</th><th>' . esc_html__( 'Incorrect', 'quizzis-for-all' ) . '</th><th>' . esc_html__( '% correct', 'quizzis-for-all' ) . '</th></tr></thead><tbody>';

		self::$question_chart_labels = array();
		self::$question_chart_values = array();

		foreach ( $question_ids as $qid ) {
			$q = get_post( $qid );
			if ( ! $q ) {
				continue;
			}
			$t     = $tally[ $qid ];
			$total = $t['correct'] + $t['incorrect'];
			$pct   = $total > 0 ? round( ( $t['correct'] / $total ) * 100, 1 ) : null;

			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( wp_trim_words( $q->post_title, 12 ) ),
				(int) $t['correct'],
				(int) $t['incorrect'],
				null === $pct ? esc_html__( 'manually graded', 'quizzis-for-all' ) : esc_html( $pct ) . '%'
			);

			if ( null !== $pct ) {
				self::$question_chart_labels[] = wp_trim_words( $q->post_title, 8 );
				self::$question_chart_values[] = $pct;
			}
		}
		echo '</tbody></table>';

		if ( ! empty( self::$question_chart_labels ) ) {
			echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:900px;margin-top:16px;"><canvas id="qmc-question-chart" height="' . (int) ( 40 * count( self::$question_chart_labels ) + 40 ) . '"></canvas></div>';
		}
	}
}
