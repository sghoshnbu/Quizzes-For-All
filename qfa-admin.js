jQuery( function ( $ ) {

	/* ---------------- Quiz editor: question list ---------------- */

	var $list  = $( '#qmc-question-list' );
	var $input = $( '#qmc_question_ids_input' );

	function syncQuestionIds() {
		var ids = [];
		$list.find( '.qmc-qli' ).each( function () {
			ids.push( $( this ).data( 'id' ) );
		} );
		$input.val( ids.join( ',' ) );
		$( '#qmc-question-count' ).text( ids.length );
	}

	if ( $list.length ) {
		$list.sortable( {
			update: syncQuestionIds
		} );

		$( '#qmc-add-question' ).on( 'click', function () {
			var $sel = $( '#qmc-question-picker' );
			var id   = $sel.val();
			var text = $sel.find( 'option:selected' ).text();
			if ( ! id ) {
				return;
			}
			if ( $list.find( '.qmc-qli[data-id="' + id + '"]' ).length ) {
				return; // already added
			}
			var $li = $(
				'<li class="qmc-qli" data-id="' + id + '" style="background:#f6f7f7;border:1px solid #ddd;padding:8px 10px;margin-bottom:5px;cursor:move;display:flex;justify-content:space-between;align-items:center;">' +
				'<span>\u2630 <strong>' + text + '</strong></span>' +
				'<a href="#" class="qmc-remove-question" style="color:#b32d2e;">&times; remove</a>' +
				'</li>'
			);
			$list.append( $li );
			syncQuestionIds();
		} );

		$list.on( 'click', '.qmc-remove-question', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.qmc-qli' ).remove();
			syncQuestionIds();
		} );
	}

	/* ---------------- Question editor: type-specific fields ---------------- */

	function updateTypeFields() {
		var type = $( '#qmc_type' ).val();
		$( '.qmc-field-group' ).each( function () {
			var types = ( $( this ).data( 'types' ) + '' ).split( ',' );
			$( this ).toggle( types.indexOf( type ) !== -1 );
		} );
	}
	$( '#qmc_type' ).on( 'change', updateTypeFields );
	updateTypeFields();

	var optIndex = $( '#qmc-options-wrap .qmc-option-row' ).length;
	$( '#qmc-add-option' ).on( 'click', function () {
		var $row = $(
			'<div class="qmc-option-row" style="margin-bottom:4px;">' +
			'<input type="radio" name="qmc_correct_radio" class="qmc-mark-radio">' +
			'<input type="checkbox" name="qmc_correct_checkbox[]" class="qmc-mark-checkbox">' +
			'<input type="text" name="qmc_option_text[]" placeholder="Option text" style="width:340px;">' +
			'<input type="text" name="qmc_option_trait[]" placeholder="trait (personality quizzes)" style="width:160px;">' +
			'<a href="#" class="qmc-remove-option">&times;</a>' +
			'</div>'
		);
		$( '#qmc-options-wrap' ).append( $row );
		optIndex++;
	} );

	$( '#qmc-options-wrap' ).on( 'click', '.qmc-remove-option', function ( e ) {
		e.preventDefault();
		if ( $( '#qmc-options-wrap .qmc-option-row' ).length > 2 ) {
			$( this ).closest( '.qmc-option-row' ).remove();
		} else {
			alert( 'A question needs at least two options.' );
		}
	} );

	/* ---------------- Question editor: matching pairs ---------------- */

	$( '#qmc-add-pair' ).on( 'click', function () {
		var $row = $(
			'<div class="qmc-pair-row" style="margin-bottom:4px;">' +
			'<input type="text" name="qmc_pair_left[]" placeholder="Left item (prompt)" style="width:280px;">' +
			' \u2192 ' +
			'<input type="text" name="qmc_pair_right[]" placeholder="Right item (correct match)" style="width:280px;">' +
			'<a href="#" class="qmc-remove-pair">&times;</a>' +
			'</div>'
		);
		$( '#qmc-pairs-wrap' ).append( $row );
	} );

	$( '#qmc-pairs-wrap' ).on( 'click', '.qmc-remove-pair', function ( e ) {
		e.preventDefault();
		if ( $( '#qmc-pairs-wrap .qmc-pair-row' ).length > 2 ) {
			$( this ).closest( '.qmc-pair-row' ).remove();
		} else {
			alert( 'A matching question needs at least two pairs.' );
		}
	} );

	// Correct-answer checkboxes need the option's own text value to be sent
	// on save; the value is set from the radio's sibling text field at
	// submit time since option IDs are assigned server-side by array order.
	$( '#post' ).on( 'submit', function () {
		$( '#qmc-options-wrap .qmc-option-row' ).each( function ( i ) {
			$( this ).find( '.qmc-mark-radio' ).val( 'opt_' + i );
			$( this ).find( '.qmc-mark-checkbox' ).val( 'opt_' + i );
		} );
	} );
} );
