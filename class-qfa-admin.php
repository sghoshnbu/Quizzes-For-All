<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QFA_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_qmc_quiz', array( __CLASS__, 'save_quiz' ) );
		add_action( 'save_post_qmc_question', array( __CLASS__, 'save_question' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_qmc_import_aiken', array( 'QFA_Aiken', 'handle_import' ) );
		add_action( 'admin_post_qmc_export_aiken', array( 'QFA_Aiken', 'handle_export' ) );
		add_action( 'admin_post_qmc_export_results_csv', array( __CLASS__, 'export_results_csv' ) );
	}

	public static function export_results_csv() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qmc_export_results' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'quizzis-for-all' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'quizzis-for-all' ) );
		}
		$quiz_id  = isset( $_GET['quiz_id'] ) ? intval( $_GET['quiz_id'] ) : 0;
		$attempts = QFA_DB::get_attempts_for_quiz( $quiz_id, 10000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=quiz-' . $quiz_id . '-results.csv' );

		// php://output is a request-scoped output stream, not a real file on
		// disk, so WP_Filesystem (which operates on the filesystem) has no
		// equivalent here — direct fopen/fputcsv/fclose is the standard,
		// WordPress.org-accepted pattern for streaming a CSV download.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'User', 'Score', 'Max Score', 'Percentage', 'Passed', 'Time Taken (s)', 'Completed At' ) );
		foreach ( $attempts as $a ) {
			$user_label = $a->user_id ? get_the_author_meta( 'display_name', $a->user_id ) : ( $a->guest_name ?: 'Guest' );
			fputcsv( $out, array( $user_label, $a->score, $a->max_score, $a->percentage, $a->passed ? 'Yes' : 'No', $a->time_taken, $a->completed_at ) );
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function menu() {
		add_menu_page(
			__( 'Quizzis For All', 'quizzis-for-all' ),
			__( 'Quizzis', 'quizzis-for-all' ),
			'edit_posts',
			'qmc_dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-forms',
			26
		);
		add_submenu_page( 'qmc_dashboard', __( 'Dashboard', 'quizzis-for-all' ), __( 'Dashboard', 'quizzis-for-all' ), 'edit_posts', 'qmc_dashboard', array( __CLASS__, 'render_dashboard' ) );

		global $submenu;
		// Quizzes / Questions CPT menus attach themselves under 'qmc_dashboard' automatically
		// because of show_in_menu => 'qmc_dashboard' set in QFA_Post_Types.

		add_submenu_page( 'qmc_dashboard', __( 'Import Questions (Aiken)', 'quizzis-for-all' ), __( 'Import (Aiken)', 'quizzis-for-all' ), 'edit_posts', 'qmc_import', array( __CLASS__, 'render_import_page' ) );
		add_submenu_page( 'qmc_dashboard', __( 'Results & Reports', 'quizzis-for-all' ), __( 'Results', 'quizzis-for-all' ), 'edit_posts', 'qmc_results', array( __CLASS__, 'render_results_page' ) );
	}

	public static function assets( $hook ) {
		global $post_type;

		// Admin chrome styles: load on every Quiz Master screen (menu pages
		// and the two custom post types' editors).
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen id check.
		if ( 0 === strpos( $page, 'qmc_' ) || in_array( $post_type, array( 'qmc_quiz', 'qmc_question' ), true ) ) {
			wp_enqueue_style( 'qfa-admin-css', QFA_PLUGIN_URL . 'assets/css/qfa-admin.css', array(), QFA_VERSION );
			wp_enqueue_style( 'dashicons' );
		}

		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && in_array( $post_type, array( 'qmc_quiz', 'qmc_question' ), true ) ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'qfa-admin', QFA_PLUGIN_URL . 'assets/js/qfa-admin.js', array( 'jquery', 'jquery-ui-sortable' ), QFA_VERSION, true );
			wp_enqueue_style( 'qfa-admin', QFA_PLUGIN_URL . 'assets/css/qfa-frontend.css', array(), QFA_VERSION );
			wp_localize_script(
				'qfa-admin',
				'QFA_Admin_Data',
				array(
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'qmc_admin_nonce' ),
					'question_types' => QFA_Question_Types::get_types(),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 *  META BOXES
	 * ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'qmc_quiz_settings', __( 'Quiz Settings', 'quizzis-for-all' ), array( __CLASS__, 'render_quiz_settings' ), 'qmc_quiz', 'normal', 'high' );
		add_meta_box( 'qmc_quiz_questions', __( 'Questions (unlimited)', 'quizzis-for-all' ), array( __CLASS__, 'render_quiz_questions' ), 'qmc_quiz', 'normal', 'high' );
		add_meta_box( 'qmc_quiz_shortcode', __( 'Shortcode', 'quizzis-for-all' ), array( __CLASS__, 'render_quiz_shortcode' ), 'qmc_quiz', 'side', 'high' );

		add_meta_box( 'qmc_question_fields', __( 'Question', 'quizzis-for-all' ), array( __CLASS__, 'render_question_fields' ), 'qmc_question', 'normal', 'high' );
	}

	public static function render_quiz_shortcode( $post ) {
		echo '<p>' . esc_html__( 'Paste this shortcode into any page or post:', 'quizzis-for-all' ) . '</p>';
		printf( '<code>[qmc_quiz id="%d"]</code>', (int) $post->ID );
		if ( 'auto-draft' !== $post->post_status ) {
			printf(
				'<p style="margin-top:10px;"><a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=qmc_export_aiken&quiz_id=' . $post->ID ), 'qmc_export_aiken' ) ),
				esc_html__( 'Export questions to Aiken (.txt)', 'quizzis-for-all' )
			);
		}
	}

	public static function render_quiz_settings( $post ) {
		wp_nonce_field( 'qmc_save_quiz', 'qmc_quiz_nonce' );
		$timer         = get_post_meta( $post->ID, '_qmc_timer_minutes', true );
		$randomize_q   = get_post_meta( $post->ID, '_qmc_randomize_questions', true );
		$randomize_a   = get_post_meta( $post->ID, '_qmc_randomize_answers', true );
		$pass_mark     = get_post_meta( $post->ID, '_qmc_pass_mark', true );
		$max_attempts  = get_post_meta( $post->ID, '_qmc_max_attempts', true );
		$per_page      = get_post_meta( $post->ID, '_qmc_questions_per_page', true );
		$show_progress = get_post_meta( $post->ID, '_qmc_show_progress_bar', true );
		$show_correct  = get_post_meta( $post->ID, '_qmc_show_correct_answers', true );
		$show_hints    = get_post_meta( $post->ID, '_qmc_show_hints', true );
		$require_login = get_post_meta( $post->ID, '_qmc_require_login', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="qmc_timer_minutes"><?php esc_html_e( 'Timer (minutes, 0 = no limit)', 'quizzis-for-all' ); ?></label></th>
				<td><input type="number" min="0" id="qmc_timer_minutes" name="qmc_timer_minutes" value="<?php echo esc_attr( $timer ?: 0 ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_pass_mark"><?php esc_html_e( 'Pass mark (%)', 'quizzis-for-all' ); ?></label></th>
				<td><input type="number" min="0" max="100" id="qmc_pass_mark" name="qmc_pass_mark" value="<?php echo esc_attr( $pass_mark !== '' ? $pass_mark : 50 ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_max_attempts"><?php esc_html_e( 'Max attempts per user (0 = unlimited)', 'quizzis-for-all' ); ?></label></th>
				<td><input type="number" min="0" id="qmc_max_attempts" name="qmc_max_attempts" value="<?php echo esc_attr( $max_attempts ?: 0 ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_questions_per_page"><?php esc_html_e( 'Questions per page (0 = all on one page)', 'quizzis-for-all' ); ?></label></th>
				<td><input type="number" min="0" id="qmc_questions_per_page" name="qmc_questions_per_page" value="<?php echo esc_attr( $per_page !== '' ? $per_page : 0 ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_accent_color"><?php esc_html_e( 'Accent color', 'quizzis-for-all' ); ?></label></th>
				<td>
					<input type="color" id="qmc_accent_color" name="qmc_accent_color" value="<?php echo esc_attr( get_post_meta( $post->ID, '_qmc_accent_color', true ) ?: '#2271b1' ); ?>">
					<p class="description"><?php esc_html_e( 'Themes the whole quiz (buttons, progress bar, option highlights) for this quiz only.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Dynamic questions (category-selective)', 'quizzis-for-all' ); ?></th>
				<td>
					<?php
					$dyn_enabled = get_post_meta( $post->ID, '_qmc_dynamic_enabled', true );
					$dyn_cats    = get_post_meta( $post->ID, '_qmc_dynamic_categories', true );
					$dyn_cats    = is_array( $dyn_cats ) ? $dyn_cats : array();
					$dyn_count   = intval( get_post_meta( $post->ID, '_qmc_dynamic_count', true ) ) ?: 10;
					$categories  = get_terms( array( 'taxonomy' => 'qmc_question_category', 'hide_empty' => false ) );
					?>
					<label><input type="checkbox" name="qmc_dynamic_enabled" <?php checked( $dyn_enabled, 1 ); ?>> <?php esc_html_e( 'Instead of the fixed question list below, pull random questions from the bank on every attempt', 'quizzis-for-all' ); ?></label>
					<p style="margin:10px 0 4px;"><?php esc_html_e( 'From categories (none selected = whole bank):', 'quizzis-for-all' ); ?></p>
					<select name="qmc_dynamic_categories[]" multiple size="4" style="min-width:260px;">
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( $cat->term_id, array_map( 'intval', $dyn_cats ), true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p><label><?php esc_html_e( 'Number of questions per attempt:', 'quizzis-for-all' ); ?>
					<input type="number" min="1" name="qmc_dynamic_count" value="<?php echo esc_attr( $dyn_count ); ?>" style="width:80px;"></label></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_randomize_questions" <?php checked( $randomize_q, 1 ); ?>> <?php esc_html_e( 'Randomize question order', 'quizzis-for-all' ); ?></label><br>
					<label><input type="checkbox" name="qmc_randomize_answers" <?php checked( $randomize_a, 1 ); ?>> <?php esc_html_e( 'Randomize answer order', 'quizzis-for-all' ); ?></label><br>
					<label><input type="checkbox" name="qmc_show_progress_bar" <?php checked( $show_progress, 1 ); ?>> <?php esc_html_e( 'Show live progress bar', 'quizzis-for-all' ); ?></label><br>
					<label><input type="checkbox" name="qmc_show_correct_answers" <?php checked( $show_correct, 1 ); ?>> <?php esc_html_e( 'Show right/wrong messages & explanations after submit', 'quizzis-for-all' ); ?></label><br>
					<label><input type="checkbox" name="qmc_show_hints" <?php checked( $show_hints, 1 ); ?>> <?php esc_html_e( 'Allow hints', 'quizzis-for-all' ); ?></label><br>
					<label><input type="checkbox" name="qmc_require_login" <?php checked( $require_login, 1 ); ?>> <?php esc_html_e( 'Require login to take this quiz', 'quizzis-for-all' ); ?></label><br>
					<?php $resume_meta = get_post_meta( $post->ID, '_qmc_allow_resume', true ); ?>
					<label><input type="checkbox" name="qmc_allow_resume" <?php checked( '' === $resume_meta || '1' === $resume_meta || 1 === $resume_meta ); ?>> <?php esc_html_e( 'Allow save & resume for logged-in users', 'quizzis-for-all' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_quiz_questions( $post ) {
		$question_ids = get_post_meta( $post->ID, '_qmc_question_ids', true );
		$question_ids = is_array( $question_ids ) ? $question_ids : array();

		$all_questions = get_posts(
			array(
				'post_type'      => 'qmc_question',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p>
			<select id="qmc-question-picker">
				<option value=""><?php esc_html_e( '— select a question from the bank —', 'quizzis-for-all' ); ?></option>
				<?php foreach ( $all_questions as $q ) : ?>
					<option value="<?php echo (int) $q->ID; ?>"><?php echo esc_html( $q->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="qmc-add-question"><?php esc_html_e( 'Add to quiz', 'quizzis-for-all' ); ?></button>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=qmc_question' ) ); ?>" target="_blank"><?php esc_html_e( '+ New question', 'quizzis-for-all' ); ?></a>
		</p>
		<ul id="qmc-question-list" style="max-width:700px;">
			<?php foreach ( $question_ids as $qid ) :
				$q = get_post( $qid );
				if ( ! $q ) {
					continue;
				}
				$type = get_post_meta( $qid, '_qmc_type', true );
				?>
				<li class="qmc-qli" data-id="<?php echo (int) $qid; ?>" style="background:#f6f7f7;border:1px solid #ddd;padding:8px 10px;margin-bottom:5px;cursor:move;display:flex;justify-content:space-between;align-items:center;">
					<span>☰ <strong><?php echo esc_html( $q->post_title ); ?></strong> <em>(<?php echo esc_html( QFA_Question_Types::get_types()[ $type ] ?? $type ); ?>)</em></span>
					<a href="#" class="qmc-remove-question" style="color:#b32d2e;">&times; <?php esc_html_e( 'remove', 'quizzis-for-all' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><span id="qmc-question-count"><?php echo count( $question_ids ); ?></span> <?php esc_html_e( 'question(s) in this quiz. There is no upper limit.', 'quizzis-for-all' ); ?></p>
		<input type="hidden" name="qmc_question_ids" id="qmc_question_ids_input" value="<?php echo esc_attr( implode( ',', $question_ids ) ); ?>">
		<?php
	}

	public static function render_question_fields( $post ) {
		wp_nonce_field( 'qmc_save_question', 'qmc_question_nonce' );
		$data  = QFA_Question_Types::get_question_data( $post->ID );
		$types = QFA_Question_Types::get_types();
		?>
		<p>
			<label for="qmc_type"><strong><?php esc_html_e( 'Question type', 'quizzis-for-all' ); ?></strong></label><br>
			<select name="qmc_type" id="qmc_type">
				<?php foreach ( $types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $data['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<div id="qmc-type-fields">
			<div class="qmc-field-group" data-types="radio,checkbox">
				<p><strong><?php esc_html_e( 'Options', 'quizzis-for-all' ); ?></strong> (<?php esc_html_e( 'check the correct one(s)', 'quizzis-for-all' ); ?>)</p>
				<div id="qmc-options-wrap">
					<?php
					$options = ! empty( $data['options'] ) ? $data['options'] : array( array( 'id' => 'opt_0', 'text' => '' ), array( 'id' => 'opt_1', 'text' => '' ) );
					$correct_radio    = ( 'radio' === $data['type'] ) ? $data['correct'] : '';
					$correct_checkbox = ( 'checkbox' === $data['type'] && is_array( $data['correct'] ) ) ? $data['correct'] : array();
					foreach ( $options as $i => $opt ) :
						?>
						<div class="qmc-option-row" style="margin-bottom:4px;">
							<input type="radio" name="qmc_correct_radio" value="<?php echo esc_attr( $opt['id'] ); ?>" <?php checked( $correct_radio, $opt['id'] ); ?> class="qmc-mark-radio">
							<input type="checkbox" name="qmc_correct_checkbox[]" value="<?php echo esc_attr( $opt['id'] ); ?>" <?php checked( in_array( $opt['id'], $correct_checkbox, true ) ); ?> class="qmc-mark-checkbox">
							<input type="text" name="qmc_option_text[]" value="<?php echo esc_attr( $opt['text'] ); ?>" placeholder="<?php esc_attr_e( 'Option text', 'quizzis-for-all' ); ?>" style="width:340px;">
							<input type="text" name="qmc_option_trait[]" value="<?php echo esc_attr( $opt['trait'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'trait (personality quizzes)', 'quizzis-for-all' ); ?>" style="width:160px;">
							<a href="#" class="qmc-remove-option">&times;</a>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button" id="qmc-add-option"><?php esc_html_e( '+ Add option', 'quizzis-for-all' ); ?></button>
				<p class="description"><?php esc_html_e( 'The "trait" field is only used by Personality-mode quizzes — give matching options across questions the same trait key (e.g. "A") and define what each trait means under the quiz\'s Personality Outcomes box.', 'quizzis-for-all' ); ?></p>
			</div>

			<div class="qmc-field-group" data-types="true_false">
				<p><strong><?php esc_html_e( 'Correct answer', 'quizzis-for-all' ); ?></strong></p>
				<label><input type="radio" name="qmc_correct_tf" value="true" <?php checked( $data['correct'], 'true' ); ?>> <?php esc_html_e( 'True', 'quizzis-for-all' ); ?></label>
				<label style="margin-left:15px;"><input type="radio" name="qmc_correct_tf" value="false" <?php checked( $data['correct'], 'false' ); ?>> <?php esc_html_e( 'False', 'quizzis-for-all' ); ?></label>
			</div>

			<div class="qmc-field-group" data-types="short_text">
				<p><label><strong><?php esc_html_e( 'Acceptable answers (comma-separated)', 'quizzis-for-all' ); ?></strong><br>
				<input type="text" name="qmc_correct_text" style="width:400px;" value="<?php echo esc_attr( is_array( $data['correct'] ) ? implode( ', ', $data['correct'] ) : '' ); ?>"></label></p>
				<label><input type="checkbox" name="qmc_case_sensitive" <?php checked( $data['case_sensitive'], 1 ); ?>> <?php esc_html_e( 'Case sensitive', 'quizzis-for-all' ); ?></label>
			</div>

			<div class="qmc-field-group" data-types="number">
				<p><label><?php esc_html_e( 'Correct number', 'quizzis-for-all' ); ?><br>
				<input type="number" step="any" name="qmc_correct_number" value="<?php echo esc_attr( $data['correct']['value'] ?? '' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Tolerance (± allowed margin)', 'quizzis-for-all' ); ?><br>
				<input type="number" step="any" name="qmc_number_tolerance" value="<?php echo esc_attr( $data['correct']['tolerance'] ?? 0 ); ?>"></label></p>
			</div>

			<div class="qmc-field-group" data-types="date">
				<p><label><?php esc_html_e( 'Correct date', 'quizzis-for-all' ); ?><br>
				<input type="date" name="qmc_correct_date" value="<?php echo esc_attr( $data['correct'] ); ?>"></label></p>
			</div>

			<div class="qmc-field-group" data-types="fill_blanks">
				<p><label><strong><?php esc_html_e( 'Sentence with blanks — use {blank} where an answer belongs', 'quizzis-for-all' ); ?></strong><br>
				<textarea name="qmc_blanks_text" rows="3" style="width:100%;" placeholder="The capital of France is {blank}."><?php echo esc_textarea( $data['blanks_text'] ); ?></textarea></label></p>
				<p><label><strong><?php esc_html_e( 'Correct answers in order, separated by |', 'quizzis-for-all' ); ?></strong><br>
				<input type="text" name="qmc_correct_blanks" style="width:100%;" placeholder="Paris" value="<?php echo esc_attr( is_array( $data['correct'] ) ? implode( ' | ', $data['correct'] ) : '' ); ?>"></label></p>
			</div>

			<div class="qmc-field-group" data-types="matching">
				<p><strong><?php esc_html_e( 'Matching pairs', 'quizzis-for-all' ); ?></strong> (<?php esc_html_e( 'left item shown to the user; right item is the correct match', 'quizzis-for-all' ); ?>)</p>
				<div id="qmc-pairs-wrap">
					<?php
					$pairs = ! empty( $data['pairs'] ) ? $data['pairs'] : array( array( 'left' => '', 'right' => '' ), array( 'left' => '', 'right' => '' ) );
					foreach ( $pairs as $pair ) :
						?>
						<div class="qmc-pair-row" style="margin-bottom:4px;">
							<input type="text" name="qmc_pair_left[]" value="<?php echo esc_attr( $pair['left'] ); ?>" placeholder="<?php esc_attr_e( 'Left item (prompt)', 'quizzis-for-all' ); ?>" style="width:280px;">
							&rarr;
							<input type="text" name="qmc_pair_right[]" value="<?php echo esc_attr( $pair['right'] ); ?>" placeholder="<?php esc_attr_e( 'Right item (correct match)', 'quizzis-for-all' ); ?>" style="width:280px;">
							<a href="#" class="qmc-remove-pair">&times;</a>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button" id="qmc-add-pair"><?php esc_html_e( '+ Add pair', 'quizzis-for-all' ); ?></button>
			</div>

			<div class="qmc-field-group" data-types="file_upload">
				<p><label><?php esc_html_e( 'Allowed file extensions (comma-separated)', 'quizzis-for-all' ); ?><br>
				<input type="text" name="qmc_allowed_types" style="width:400px;" value="<?php echo esc_attr( $data['allowed_types'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Max file size (MB)', 'quizzis-for-all' ); ?><br>
				<input type="number" min="1" name="qmc_max_file_size_mb" value="<?php echo esc_attr( $data['max_file_size_mb'] ); ?>"></label></p>
				<p><em><?php esc_html_e( 'File uploads are not auto-scored — review and grade them manually from the Results screen.', 'quizzis-for-all' ); ?></em></p>
			</div>

			<div class="qmc-field-group" data-types="text">
				<p><em><?php esc_html_e( 'This is a free-text / essay answer. It is not auto-scored — grade it manually from the Results screen.', 'quizzis-for-all' ); ?></em></p>
			</div>

			<div class="qmc-field-group" data-types="info">
				<p><em><?php esc_html_e( 'Info banners display content (use the text below) with no answer field and are not scored — useful for section breaks or instructions.', 'quizzis-for-all' ); ?></em></p>
			</div>
		</div>

		<hr>
		<table class="form-table">
			<tr>
				<th><label for="qmc_points"><?php esc_html_e( 'Points / weight', 'quizzis-for-all' ); ?></label></th>
				<td><input type="number" step="any" min="0" id="qmc_points" name="qmc_points" value="<?php echo esc_attr( $data['points'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_hint"><?php esc_html_e( 'Hint', 'quizzis-for-all' ); ?></label></th>
				<td><input type="text" id="qmc_hint" name="qmc_hint" style="width:400px;" value="<?php echo esc_attr( $data['hint'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="qmc_explanation"><?php esc_html_e( 'Explanation (shown after answering)', 'quizzis-for-all' ); ?></label></th>
				<td><?php wp_editor( $data['explanation'], 'qmc_explanation', array( 'textarea_rows' => 4, 'media_buttons' => false ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Required', 'quizzis-for-all' ); ?></th>
				<td><label><input type="checkbox" name="qmc_required" <?php checked( $data['required'], 1 ); ?>> <?php esc_html_e( 'User must answer this question to submit the quiz', 'quizzis-for-all' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 *  SAVE HANDLERS
	 * ------------------------------------------------------------------ */

	public static function save_quiz( $post_id ) {
		if ( ! isset( $_POST['qmc_quiz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_quiz_nonce'] ) ), 'qmc_save_quiz' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_qmc_timer_minutes', max( 0, intval( $_POST['qmc_timer_minutes'] ?? 0 ) ) );
		update_post_meta( $post_id, '_qmc_pass_mark', min( 100, max( 0, floatval( $_POST['qmc_pass_mark'] ?? 50 ) ) ) );
		update_post_meta( $post_id, '_qmc_max_attempts', max( 0, intval( $_POST['qmc_max_attempts'] ?? 0 ) ) );
		update_post_meta( $post_id, '_qmc_questions_per_page', max( 0, intval( $_POST['qmc_questions_per_page'] ?? 0 ) ) );
		update_post_meta( $post_id, '_qmc_randomize_questions', ! empty( $_POST['qmc_randomize_questions'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_randomize_answers', ! empty( $_POST['qmc_randomize_answers'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_show_progress_bar', ! empty( $_POST['qmc_show_progress_bar'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_show_correct_answers', ! empty( $_POST['qmc_show_correct_answers'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_show_hints', ! empty( $_POST['qmc_show_hints'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_require_login', ! empty( $_POST['qmc_require_login'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_allow_resume', ! empty( $_POST['qmc_allow_resume'] ) ? 1 : 0 );

		$accent = isset( $_POST['qmc_accent_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['qmc_accent_color'] ) ) : '';
		update_post_meta( $post_id, '_qmc_accent_color', $accent ?: '' );

		update_post_meta( $post_id, '_qmc_dynamic_enabled', ! empty( $_POST['qmc_dynamic_enabled'] ) ? 1 : 0 );
		$dyn_cats = isset( $_POST['qmc_dynamic_categories'] ) && is_array( $_POST['qmc_dynamic_categories'] ) ? array_map( 'intval', wp_unslash( $_POST['qmc_dynamic_categories'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() only inspects the type; values are unslashed and cast to int in the same expression.
		update_post_meta( $post_id, '_qmc_dynamic_categories', $dyn_cats );
		update_post_meta( $post_id, '_qmc_dynamic_count', max( 1, intval( $_POST['qmc_dynamic_count'] ?? 10 ) ) );
		$ids_raw = isset( $_POST['qmc_question_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['qmc_question_ids'] ) ) : '';
		$ids     = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );
		update_post_meta( $post_id, '_qmc_question_ids', array_values( $ids ) );
	}

	public static function save_question( $post_id ) {
		if ( ! isset( $_POST['qmc_question_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_question_nonce'] ) ), 'qmc_save_question' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		QFA_Question_Types::save_question_data( $post_id, wp_unslash( $_POST ) );
	}

	/* ------------------------------------------------------------------ *
	 *  PAGES
	 * ------------------------------------------------------------------ */

	public static function render_dashboard() {
		$quiz_count     = wp_count_posts( 'qmc_quiz' )->publish ?? 0;
		$question_count = wp_count_posts( 'qmc_question' )->publish ?? 0;
		$attempt_count  = QFA_DB::count_all_attempts();
		$pending        = QFA_DB::count_pending_grading();
		$overview       = QFA_DB::get_overview_stats();
		$per_quiz       = QFA_DB::get_per_quiz_stats();
		$avg            = $overview && $overview->avg_percentage ? round( $overview->avg_percentage, 1 ) : 0;
		$pass_rate      = ( $overview && $overview->total_attempts > 0 ) ? round( ( $overview->total_passed / $overview->total_attempts ) * 100, 1 ) : 0;
		?>
		<div class="wrap qmc-admin">

			<div class="qmc-hero">
				<div>
					<h1><?php esc_html_e( 'Quizzis For All', 'quizzis-for-all' ); ?></h1>
					<p><?php esc_html_e( 'Unlimited quizzes and questions, eleven question types, gamification, certificates, manual grading, and GIFT / CSV / Moodle XML / Aiken interchange.', 'quizzis-for-all' ); ?></p>
				</div>
				<div class="qmc-hero-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=qmc_quiz' ) ); ?>"><?php esc_html_e( '+ New Quiz', 'quizzis-for-all' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=qmc_question' ) ); ?>"><?php esc_html_e( '+ New Question', 'quizzis-for-all' ); ?></a>
				</div>
			</div>

			<div class="qmc-stats">
				<div class="qmc-stat">
					<span class="qmc-stat-icon dashicons dashicons-forms"></span>
					<div class="qmc-stat-value"><?php echo (int) $quiz_count; ?></div>
					<div class="qmc-stat-label"><?php esc_html_e( 'Quizzes', 'quizzis-for-all' ); ?></div>
					<div class="qmc-stat-sub"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=qmc_quiz' ) ); ?>"><?php esc_html_e( 'Manage quizzes →', 'quizzis-for-all' ); ?></a></div>
				</div>
				<div class="qmc-stat">
					<span class="qmc-stat-icon dashicons dashicons-database"></span>
					<div class="qmc-stat-value"><?php echo (int) $question_count; ?></div>
					<div class="qmc-stat-label"><?php esc_html_e( 'Questions in bank', 'quizzis-for-all' ); ?></div>
					<div class="qmc-stat-sub"><a href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_formats' ) ); ?>"><?php esc_html_e( 'Import / export →', 'quizzis-for-all' ); ?></a></div>
				</div>
				<div class="qmc-stat is-ok">
					<span class="qmc-stat-icon dashicons dashicons-chart-bar"></span>
					<div class="qmc-stat-value"><?php echo (int) $attempt_count; ?></div>
					<div class="qmc-stat-label"><?php esc_html_e( 'Attempts recorded', 'quizzis-for-all' ); ?></div>
					<div class="qmc-stat-sub"><a href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_results' ) ); ?>"><?php esc_html_e( 'View results →', 'quizzis-for-all' ); ?></a></div>
				</div>
				<div class="qmc-stat <?php echo $pending > 0 ? 'is-warn' : ''; ?>">
					<span class="qmc-stat-icon dashicons dashicons-edit"></span>
					<div class="qmc-stat-value"><?php echo (int) $pending; ?></div>
					<div class="qmc-stat-label"><?php esc_html_e( 'Awaiting grading', 'quizzis-for-all' ); ?></div>
					<div class="qmc-stat-sub">
						<?php if ( $pending > 0 ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading' ) ); ?>"><strong><?php esc_html_e( 'Grade now →', 'quizzis-for-all' ); ?></strong></a>
						<?php else : ?>
							<?php esc_html_e( 'Queue is clear', 'quizzis-for-all' ); ?>
						<?php endif; ?>
					</div>
				</div>
				<div class="qmc-stat">
					<span class="qmc-stat-icon dashicons dashicons-awards"></span>
					<div class="qmc-stat-value"><?php echo esc_html( $avg ); ?>%</div>
					<div class="qmc-stat-label"><?php esc_html_e( 'Average score', 'quizzis-for-all' ); ?></div>
					<div class="qmc-stat-sub"><?php
						/* translators: %s: Pass rate percentage. */
						printf( esc_html__( 'Pass rate %s%%', 'quizzis-for-all' ), esc_html( $pass_rate ) );
					?></div>
				</div>
			</div>

			<div class="qmc-panels">
				<div class="qmc-panel">
					<h2>
						<span><?php esc_html_e( 'Recent attempts', 'quizzis-for-all' ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_results' ) ); ?>"><?php esc_html_e( 'All results', 'quizzis-for-all' ); ?></a>
					</h2>
					<?php $recent = QFA_DB::get_recent_attempts( 6 ); ?>
					<?php if ( empty( $recent ) ) : ?>
						<div class="qmc-panel-body"><p class="qmc-empty"><?php esc_html_e( 'No attempts yet. Publish a quiz and share its shortcode or block to get started.', 'quizzis-for-all' ); ?></p></div>
					<?php else : ?>
						<table>
							<thead><tr>
								<th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th>
								<th><?php esc_html_e( 'Who', 'quizzis-for-all' ); ?></th>
								<th><?php esc_html_e( 'Result', 'quizzis-for-all' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $recent as $a ) :
								$who = $a->user_id ? get_the_author_meta( 'display_name', $a->user_id ) : ( $a->guest_name ?: __( 'Guest', 'quizzis-for-all' ) );
								?>
								<tr>
									<td><?php echo esc_html( get_the_title( $a->quiz_id ) ?: '#' . $a->quiz_id ); ?></td>
									<td><?php echo esc_html( $who ); ?></td>
									<td>
										<span class="qmc-pill <?php echo $a->passed ? 'qmc-pill-ok' : 'qmc-pill-bad'; ?>"><?php echo esc_html( $a->percentage ); ?>%</span>
										<?php if ( $a->needs_grading ) : ?>
											<a class="qmc-pill qmc-pill-warn" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading&attempt=' . $a->id ) ); ?>"><?php esc_html_e( 'grade', 'quizzis-for-all' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="qmc-panel">
					<h2>
						<span><?php esc_html_e( 'Quiz performance', 'quizzis-for-all' ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_reports' ) ); ?>"><?php esc_html_e( 'Reports', 'quizzis-for-all' ); ?></a>
					</h2>
					<?php if ( empty( $per_quiz ) ) : ?>
						<div class="qmc-panel-body"><p class="qmc-empty"><?php esc_html_e( 'Performance data appears here once quizzes start being attempted.', 'quizzis-for-all' ); ?></p></div>
					<?php else : ?>
						<table>
							<thead><tr>
								<th><?php esc_html_e( 'Quiz', 'quizzis-for-all' ); ?></th>
								<th><?php esc_html_e( 'Attempts', 'quizzis-for-all' ); ?></th>
								<th><?php esc_html_e( 'Avg.', 'quizzis-for-all' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_slice( $per_quiz, 0, 6 ) as $row ) : ?>
								<tr>
									<td><?php echo esc_html( get_the_title( $row->quiz_id ) ?: '#' . $row->quiz_id ); ?></td>
									<td><?php echo (int) $row->attempts; ?></td>
									<td><?php echo esc_html( round( $row->avg_percentage, 1 ) ); ?>%</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<h2 style="margin-top:26px;"><?php esc_html_e( 'Jump to', 'quizzis-for-all' ); ?></h2>
			<div class="qmc-quicklinks">
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading' ) ); ?>"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Manual grading', 'quizzis-for-all' ); ?></a>
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_formats' ) ); ?>"><span class="dashicons dashicons-database-import"></span><?php esc_html_e( 'GIFT / CSV / Moodle XML', 'quizzis-for-all' ); ?></a>
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_import' ) ); ?>"><span class="dashicons dashicons-media-text"></span><?php esc_html_e( 'Aiken import', 'quizzis-for-all' ); ?></a>
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_reports' ) ); ?>"><span class="dashicons dashicons-chart-area"></span><?php esc_html_e( 'Reports', 'quizzis-for-all' ); ?></a>
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_gamification' ) ); ?>"><span class="dashicons dashicons-awards"></span><?php esc_html_e( 'Gamification', 'quizzis-for-all' ); ?></a>
				<a class="qmc-quicklink" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=qmc_question_category&post_type=qmc_question' ) ); ?>"><span class="dashicons dashicons-category"></span><?php esc_html_e( 'Question categories', 'quizzis-for-all' ); ?></a>
			</div>
		</div>
		<?php
	}

	public static function render_import_page() {
		QFA_Aiken::render_import_page();
	}

	public static function render_results_page() {
		$quizzes = get_posts( array( 'post_type' => 'qmc_quiz', 'posts_per_page' => -1 ) );
		// Read-only screen filter, no state change — a nonce isn't required.
		$quiz_id = isset( $_GET['quiz_id'] ) ? intval( $_GET['quiz_id'] ) : ( $quizzes[0]->ID ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Results & Reports', 'quizzis-for-all' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="qmc_results">
				<select name="quiz_id" onchange="this.form.submit()">
					<?php foreach ( $quizzes as $q ) : ?>
						<option value="<?php echo (int) $q->ID; ?>" <?php selected( $quiz_id, $q->ID ); ?>><?php echo esc_html( $q->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php if ( $quiz_id ) :
				$attempts = QFA_DB::get_attempts_for_quiz( $quiz_id );
				?>
				<table class="widefat striped" style="margin-top:15px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Score', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Percentage', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Passed', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Time taken', 'quizzis-for-all' ); ?></th>
							<th><?php esc_html_e( 'Completed', 'quizzis-for-all' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $attempts ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No attempts yet.', 'quizzis-for-all' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $attempts as $a ) :
							$user_label = $a->user_id ? get_the_author_meta( 'display_name', $a->user_id ) : ( $a->guest_name ?: __( 'Guest', 'quizzis-for-all' ) );
							?>
							<tr>
								<td><?php echo esc_html( $user_label ); ?></td>
								<td><?php echo esc_html( $a->score . ' / ' . $a->max_score ); ?></td>
								<td><?php echo esc_html( $a->percentage ); ?>%</td>
								<td><?php echo $a->passed ? '✅' : '❌'; ?></td>
								<td><?php echo esc_html( gmdate( 'i:s', (int) $a->time_taken ) ); ?></td>
								<td><?php echo esc_html( $a->completed_at ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=qmc_grading&attempt=' . $a->id ) ); ?>">
										<?php echo $a->needs_grading ? esc_html__( 'Grade', 'quizzis-for-all' ) : esc_html__( 'Review', 'quizzis-for-all' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=qmc_export_results_csv&quiz_id=' . $quiz_id ), 'qmc_export_results' ) ); ?>"><?php esc_html_e( 'Export as CSV', 'quizzis-for-all' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
