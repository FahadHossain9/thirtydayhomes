/**
 * The map: circles, never pins.
 *
 * Everything this file draws comes from one data attribute the server
 * wrote, and that attribute holds an approximate point per home — the real
 * one never reaches the browser, so there is nothing here that could leak
 * it however this code is read. See TDH\Maps in the plugin.
 *
 * The map is an addition. The list is rendered first, server-side, and is
 * the whole product on its own; if Google never answers, if the key is
 * refused, or if JavaScript is off, the page is still complete and says
 * what happened. Nothing below is required for a renter to find a home.
 *
 * Dependency-free, like the rest of the theme's scripts.
 */
( function () {
	'use strict';

	var panels = document.querySelectorAll( '[data-tdh-map]' );

	if ( ! panels.length ) {
		return;
	}

	var LOAD_TIMEOUT = 9000;
	var ready        = false;

	/*
	 * A quieter base map.
	 *
	 * Google's default is built for finding a restaurant: every shop, zoo
	 * and car park shouts, and our circles end up as one more thing on a
	 * busy page. Turning the businesses off and softening the ground lets
	 * the homes be the only gold on screen. Hospitals stay, because on
	 * this marketplace they are the reason anyone is looking at the map,
	 * and parks stay because they tell a renter what a street feels like.
	 */
	var QUIET = [
		{ elementType: 'geometry', stylers: [ { color: '#f7f5ef' } ] },
		{ elementType: 'labels.text.fill', stylers: [ { color: '#74808b' } ] },
		{ elementType: 'labels.text.stroke', stylers: [ { color: '#faf8f2' } ] },
		{ featureType: 'poi', stylers: [ { visibility: 'off' } ] },
		{ featureType: 'poi.medical', stylers: [ { visibility: 'on' } ] },
		{ featureType: 'poi.park', elementType: 'geometry', stylers: [ { visibility: 'on' }, { color: '#e7efe4' } ] },
		{ featureType: 'poi.park', elementType: 'labels.text.fill', stylers: [ { color: '#8aa088' } ] },
		{ featureType: 'transit', stylers: [ { visibility: 'off' } ] },
		{ featureType: 'road', elementType: 'geometry', stylers: [ { color: '#ffffff' } ] },
		{ featureType: 'road', elementType: 'labels.icon', stylers: [ { visibility: 'off' } ] },
		{ featureType: 'road.arterial', elementType: 'geometry', stylers: [ { color: '#fdfcf9' } ] },
		{ featureType: 'road.highway', elementType: 'geometry', stylers: [ { color: '#f2ead5' } ] },
		{ featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [ { color: '#e8dcc0' } ] },
		{ featureType: 'administrative', elementType: 'geometry.stroke', stylers: [ { color: '#e4e5e6' } ] },
		{ featureType: 'administrative.land_parcel', stylers: [ { visibility: 'off' } ] },
		{ featureType: 'water', elementType: 'geometry', stylers: [ { color: '#d9e6ee' } ] },
		{ featureType: 'water', elementType: 'labels.text.fill', stylers: [ { color: '#9fb3c0' } ] }
	];

	/**
	 * The price tag that sits on a home's circle.
	 *
	 * A custom overlay rather than a Google marker, for two reasons: a
	 * marker is a pin, and a pin is the one thing this feature must never
	 * draw; and an overlay is ordinary HTML, so it takes the site's own
	 * type and colours instead of looking like a Google control.
	 */
	function definePriceTag() {

		function PriceTag( position, label, name, onClick ) {
			this.position = position;
			this.label    = label;
			this.name     = name;
			this.onClick  = onClick;
			this.div      = null;
		}

		PriceTag.prototype = Object.create( google.maps.OverlayView.prototype );

		PriceTag.prototype.onAdd = function () {
			var self   = this;
			var button = document.createElement( 'button' );

			button.type      = 'button';
			button.className = 'map-pill';
			button.textContent = this.label;
			button.setAttribute( 'aria-label', this.name + ', ' + this.label );

			button.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				self.onClick();
			} );

			this.div = button;
			this.getPanes().floatPane.appendChild( button );
		};

		PriceTag.prototype.draw = function () {
			var point = this.getProjection().fromLatLngToDivPixel( this.position );

			if ( this.div && point ) {
				this.div.style.left = point.x + 'px';
				this.div.style.top  = point.y + 'px';
			}
		};

		PriceTag.prototype.onRemove = function () {
			if ( this.div && this.div.parentNode ) {
				this.div.parentNode.removeChild( this.div );
			}
			this.div = null;
		};

		return PriceTag;
	}

	/** A CSS token, resolved once: the Maps API wants a colour, not a var(). */
	function token( name, fallback ) {
		var value = getComputedStyle( document.documentElement ).getPropertyValue( name );
		return value ? value.trim() : fallback;
	}

	function read( panel ) {
		try {
			return JSON.parse( panel.getAttribute( 'data-tdh-map' ) );
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Our own words, in place of whatever was there.
	 *
	 * The panel is emptied first on purpose. When Google refuses a key it
	 * paints its own "Oops! Something went wrong — see the JavaScript
	 * console" over the container, which is written for a developer, is
	 * only ever in English, and tells a renter nothing they can act on.
	 * Appending underneath would leave both.
	 */
	function fail( panel, data ) {

		var words = ( data && data.words ) ? data.words : {};

		panel.innerHTML = '';
		panel.classList.add( 'is-failed' );
		panel.removeAttribute( 'role' );
		panel.removeAttribute( 'aria-label' );

		var status = document.createElement( 'p' );
		status.className = 'map-status is-error';
		status.textContent = words.failed || 'The map could not be loaded.';
		panel.appendChild( status );

		// Somewhere to go, not just something to read.
		if ( data && data.listUrl && words.list ) {
			var back = document.createElement( 'a' );
			back.className = 'map-status-link';
			back.href = data.listUrl;
			back.textContent = words.list;
			panel.appendChild( back );
		}
	}

	function failAll() {
		Array.prototype.forEach.call( panels, function ( panel ) {
			fail( panel, read( panel ) );
		} );
	}

	function draw( panel ) {

		var data = read( panel );

		if ( ! data || ! data.homes || ! data.homes.length ) {
			return;
		}

		var gold   = token( '--color-gold', '#d7b967' );
		var deep   = token( '--color-gold-deep', '#a68631' );
		var navy   = token( '--color-navy', '#0c192b' );
		var motion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		panel.innerHTML = '';

		var map = new google.maps.Map( panel, {
			mapTypeControl: false,
			streetViewControl: false,
			fullscreenControl: false,
			// Street View would let someone walk the circle looking for the
			// house. The circle is the promise; this keeps it.
			zoomControl: true,
			gestureHandling: 'cooperative',
			backgroundColor: token( '--color-cream', '#faf8f2' ),
			styles: QUIET
		} );

		var PriceTag = definePriceTag();
		var bounds   = new google.maps.LatLngBounds();
		var open     = null;

		data.homes.forEach( function ( home ) {

			var at = { lat: home.lat, lng: home.lng };

			/*
			 * Two rings rather than one flat disc. The wide, almost
			 * invisible halo is the honest edge of what we know; the
			 * tighter ring inside gives the eye something to land on. The
			 * home is somewhere in the halo, and the softness is the
			 * point — a hard-edged disc reads as a measurement.
			 */
			var halo = new google.maps.Circle( {
				map: map,
				center: at,
				radius: data.circle,
				strokeColor: deep,
				strokeOpacity: 0.55,
				strokeWeight: 1,
				fillColor: gold,
				fillOpacity: 0.16,
				clickable: false
			} );

			/*
			 * The inner ring must never be tighter than the error it sits
			 * on. The published point can be OFFSET_MAX (250 m) from the
			 * real one, so a ring drawn inside that distance would invite
			 * the eye to read "the home is in here" about an area the home
			 * may not be in. At 0.75 of the circle it is 300 m, comfortably
			 * wider than the offset, so both rings remain true and the
			 * inner one is only there to give the eye an edge to land on.
			 */
			var circle = new google.maps.Circle( {
				map: map,
				center: at,
				radius: data.circle * 0.75,
				strokeColor: deep,
				strokeOpacity: 0.7,
				strokeWeight: 2,
				fillColor: gold,
				fillOpacity: 0.16,
				clickable: ! data.single
			} );

			bounds.union( halo.getBounds() );

			if ( data.single ) {
				return;
			}

			// A compact card, built with the DOM rather than a string, so a
			// home whose name contains a quote or a bracket cannot break
			// out of the markup.
			var card = document.createElement( 'div' );
			card.className = 'map-card';

			/*
			 * Our own close button. Google's title bar is switched off so
			 * the card looks like the rest of the site, and that takes its
			 * X away with it — leaving a card that cannot be dismissed,
			 * because clicking the map does not close an InfoWindow by
			 * itself either. Escape and a click on the map are wired up
			 * below as well, for keyboard and for habit.
			 */
			var shut = document.createElement( 'button' );
			shut.type = 'button';
			shut.className = 'map-card-close';
			shut.textContent = '×';
			shut.setAttribute( 'aria-label', data.words.close || 'Close' );
			card.appendChild( shut );

			var name = document.createElement( 'b' );
			name.textContent = home.title;
			card.appendChild( name );

			if ( home.where ) {
				var where = document.createElement( 'span' );
				where.textContent = home.where;
				card.appendChild( where );
			}

			if ( home.price ) {
				var price = document.createElement( 'span' );
				price.className = 'map-card-price';
				price.textContent = home.price;
				card.appendChild( price );
			}

			var about = document.createElement( 'small' );
			about.textContent = data.words.about;
			card.appendChild( about );

			var link = document.createElement( 'a' );
			link.href = home.url;
			link.textContent = data.words.view;
			card.appendChild( link );

			var info = new google.maps.InfoWindow( {
				content: card,
				// Newer versions drop Google's own title bar; older ones
				// ignore the option, which costs nothing.
				headerDisabled: true,
				pixelOffset: new google.maps.Size( 0, -18 )
			} );

			var show = function () {
				if ( open ) {
					open.close();
				}
				info.setPosition( at );
				info.open( map );
				open = info;
			};

			shut.addEventListener( 'click', function () {
				info.close();
				open = null;
			} );

			circle.addListener( 'click', show );

			// The price is what a renter scans a map for, so it is on the
			// map rather than hidden behind a click. Homes with no price
			// keep their circle and stay clickable.
			if ( home.price ) {
				var tag = new PriceTag( new google.maps.LatLng( at.lat, at.lng ), home.price, home.title, show );
				tag.setMap( map );
			}
		} );

		/*
		 * A single home gets less padding and is allowed one step closer:
		 * its map is a short box on a property page, and a lone circle
		 * floating in half a city says nothing. A results map keeps more
		 * air, because circles near the edge are easy to miss.
		 */
		map.fitBounds( bounds, data.single ? 20 : 48 );

		var closest = data.single ? 16 : 15;

		google.maps.event.addListenerOnce( map, 'idle', function () {
			if ( map.getZoom() > closest ) {
				map.setZoom( closest );
			}
		} );

		// The two other ways anyone expects to dismiss a card.
		var dismiss = function () {
			if ( open ) {
				open.close();
				open = null;
			}
		};

		map.addListener( 'click', dismiss );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				dismiss();
			}
		} );

		if ( motion ) {
			// Panning animation is the only motion the API adds by itself.
			map.setOptions( { gestureHandling: 'greedy' } );
		}

		panel.setAttribute( 'role', 'img' );
		panel.setAttribute(
			'aria-label',
			data.single
				? data.words.about
				: data.homes.length + ' ' + data.words.about
		);
	}

	/** Called by the Maps loader once the library is there. */
	window.tdhMapsReady = function () {
		ready = true;
		Array.prototype.forEach.call( panels, draw );
	};

	/** Called by the Maps library itself when the key is refused. */
	window.gm_authFailure = failAll;

	// Blocked, offline, or a key restricted to another domain: the callback
	// never comes and the page must not sit on "Loading the map" for ever.
	window.setTimeout( function () {
		if ( ! ready ) {
			failAll();
		}
	}, LOAD_TIMEOUT );
}() );
