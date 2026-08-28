<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the two core content types:
 *  - qmc_quiz     : a quiz (settings + an ordered list of question IDs)
 *  - qmc_question : a single reusable question ("question bank" item)
 * plus a taxonomy to categorize questions so quizzes can be built
 * category-selectively later.
 */
class QFA_Post_Types {

	protected static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		register_post_type(
			'qmc_quiz',
			array(
				'label'        => __( 'Quizzes', 'quizzis-for-all' ),
				'labels'       => array(
					'name'          => __( 'Quizzes', 'quizzis-for-all' ),
					'singular_name' => __( 'Quiz', 'quizzis-for-all' ),
					'add_new_item'  => __( 'Add New Quiz', 'quizzis-for-all' ),
					'edit_item'     => __( 'Edit Quiz', 'quizzis-for-all' ),
				),
				'public'       => true,
				'show_in_menu' => 'qmc_dashboard',
				'menu_icon'    => 'dashicons-forms',
				'supports'     => array( 'title', 'editor' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'quiz' ),
				'show_in_rest' => false,
			)
		);

		register_post_type(
			'qmc_question',
			array(
				'label'        => __( 'Questions', 'quizzis-for-all' ),
				'labels'       => array(
					'name'          => __( 'Questions', 'quizzis-for-all' ),
					'singular_name' => __( 'Question', 'quizzis-for-all' ),
					'add_new_item'  => __( 'Add New Question', 'quizzis-for-all' ),
					'edit_item'     => __( 'Edit Question', 'quizzis-for-all' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'qmc_dashboard',
				'supports'     => array( 'title' ),
				'has_archive'  => false,
				'show_in_rest' => false,
			)
		);

		register_taxonomy(
			'qmc_question_category',
			'qmc_question',
			array(
				'label'        => __( 'Question Categories', 'quizzis-for-all' ),
				'hierarchical' => true,
				'show_ui'      => true,
				'show_admin_column' => true,
				'show_in_menu' => 'qmc_dashboard',
				'rewrite'      => false,
			)
		);
	}
}
