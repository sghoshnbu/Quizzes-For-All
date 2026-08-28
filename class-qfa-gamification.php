<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points, badges, and a leaderboard shortcode.
 *
 * Design choice: rather than incrementing a running point total on every
 * attempt (easy to get out of sync via retries/edits), a user's point
 * total and badge eligibility are always recomputed from the attempts
 * table itself — "best score per quiz, summed" — so they can never drift
 * from the underlying data. The only thing persisted is which badges have
 * already been *awarded*, so we can tell the test-taker which ones are
 * new right after they submit.
 */
class QFA_Gamification {

	public static function init() {
		add_shortcode( 'qmc_leaderboard', array( __CLASS__, 'leaderboard_shortcode' ) );
		add_shortcode( 'qmc_user_points', array( __CLASS__, 'user_points_shortcode' ) );
		// New-branding aliases; originals stay registered for existing content.
		add_shortcode( 'qfa_leaderboard', array( __CLASS__, 'leaderboard_shortcode' ) );
		add_shortcode( 'qfa_user_points', array( __CLASS__, 'user_points_shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
	}

	public static function is_enabled() {
		return '0' !== get_option( 'qmc_gamification_enabled', '1' );
	}

	/**
	 * The fixed badge rule set for Phase 2. Each rule's `check` receives
	 * the user's aggregate stats (see QFA_DB::get_user_stats) and returns
	 * true/false. Extend this array to add more badges later.
	 */
	public static function get_badge_rules() {
		return array(
			'first_quiz'    => array(
				'label'       => __( 'First Steps', 'quizzis-for-all' ),
				'description' => __( 'Complete your first quiz.', 'quizzis-for-all' ),
				'check'       => function ( $s ) { return $s->quizzes_completed >= 1; },
			),
			'perfect_score' => array(
				'label'       => __( 'Perfectionist', 'quizzis-for-all' ),
				'description' => __( 'Score 100% on any quiz.', 'quizzis-for-all' ),
				'check'       => function ( $s ) { return $s->perfect_scores >= 1; },
			),
			'five_passed'   => array(
				'label'       => __( 'On a Roll', 'quizzis-for-all' ),
				'description' => __( 'Pass 5 quizzes.', 'quizzis-for-all' ),
				'check'       => function ( $s ) { return $s->passed_count >= 5; },
			),
			'quiz_master'   => array(
				'label'       => __( 'Quiz Master', 'quizzis-for-all' ),
				'description' => __( 'Complete 10 different quizzes.', 'quizzis-for-all' ),
				'check'       => function ( $s ) { return $s->quizzes_completed >= 10; },
			),
			'dedicated'     => array(
				'label'       => __( 'Dedicated Learner', 'quizzis-for-all' ),
				'description' => __( 'Make 25 quiz attempts.', 'quizzis-for-all' ),
				'check'       => function ( $s ) { return $s->total_attempts >= 25; },
			),
		);
	}

	/**
	 * Called right after an attempt is stored. Recomputes the user's
	 * badge eligibility and returns any badges newly earned by this
	 * attempt (for on-screen congratulations); silently does nothing if
	 * gamification is disabled or the attempt was made as a guest.
	 */
	public static function process_attempt( $user_id, $quiz_id, $score, $percentage, $passed ) {
		if ( ! self::is_enabled() || ! $user_id ) {
			return array();
		}

		$stats  = QFA_DB::get_user_stats( $user_id );
		$rules  = self::get_badge_rules();
		$earned = array();
		foreach ( $rules as $slug => $rule ) {
			if ( call_user_func( $rule['check'], $stats ) ) {
				$earned[] = $slug;
			}
		}

		$previous = get_user_meta( $user_id, '_qmc_badges', true );
		$previous = is_array( $previous ) ? $previous : array();
		$new      = array_diff( $earned, $previous );

		if ( ! empty( $new ) ) {
			update_user_meta( $user_id, '_qmc_badges', $earned );
		}

		$new_badge_objects = array();
		foreach ( $new as $slug ) {
			$new_badge_objects[] = array(
				'slug'  => $slug,
				'label' => $rules[ $slug ]['label'],
			);
		}
		return $new_badge_objects;
	}

	public static function get_user_badges( $user_id ) {
		$earned = get_user_meta( $user_id, '_qmc_badges', true );
		$earned = is_array( $earned ) ? $earned : array();
		$rules  = self::get_badge_rules();
		$out    = array();
		foreach ( $earned as $slug ) {
			if ( isset( $rules[ $slug ] ) ) {
				$out[] = $rules[ $slug ];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 *  Shortcodes
	 * ------------------------------------------------------------------ */

	public static function leaderboard_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'quiz_id' => 0,
				'limit'   => 10,
			),
			$atts
		);

		$rows = QFA_DB::get_leaderboard( intval( $atts['quiz_id'] ), intval( $atts['limit'] ) );
		if ( empty( $rows ) ) {
			return '<p>' . esc_html__( 'No attempts recorded yet.', 'quizzis-for-all' ) . '</p>';
		}

		ob_start();
		?>
		<table class="qmc-leaderboard">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Name', 'quizzis-for-all' ); ?></th>
					<th><?php esc_html_e( 'Best Score', 'quizzis-for-all' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'quizzis-for-all' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$rank = 1;
				foreach ( $rows as $row ) :
					$name = $row->user_id ? get_the_author_meta( 'display_name', $row->user_id ) : ( $row->guest_name ?: __( 'Guest', 'quizzis-for-all' ) );
					?>
					<tr>
						<td><?php echo (int) $rank; ?></td>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $row->best_percentage ); ?>%</td>
						<td><?php echo (int) $row->attempts; ?></td>
					</tr>
					<?php
					$rank++;
				endforeach;
				?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	public static function user_points_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your quiz points and badges.', 'quizzis-for-all' ) . '</p>';
		}
		$user_id = get_current_user_id();
		$stats   = QFA_DB::get_user_stats( $user_id );
		$badges  = self::get_user_badges( $user_id );

		ob_start();
		?>
		<div class="qmc-user-points">
			<p><strong><?php echo esc_html( $stats->total_points ); ?></strong> <?php esc_html_e( 'points', 'quizzis-for-all' ); ?>
			&middot; <?php echo (int) $stats->quizzes_completed; ?> <?php esc_html_e( 'quizzes completed', 'quizzis-for-all' ); ?></p>
			<?php if ( ! empty( $badges ) ) : ?>
				<div class="qmc-badges">
					<?php foreach ( $badges as $b ) : ?>
						<span class="qmc-badge" title="<?php echo esc_attr( $b['description'] ); ?>">🏅 <?php echo esc_html( $b['label'] ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 *  Admin
	 * ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_submenu_page( 'qmc_dashboard', __( 'Gamification', 'quizzis-for-all' ), __( 'Gamification', 'quizzis-for-all' ), 'manage_options', 'qmc_gamification', array( __CLASS__, 'render_admin_page' ) );
	}

	public static function render_admin_page() {
		if ( isset( $_POST['qmc_gamification_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_gamification_nonce'] ) ), 'qmc_gamification_save' ) ) {
			update_option( 'qmc_gamification_enabled', empty( $_POST['qmc_gamification_enabled'] ) ? '0' : '1' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'quizzis-for-all' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gamification', 'quizzis-for-all' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'qmc_gamification_save', 'qmc_gamification_nonce' ); ?>
				<p>
					<label>
						<input type="checkbox" name="qmc_gamification_enabled" <?php checked( self::is_enabled() ); ?>>
						<?php esc_html_e( 'Enable points & badges for logged-in users', 'quizzis-for-all' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Save', 'quizzis-for-all' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Badges', 'quizzis-for-all' ); ?></h2>
			<table class="widefat striped" style="max-width:700px;">
				<thead><tr><th><?php esc_html_e( 'Badge', 'quizzis-for-all' ); ?></th><th><?php esc_html_e( 'How to earn it', 'quizzis-for-all' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( self::get_badge_rules() as $rule ) : ?>
						<tr><td>🏅 <?php echo esc_html( $rule['label'] ); ?></td><td><?php echo esc_html( $rule['description'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Shortcodes', 'quizzis-for-all' ); ?></h2>
			<p><code>[qmc_leaderboard]</code> — <?php esc_html_e( 'site-wide leaderboard. Add quiz_id="123" to scope to one quiz, limit="20" to change row count.', 'quizzis-for-all' ); ?></p>
			<p><code>[qmc_user_points]</code> — <?php esc_html_e( "shows the logged-in user's points and earned badges.", 'quizzis-for-all' ); ?></p>
		</div>
		<?php
	}
}
