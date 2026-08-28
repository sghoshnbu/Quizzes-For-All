<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native WordPress integrations — everything here uses only core WP
 * surfaces (block editor, widgets, admin dashboard, REST API), so no
 * external service, account, or API key is ever needed:
 *
 *  - A "Quiz" Gutenberg block (server-rendered through the same shortcode
 *    pipeline, so every quiz feature works identically in the block).
 *  - A classic widget for sidebar/footer areas on non-block themes.
 *  - An at-a-glance admin dashboard widget with totals and the latest
 *    attempts.
 *  - Read-only REST endpoints (qmc/v1) exposing the public quiz list and
 *    leaderboards for headless/JS consumers — the same data the public
 *    shortcodes already display, nothing more.
 */
class QFA_Integrations {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Gutenberg block
	 * ------------------------------------------------------------------ */

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'qfa-block',
			QFA_PLUGIN_URL . 'assets/js/qfa-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data' ),
			QFA_VERSION,
			true
		);

		$quizzes = get_posts(
			array(
				'post_type'      => 'qmc_quiz',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$options = array( array( 'label' => __( '— select a quiz —', 'quizzis-for-all' ), 'value' => 0 ) );
		foreach ( $quizzes as $q ) {
			$options[] = array( 'label' => $q->post_title, 'value' => $q->ID );
		}
		wp_localize_script( 'qfa-block', 'QFA_Block_Data', array( 'quizzes' => $options ) );

		$block_args = array(
			'editor_script'   => 'qfa-block',
			'render_callback' => array( __CLASS__, 'render_block' ),
			'attributes'      => array(
				'quizId' => array(
					'type'    => 'number',
					'default' => 0,
				),
			),
		);

		register_block_type( 'quizzis-for-all/quiz', $block_args );

		// The block was named quiz-master-core/quiz before the rename, and
		// posts that already contain it store that name in their markup.
		// Keeping it registered (server-render only, no editor script)
		// stops those posts showing "block not found".
		$legacy_args = $block_args;
		unset( $legacy_args['editor_script'] );
		register_block_type( 'quiz-master-core/quiz', $legacy_args );
	}

	public static function render_block( $attributes ) {
		$quiz_id = intval( $attributes['quizId'] ?? 0 );
		if ( ! $quiz_id ) {
			return '';
		}
		// Reuse the shortcode pipeline so blocks and shortcodes can never
		// drift apart in behavior.
		return QFA_Frontend::shortcode( array( 'id' => $quiz_id ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Classic widget
	 * ------------------------------------------------------------------ */

	public static function register_widget() {
		register_widget( 'QFA_Quiz_Widget' );
	}

	/* ------------------------------------------------------------------ *
	 *  Admin dashboard widget
	 * ------------------------------------------------------------------ */

	public static function dashboard_widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'qmc_dashboard_widget', __( 'Quizzis For All — at a glance', 'quizzis-for-all' ), array( __CLASS__, 'render_dashboard_widget' ) );
	}

	public static function render_dashboard_widget() {
		$quiz_count     = wp_count_posts( 'qmc_quiz' )->publish ?? 0;
		$question_count = wp_count_posts( 'qmc_question' )->publish ?? 0;
		$attempt_count  = QFA_DB::count_all_attempts();
		?>
		<p>
			<strong><?php echo (int) $quiz_count; ?></strong> <?php esc_html_e( 'quizzes', 'quizzis-for-all' ); ?> ·
			<strong><?php echo (int) $question_count; ?></strong> <?php esc_html_e( 'questions in bank', 'quizzis-for-all' ); ?> ·
			<strong><?php echo (int) $attempt_count; ?></strong> <?php esc_html_e( 'attempts', 'quizzis-for-all' ); ?>
		</p>
		<?php
		$recent = QFA_DB::get_recent_attempts( 5 );
		if ( ! empty( $recent ) ) :
			?>
			<table class="widefat striped" style="margin-top:6px;">
				<thead><tr><th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th><th><?php esc_html_e( 'User', 'quizzis-for-all' ); ?></th><th><?php esc_html_e( 'Score', 'quizzis-for-all' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $recent as $a ) :
						$user_label = $a->user_id ? get_the_author_meta( 'display_name', $a->user_id ) : ( $a->guest_name ?: __( 'Guest', 'quizzis-for-all' ) );
						?>
						<tr>
							<td><?php echo esc_html( get_the_title( $a->quiz_id ) ?: '#' . $a->quiz_id ); ?></td>
							<td><?php echo esc_html( $user_label ); ?></td>
							<td><?php echo esc_html( $a->percentage ); ?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p style="margin-top:8px;">
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_reports' ) ); ?>"><?php esc_html_e( 'Full reports', 'quizzis-for-all' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=qmc_quiz' ) ); ?>"><?php esc_html_e( '+ New quiz', 'quizzis-for-all' ); ?></a>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 *  REST API (read-only, public data only)
	 * ------------------------------------------------------------------ */

	public static function rest_routes() {
		register_rest_route(
			'qmc/v1',
			'/quizzes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_quizzes' ),
				'permission_callback' => '__return_true', // Public list of published quizzes — same visibility as the site frontend.
			)
		);
		register_rest_route(
			'qmc/v1',
			'/leaderboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_leaderboard' ),
				'permission_callback' => '__return_true', // Same data the public [qmc_leaderboard] shortcode already renders.
				'args'                => array(
					'quiz_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
					'limit'   => array( 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public static function rest_quizzes() {
		$quizzes = get_posts(
			array(
				'post_type'      => 'qmc_quiz',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $quizzes as $q ) {
			$ids   = get_post_meta( $q->ID, '_qmc_question_ids', true );
			$out[] = array(
				'id'             => $q->ID,
				'title'          => $q->post_title,
				'url'            => get_permalink( $q ),
				'question_count' => get_post_meta( $q->ID, '_qmc_dynamic_enabled', true )
					? intval( get_post_meta( $q->ID, '_qmc_dynamic_count', true ) )
					: ( is_array( $ids ) ? count( $ids ) : 0 ),
				'mode'           => QFA_Quiz_Types::get_mode( $q->ID ),
				'timer_minutes'  => intval( get_post_meta( $q->ID, '_qmc_timer_minutes', true ) ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function rest_leaderboard( $request ) {
		$rows = QFA_DB::get_leaderboard( $request['quiz_id'], min( 100, max( 1, $request['limit'] ) ) );
		$out  = array();
		$rank = 1;
		foreach ( $rows as $row ) {
			$out[] = array(
				'rank'            => $rank++,
				'name'            => $row->user_id ? get_the_author_meta( 'display_name', $row->user_id ) : ( $row->guest_name ?: __( 'Guest', 'quizzis-for-all' ) ),
				'best_percentage' => floatval( $row->best_percentage ),
				'attempts'        => intval( $row->attempts ),
			);
		}
		return rest_ensure_response( $out );
	}
}

/**
 * Classic sidebar/footer widget: pick a quiz, it renders through the same
 * shortcode pipeline as everywhere else.
 */
class QFA_Quiz_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'qmc_quiz_widget',
			__( 'Quizzis For All — Quiz', 'quizzis-for-all' ),
			array( 'description' => __( 'Embed a quiz in any widget area.', 'quizzis-for-all' ) )
		);
	}

	public function widget( $args, $instance ) {
		$quiz_id = intval( $instance['quiz_id'] ?? 0 );
		if ( ! $quiz_id ) {
			return;
		}
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-supplied widget markup, standard widget API usage.
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-supplied widget markup; title escaped.
		}
		echo QFA_Frontend::shortcode( array( 'id' => $quiz_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is escaped at build time within QFA_Frontend.
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-supplied widget markup, standard widget API usage.
	}

	public function form( $instance ) {
		$title   = $instance['title'] ?? '';
		$quiz_id = intval( $instance['quiz_id'] ?? 0 );
		$quizzes = get_posts( array( 'post_type' => 'qmc_quiz', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'quizzis-for-all' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'quiz_id' ) ); ?>"><?php esc_html_e( 'Quiz:', 'quizzis-for-all' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'quiz_id' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'quiz_id' ) ); ?>">
				<option value="0"><?php esc_html_e( '— select —', 'quizzis-for-all' ); ?></option>
				<?php foreach ( $quizzes as $q ) : ?>
					<option value="<?php echo (int) $q->ID; ?>" <?php selected( $quiz_id, $q->ID ); ?>><?php echo esc_html( $q->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title'   => sanitize_text_field( $new_instance['title'] ?? '' ),
			'quiz_id' => intval( $new_instance['quiz_id'] ?? 0 ),
		);
	}
}
