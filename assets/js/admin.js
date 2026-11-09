/**
 * Runs an export step by step through the REST API and shows its progress.
 */
( function () {
	'use strict';

	const config = window.rmdMtlAdmin;
	const panel = document.getElementById( 'rmd-mtl-progress' );
	const form = document.getElementById( 'rmd-mtl-form' );
	if ( ! config || ! panel || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const apiFetch = window.wp.apiFetch;
	const t = config.strings;
	let running = false;

	document.addEventListener( 'click', ( event ) => {
		const confirmLink = event.target.closest( '.rmd-mtl-confirm' );
		if ( confirmLink && ! window.confirm( t.confirmDelete ) ) {
			event.preventDefault();
		}
	} );

	if ( form ) {
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			if ( ! running ) {
				start( formData() );
			}
		} );

		// The database is named like the folder unless a name is entered.
		const folder = form.elements.folder;
		const database = form.elements.db_name;
		folder.addEventListener( 'input', () => {
			database.placeholder = folder.value.trim();
		} );
	}

	if ( config.status ) {
		render( config.status );
	}

	function formData() {
		const data = {};
		Array.from( form.elements ).forEach( ( field ) => {
			if ( ! field.name ) {
				return;
			}
			data[ field.name ] = 'checkbox' === field.type ? ( field.checked ? '1' : '0' ) : field.value;
		} );
		return data;
	}

	function start( data ) {
		running = true;
		setButtons( true );
		render( { progress: 0, message: t.starting } );
		panel.scrollIntoView( { block: 'nearest' } );
		apiFetch( { path: config.path, method: 'POST', data } )
			.then( loop )
			.catch( ( error ) => fail( error, false ) );
	}

	function loop( status ) {
		render( status );
		if ( status.done ) {
			running = false;
			window.location.reload();
			return;
		}
		step();
	}

	function step() {
		apiFetch( { path: config.path + '/step', method: 'POST' } )
			.then( loop )
			.catch( ( error ) => fail( error, true ) );
	}

	function resume() {
		running = true;
		setButtons( true );
		step();
	}

	function cancel( confirmFirst ) {
		if ( confirmFirst && ! window.confirm( t.confirmCancel ) ) {
			return;
		}
		apiFetch( { path: config.path, method: 'DELETE' } )
			.then( () => window.location.reload() )
			.catch( ( error ) => fail( error, true ) );
	}

	/**
	 * @param {Error}   error     Error of the request.
	 * @param {boolean} resumable Whether an export exists that can be continued or cancelled.
	 */
	function fail( error, resumable ) {
		running = false;
		setButtons( false );
		const box = element( 'div', 'notice notice-error inline' );
		box.append(
			element( 'p', '', t.failed + ' ' + ( error && error.message ? error.message : String( error ) ) )
		);
		if ( resumable ) {
			const actions = element( 'p' );
			actions.append(
				button( t.retry, 'button button-primary', resume ),
				' ',
				button( t.cancel, 'button', () => cancel( true ) )
			);
			box.append( actions );
		}
		panel.append( box );
	}

	function render( status ) {
		panel.hidden = false;
		panel.replaceChildren();

		const title = element( 'h2', '', status.title || '' );
		const bar = element( 'progress', 'rmd-mtl-bar' );
		bar.max = 1;
		bar.value = status.progress || 0;
		panel.append( title, bar, element( 'p', 'rmd-mtl-message', status.message || '' ) );

		if ( status.done ) {
			panel.append( element( 'p', 'rmd-mtl-finished', t.finished ) );
		}

		if ( status.downloads && status.downloads.length ) {
			const downloads = element( 'p', 'rmd-mtl-downloads' );
			status.downloads.forEach( ( file ) => {
				const link = element( 'a', 'button button-primary', ( t.download[ file.key ] || file.name ) + ' (' + file.size + ')' );
				link.href = file.url;
				downloads.append( link, ' ' );
			} );
			panel.append( downloads );
		}

		if ( status.leftovers > 0 ) {
			panel.append( element( 'p', 'rmd-mtl-hint', t.leftovers ) );
		}

		if ( status.warnings && status.warnings.length ) {
			panel.append( element( 'h3', '', t.warnings ) );
			const list = element( 'ul', 'ul-disc' );
			status.warnings.forEach( ( warning ) => list.append( element( 'li', '', warning ) ) );
			panel.append( list );
		}

		const actions = element( 'p', 'rmd-mtl-panel-actions' );
		if ( status.done ) {
			actions.append( button( t.dismiss, 'button', () => cancel( false ) ) );
		} else if ( ! running && status.phase ) {
			setButtons( true );
			actions.append(
				button( t.retry, 'button button-primary', resume ),
				' ',
				button( t.cancel, 'button', () => cancel( true ) )
			);
		}
		panel.append( actions );
	}

	function setButtons( disabled ) {
		document.querySelectorAll( '.rmd-mtl-export' ).forEach( ( el ) => {
			el.disabled = disabled;
		} );
	}

	function button( label, className, onClick ) {
		const el = element( 'button', className, label );
		el.type = 'button';
		el.addEventListener( 'click', onClick );
		return el;
	}

	function element( tag, className, text ) {
		const el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( undefined !== text ) {
			el.textContent = text;
		}
		return el;
	}
} )();
