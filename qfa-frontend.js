( function () {
	'use strict';

	function forEach( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	function buildAnswerFieldValue( q, formData ) {
		var qid = q.getAttribute( 'data-qid' );
		var type = q.getAttribute( 'data-type' );
		var field = q.querySelector( '.qmc-q-field' );

		if ( type === 'checkbox' ) {
			var vals = [];
			forEach( field.querySelectorAll( 'input[type="checkbox"]:checked' ), function ( el ) { vals.push( el.value ); } );
			return vals;
		}
		if ( type === 'radio' || type === 'true_false' ) {
			var checked = field.querySelector( 'input[type="radio"]:checked' );
			return checked ? checked.value : '';
		}
		if ( type === 'fill_blanks' ) {
			var vals2 = [];
			forEach( field.querySelectorAll( '.qmc-blank' ), function ( el ) { vals2.push( el.value ); } );
			return vals2;
		}
		if ( type === 'matching' ) {
			var map = {};
			forEach( field.querySelectorAll( 'select' ), function ( el ) {
				var m = el.name.match( /\[([^\]]+)\]$/ );
				if ( m ) {
					map[ m[ 1 ] ] = el.value;
				}
			} );
			return map;
		}
		if ( type === 'file_upload' ) {
			if ( formData ) {
				var fileInput = field.querySelector( 'input[type="file"]' );
				if ( fileInput && fileInput.files && fileInput.files[ 0 ] ) {
					formData.append( 'qmc_file_' + qid, fileInput.files[ 0 ] );
					return '__FILE_UPLOADED__';
				}
			}
			return '';
		}
		var input = field.querySelector( 'input, textarea' );
		return input ? input.value : '';
	}

	function initQuiz( root ) {
		var quizId      = root.getAttribute( 'data-quiz-id' );
		var timer       = parseInt( root.getAttribute( 'data-timer' ), 10 ) || 0;
		var perPage     = parseInt( root.getAttribute( 'data-per-page' ), 10 ) || 0;
		var allowResume = root.getAttribute( 'data-allow-resume' ) === '1';
		var quizMode    = root.getAttribute( 'data-quiz-mode' ) || 'standard';
		var intro       = root.querySelector( '.qmc-intro' );
		var form        = root.querySelector( '.qmc-form' );
		var startBtn    = root.querySelector( '.qmc-start-btn' );
		var resumeBtn   = root.querySelector( '.qmc-resume-btn' );
		var prevBtn     = root.querySelector( '.qmc-prev-btn' );
		var nextBtn     = root.querySelector( '.qmc-next-btn' );
		var submitBtn   = root.querySelector( '.qmc-submit-btn' );
		var resultsEl   = root.querySelector( '.qmc-results' );
		var timerEl     = root.querySelector( '.qmc-timer-display' );
		var progressEl  = root.querySelector( '.qmc-progress-inner' );
		var questions   = Array.prototype.slice.call( root.querySelectorAll( '.qmc-question' ) );
		var copyProtect = root.getAttribute( 'data-copy-protect' ) === '1';
		var tabWarn     = root.getAttribute( 'data-tab-warn' ) === '1';
		var tabLimit    = parseInt( root.getAttribute( 'data-tab-limit' ), 10 ) || 0;
		var logEvents   = root.getAttribute( 'data-log-events' ) === '1';
		var integrity   = { tab_switches: 0, paste_blocked: 0, auto_submitted: 0 };
		var quizActive  = false;
		var savedTag    = root.querySelector( '.qmc-saved-progress' );
		var saved       = savedTag ? JSON.parse( savedTag.textContent || '{}' ) : null;

		var totalPages = perPage > 0
			? Math.max.apply( null, questions.map( function ( q ) { return parseInt( q.getAttribute( 'data-page' ), 10 ); } ) ) + 1
			: 1;
		var currentPage      = 0;
		var startTime         = null;
		var timerInterval     = null;
		var autosaveInterval  = null;
		var secondsLeft       = timer * 60;

		function showPage( page ) {
			currentPage = page;
			questions.forEach( function ( q ) {
				var qPage = perPage > 0 ? parseInt( q.getAttribute( 'data-page' ), 10 ) : 0;
				q.style.display = ( perPage === 0 || qPage === page ) ? '' : 'none';
			} );
			prevBtn.style.display = page > 0 ? '' : 'none';
			nextBtn.style.display = ( perPage > 0 && page < totalPages - 1 ) ? '' : 'none';
			submitBtn.style.display = ( perPage === 0 || page === totalPages - 1 ) ? '' : 'none';
			updateProgress();
		}

		function updateProgress() {
			if ( ! progressEl ) {
				return;
			}
			var answered = questions.filter( isAnswered ).length;
			var scoreable = questions.filter( function ( q ) { return q.getAttribute( 'data-type' ) !== 'info'; } ).length;
			var pct = scoreable > 0 ? Math.round( ( answered / scoreable ) * 100 ) : 0;
			progressEl.style.width = pct + '%';
		}

		function isAnswered( q ) {
			var inputs = q.querySelectorAll( 'input, textarea, select' );
			for ( var i = 0; i < inputs.length; i++ ) {
				var el = inputs[ i ];
				if ( ( el.type === 'radio' || el.type === 'checkbox' ) && el.checked ) {
					return true;
				}
				if ( el.tagName === 'SELECT' && el.value !== '' ) {
					return true;
				}
				if ( ( el.type === 'text' || el.type === 'number' || el.type === 'date' || el.tagName === 'TEXTAREA' ) && el.value.trim() !== '' ) {
					return true;
				}
			}
			return false;
		}

		function validateRequired( page ) {
			var pageQuestions = questions.filter( function ( q ) {
				var qPage = perPage > 0 ? parseInt( q.getAttribute( 'data-page' ), 10 ) : 0;
				return perPage === 0 ? true : qPage === page;
			} );
			for ( var i = 0; i < pageQuestions.length; i++ ) {
				var q = pageQuestions[ i ];
				if ( q.getAttribute( 'data-required' ) === '1' && ! isAnswered( q ) ) {
					q.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					q.style.borderColor = '#d63638';
					return false;
				}
				q.style.borderColor = '';
			}
			return true;
		}

		function startTimer() {
			if ( ! timer ) {
				return;
			}
			updateTimerDisplay();
			timerInterval = setInterval( function () {
				secondsLeft--;
				updateTimerDisplay();
				if ( secondsLeft <= 30 ) {
					root.querySelector( '.qmc-timer' ).classList.add( 'qmc-timer-warning' );
				}
				if ( secondsLeft <= 0 ) {
					clearInterval( timerInterval );
					submitQuiz();
				}
			}, 1000 );
		}

		function updateTimerDisplay() {
			var m = Math.floor( secondsLeft / 60 );
			var s = secondsLeft % 60;
			timerEl.textContent = m + ':' + ( s < 10 ? '0' : '' ) + s;
		}

		function collectAnswers( formData ) {
			var answers = {};
			questions.forEach( function ( q ) {
				if ( q.getAttribute( 'data-type' ) === 'info' ) {
					return;
				}
				answers[ q.getAttribute( 'data-qid' ) ] = buildAnswerFieldValue( q, formData );
			} );
			return answers;
		}

		function applyAnswers( answers ) {
			questions.forEach( function ( q ) {
				var qid = q.getAttribute( 'data-qid' );
				var type = q.getAttribute( 'data-type' );
				if ( ! answers || ! ( qid in answers ) ) {
					return;
				}
				var val = answers[ qid ];
				var field = q.querySelector( '.qmc-q-field' );

				if ( type === 'checkbox' && Array.isArray( val ) ) {
					forEach( field.querySelectorAll( 'input[type="checkbox"]' ), function ( el ) {
						el.checked = val.indexOf( el.value ) !== -1;
					} );
				} else if ( type === 'radio' || type === 'true_false' ) {
					forEach( field.querySelectorAll( 'input[type="radio"]' ), function ( el ) {
						el.checked = el.value === val;
					} );
				} else if ( type === 'fill_blanks' && Array.isArray( val ) ) {
					forEach( field.querySelectorAll( '.qmc-blank' ), function ( el, i ) { el.value = val[ i ] || ''; } );
				} else if ( type === 'matching' && val && typeof val === 'object' ) {
					forEach( field.querySelectorAll( 'select' ), function ( el ) {
						var m = el.name.match( /\[([^\]]+)\]$/ );
						if ( m && val[ m[ 1 ] ] ) {
							el.value = val[ m[ 1 ] ];
						}
					} );
				} else if ( type === 'file_upload' ) {
					// Can't restore a file input's value programmatically — skipped.
				} else {
					var input = field.querySelector( 'input, textarea' );
					if ( input ) {
						input.value = val || '';
					}
				}
			} );
			updateProgress();
		}

		function saveProgress() {
			if ( ! allowResume || ! window.QFA_Data ) {
				return;
			}
			var answers = collectAnswers( null );
			var elapsed = startTime ? Math.round( ( Date.now() - startTime ) / 1000 ) : 0;
			var body = new URLSearchParams();
			body.append( 'action', 'qmc_save_progress' );
			body.append( 'nonce', window.QFA_Data.nonce );
			body.append( 'quiz_id', quizId );
			body.append( 'current_index', currentPage );
			body.append( 'time_elapsed', elapsed );
			body.append( 'answers', JSON.stringify( answers ) );
			fetch( window.QFA_Data.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).catch( function () { /* best-effort — a failed autosave shouldn't interrupt the quiz */ } );
		}

		function submitQuiz() {
			quizActive = false;
			if ( timerInterval ) {
				clearInterval( timerInterval );
			}
			if ( autosaveInterval ) {
				clearInterval( autosaveInterval );
			}
			var timeTaken = Math.round( ( Date.now() - startTime ) / 1000 );

			submitBtn.disabled = true;
			submitBtn.textContent = 'Submitting…';

			var formData = new FormData();
			var answers = collectAnswers( formData ); // may append file(s) to formData
			formData.append( 'action', 'qmc_submit_quiz' );
			formData.append( 'nonce', window.QFA_Data.nonce );
			formData.append( 'quiz_id', quizId );
			formData.append( 'time_taken', timeTaken );
			formData.append( 'answers', JSON.stringify( answers ) );
			if ( logEvents || tabWarn ) {
				formData.append( 'integrity', JSON.stringify( integrity ) );
			}
			var hp = form.querySelector( 'input[name="qmc_hp"]' );
			if ( hp ) {
				formData.append( 'qmc_hp', hp.value );
			}

			fetch( window.QFA_Data.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						renderResults( res.data );
					} else {
						alert( ( res.data && res.data.message ) || 'Something went wrong submitting the quiz.' );
						submitBtn.disabled = false;
						submitBtn.textContent = 'Submit Quiz';
					}
				} )
				.catch( function () {
					alert( 'Network error submitting the quiz. Please try again.' );
					submitBtn.disabled = false;
					submitBtn.textContent = 'Submit Quiz';
				} );
		}

		function beginQuiz( fromResume ) {
			intro.style.display = 'none';
			form.style.display = '';
			var elapsedMs = 0;
			if ( fromResume && saved ) {
				applyAnswers( saved.answers );
				elapsedMs = ( saved.time_elapsed || 0 ) * 1000;
				secondsLeft = Math.max( 0, timer * 60 - Math.round( elapsedMs / 1000 ) );
			}
			startTime = Date.now() - elapsedMs;
			quizActive = true;
			// Marks this browser as the session that owns the attempt, so
			// single-session mode can tell "my own tab" from "another device".
			document.cookie = 'qmc_session_' + quizId + '=1; path=/; SameSite=Lax';
			showPage( fromResume && saved ? Math.min( saved.current_index || 0, totalPages - 1 ) : 0 );
			startTimer();
			initIntegrity();
			if ( allowResume ) {
				autosaveInterval = setInterval( saveProgress, 20000 );
				window.addEventListener( 'beforeunload', saveProgress );
			}
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! validateRequired( currentPage ) ) {
				return;
			}
			submitQuiz();
		} );

		nextBtn.addEventListener( 'click', function () {
			if ( ! validateRequired( currentPage ) ) {
				return;
			}
			showPage( Math.min( currentPage + 1, totalPages - 1 ) );
			saveProgress();
		} );
		prevBtn.addEventListener( 'click', function () {
			showPage( Math.max( currentPage - 1, 0 ) );
		} );

		startBtn.addEventListener( 'click', function () { beginQuiz( false ); } );
		if ( resumeBtn ) {
			resumeBtn.addEventListener( 'click', function () { beginQuiz( true ); } );
		}

		// Hint toggles.
		forEach( root.querySelectorAll( '.qmc-hint-toggle a' ), function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var span = a.parentNode.querySelector( '.qmc-hint-text' );
				span.style.display = span.style.display === 'none' ? 'block' : 'none';
			} );
		} );

		// Live progress bar + keyboard navigation (number keys select radio options).
		root.addEventListener( 'change', updateProgress );
		root.addEventListener( 'keydown', function ( e ) {
			if ( form.style.display === 'none' ) {
				return;
			}
			if ( e.key >= '1' && e.key <= '9' ) {
				var visibleQ = questions.find( function ( q ) {
					return q.style.display !== 'none' && q.contains( document.activeElement );
				} );
				if ( visibleQ ) {
					var opts = visibleQ.querySelectorAll( 'input[type="radio"], input[type="checkbox"]' );
					var idx = parseInt( e.key, 10 ) - 1;
					if ( opts[ idx ] ) {
						opts[ idx ].checked = true;
						opts[ idx ].focus();
						updateProgress();
					}
				}
			} else if ( e.key === 'Enter' && document.activeElement.tagName !== 'TEXTAREA' ) {
				if ( nextBtn.style.display !== 'none' ) {
					e.preventDefault();
					nextBtn.click();
				}
			}
		} );


		/**
		 * Integrity guards. All of these are browser-side and therefore
		 * advisory — they raise friction and record signals, they do not
		 * make cheating impossible. The server-side checks (honeypot,
		 * minimum duration, single session) are the enforcing ones.
		 */
		function initIntegrity() {
			if ( copyProtect ) {
				// Only question text is protected; answer fields must stay
				// fully usable (including paste into essay answers being
				// counted rather than silently broken).
				forEach( root.querySelectorAll( '.qmc-q-title, .qmc-option, .qmc-info-banner, .qmc-matching-table' ), function ( elm ) {
					elm.style.userSelect = 'none';
					elm.style.webkitUserSelect = 'none';
					elm.addEventListener( 'contextmenu', function ( e ) { e.preventDefault(); } );
					elm.addEventListener( 'copy', function ( e ) { e.preventDefault(); } );
				} );
				forEach( root.querySelectorAll( '.qmc-textarea, .qmc-input' ), function ( elm ) {
					elm.addEventListener( 'paste', function () {
						integrity.paste_blocked++;
					} );
				} );
			}

			if ( tabWarn ) {
				document.addEventListener( 'visibilitychange', function () {
					if ( ! quizActive || ! document.hidden ) {
						return;
					}
					integrity.tab_switches++;

					if ( tabLimit > 0 && integrity.tab_switches >= tabLimit ) {
						integrity.auto_submitted = 1;
						showIntegrityNotice(
							'You left the quiz too many times. Your attempt has been submitted automatically.'
						);
						submitQuiz();
						return;
					}

					var left = tabLimit > 0 ? ( tabLimit - integrity.tab_switches ) : 0;
					showIntegrityNotice(
						'Please stay on this tab while taking the quiz. Switches recorded: ' + integrity.tab_switches +
						( tabLimit > 0 ? ' — the quiz auto-submits after ' + left + ' more.' : '' )
					);
				} );
			}
		}

		function showIntegrityNotice( message ) {
			var box = root.querySelector( '.qmc-integrity-notice' );
			if ( ! box ) {
				box = document.createElement( 'div' );
				box.className = 'qmc-integrity-notice';
				box.setAttribute( 'role', 'alert' );
				form.insertBefore( box, form.firstChild );
			}
			box.textContent = message;
			box.style.display = 'block';
		}

		function summaryHtml( data ) {
			var html = '';
			if ( data.mode === 'personality' && data.personality ) {
				html += '<h3>' + data.personality.label + '</h3>';
				if ( data.personality.description ) {
					html += '<p>' + data.personality.description + '</p>';
				}
			} else {
				html += '<h3>' + data.percentage + '%</h3>';
				html += '<p>' + data.score + ' / ' + data.max_score + ' points — ' +
					( data.passed ? 'Passed ✅' : 'Not passed (pass mark ' + data.pass_mark + '%) ❌' ) + '</p>';
			}
			if ( data.needs_manual ) {
				html += '<p><em>This quiz includes essay or file-upload answers that need to be graded manually — your final score may change.</em></p>';
			}
			if ( data.new_badges && data.new_badges.length ) {
				html += '<div class="qmc-badges-earned"><strong>🏅 New badge' + ( data.new_badges.length > 1 ? 's' : '' ) + ' earned:</strong> ';
				html += data.new_badges.map( function ( b ) { return b.label; } ).join( ', ' );
				html += '</div>';
			}
			if ( data.certificate_url ) {
				html += '<p><a class="qmc-btn" href="' + data.certificate_url + '" target="_blank">🎓 View / print your certificate</a></p>';
			}
			if ( data.next_quiz ) {
				html += '<p><a class="qmc-btn qmc-btn-secondary" href="' + data.next_quiz.url + '">Next: ' + data.next_quiz.title + ' →</a></p>';
			}
			return html;
		}

		function renderResults( data ) {
			form.style.display = 'none';
			resultsEl.style.display = '';
			resultsEl.classList.add( data.passed ? 'passed' : 'failed' );

			var html = summaryHtml( data );

			if ( data.show_correct && data.per_question ) {
				html += '<div style="text-align:left;margin-top:20px;">';
				questions.forEach( function ( q ) {
					var qid = q.getAttribute( 'data-qid' );
					var info = data.per_question[ qid ];
					if ( ! info ) {
						return;
					}
					var fb = q.querySelector( '.qmc-feedback' );
					fb.style.display = 'block';
					if ( info.is_correct === true ) {
						fb.className = 'qmc-feedback correct';
						fb.innerHTML = '✔ Correct' + ( info.explanation ? '<span class="qmc-explanation">' + info.explanation + '</span>' : '' );
					} else if ( info.is_correct === false ) {
						fb.className = 'qmc-feedback incorrect';
						fb.innerHTML = '✘ Incorrect' + ( info.explanation ? '<span class="qmc-explanation">' + info.explanation + '</span>' : '' );
					}
				} );
				html += '</div>';
				resultsEl.innerHTML = html;
				form.style.display = '';
				form.querySelectorAll( 'input, textarea, button, select' ).forEach( function ( el ) { el.disabled = true; } );
				resultsEl.style.display = 'none';
				var summaryNode = document.createElement( 'div' );
				summaryNode.className = 'qmc-results ' + ( data.passed ? 'passed' : 'failed' );
				summaryNode.innerHTML = summaryHtml( data );
				root.insertBefore( summaryNode, form );
			} else {
				resultsEl.innerHTML = html;
			}
		}
	}

	function initPopups() {
		forEach( document.querySelectorAll( '.qmc-popup-trigger' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				var overlay = document.getElementById( btn.getAttribute( 'data-target' ) + '-overlay' );
				if ( overlay ) {
					overlay.style.display = 'flex';
				}
			} );
		} );
		forEach( document.querySelectorAll( '.qmc-popup-close' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				var overlay = btn.closest( '.qmc-popup-overlay' );
				if ( overlay ) {
					overlay.style.display = 'none';
				}
			} );
		} );
		forEach( document.querySelectorAll( '.qmc-popup-overlay' ), function ( overlay ) {
			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					overlay.style.display = 'none';
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		forEach( document.querySelectorAll( '.qmc-quiz' ), initQuiz );
		initPopups();
	} );
} )();
