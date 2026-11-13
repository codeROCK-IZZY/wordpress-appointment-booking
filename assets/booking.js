/* Booking widget: service and person, a strip of days with how many times are free, the times, then a short form.
 * No build step and no dependencies. Times are shown in the business timezone; when the visitor's own timezone is
 * different, each time also shows the visitor's local time so nobody books the wrong hour. */
( function () {
	'use strict';

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'className' ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function getJson( url ) {
		return fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function init( root ) {
		var cfg = JSON.parse( root.getAttribute( 'data-abk' ) );
		var t = cfg.strings;
		var visitorTz = '';
		try {
			visitorTz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {}
		var sel = {
			service: root.querySelector( '.abk-service' ),
			staff: root.querySelector( '.abk-staff' ),
			days: root.querySelector( '.abk-days' ),
			times: root.querySelector( '.abk-times' ),
			form: root.querySelector( '.abk-form' ),
			chosen: root.querySelector( '.abk-chosen' ),
			error: root.querySelector( '.abk-error' ),
			done: root.querySelector( '.abk-done' ),
		};
		// seq numbers each request for days or times: a response that is not the latest is ignored, so a slow answer for
		// a day the visitor has already left can never replace the times they are looking at.
		var state = { services: [], staff: [], day: '', slot: null, seq: 0 };

		function query( path, params ) {
			var q = Object.keys( params ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} );
			return cfg.rest + path + ( cfg.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + q.join( '&' );
		}

		function fillStaff() {
			var service = sel.service.value;
			sel.staff.innerHTML = '';
			sel.staff.appendChild( el( 'option', { value: '' }, t.anyone ) );
			state.staff.forEach( function ( p ) {
				if ( p.services.indexOf( service ) !== -1 ) {
					var o = el( 'option', { value: p.id }, p.name );
					if ( p.id === cfg.staff ) {
						o.selected = true;
					}
					sel.staff.appendChild( o );
				}
			} );
		}

		function loadDays() {
			var mine = ++state.seq;
			state.slot = null;
			sel.days.textContent = t.loading;
			sel.times.innerHTML = '';
			sel.form.hidden = true;
			getJson( query( '/days', { service: sel.service.value, staff: sel.staff.value } ) ).then( function ( res ) {
				if ( mine !== state.seq ) {
					return;
				}
				sel.days.innerHTML = '';
				var first = '';
				res.days.forEach( function ( d ) {
					var b = el( 'button', { type: 'button', className: 'abk-day', 'data-date': d.date } );
					b.appendChild( el( 'span', {}, d.label ) );
					b.appendChild( el( 'small', {}, d.free ? d.free + ' ' + t.free : t.full ) );
					b.disabled = ! d.free;
					b.addEventListener( 'click', function () {
						pickDay( d.date );
					} );
					sel.days.appendChild( b );
					if ( ! first && d.free ) {
						first = d.date;
					}
				} );
				if ( first ) {
					pickDay( first );
				}
			} );
		}

		function visitorLabel( iso ) {
			if ( ! visitorTz || visitorTz === cfg.tz ) {
				return '';
			}
			try {
				return new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit', timeZone: visitorTz } ).format( new Date( iso ) );
			} catch ( e ) {
				return '';
			}
		}

		function pickDay( date ) {
			var mine = ++state.seq;
			state.day = date;
			state.slot = null;
			Array.prototype.forEach.call( sel.days.children, function ( b ) {
				b.setAttribute( 'aria-pressed', b.getAttribute( 'data-date' ) === date ? 'true' : 'false' );
			} );
			sel.times.textContent = t.loading;
			sel.form.hidden = true;
			getJson( query( '/slots', { service: sel.service.value, staff: sel.staff.value, date: date } ) ).then( function ( res ) {
				if ( mine !== state.seq ) {
					return;
				}
				sel.times.innerHTML = '';
				if ( ! res.slots.length ) {
					sel.times.textContent = t.noTimes;
					return;
				}
				res.slots.forEach( function ( s ) {
					var b = el( 'button', { type: 'button', className: 'abk-time' }, s.label );
					var mine = visitorLabel( s.iso );
					if ( mine ) {
						b.appendChild( el( 'small', {}, mine + ' ' + t.yourTime ) );
					}
					b.addEventListener( 'click', function () {
						state.slot = s;
						Array.prototype.forEach.call( sel.times.children, function ( x ) {
							x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
						} );
						var svc = sel.service.options[ sel.service.selectedIndex ].text;
						sel.chosen.textContent = svc + ' · ' + document.querySelector( '.abk-day[aria-pressed="true"] span' ).textContent + ' · ' + s.label;
						sel.form.hidden = false;
						sel.form.querySelector( 'input[name="name"]' ).focus();
					} );
					sel.times.appendChild( b );
				} );
			} );
		}

		sel.form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			if ( ! state.slot ) {
				return;
			}
			var button = sel.form.querySelector( 'button' );
			var body = new FormData( sel.form );
			body.append( 'service', sel.service.value );
			body.append( 'staff', sel.staff.value );
			body.append( 'start', String( state.slot.start ) );
			body.append( 'page_url', cfg.page );
			button.disabled = true;
			button.textContent = t.booking;
			sel.error.textContent = '';
			fetch( cfg.rest + '/bookings', { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json().then( function ( j ) {
						return { status: r.status, body: j };
					} );
				} )
				.then( function ( res ) {
					button.disabled = false;
					button.textContent = t.book;
					if ( res.status === 201 && res.body.ok ) {
						sel.form.hidden = true;
						sel.times.innerHTML = '';
						sel.days.innerHTML = '';
						sel.done.innerHTML = '';
						sel.done.appendChild( el( 'h3', {}, t.booked ) );
						sel.done.appendChild( el( 'p', {}, res.body.service + ' · ' + res.body.staff + ' · ' + res.body.when ) );
						var links = el( 'p', {} );
						links.appendChild( el( 'a', { href: res.body.ics }, t.calendar ) );
						links.appendChild( document.createTextNode( ' · ' ) );
						links.appendChild( el( 'a', { href: res.body.manage }, t.manage ) );
						sel.done.appendChild( links );
						sel.done.appendChild( el( 'p', {}, t.emailed ) );
						sel.done.hidden = false;
						sel.done.focus();
						return;
					}
					var errors = res.body.errors || {};
					var messages = Object.keys( errors ).map( function ( k ) {
						return errors[ k ];
					} );
					sel.error.textContent = messages.join( ' ' ) || res.body.message || t.failed;
					if ( res.body.status === 'taken' || res.body.status === 'unavailable' ) {
						pickDay( state.day );
					}
				} )
				.catch( function () {
					button.disabled = false;
					button.textContent = t.book;
					sel.error.textContent = t.failed;
				} );
		} );

		getJson( cfg.rest + '/services' ).then( function ( res ) {
			state.services = res.services;
			state.staff = res.staff;
			res.services.forEach( function ( s ) {
				var o = el( 'option', { value: s.id }, s.name + ' (' + s.duration + ' min)' );
				if ( s.id === cfg.service ) {
					o.selected = true;
				}
				sel.service.appendChild( o );
			} );
			fillStaff();
			loadDays();
		} );
		sel.service.addEventListener( 'change', function () {
			fillStaff();
			loadDays();
		} );
		sel.staff.addEventListener( 'change', loadDays );
	}

	document.querySelectorAll( '.abk[data-abk]' ).forEach( init );
} )();
