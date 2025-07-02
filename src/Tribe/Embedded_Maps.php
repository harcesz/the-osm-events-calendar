<?php
/**
 * Facilitates embedding one or more maps utilizing OpenStreetMap.
 */
class Tribe__Events__Embedded_Maps {
	/**
	 * Script handle (not needed for OSM iframe, kept for compatibility).
	 */
	const MAP_HANDLE = 'tribe_events_embedded_map';

	/**
	 * @var Tribe__Events__Embedded_Maps
	 */
	protected static $instance;

	/**
	 * Post ID of the current event.
	 *
	 * @var int
	 */
	protected $event_id = 0;

	/**
	 * Post ID of the current venue (if known/if can be determined).
	 *
	 * @var int
	 */
	protected $venue_id = 0;

	/**
	 * Address of the current event/venue.
	 *
	 * @var string
	 */
	protected $address = '';

	/**
	 * Container for map address data (potentially allowing for multiple maps per page).
	 *
	 * @var array
	 */
	protected $embedded_maps = [];

	/**
	 * Indicates if the map script has been enqueued (not needed for OSM iframe).
	 *
	 * @var bool
	 */
	protected $map_script_enqueued = false;

	/**
	 * @return Tribe__Events__Embedded_Maps
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Returns the placeholder HTML needed to embed a map within a page.
	 *
	 * @param int  $post_id ID of the pertinent event or venue
	 * @param int  $width
	 * @param int  $height
	 * @param bool $force_load add the map even if no address data can be found
	 *
	 * @return string
	 */
	public function get_map( $post_id, $width, $height, $force_load ) {
		$this->get_ids( $post_id );

		// Bail if either the venue or event couldn't be determined
		if ( ! tribe_is_venue( $this->venue_id ) && ! tribe_is_event( $this->event_id ) ) {
			return apply_filters( 'tribe_get_embedded_map', '' );
		}

		$this->form_address();

		if ( empty( $this->address ) && ! $force_load ) {
			return apply_filters( 'tribe_get_embedded_map', '' );
		}

		$this->embedded_maps[] = [
			'address' => $this->address,
			'title'   => esc_html( get_the_title( $this->venue_id ) ),
		];

		end( $this->embedded_maps );
		$index = key( $this->embedded_maps );

		ob_start();

		// Calculate width and height
		if ( is_numeric( $width ) ) {
			$width .= 'px';
		}
		if ( is_numeric( $height ) ) {
			$height .= 'px';
		}
		$width  = $width  ? $width  : apply_filters( 'tribe_events_single_map_default_width', '100%' );
		$height = $height ? $height : apply_filters( 'tribe_events_single_map_default_height', '350px' );

		// Try to get coordinates, fallback to address if not possible
		$lat = $lng = null;
		$address = trim($this->address);

		// If using PRO Geo_Loc, try to get lat/lng
		if ( class_exists( 'Tribe__Events__Pro__Geo_Loc' ) ) {
			$lat = get_post_meta( $this->venue_id, Tribe__Events__Pro__Geo_Loc::LAT, true );
			$lng = get_post_meta( $this->venue_id, Tribe__Events__Pro__Geo_Loc::LNG, true );
			if ( !empty($lat) && !empty($lng) ) {
				$address = "$lat,$lng";
			}
		}

		// If we have lat/lng, use them for OSM marker, otherwise attempt geocoding (not implemented here)
		if ( preg_match( '/^(-?\d+\.\d+),\s*(-?\d+\.\d+)$/', $address, $matches ) ) {
			$lat = $matches[1];
			$lng = $matches[2];
		} else {
			// Fallback: no coordinates, display address text only, or optionally use a static map service with geocoding
			$lat = $lng = null;
		}

		if ( $lat && $lng ) {
			// OSM embed with marker at the coordinates
			$zoom = apply_filters( 'tribe_events_single_map_zoom_level', 15 );
			$osm_url = "https://www.openstreetmap.org/export/embed.html?bbox=" .
				($lng-0.005) . "%2C" . ($lat-0.003) . "%2C" . ($lng+0.005) . "%2C" . ($lat+0.003) .
				"&layer=mapnik&marker={$lat},{$lng}";
			echo '<div class="tribe-events-osm-map" style="width:'.esc_attr($width).'; height:'.esc_attr($height).';">';
			echo '<iframe width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="' . esc_url($osm_url) . '" style="border:1px solid #ccc"></iframe>';
			echo '</div>';
		} elseif ( !empty($address) ) {
			// No coordinates, just display the address (optionally, you could integrate a geocoding API here)
			echo '<div class="tribe-events-osm-address" style="width:'.esc_attr($width).'; height:'.esc_attr($height).'; display:flex; align-items:center; justify-content:center; border:1px solid #ccc;">';
			echo esc_html( $address );
			echo '</div>';
		}

		do_action( 'tribe_events_map_embedded', $index, $this->venue_id );
		return apply_filters( 'tribe_get_embedded_map', ob_get_clean() );
	}

	protected function get_ids( $post_id ) {
		$post_id = Tribe__Events__Main::postIdHelper( $post_id );
		$this->event_id = tribe_is_event( $post_id ) ? $post_id : 0;
		$this->venue_id = tribe_is_venue( $post_id ) ? $post_id : tribe_get_venue_id( $post_id );
	}

	protected function form_address() {
		$this->address = '';
		$location_parts = [ 'address', 'city', 'state', 'province', 'zip', 'country' ];

		// Form the address string for the map
		foreach ( $location_parts as $val ) {
			$address_part = call_user_func( 'tribe_get_' . $val, $this->venue_id );
			if ( $address_part ) {
				$this->address .= $address_part . ' ';
			}
		}

		if ( class_exists( 'Tribe__Events__Pro__Geo_Loc' ) && empty( trim($this->address) ) ) {
			$overwrite = (int) get_post_meta( $this->venue_id, Tribe__Events__Pro__Geo_Loc::OVERWRITE, true );
			if ( $overwrite ) {
				$lat = get_post_meta( $this->venue_id, Tribe__Events__Pro__Geo_Loc::LAT, true );
				$lng = get_post_meta( $this->venue_id, Tribe__Events__Pro__Geo_Loc::LNG, true );
				$this->address = $lat . ',' . $lng;
			}
		}
	}

	public function get_map_data( $map_index ) {
		return isset( $this->embedded_maps[ $map_index ] ) ? $this->embedded_maps[ $map_index ] : [];
	}

	public function update_map_data( $map_index, array $data ) {
		$this->embedded_maps[ $map_index ] = $data;
	}

	// No scripts needed for OSM iframe, so these are now empty for compatibility
	protected function setup_scripts() {}
	protected function enqueue_map_scripts() {}
}
