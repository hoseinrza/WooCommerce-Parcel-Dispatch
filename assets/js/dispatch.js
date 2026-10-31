/**
 * Barcode scanner workflow for the parcel dispatch screen.
 *
 * All business rules live on the server; this file only orchestrates the
 * scanner UI, calls the REST API, and renders the response.
 */
( function () {
	'use strict';

	var data = window.wcpdData;

	if ( ! data || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var apiFetch = window.wp.apiFetch;
	var i18n = data.i18n || {};

	apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );

	var form = document.getElementById( 'wcpd-scan-form' );
	var input = document.getElementById( 'wcpd-barcode' );
	var lookupButton = document.getElementById( 'wcpd-lookup' );
	var messageBox = document.getElementById( 'wcpd-message' );
	var orderCard = document.getElementById( 'wcpd-order' );
	var confirmButton = document.getElementById( 'wcpd-confirm' );
	var cancelButton = document.getElementById( 'wcpd-cancel' );
	var openOrderLink = document.getElementById( 'wcpd-open-order' );
	var filtersForm = document.getElementById( 'wcpd-history-filters' );
	var historyBody = document.getElementById( 'wcpd-history-body' );
	var prevButton = document.getElementById( 'wcpd-history-prev' );
	var nextButton = document.getElementById( 'wcpd-history-next' );
	var pageIndicator = document.getElementById( 'wcpd-history-page' );

	if ( ! form || ! input || ! orderCard || ! historyBody ) {
		return;
	}

	var lookupLabel = lookupButton ? lookupButton.textContent : '';
	var busy = false;
	var historyBusy = false;
	var currentOrder = null;
	var historyPage = 1;
	var historyPages = 1;
	var filters = {};

	function text( id, value ) {
		var element = document.getElementById( id );

		if ( element ) {
			element.textContent = value;
		}
	}

	function format( template, first, second ) {
		return String( template )
			.replace( '%1$s', String( first ) )
			.replace( '%2$s', String( second ) );
	}

	function setMessage( value, type ) {
		if ( ! messageBox ) {
			return;
		}

		messageBox.textContent = value || '';
		messageBox.className = 'wcpd-message' + ( type ? ' wcpd-message--' + type : '' );
		messageBox.hidden = ! value;
	}

	function setBusy( state, label ) {
		busy = state;
		form.setAttribute( 'aria-busy', String( state ) );

		if ( lookupButton ) {
			lookupButton.disabled = state;
			lookupButton.textContent = state && label ? label : lookupLabel;
		}

		if ( confirmButton ) {
			confirmButton.disabled = state || ! currentOrder;
		}
	}

	function focusScanner() {
		input.focus();
		input.select();
	}

	function resetScanner() {
		form.reset();
		focusScanner();
	}

	function hideOrder() {
		currentOrder = null;

		if ( confirmButton ) {
			confirmButton.disabled = true;
		}

		if ( openOrderLink ) {
			openOrderLink.hidden = true;
			openOrderLink.removeAttribute( 'href' );
		}

		orderCard.hidden = true;
	}

	function setOpenOrderLink( order ) {
		if ( ! openOrderLink ) {
			return;
		}

		var url = order && typeof order.edit_url === 'string' ? order.edit_url : '';

		// Never navigate to a scheme other than http(s).
		if ( ! /^https?:\/\//i.test( url ) ) {
			openOrderLink.hidden = true;
			openOrderLink.removeAttribute( 'href' );
			return;
		}

		openOrderLink.href = url;
		openOrderLink.hidden = false;
	}

	function renderOrder( order ) {
		currentOrder = order;

		text( 'wcpd-order-number', '#' + order.number );
		text( 'wcpd-order-status', order.status_label || order.status || '—' );
		text( 'wcpd-order-customer', order.customer && order.customer.name ? order.customer.name : '—' );
		text( 'wcpd-order-phone', order.customer && order.customer.phone ? order.customer.phone : '—' );
		text( 'wcpd-order-address', order.shipping_address || '—' );
		text( 'wcpd-order-barcode', order.barcode || '—' );
		text( 'wcpd-order-dispatch-status', i18n.notDispatched || '' );

		var list = document.getElementById( 'wcpd-order-items' );

		if ( list ) {
			list.textContent = '';

			var items = Array.isArray( order.items ) ? order.items : [];

			if ( items.length === 0 ) {
				var empty = document.createElement( 'li' );
				empty.textContent = i18n.noItems || '';
				list.appendChild( empty );
			}

			items.forEach( function ( item ) {
				var row = document.createElement( 'li' );
				row.textContent = item.name + ' × ' + item.quantity;
				list.appendChild( row );
			} );
		}

		orderCard.hidden = false;
		setOpenOrderLink( order );

		if ( confirmButton ) {
			confirmButton.disabled = false;
		}
	}

	function errorText( error ) {
		var output = error && error.message ? error.message : i18n.requestFailed || '';
		var details = error && error.data ? error.data : null;

		if ( error && error.code === 'wcpd_already_dispatched' && details && details.dispatch ) {
			var dispatch = details.dispatch;
			var who = dispatch.user_id ? '#' + dispatch.user_id : '—';
			output += '\n' + format( i18n.previousDispatch || '', who, dispatch.dispatched_at || '—' );
		} else if ( error && error.code === 'wcpd_multiple_orders' && details && Array.isArray( details.order_ids ) ) {
			output += '\n' + details.order_ids.map( function ( id ) { return '#' + id; } ).join( '، ' );
		}

		return output;
	}

	function handleError( error ) {
		setMessage( errorText( error ), 'error' );
	}

	function submitScan() {
		var barcode = String( input.value || '' ).trim();

		// While a request is in flight, keep the new characters for the next scan.
		if ( '' === barcode || busy ) {
			return;
		}

		input.value = '';
		lookupBarcode( barcode );
	}

	async function lookupBarcode( raw ) {
		var barcode = String( raw || '' ).trim();

		if ( '' === barcode || busy ) {
			return;
		}

		hideOrder();
		setBusy( true, i18n.lookingUpHint || '' );
		setMessage( i18n.lookingUp || '', 'info' );

		try {
			var response = await apiFetch( {
				path: '/wcpd/v1/barcode/lookup',
				method: 'POST',
				data: { barcode: barcode },
			} );

			if ( ! response || ! response.order ) {
				throw { code: 'wcpd_order_not_found', message: i18n.notFound || '' };
			}

			renderOrder( response.order );
			setMessage( i18n.orderFound || '', 'success' );
		} catch ( error ) {
			hideOrder();
			handleError( error );
		} finally {
			setBusy( false );
			focusScanner();
		}
	}

	async function confirmDispatch() {
		if ( ! currentOrder || busy ) {
			return;
		}

		setBusy( true, i18n.dispatching || '' );
		setMessage( i18n.dispatching || '', 'info' );

		try {
			await apiFetch( {
				path: '/wcpd/v1/dispatch',
				method: 'POST',
				data: {
					barcode: currentOrder.barcode,
					order_id: currentOrder.id,
				},
			} );

			hideOrder();
			resetScanner();
			setMessage( i18n.dispatched || '', 'success' );
			loadHistory( 1 );
		} catch ( error ) {
			handleError( error );
			focusScanner();

			if ( error && error.code === 'wcpd_already_dispatched' ) {
				loadHistory( historyPage );
			}
		} finally {
			setBusy( false );
			focusScanner();
		}
	}

	function appendCell( row, value ) {
		var cell = document.createElement( 'td' );
		cell.textContent = value;
		row.appendChild( cell );
	}

	function renderHistory( response ) {
		var items = response && Array.isArray( response.items ) ? response.items : [];

		historyPages = response && response.pages ? Math.max( 1, response.pages ) : 1;
		historyPage = response && response.page ? response.page : historyPage;
		historyBody.textContent = '';

		if ( items.length === 0 ) {
			var emptyRow = document.createElement( 'tr' );
			var emptyCell = document.createElement( 'td' );
			emptyCell.colSpan = 4;
			emptyCell.textContent = i18n.emptyHistory || '';
			emptyRow.appendChild( emptyCell );
			historyBody.appendChild( emptyRow );
		}

		items.forEach( function ( item ) {
			var row = document.createElement( 'tr' );
			var operator = item.operator && item.operator.name ? item.operator.name : '#' + item.user_id;

			appendCell( row, item.barcode );
			appendCell( row, '#' + item.order_id );
			appendCell( row, operator );
			appendCell( row, item.dispatched_at );
			historyBody.appendChild( row );
		} );

		updatePagination();
	}

	function updatePagination() {
		if ( pageIndicator ) {
			pageIndicator.textContent = format( i18n.pageOf || '', historyPage, historyPages );
		}

		if ( prevButton ) {
			prevButton.disabled = historyBusy || historyPage <= 1;
		}

		if ( nextButton ) {
			nextButton.disabled = historyBusy || historyPage >= historyPages;
		}
	}

	async function loadHistory( page ) {
		if ( historyBusy ) {
			return;
		}

		historyBusy = true;
		historyPage = Math.max( 1, page || 1 );
		historyBody.textContent = '';
		updatePagination();

		var params = new URLSearchParams( {
			page: String( historyPage ),
			per_page: String( data.pageSize || 20 ),
		} );

		Object.keys( filters ).forEach( function ( key ) {
			if ( filters[ key ] ) {
				params.set( key, filters[ key ] );
			}
		} );

		try {
			var response = await apiFetch( {
				path: '/wcpd/v1/dispatches?' + params.toString(),
			} );

			renderHistory( response );
		} catch ( error ) {
			historyBody.textContent = '';

			var failedRow = document.createElement( 'tr' );
			var failedCell = document.createElement( 'td' );
			failedCell.colSpan = 4;
			failedCell.textContent = error && error.message ? error.message : i18n.loadFailed || '';
			failedRow.appendChild( failedCell );
			historyBody.appendChild( failedRow );

			setMessage( i18n.loadFailed || '', 'error' );
		} finally {
			historyBusy = false;
			updatePagination();
		}
	}

	input.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			submitScan();
			return;
		}

		// HID scanners can be configured to send Tab instead of Enter. Only consume
		// Tab when the input holds a scan, so empty-input Tab keeps normal navigation.
		if ( event.key === 'Tab' && String( input.value || '' ).trim() !== '' ) {
			event.preventDefault();
			submitScan();
		}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		submitScan();
	} );

	if ( confirmButton ) {
		confirmButton.addEventListener( 'click', confirmDispatch );
	}

	if ( cancelButton ) {
		cancelButton.addEventListener( 'click', function () {
			hideOrder();
			resetScanner();
			setMessage( i18n.cancelled || '', 'info' );
		} );
	}

	if ( filtersForm ) {
		filtersForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			filters = {};

			[ 'barcode', 'order_id', 'operator' ].forEach( function ( name ) {
				var field = filtersForm.querySelector( '[name="' + name + '"]' );

				if ( field && String( field.value ).trim() !== '' ) {
					filters[ name ] = String( field.value ).trim();
				}
			} );

			loadHistory( 1 );
		} );

		filtersForm.addEventListener( 'reset', function () {
			filters = {};
			window.setTimeout( function () {
				loadHistory( 1 );
			}, 0 );
		} );
	}

	if ( prevButton ) {
		prevButton.addEventListener( 'click', function () {
			loadHistory( historyPage - 1 );
		} );
	}

	if ( nextButton ) {
		nextButton.addEventListener( 'click', function () {
			loadHistory( historyPage + 1 );
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && ! orderCard.hidden ) {
			event.preventDefault();
			hideOrder();
			resetScanner();
			setMessage( i18n.cancelled || '', 'info' );
		}
	} );

	focusScanner();
	hideOrder();
	loadHistory( 1 );
}() );
