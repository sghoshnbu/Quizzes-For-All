/**
 * Quizzis For All — "Quiz" block.
 * Deliberately build-free (no JSX/webpack): plain wp.element calls so the
 * plugin ships no toolchain and the file can be edited directly.
 */
( function ( blocks, element, components, blockEditor ) {
	'use strict';

	var el = element.createElement;
	var quizzes = ( window.QFA_Block_Data && window.QFA_Block_Data.quizzes ) || [];

	blocks.registerBlockType( 'quizzis-for-all/quiz', {
		title: 'Quiz',
		description: 'Embed a Quizzis For All quiz.',
		icon: 'welcome-learn-more',
		category: 'embed',
		attributes: {
			quizId: { type: 'number', default: 0 }
		},
		edit: function ( props ) {
			var selected = null;
			for ( var i = 0; i < quizzes.length; i++ ) {
				if ( parseInt( quizzes[ i ].value, 10 ) === props.attributes.quizId ) {
					selected = quizzes[ i ];
				}
			}
			return el(
				'div',
				{ style: {
					border: '1.5px dashed #2271b1',
					borderRadius: '10px',
					padding: '18px 20px',
					background: '#f6f9fc'
				} },
				el( 'p', { style: { margin: '0 0 8px', fontWeight: 600 } }, '🎓 Quizzis For All' ),
				el( components.SelectControl, {
					label: 'Quiz to display',
					value: props.attributes.quizId,
					options: quizzes,
					onChange: function ( val ) {
						props.setAttributes( { quizId: parseInt( val, 10 ) || 0 } );
					}
				} ),
				selected && props.attributes.quizId
					? el( 'p', { style: { margin: 0, color: '#667079', fontSize: '13px' } },
						'Will render "' + selected.label + '" on the frontend.' )
					: el( 'p', { style: { margin: 0, color: '#667079', fontSize: '13px' } },
						'Select a quiz above — it renders fully on the published page.' )
			);
		},
		save: function () {
			return null; // Server-rendered via render_callback.
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor );
