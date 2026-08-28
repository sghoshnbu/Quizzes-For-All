<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrity ("anti-cheating") controls, all opt-in per quiz.
 *
 * An honest framing matters here: none of these are unbeatable. Anything
 * enforced in the browser can be bypassed by a determined user with
 * devtools, and a second device defeats every one of them at once. What
 * they do well is raise friction for casual copying, record signals an
 * invigilator can act on, and make expectations explicit to the class.
 * They are not a substitute for proctoring a high-stakes exam.
 *
 * The server-side pieces (submission window, honeypot, one-attempt-in-
 * flight) are the ones that actually can't be clicked away.
 */
class QFA_Integrity {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_qmc_quiz', array( __CLASS__, 'save_settings' ) );
	}

	public static function get_settings( $quiz_id ) {
		return array(
			'copy_protect'   => (bool) get_post_meta( $quiz_id, '_qmc_copy_protect', true ),
			'tab_warn'       => (bool) get_post_meta( $quiz_id, '_qmc_tab_warn', true ),
			'tab_limit'      => intval( get_post_meta( $quiz_id, '_qmc_tab_limit', true ) ),
			'honeypot'       => (bool) get_post_meta( $quiz_id, '_qmc_honeypot', true ),
			'min_seconds'    => intval( get_post_meta( $quiz_id, '_qmc_min_seconds', true ) ),
			'single_session' => (bool) get_post_meta( $quiz_id, '_qmc_single_session', true ),
			'log_events'     => (bool) get_post_meta( $quiz_id, '_qmc_log_events', true ),
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Admin UI
	 * ------------------------------------------------------------------ */

	public static function meta_box() {
		add_meta_box( 'qmc_integrity', __( 'Exam Integrity', 'quizzis-for-all' ), array( __CLASS__, 'render_settings' ), 'qmc_quiz', 'normal', 'default' );
	}

	public static function render_settings( $post ) {
		wp_nonce_field( 'qmc_save_integrity', 'qmc_integrity_nonce' );
		$s = self::get_settings( $post->ID );
		?>
		<p class="description" style="margin-bottom:14px;">
			<?php esc_html_e( 'These raise the effort required to cheat casually and record signals you can review — they are not proctoring, and a second device defeats all of the browser-side ones. Use them alongside, not instead of, invigilation for high-stakes exams.', 'quizzis-for-all' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Copy protection', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_copy_protect" <?php checked( $s['copy_protect'] ); ?>> <?php esc_html_e( 'Discourage text selection, right-click, and copy on question text', 'quizzis-for-all' ); ?></label>
					<p class="description"><?php esc_html_e( 'Answer fields stay fully usable. Screen-readers and keyboard navigation are unaffected.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tab switching', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_tab_warn" <?php checked( $s['tab_warn'] ); ?>> <?php esc_html_e( 'Warn when the test-taker leaves the quiz tab, and count each switch', 'quizzis-for-all' ); ?></label>
					<p style="margin-top:8px;">
						<label><?php esc_html_e( 'Auto-submit after this many switches (0 = never auto-submit):', 'quizzis-for-all' ); ?>
						<input type="number" min="0" name="qmc_tab_limit" value="<?php echo esc_attr( $s['tab_limit'] ); ?>" style="width:80px;"></label>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Submission checks', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_honeypot" <?php checked( $s['honeypot'] ); ?>> <?php esc_html_e( 'Honeypot field — silently rejects bot submissions', 'quizzis-for-all' ); ?></label><br>
					<label style="display:block;margin-top:8px;"><?php esc_html_e( 'Reject submissions faster than (seconds, 0 = no minimum):', 'quizzis-for-all' ); ?>
					<input type="number" min="0" name="qmc_min_seconds" value="<?php echo esc_attr( $s['min_seconds'] ); ?>" style="width:80px;"></label>
					<p class="description"><?php esc_html_e( 'Both are enforced on the server, so they cannot be disabled from the browser.', 'quizzis-for-all' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Sessions & logging', 'quizzis-for-all' ); ?></th>
				<td>
					<label><input type="checkbox" name="qmc_single_session" <?php checked( $s['single_session'] ); ?>> <?php esc_html_e( 'One attempt in flight at a time per user (blocks parallel tabs/devices)', 'quizzis-for-all' ); ?></label><br>
					<label style="display:block;margin-top:8px;"><input type="checkbox" name="qmc_log_events" <?php checked( $s['log_events'] ); ?>> <?php esc_html_e( 'Record integrity events on the attempt (tab switches, paste attempts) for review', 'quizzis-for-all' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_settings( $post_id ) {
		if ( ! isset( $_POST['qmc_integrity_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qmc_integrity_nonce'] ) ), 'qmc_save_integrity' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_qmc_copy_protect', ! empty( $_POST['qmc_copy_protect'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_tab_warn', ! empty( $_POST['qmc_tab_warn'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_tab_limit', max( 0, intval( $_POST['qmc_tab_limit'] ?? 0 ) ) );
		update_post_meta( $post_id, '_qmc_honeypot', ! empty( $_POST['qmc_honeypot'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_min_seconds', max( 0, intval( $_POST['qmc_min_seconds'] ?? 0 ) ) );
		update_post_meta( $post_id, '_qmc_single_session', ! empty( $_POST['qmc_single_session'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_qmc_log_events', ! empty( $_POST['qmc_log_events'] ) ? 1 : 0 );
	}

	/* ------------------------------------------------------------------ *
	 *  Server-side enforcement
	 * ------------------------------------------------------------------ */

	/**
	 * Checks that genuinely can't be bypassed client-side. Returns an
	 * error string to reject the submission with, or '' to allow it.
	 */
	public static function validate_submission( $quiz_id, $time_taken, array $post ) {
		$s = self::get_settings( $quiz_id );

		// Honeypot: a field hidden from humans; only automation fills it.
		if ( $s['honeypot'] && ! empty( $post['qmc_hp'] ) ) {
			return __( 'Submission rejected.', 'quizzis-for-all' );
		}

		// Implausibly fast completion.
		if ( $s['min_seconds'] > 0 && $time_taken < $s['min_seconds'] ) {
			return sprintf(
				/* translators: %d: minimum number of seconds */
				__( 'That was submitted too quickly — please spend at least %d seconds on the quiz.', 'quizzis-for-all' ),
				$s['min_seconds']
			);
		}

		return '';
	}

	/**
	 * Normalize the integrity report the browser sends alongside a
	 * submission. Client-reported and therefore advisory only — it is
	 * stored for an instructor to interpret, never used to auto-fail.
	 */
	public static function sanitize_report( $raw ) {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array(
			'tab_switches'  => max( 0, intval( $decoded['tab_switches'] ?? 0 ) ),
			'paste_blocked' => max( 0, intval( $decoded['paste_blocked'] ?? 0 ) ),
			'auto_submitted' => ! empty( $decoded['auto_submitted'] ) ? 1 : 0,
		);
	}

	/** Does this report contain anything worth an instructor's attention? */
	public static function is_flagged( array $report ) {
		return ! empty( $report['tab_switches'] ) || ! empty( $report['paste_blocked'] ) || ! empty( $report['auto_submitted'] );
	}

	/** One-line human summary for admin screens. */
	public static function summarize( array $report ) {
		$bits = array();
		if ( ! empty( $report['tab_switches'] ) ) {
			$bits[] = sprintf(
				/* translators: %d: number of times the test-taker left the tab */
				_n( '%d tab switch', '%d tab switches', $report['tab_switches'], 'quizzis-for-all' ),
				$report['tab_switches']
			);
		}
		if ( ! empty( $report['paste_blocked'] ) ) {
			$bits[] = sprintf(
				/* translators: %d: number of blocked paste attempts */
				_n( '%d paste attempt', '%d paste attempts', $report['paste_blocked'], 'quizzis-for-all' ),
				$report['paste_blocked']
			);
		}
		if ( ! empty( $report['auto_submitted'] ) ) {
			$bits[] = __( 'auto-submitted on tab limit', 'quizzis-for-all' );
		}
		return implode( ', ', $bits );
	}
}
