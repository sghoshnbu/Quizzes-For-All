<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the custom database table used for storing quiz attempts/results.
 * A custom table (rather than post meta) is used because attempt volume can
 * be large and we need fast filtering/reporting by quiz, user, and date.
 *
 * This class is the sole owner of that custom table, so every query in it
 * necessarily goes straight to $wpdb rather than a WP_Query-style API, and
 * every query's FROM clause interpolates $table — which is always
 * self::table_name() (a hardcoded prefix + constant), never user input.
 * Attempt data also isn't a good fit for the object cache (it's written
 * once per submission and read in aggregate for reporting, not repeatedly
 * fetched by the same key), so object caching is intentionally skipped
 * too. The three warning types below are disabled for the whole file on
 * that basis rather than annotated on every individual query.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class QFA_DB {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . QFA_TABLE_ATTEMPTS;
	}

	public static function create_tables() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			quiz_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			guest_name VARCHAR(190) DEFAULT '',
			guest_email VARCHAR(190) DEFAULT '',
			score DECIMAL(10,2) NOT NULL DEFAULT 0,
			max_score DECIMAL(10,2) NOT NULL DEFAULT 0,
			percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
			passed TINYINT(1) NOT NULL DEFAULT 0,
			answers LONGTEXT NULL,
			question_breakdown LONGTEXT NULL,
			time_taken INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'completed',
			needs_grading TINYINT(1) NOT NULL DEFAULT 0,
			integrity_report TEXT NULL,
			certificate_token VARCHAR(64) NULL,
			started_at DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id),
			KEY user_id (user_id),
			KEY needs_grading (needs_grading),
			KEY certificate_token (certificate_token)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'qmc_db_version', QFA_VERSION );
	}

	/**
	 * Insert a completed attempt. Returns the new attempt ID.
	 */
	public static function insert_attempt( array $data ) {
		global $wpdb;

		$defaults = array(
			'quiz_id'      => 0,
			'user_id'      => 0,
			'guest_name'   => '',
			'guest_email'  => '',
			'score'        => 0,
			'max_score'    => 0,
			'percentage'   => 0,
			'passed'       => 0,
			'answers'      => '',
			'question_breakdown' => '',
			'time_taken'   => 0,
			'status'       => 'completed',
			'needs_grading' => 0,
			'integrity_report' => '',
			'certificate_token' => '',
			'started_at'   => current_time( 'mysql' ),
			'completed_at' => current_time( 'mysql' ),
		);
		$data = wp_parse_args( $data, $defaults );

		// No explicit $format array: wp_parse_args() means the final key
		// order depends on what the caller passed, so a positional format
		// list would be fragile. wpdb defaults every field to %s, which is
		// safe here — MySQL casts the numeric string literals into the
		// DECIMAL/INT columns.
		$wpdb->insert( self::table_name(), $data );

		return $wpdb->insert_id;
	}

	public static function get_attempts_for_quiz( $quiz_id, $limit = 200 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE quiz_id = %d AND status = 'completed' ORDER BY completed_at DESC LIMIT %d", $quiz_id, $limit )
		);
	}

	public static function count_attempts( $quiz_id, $user_id ) {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE quiz_id = %d AND user_id = %d AND status = 'completed'", $quiz_id, $user_id )
		);
	}

	public static function get_attempt( $attempt_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $attempt_id ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Reporting helpers (Phase 2)
	 * ------------------------------------------------------------------ */

	/** Site-wide totals for the dashboard overview. */
	public static function get_overview_stats() {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			"SELECT COUNT(*) AS total_attempts,
			        AVG(percentage) AS avg_percentage,
			        SUM(passed) AS total_passed,
			        COUNT(DISTINCT quiz_id) AS quizzes_attempted,
			        COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS distinct_users
			 FROM $table WHERE status = 'completed'"
		);
		return $row;
	}

	/** Per-quiz summary rows: attempts, average %, pass rate. */
	public static function get_per_quiz_stats() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			"SELECT quiz_id,
			        COUNT(*) AS attempts,
			        AVG(percentage) AS avg_percentage,
			        SUM(passed) AS passed_count,
			        AVG(time_taken) AS avg_time
			 FROM $table
			 WHERE status = 'completed'
			 GROUP BY quiz_id
			 ORDER BY attempts DESC"
		);
	}

	/** Attempts-per-day for the last N days, for the trend chart. */
	public static function get_attempts_by_day( $days = 30 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(completed_at) AS day, COUNT(*) AS total
				 FROM $table
				 WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY DATE(completed_at)
				 ORDER BY day ASC",
				$days
			)
		);
	}

	/** All attempts for a quiz, used to compute per-question difficulty. */
	public static function get_breakdowns_for_quiz( $quiz_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_col(
			$wpdb->prepare( "SELECT question_breakdown FROM $table WHERE quiz_id = %d AND status = 'completed' AND question_breakdown != ''", $quiz_id )
		);
	}

	/**
	 * Leaderboard: best attempt per user (or guest name) across all quizzes,
	 * or restricted to one quiz. Ranked by percentage, then speed.
	 */
	public static function get_leaderboard( $quiz_id = 0, $limit = 10 ) {
		global $wpdb;
		$table = self::table_name();
		$where = "WHERE status = 'completed'" . ( $quiz_id ? $wpdb->prepare( ' AND quiz_id = %d', $quiz_id ) : '' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, guest_name,
				        MAX(percentage) AS best_percentage,
				        MIN(time_taken) AS best_time,
				        COUNT(*) AS attempts
				 FROM $table
				 $where
				 GROUP BY user_id, guest_name
				 ORDER BY best_percentage DESC, best_time ASC
				 LIMIT %d",
				$limit
			)
		);
	}

	/** Best percentage a given user has achieved on a given quiz (0 if none). */
	public static function get_best_percentage( $quiz_id, $user_id ) {
		global $wpdb;
		$table = self::table_name();
		$val   = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(percentage) FROM $table WHERE quiz_id = %d AND user_id = %d AND status = 'completed'", $quiz_id, $user_id )
		);
		return $val ? floatval( $val ) : 0;
	}

	/** Count of distinct quizzes a user has completed (any attempt). */
	public static function count_distinct_quizzes_completed( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT quiz_id) FROM $table WHERE user_id = %d AND status = 'completed'", $user_id )
		);
	}

	/**
	 * Aggregate stats used by the gamification engine. total_points is
	 * defined as the sum of the user's *best* score on each quiz they've
	 * attempted (so retaking a quiz can only raise, never inflate, points).
	 */
	public static function get_user_stats( $user_id ) {
		global $wpdb;
		$table = self::table_name();

		$total_points = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(best) FROM (
					SELECT MAX(score) AS best FROM $table WHERE user_id = %d AND status = 'completed' GROUP BY quiz_id
				) t",
				$user_id
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT quiz_id) AS quizzes_completed,
				        COUNT(*) AS total_attempts,
				        SUM(CASE WHEN percentage = 100 THEN 1 ELSE 0 END) AS perfect_scores,
				        SUM(passed) AS passed_count
				 FROM $table WHERE user_id = %d AND status = 'completed'",
				$user_id
			)
		);

		$row->total_points = $total_points ? floatval( $total_points ) : 0;
		$row->quizzes_completed = (int) $row->quizzes_completed;
		$row->total_attempts    = (int) $row->total_attempts;
		$row->perfect_scores    = (int) $row->perfect_scores;
		$row->passed_count      = (int) $row->passed_count;

		return $row;
	}

	/* ------------------------------------------------------------------ *
	 *  Save & resume (Phase 3)
	 * ------------------------------------------------------------------ */

	/** Fetch a logged-in user's in-progress attempt for a quiz, if any. */
	public static function get_in_progress( $quiz_id, $user_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE quiz_id = %d AND user_id = %d AND status = 'in_progress' ORDER BY id DESC LIMIT 1", $quiz_id, $user_id )
		);
	}

	/**
	 * Create or update the single in-progress row for this user+quiz.
	 * Returns the row ID.
	 */
	public static function upsert_progress( $quiz_id, $user_id, $answers_json, $current_index, $time_elapsed ) {
		global $wpdb;
		$existing = self::get_in_progress( $quiz_id, $user_id );

		$data = array(
			'answers'    => $answers_json,
			'time_taken' => $time_elapsed,
			'started_at' => $existing ? $existing->started_at : current_time( 'mysql' ),
		);
		// current_index is stashed inside question_breakdown as a tiny JSON
		// marker — reusing the column avoids another schema change for what
		// is otherwise a single integer.
		$data['question_breakdown'] = wp_json_encode( array( 'current_index' => $current_index ) );

		if ( $existing ) {
			$wpdb->update( self::table_name(), $data, array( 'id' => $existing->id ) );
			return $existing->id;
		}

		$data['quiz_id'] = $quiz_id;
		$data['user_id'] = $user_id;
		$data['status']  = 'in_progress';
		$wpdb->insert( self::table_name(), $data );
		return $wpdb->insert_id;
	}

	/** Turn an in-progress row into a completed one (used when a saved attempt is finally submitted). */
	public static function complete_attempt( $attempt_id, array $data ) {
		global $wpdb;
		$data['status']       = 'completed';
		$data['completed_at'] = current_time( 'mysql' );
		// No explicit $format: wpdb defaults every field to %s, which is
		// safe here (MySQL casts numeric string literals) and avoids a
		// fragile positional format array tied to $data's key order.
		$wpdb->update( self::table_name(), $data, array( 'id' => $attempt_id ) );
	}

	public static function set_certificate_token( $attempt_id, $token ) {
		global $wpdb;
		$wpdb->update( self::table_name(), array( 'certificate_token' => $token ), array( 'id' => $attempt_id ), array( '%s' ), array( '%d' ) );
	}

	public static function get_attempt_by_certificate_token( $token ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE certificate_token = %s", $token ) );
	}

	/** A user's completed attempt history, most recent first — for the user dashboard. */
	public static function get_user_history( $user_id, $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d AND status = 'completed' ORDER BY completed_at DESC LIMIT %d", $user_id, $limit )
		);
	}

	/** All of a user's in-progress attempts — for the "resume" list on the dashboard. */
	public static function get_user_in_progress( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d AND status = 'in_progress' ORDER BY id DESC", $user_id )
		);
	}

	/** Total completed attempts across the whole site — for the admin dashboard tile. */
	public static function count_all_attempts() {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'completed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is self::table_name(), a hardcoded identifier, not user input.
	}

	/** Most recent completed attempts, newest first — dashboard widgets. */
	public static function get_recent_attempts( $limit = 5 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE status = 'completed' ORDER BY completed_at DESC LIMIT %d", $limit )
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Manual grading (Phase 5)
	 * ------------------------------------------------------------------ */

	/**
	 * Attempts that contain at least one manually-graded answer which
	 * hasn't been scored yet. Flagged via the needs_grading column so the
	 * queue is a cheap indexed lookup rather than a JSON scan.
	 */
	public static function get_pending_grading( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE status = 'completed' AND needs_grading = 1 ORDER BY completed_at ASC LIMIT %d", $limit )
		);
	}

	public static function count_pending_grading() {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'completed' AND needs_grading = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a hardcoded identifier.
	}

	/**
	 * Apply an instructor's manual scores to an attempt: recompute the
	 * total, percentage and pass flag, store per-question feedback, and
	 * clear the pending-grading flag.
	 */
	public static function apply_manual_grades( $attempt_id, $added_score, $added_max, array $manual_detail ) {
		global $wpdb;
		$attempt = self::get_attempt( $attempt_id );
		if ( ! $attempt ) {
			return false;
		}

		$score     = floatval( $attempt->score ) + floatval( $added_score );
		$max_score = floatval( $attempt->max_score ) + floatval( $added_max );
		$pct       = $max_score > 0 ? round( ( $score / $max_score ) * 100, 2 ) : 0;
		$pass_mark = floatval( get_post_meta( $attempt->quiz_id, '_qmc_pass_mark', true ) );

		// Merge the manual results into the stored per-question breakdown
		// so reports and the review screen show a complete picture.
		$breakdown = json_decode( $attempt->question_breakdown, true );
		$breakdown = is_array( $breakdown ) ? $breakdown : array();
		foreach ( $manual_detail as $qid => $detail ) {
			$breakdown[ $qid ] = array_merge(
				isset( $breakdown[ $qid ] ) && is_array( $breakdown[ $qid ] ) ? $breakdown[ $qid ] : array(),
				$detail
			);
		}

		$wpdb->update(
			self::table_name(),
			array(
				'score'              => $score,
				'max_score'          => $max_score,
				'percentage'         => $pct,
				'passed'             => $pct >= $pass_mark ? 1 : 0,
				'question_breakdown' => wp_json_encode( $breakdown ),
				'needs_grading'      => 0,
			),
			array( 'id' => $attempt_id )
		);

		return array(
			'score'      => $score,
			'max_score'  => $max_score,
			'percentage' => $pct,
			'passed'     => $pct >= $pass_mark,
		);
	}
}
