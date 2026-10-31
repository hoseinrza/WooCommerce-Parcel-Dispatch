import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const script = readFileSync( new URL( '../../assets/js/dispatch.js', import.meta.url ), 'utf8' );

const MARKUP = `<!doctype html>
<html><body>
	<form id="wcpd-scan-form" autocomplete="off">
		<input type="text" id="wcpd-barcode" name="barcode" inputmode="numeric" maxlength="100" required />
		<button type="submit" id="wcpd-lookup">جست‌وجو</button>
	</form>
	<div id="wcpd-message" role="status" aria-live="polite" hidden></div>
	<section id="wcpd-order" hidden>
		<dl>
			<dd id="wcpd-order-number">—</dd>
			<dd id="wcpd-order-status">—</dd>
			<dd id="wcpd-order-customer">—</dd>
			<dd id="wcpd-order-phone">—</dd>
			<dd id="wcpd-order-address">—</dd>
			<dd id="wcpd-order-barcode">—</dd>
			<dd id="wcpd-order-dispatch-status">—</dd>
			<ul id="wcpd-order-items"></ul>
		</dl>
		<button type="button" id="wcpd-confirm">تأیید خروج مرسوله</button>
		<button type="button" id="wcpd-cancel">لغو</button>
		<a id="wcpd-open-order" target="_blank" rel="noopener noreferrer" hidden>باز کردن سفارش</a>
	</section>
	<form id="wcpd-history-filters">
		<input type="text" name="barcode" />
		<input type="text" name="order_id" />
		<input type="text" name="operator" />
	</form>
	<table><tbody id="wcpd-history-body"></tbody></table>
	<button type="button" id="wcpd-history-prev">قبلی</button>
	<span id="wcpd-history-page"></span>
	<button type="button" id="wcpd-history-next">بعدی</button>
</body></html>`;

const I18N = {
	lookingUp: 'در حال جست‌وجو…',
	lookingUpHint: 'در حال جست‌وجو…',
	dispatching: 'در حال ثبت…',
	dispatched: 'خروج ثبت شد.',
	cancelled: 'لغو شد.',
	notFound: 'سفارش پیدا نشد.',
	requestFailed: 'درخواست ناموفق بود.',
	emptyHistory: 'خالی',
	loadFailed: 'بارگذاری ناموفق بود.',
	orderFound: 'سفارش پیدا شد.',
	previousDispatch: 'ثبت قبلی: %1$s — %2$s',
	previous: 'قبلی',
	next: 'بعدی',
	pageOf: 'صفحه %1$s از %2$s',
	noItems: 'بدون قلم',
	notDispatched: 'خروج نخورده',
	alreadyScanned: 'قبلاً اسکن شده',
};

function orderFor( barcode, id = 42 ) {
	return {
		id,
		number: String( id ),
		status: 'processing',
		status_label: 'در حال انجام',
		barcode,
		customer: { name: 'Test Customer', phone: '09121234567' },
		shipping_address: 'Valiasr St, No. 12',
		items: [ { name: 'Parcel Item', quantity: 2 } ],
	};
}

function setup( t, config = {} ) {
	const dom = new JSDOM( MARKUP, {
		url: 'https://example.test/wp-admin/admin.php?page=wcpd-dispatch',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
	} );
	const { window } = dom;
	const calls = [];
	const pending = [];

	function apiFetch( options ) {
		const call = {
			path: options.path,
			method: options.method || 'GET',
			data: options.data,
		};
		calls.push( call );

		if ( call.path.indexOf( '/wcpd/v1/dispatches' ) === 0 ) {
			return Promise.resolve( config.history || { items: [], total: 0, page: 1, per_page: 20, pages: 0 } );
		}

		return new Promise( ( resolve, reject ) => {
			pending.push( { call, resolve, reject } );
		} );
	}

	apiFetch.use = function () {};
	apiFetch.createNonceMiddleware = function () {
		return function () {};
	};

	window.wp = { apiFetch };
	window.wcpdData = {
		restUrl: 'https://example.test/wp-json/wcpd/v1/',
		nonce: 'test-nonce',
		pageSize: 20,
		i18n: I18N,
	};

	window.eval( script );

	const environment = {
		dom,
		window,
		document: window.document,
		form: window.document.getElementById( 'wcpd-scan-form' ),
		input: window.document.getElementById( 'wcpd-barcode' ),
		calls,
		pending,
		press( key ) {
			const event = new window.KeyboardEvent( 'keydown', {
				key,
				bubbles: true,
				cancelable: true,
			} );
			environment.input.dispatchEvent( event );
			return event;
		},
		lookups() {
			return calls.filter( ( call ) => call.path === '/wcpd/v1/barcode/lookup' );
		},
		dispatches() {
			return calls.filter( ( call ) => call.path === '/wcpd/v1/dispatch' );
		},
		flush() {
			return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		},
	};

	t.after( () => window.close() );

	return environment;
}

async function scan( env, barcode ) {
	env.input.value = barcode;
	const event = env.press( 'Enter' );
	await Promise.resolve();
	return event;
}

async function resolveLookup( env, index, order ) {
	env.pending[ index ].resolve( { order } );
	await env.flush();
}

test( 'focuses the barcode input on load and keeps it a text field', async ( t ) => {
	const env = setup( t );
	await env.flush();

	assert.equal( env.input.getAttribute( 'type' ), 'text' );
	assert.equal( env.document.activeElement, env.input );
} );

test( 'Enter triggers exactly one lookup and preserves leading zeros', async ( t ) => {
	const env = setup( t );

	const event = await scan( env, '0012345678901' );

	assert.equal( event.defaultPrevented, true );
	assert.equal( env.lookups().length, 1 );
	assert.equal( env.lookups()[ 0 ].data.barcode, '0012345678901' );
	assert.equal( env.input.value, '' );
	assert.equal( env.document.activeElement, env.input );

	await resolveLookup( env, 0, orderFor( '0012345678901' ) );

	assert.equal( env.document.getElementById( 'wcpd-order' ).hidden, false );
	assert.equal( env.document.activeElement, env.input );
	assert.equal( env.dispatches().length, 0 );
} );

test( 'Tab with a scanned value triggers one lookup and is consumed', async ( t ) => {
	const env = setup( t );

	env.input.value = '1234567890';
	const event = env.press( 'Tab' );
	await Promise.resolve();

	assert.equal( event.defaultPrevented, true );
	assert.equal( env.lookups().length, 1 );
	assert.equal( env.lookups()[ 0 ].data.barcode, '1234567890' );
	assert.equal( env.input.value, '' );
} );

test( 'Tab on an empty input keeps normal focus navigation', async ( t ) => {
	const env = setup( t );
	await env.flush();

	const event = env.press( 'Tab' );

	assert.equal( event.defaultPrevented, false );
	assert.equal( env.lookups().length, 0 );
} );

test( 'repeated Enter events during an in-flight lookup send one request and keep new input', async ( t ) => {
	const env = setup( t );

	env.input.value = '1111111111';
	env.press( 'Enter' );
	env.press( 'Enter' );
	await Promise.resolve();

	env.input.value = '2222222222';
	env.press( 'Enter' );
	env.press( 'Enter' );

	assert.equal( env.lookups().length, 1 );
	assert.equal( env.input.value, '2222222222' );

	await resolveLookup( env, 0, orderFor( '1111111111' ) );

	assert.equal( env.document.activeElement, env.input );
} );

test( 'focus stays in the input after an error and a second scan works', async ( t ) => {
	const env = setup( t );

	await scan( env, '3333333333' );
	env.pending[ 0 ].reject( { code: 'wcpd_order_not_found', message: 'not found' } );
	await env.flush();

	assert.equal( env.document.activeElement, env.input );
	assert.equal( env.document.getElementById( 'wcpd-message' ).hidden, false );

	await scan( env, '0044444444' );

	assert.equal( env.lookups().length, 2 );
	assert.equal( env.lookups()[ 1 ].data.barcode, '0044444444' );

	await resolveLookup( env, 1, orderFor( '0044444444' ) );
	assert.equal( env.document.activeElement, env.input );
} );

test( 'scanning never dispatches; explicit confirmation sends the displayed order', async ( t ) => {
	const env = setup( t );

	await scan( env, '0012345678901' );
	await resolveLookup( env, 0, {
		...orderFor( '0012345678901' ),
		edit_url: 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
	} );

	assert.equal( env.dispatches().length, 0 );
	assert.equal( env.document.getElementById( 'wcpd-open-order' ).hidden, false );

	env.document.getElementById( 'wcpd-confirm' ).click();
	await Promise.resolve();

	assert.equal( env.dispatches().length, 1 );
	// Copy into this realm so strict deep equality is not tripped by jsdom's Object prototype.
	assert.deepEqual( { ...env.dispatches()[ 0 ].data }, {
		barcode: '0012345678901',
		order_id: 42,
	} );

	env.pending[ 1 ].resolve( {
		dispatch: { id: 9, barcode: '0012345678901', order_id: 42, user_id: 7, dispatched_at: '2026-10-31 10:00:00' },
		order: orderFor( '0012345678901' ),
	} );
	await env.flush();

	assert.equal( env.document.activeElement, env.input );
	assert.equal( env.input.value, '' );
	assert.equal( env.document.getElementById( 'wcpd-order' ).hidden, true );
	assert.equal( env.document.getElementById( 'wcpd-open-order' ).hidden, true );
} );

test( 'open-order link points at the order edit screen and clears on cancel', async ( t ) => {
	const env = setup( t );
	const link = env.document.getElementById( 'wcpd-open-order' );
	const editUrl = 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=42';

	assert.equal( link.hidden, true );

	await scan( env, '0012345678901' );
	await resolveLookup( env, 0, { ...orderFor( '0012345678901' ), edit_url: editUrl } );

	assert.equal( link.hidden, false );
	assert.equal( link.getAttribute( 'href' ), editUrl );
	assert.equal( link.getAttribute( 'target' ), '_blank' );
	assert.match( link.getAttribute( 'rel' ) || '', /noopener/ );

	env.document.getElementById( 'wcpd-cancel' ).click();

	assert.equal( link.hidden, true );
	assert.equal( link.hasAttribute( 'href' ), false );
} );

test( 'open-order link refuses non-http urls', async ( t ) => {
	const env = setup( t );
	const link = env.document.getElementById( 'wcpd-open-order' );

	await scan( env, '1234567890' );
	await resolveLookup( env, 0, { ...orderFor( '1234567890' ), edit_url: 'javascript:alert(1)' } );

	assert.equal( link.hidden, true );
	assert.equal( link.hasAttribute( 'href' ), false );
} );

test( 'repeated confirmation clicks send one dispatch request', async ( t ) => {
	const env = setup( t );

	await scan( env, '0012345678901' );
	await resolveLookup( env, 0, orderFor( '0012345678901' ) );

	const confirm = env.document.getElementById( 'wcpd-confirm' );
	confirm.click();
	confirm.click();
	confirm.click();
	await Promise.resolve();

	assert.equal( env.dispatches().length, 1 );

	env.pending[ 1 ].resolve( {
		dispatch: { id: 9, barcode: '0012345678901', order_id: 42, user_id: 7, dispatched_at: '2026-10-31 10:00:00' },
		order: orderFor( '0012345678901' ),
	} );
	await env.flush();
} );

test( 'submitting the form with the lookup button behaves like a scan', async ( t ) => {
	const env = setup( t );

	env.input.value = '0055555555';
	env.form.dispatchEvent( new env.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await Promise.resolve();

	assert.equal( env.lookups().length, 1 );
	assert.equal( env.lookups()[ 0 ].data.barcode, '0055555555' );
	assert.equal( env.input.value, '' );

	await resolveLookup( env, 0, orderFor( '0055555555' ) );
	assert.equal( env.document.activeElement, env.input );
} );

test( 'history renders stored markup as inert text', async ( t ) => {
	const payload = '<img src=x onerror="window.__wcpdXss=1"><script>window.__wcpdXss=2</script>';

	const env = setup( t, {
		history: {
			items: [
				{
					id: 1,
					barcode: payload,
					order_id: 42,
					user_id: 7,
					dispatched_at: payload,
					operator: { id: 7, name: payload },
				},
			],
			total: 1,
			page: 1,
			per_page: 20,
			pages: 1,
		},
	} );

	await env.flush();
	await env.flush();

	const body = env.document.getElementById( 'wcpd-history-body' );

	assert.equal( body.querySelectorAll( 'img, script' ).length, 0 );
	assert.equal( env.window.__wcpdXss, undefined );
	assert.equal( body.rows.length, 1 );
	assert.equal( body.rows[ 0 ].cells[ 0 ].textContent, payload );
	assert.equal( body.rows[ 0 ].cells[ 2 ].textContent, payload );
	assert.equal( body.rows[ 0 ].cells[ 3 ].textContent, payload );
} );

test( 'order card renders payload fields as inert text', async ( t ) => {
	const env = setup( t );
	const payload = '<img src=x onerror="window.__wcpdXss=3">';

	await scan( env, '1234567890' );
	await resolveLookup( env, 0, {
		...orderFor( '1234567890' ),
		customer: { name: payload, phone: payload },
		shipping_address: payload,
	} );

	const card = env.document.getElementById( 'wcpd-order' );

	assert.equal( card.querySelectorAll( 'img, script' ).length, 0 );
	assert.equal( env.window.__wcpdXss, undefined );
	assert.equal( env.document.getElementById( 'wcpd-order-customer' ).textContent, payload );
	assert.equal( env.document.getElementById( 'wcpd-order-phone' ).textContent, payload );
	assert.equal( env.document.getElementById( 'wcpd-order-address' ).textContent, payload );
} );
