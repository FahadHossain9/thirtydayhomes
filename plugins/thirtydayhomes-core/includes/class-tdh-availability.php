<?php
/**
 * When a home can be lived in: available-from plus the dates it is taken.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH;

defined( 'ABSPATH' ) || exit;

/**
 * ─── WHAT IS STORED ────────────────────────────────────────────────────────
 *
 *   _tdh_available_from   one date, YYYY-MM-DD; blank means "free now"
 *   _tdh_blocked_ranges   one period per line, "2026-12-15 to 2027-01-05",
 *                         both days included, sorted, never overlapping
 *
 * Plain text rather than a serialised array, so the wp-admin meta box shows
 * something a person can read and correct. Everything that reads it goes
 * through ranges(), which forgives a hand-edited line: anything that is not
 * two real dates in order is skipped, and overlaps are joined.
 *
 * ─── WHAT "FREE" MEANS ─────────────────────────────────────────────────────
 *
 * A stay runs from the move-in day to the move-out day. The move-out day is
 * not a night spent in the home, so a stay ending on 15 Dec fits before a
 * period that starts on 15 Dec — the turnover day is shared, the way a
 * renter and a landlord both count it. fits() is the one rule; the property
 * page, the cards and the date search (task C2) all ask it.
 *
 * ─── WHAT A LANDLORD CAN TYPE ──────────────────────────────────────────────
 *
 * check() takes the rows as typed and answers in three parts: the periods to
 * store, the rows it refused (kept, with the reason, so nothing typed is
 * lost), and notes about anything it changed on its own — overlapping or
 * touching periods are joined, and the landlord is told which.
 *
 *   a period that ends before it starts     refused, said why
 *   half a period, or a date that isn't one refused, said why
 *   more than three years ahead             refused: almost always a typo
 *   in the past                             kept, as history; renters never see it
 *   more than MAX_RANGES                    the extra ones refused, named
 */
final class Availability {

	public const NONCE = 'tdh_listing_availability';

	/** Where the periods live. */
	public const META = '_tdh_blocked_ranges';

	/** The most periods one home keeps. Said in the form before it is reached. */
	public const MAX_RANGES = 20;

	/** A date further ahead than this is refused as a typo. */
	public const HORIZON_YEARS = 3;

	/** Months the property page's calendar covers. */
	public const CALENDAR_MONTHS = 12;

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ] );
	}

	/* ---------------------------------------------------------------------
	 * Dates
	 * ------------------------------------------------------------------ */

	/** Today in the site's own timezone, YYYY-MM-DD. */
	public static function today(): string {

		/**
		 * Filter "today" for availability — the test suite pins it.
		 *
		 * @param string $today YYYY-MM-DD.
		 */
		$today = (string) apply_filters( 'tdh_availability_today', current_time( 'Y-m-d' ) );

		return self::is_date( $today ) ? $today : current_time( 'Y-m-d' );
	}

	/** A real calendar date written YYYY-MM-DD. "2026-02-30" is not one. */
	public static function is_date( string $value ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** The date $days after $ymd. */
	public static function add_days( string $ymd, int $days ): string {
		return self::day( $ymd )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/** Whole days from $from to $to (negative when $to is earlier). */
	public static function days_between( string $from, string $to ): int {
		$diff = self::day( $from )->diff( self::day( $to ) );
		return (int) $diff->days * ( $diff->invert ? -1 : 1 );
	}

	/** The last date a landlord may type: today plus HORIZON_YEARS. */
	public static function horizon(): string {
		return self::day( self::today() )->modify( '+' . self::HORIZON_YEARS . ' years' )->format( 'Y-m-d' );
	}

	/** A date at midnight UTC — calendar arithmetic with no daylight saving in it. */
	private static function day( string $ymd ): \DateTimeImmutable {
		return new \DateTimeImmutable( $ymd, new \DateTimeZone( 'UTC' ) );
	}

	/** "15 Dec 2026". */
	public static function format_day( string $ymd ): string {
		return self::is_date( $ymd ) ? (string) wp_date( 'j M Y', self::day( $ymd )->getTimestamp(), new \DateTimeZone( 'UTC' ) ) : '';
	}

	/**
	 * A period the way people write one:
	 *   25 Dec 2026 · 15 – 20 Dec 2026 · 15 Nov – 5 Dec 2026 · 15 Dec 2026 – 5 Jan 2027
	 *
	 * @param array{from:string,to:string} $range
	 */
	public static function format_range( array $range ): string {

		$from = (string) ( $range['from'] ?? '' );
		$to   = (string) ( $range['to'] ?? '' );

		if ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
			return '';
		}

		if ( $from === $to ) {
			return self::format_day( $from );
		}

		$utc = new \DateTimeZone( 'UTC' );
		$a   = self::day( $from );
		$b   = self::day( $to );

		if ( $a->format( 'Y-m' ) === $b->format( 'Y-m' ) ) {
			$start = (string) wp_date( 'j', $a->getTimestamp(), $utc );
		} elseif ( $a->format( 'Y' ) === $b->format( 'Y' ) ) {
			$start = (string) wp_date( 'j M', $a->getTimestamp(), $utc );
		} else {
			$start = self::format_day( $from );
		}

		/* translators: 1: first day, 2: last day — e.g. "15 Dec" and "5 Jan 2027" */
		return sprintf( __( '%1$s – %2$s', 'thirtydayhomes' ), $start, self::format_day( $to ) );
	}

	/* ---------------------------------------------------------------------
	 * Stored periods
	 * ------------------------------------------------------------------ */

	/**
	 * The periods in a stored value. Forgiving: a line that is not two real
	 * dates in order is skipped; one date alone is a single day.
	 *
	 * @return array<int,array{from:string,to:string}>
	 */
	public static function parse( string $stored ): array {

		$ranges = [];

		foreach ( preg_split( '/[\r\n;]+/', $stored ) ?: [] as $line ) {

			preg_match_all( '/\d{4}-\d{2}-\d{2}/', $line, $found );
			$dates = array_values( array_filter( $found[0], [ self::class, 'is_date' ] ) );

			if ( ! $dates ) {
				continue;
			}

			$from = $dates[0];
			$to   = $dates[1] ?? $dates[0];

			if ( $to >= $from ) {
				$ranges[] = [ 'from' => $from, 'to' => $to ];
			}
		}

		return self::merge( $ranges );
	}

	/** Periods as they are stored: one per line. */
	public static function serialise( array $ranges ): string {
		return implode(
			"\n",
			array_map( static fn( array $r ): string => $r['from'] . ' to ' . $r['to'], self::merge( $ranges ) )
		);
	}

	/**
	 * Sorted, with overlapping or touching periods joined into one.
	 *
	 * @param array<int,array{from:string,to:string}> $ranges
	 * @return array<int,array{from:string,to:string}>
	 */
	public static function merge( array $ranges ): array {
		return array_map(
			static fn( array $group ): array => [ 'from' => $group['from'], 'to' => $group['to'] ],
			self::groups( $ranges )
		);
	}

	/**
	 * merge(), keeping which periods went into each result — so the form can
	 * say "these two were joined" instead of changing the dates silently.
	 *
	 * @param array<int,array{from:string,to:string}> $ranges
	 * @return array<int,array{from:string,to:string,parts:int}>
	 */
	private static function groups( array $ranges ): array {

		$ranges = array_values(
			array_filter(
				$ranges,
				static fn( $r ): bool => is_array( $r ) && self::is_date( (string) ( $r['from'] ?? '' ) ) && self::is_date( (string) ( $r['to'] ?? '' ) ) && $r['to'] >= $r['from']
			)
		);

		usort( $ranges, static fn( array $a, array $b ): int => [ $a['from'], $a['to'] ] <=> [ $b['from'], $b['to'] ] );

		$out = [];

		foreach ( $ranges as $r ) {
			$last = count( $out ) - 1;

			// Overlapping, or starting the day after the last one ends.
			if ( $last >= 0 && $r['from'] <= self::add_days( $out[ $last ]['to'], 1 ) ) {
				$out[ $last ]['to'] = max( $out[ $last ]['to'], $r['to'] );
				++$out[ $last ]['parts'];
				continue;
			}

			$out[] = [ 'from' => $r['from'], 'to' => $r['to'], 'parts' => 1 ];
		}

		return $out;
	}

	/**
	 * A home's periods.
	 *
	 * @return array<int,array{from:string,to:string}>
	 */
	public static function ranges( int $id ): array {
		return $id > 0 ? self::parse( (string) get_post_meta( $id, self::META, true ) ) : [];
	}

	/** The stored available-from date, or '' when none (or not a real date). */
	public static function available_from( int $id ): string {
		$from = trim( (string) get_post_meta( $id, '_tdh_available_from', true ) );
		return self::is_date( $from ) ? $from : '';
	}

	/* ---------------------------------------------------------------------
	 * What a landlord typed
	 * ------------------------------------------------------------------ */

	/**
	 * Check the periods a landlord typed.
	 *
	 * @param array<int,array{from:string,to:string}> $rows As typed.
	 * @return array{ranges:array<int,array{from:string,to:string}>,refused:array<int,array{from:string,to:string,error:string}>,errors:string[],notes:string[]}
	 */
	public static function check( array $rows ): array {

		$valid   = [];
		$refused = [];
		$horizon = self::horizon();

		foreach ( $rows as $row ) {

			$from = trim( (string) ( $row['from'] ?? '' ) );
			$to   = trim( (string) ( $row['to'] ?? '' ) );

			if ( '' === $from && '' === $to ) {
				continue; // An empty row is a row nobody used.
			}

			$error = '';

			if ( '' === $from || '' === $to ) {
				$error = '' === $to
					/* translators: %s: the date given */
					? sprintf( __( 'A period starting %s has no last day. Add one — for a single day, use the same date twice.', 'thirtydayhomes' ), self::format_day( $from ) ?: $from )
					/* translators: %s: the date given */
					: sprintf( __( 'A period ending %s has no first day. Add one — for a single day, use the same date twice.', 'thirtydayhomes' ), self::format_day( $to ) ?: $to );
			} elseif ( ! self::is_date( $from ) || ! self::is_date( $to ) ) {
				/* translators: %s: what was typed */
				$error = sprintf( __( '“%s” is not a date we can read. Pick the day from the calendar.', 'thirtydayhomes' ), self::is_date( $from ) ? $to : $from );
			} elseif ( $to < $from ) {
				$error = sprintf(
					/* translators: 1: first day typed, 2: last day typed */
					__( '%1$s to %2$s ends before it starts. Put the first day on the left, the last day on the right.', 'thirtydayhomes' ),
					self::format_day( $from ),
					self::format_day( $to )
				);
			} elseif ( $to > $horizon ) {
				$error = sprintf(
					/* translators: 1: the date, 2: number of years */
					__( '%1$s is more than %2$s years ahead — check the year.', 'thirtydayhomes' ),
					self::format_day( $to ),
					number_format_i18n( self::HORIZON_YEARS )
				);
			}

			if ( '' !== $error ) {
				$refused[] = [ 'from' => $from, 'to' => $to, 'error' => $error ];
				continue;
			}

			$valid[] = [ 'from' => $from, 'to' => $to ];
		}

		$groups = self::groups( $valid );
		$notes  = [];

		foreach ( $groups as $group ) {
			if ( $group['parts'] > 1 ) {
				$notes[] = sprintf(
					/* translators: %s: the joined period, e.g. "10 – 31 Dec 2026" */
					__( 'Periods that overlapped or touched were joined into one: %s.', 'thirtydayhomes' ),
					self::format_range( $group )
				);
			}
		}

		$ranges = array_map( static fn( array $g ): array => [ 'from' => $g['from'], 'to' => $g['to'] ], $groups );

		// Past the limit: keep the earliest, name the rest.
		if ( count( $ranges ) > self::MAX_RANGES ) {
			foreach ( array_slice( $ranges, self::MAX_RANGES ) as $extra ) {
				$refused[] = [
					'from'  => $extra['from'],
					'to'    => $extra['to'],
					'error' => sprintf(
						/* translators: 1: the period, 2: the limit */
						__( '%1$s was not saved: a home can have %2$s unavailable periods. Remove one you no longer need, then add this again.', 'thirtydayhomes' ),
						self::format_range( $extra ),
						number_format_i18n( self::MAX_RANGES )
					),
				];
			}
			$ranges = array_slice( $ranges, 0, self::MAX_RANGES );
		}

		return [
			'ranges'  => $ranges,
			'refused' => $refused,
			'errors'  => array_column( $refused, 'error' ),
			'notes'   => $notes,
		];
	}

	/**
	 * Check a typed available-from date.
	 *
	 * @return array{value:?string,error:string} value '' clears it; null leaves it alone.
	 */
	public static function check_from( string $typed ): array {

		$typed = trim( $typed );

		if ( '' === $typed ) {
			return [ 'value' => '', 'error' => '' ];
		}

		if ( ! self::is_date( $typed ) ) {
			return [
				'value' => null,
				/* translators: %s: what was typed */
				'error' => sprintf( __( 'Available from: “%s” is not a date we can read. Pick the day from the calendar, or leave it blank if the home is free now.', 'thirtydayhomes' ), $typed ),
			];
		}

		if ( $typed > self::horizon() ) {
			return [
				'value' => null,
				'error' => sprintf(
					/* translators: 1: the date, 2: number of years */
					__( 'Available from: %1$s is more than %2$s years ahead — check the year.', 'thirtydayhomes' ),
					self::format_day( $typed ),
					number_format_i18n( self::HORIZON_YEARS )
				),
			];
		}

		return [ 'value' => $typed, 'error' => '' ];
	}

	/**
	 * Save what an availability form posted — the wizard's step 2 and the
	 * dashboard panel share this. Good values are stored; refused rows are
	 * kept for the form to show again, with their reasons.
	 *
	 * Fields that are not in the POST are left alone, so a page rendered
	 * before these fields existed cannot erase them.
	 *
	 * @return string[] What could not be saved, in words.
	 */
	public static function save_posted( int $id ): array {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
		$problems   = [];
		$kept_from  = null;
		$kept_rows  = [];

		if ( array_key_exists( 'tdh_available', $_POST ) ) {
			$typed = sanitize_text_field( wp_unslash( (string) $_POST['tdh_available'] ) );
			$from  = self::check_from( $typed );

			if ( '' !== $from['error'] ) {
				$problems[] = $from['error'];
				$kept_from  = $typed;
			} elseif ( '' === $from['value'] ) {
				delete_post_meta( $id, '_tdh_available_from' );
			} else {
				update_post_meta( $id, '_tdh_available_from', $from['value'] );
			}
		}

		if ( ! empty( $_POST['tdh_availability'] ) ) {
			$firsts = array_values( (array) ( $_POST['tdh_blocked_from'] ?? [] ) );
			$lasts  = array_values( (array) ( $_POST['tdh_blocked_to'] ?? [] ) );
			$rows   = [];

			for ( $i = 0, $n = max( count( $firsts ), count( $lasts ) ); $i < $n; $i++ ) {
				$rows[] = [
					'from' => sanitize_text_field( wp_unslash( (string) ( $firsts[ $i ] ?? '' ) ) ),
					'to'   => sanitize_text_field( wp_unslash( (string) ( $lasts[ $i ] ?? '' ) ) ),
				];
			}

			$checked = self::check( $rows );

			if ( $checked['ranges'] ) {
				update_post_meta( $id, self::META, self::serialise( $checked['ranges'] ) );
			} else {
				delete_post_meta( $id, self::META );
			}

			$problems  = array_merge( $problems, $checked['errors'] );
			$kept_rows = $checked['refused'];

			if ( $checked['notes'] ) {
				self::note( $id, $checked['notes'] );
			}
		}
		// phpcs:enable

		if ( null !== $kept_from || $kept_rows ) {
			self::stash( $id, $kept_from, $kept_rows );
		}

		return $problems;
	}

	/* ---------------------------------------------------------------------
	 * Typed values and notes, across the redirect
	 * ------------------------------------------------------------------ */

	/**
	 * Keep what was refused, for this home only.
	 *
	 * @param array<int,array{from:string,to:string,error:string}> $rows
	 */
	private static function stash( int $id, ?string $from, array $rows, array $errors = [], bool $replace = false ): void {
		set_transient(
			'tdh_avail_' . get_current_user_id(),
			[ 'listing' => $id, 'from' => $from, 'rows' => array_values( $rows ), 'errors' => $errors, 'replace' => $replace ],
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * What was refused for this home, once.
	 *
	 * `replace` is true when nothing was saved at all (the page had expired):
	 * the rows kept are then the whole set as typed, shown instead of the
	 * stored periods rather than beside them.
	 *
	 * @return array{from:?string,rows:array<int,array{from:string,to:string,error:string}>,errors:string[],replace:bool}
	 */
	public static function take_kept( int $id ): array {

		$empty = [ 'from' => null, 'rows' => [], 'errors' => [], 'replace' => false ];

		if ( ! is_user_logged_in() || $id < 1 ) {
			return $empty;
		}

		$key    = 'tdh_avail_' . get_current_user_id();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) || (int) ( $stored['listing'] ?? 0 ) !== $id ) {
			return $empty;
		}

		delete_transient( $key );

		return [
			'from'    => isset( $stored['from'] ) ? (string) $stored['from'] : null,
			'rows'    => array_values( array_filter( (array) ( $stored['rows'] ?? [] ), 'is_array' ) ),
			'errors'  => array_map( 'strval', (array) ( $stored['errors'] ?? [] ) ),
			'replace' => ! empty( $stored['replace'] ),
		];
	}

	/** Remember what was changed on the landlord's behalf, to say it next page. */
	private static function note( int $id, array $notes ): void {
		set_transient( 'tdh_avail_notes_' . get_current_user_id(), [ 'listing' => $id, 'notes' => array_values( $notes ) ], 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Notes for this home, once.
	 *
	 * @return string[]
	 */
	public static function take_notes( int $id ): array {

		if ( ! is_user_logged_in() || $id < 1 ) {
			return [];
		}

		$key    = 'tdh_avail_notes_' . get_current_user_id();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) || (int) ( $stored['listing'] ?? 0 ) !== $id ) {
			return [];
		}

		delete_transient( $key );

		return array_map( 'strval', (array) ( $stored['notes'] ?? [] ) );
	}

	/* ---------------------------------------------------------------------
	 * Is it free?
	 * ------------------------------------------------------------------ */

	/**
	 * Could a stay from $start (move in) to $end (move out) happen?
	 *
	 * The move-out day is not a night in the home, so it may be the first
	 * day of a blocked period. Pure: the rule itself, with no post behind it.
	 *
	 * @param array<int,array{from:string,to:string}> $ranges
	 */
	public static function fits( array $ranges, string $available_from, int $min_stay, string $start, string $end ): bool {

		if ( ! self::is_date( $start ) || ! self::is_date( $end ) || $end <= $start ) {
			return false;
		}

		if ( $min_stay > 0 && self::days_between( $start, $end ) < $min_stay ) {
			return false;
		}

		if ( self::is_date( $available_from ) && $start < $available_from ) {
			return false;
		}

		$last_night = self::add_days( $end, -1 );

		foreach ( $ranges as $r ) {
			if ( $r['from'] <= $last_night && $r['to'] >= $start ) {
				return false;
			}
		}

		return true;
	}

	/** fits(), for a home. */
	public static function is_free( int $id, string $start, string $end ): bool {
		return self::fits( self::ranges( $id ), self::available_from( $id ), self::min_stay( $id ), $start, $end );
	}

	/** The minimum stay in nights, 0 when none is set. */
	public static function min_stay( int $id ): int {
		return max( 0, (int) get_post_meta( $id, '_tdh_min_stay_days', true ) );
	}

	/**
	 * The first day a renter could move in for the minimum stay — from today
	 * or available-from, whichever is later, skipping taken periods and gaps
	 * too short for the minimum stay. '' when nothing fits within the
	 * horizon.
	 *
	 * @param array<int,array{from:string,to:string}> $ranges
	 */
	public static function first_move_in( array $ranges, string $available_from, int $min_stay, ?string $today = null ): string {

		$today   = $today ?? self::today();
		$day     = self::is_date( $available_from ) && $available_from > $today ? $available_from : $today;
		$nights  = max( 1, $min_stay );
		$horizon = self::day( $today )->modify( '+' . self::HORIZON_YEARS . ' years' )->format( 'Y-m-d' );
		$ranges  = self::merge( $ranges );

		// Each pass either answers or jumps past a whole taken period, so the
		// loop runs at most once per period.
		for ( $guard = 0; $guard <= count( $ranges ) + 1; $guard++ ) {

			if ( $day > $horizon ) {
				return '';
			}

			$last_night = self::add_days( $day, $nights - 1 );
			$clash      = null;

			foreach ( $ranges as $r ) {
				if ( $r['from'] <= $last_night && $r['to'] >= $day ) {
					$clash = $r;
					break;
				}
			}

			if ( ! $clash ) {
				return $day;
			}

			$day = self::add_days( $clash['to'], 1 );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * In words, and as a calendar
	 * ------------------------------------------------------------------ */

	/**
	 * What renters are told.
	 *
	 * @return array{move_in:string,now:bool,headline:string,short:string,upcoming:string[],past:string[]}
	 */
	public static function summary( int $id ): array {

		$today   = self::today();
		$ranges  = self::ranges( $id );
		$move_in = self::first_move_in( $ranges, self::available_from( $id ), self::min_stay( $id ), $today );
		$now     = $move_in === $today;

		if ( '' === $move_in ) {
			$headline = __( 'No open dates right now', 'thirtydayhomes' );
			$short    = $headline;
		} elseif ( $now ) {
			$headline = __( 'Available now', 'thirtydayhomes' );
			$short    = $headline;
		} else {
			/* translators: %s: date, e.g. "11 Nov 2026" */
			$headline = sprintf( __( 'Available from %s', 'thirtydayhomes' ), self::format_day( $move_in ) );
			/* translators: %s: day and month, e.g. "11 Nov" */
			$short = sprintf( __( 'Available %s', 'thirtydayhomes' ), (string) wp_date( 'j M', self::day( $move_in )->getTimestamp(), new \DateTimeZone( 'UTC' ) ) );
		}

		$upcoming = [];
		$past     = [];

		foreach ( $ranges as $r ) {
			if ( $r['to'] < $today ) {
				$past[] = self::format_range( $r );
			} else {
				$upcoming[] = self::format_range( $r );
			}
		}

		return [
			'move_in'  => $move_in,
			'now'      => $now,
			'headline' => $headline,
			'short'    => $short,
			'upcoming' => $upcoming,
			'past'     => $past,
		];
	}

	/**
	 * The weekday headings, starting on the site's first day of the week.
	 *
	 * @return array<int,array{short:string,long:string}>
	 */
	public static function weekdays(): array {

		global $wp_locale;

		$start = (int) get_option( 'start_of_week', 0 );
		$days  = [];

		for ( $i = 0; $i < 7; $i++ ) {
			$n    = ( $start + $i ) % 7;
			$long = $wp_locale ? (string) $wp_locale->get_weekday( $n ) : gmdate( 'l', strtotime( "Sunday +{$n} days" ) );

			// "Sun", not "S": two S's and two T's in one row read as a puzzle.
			$days[] = [
				'short' => $wp_locale ? (string) $wp_locale->get_weekday_abbrev( $long ) : mb_substr( $long, 0, 3 ),
				'long'  => $long,
			];
		}

		return $days;
	}

	/**
	 * Months for the property page, from the month the home can first be
	 * lived in (never before this month). Each day says what it is:
	 *   past         before today
	 *   unavailable  before available-from, or inside a taken period
	 *   free         otherwise
	 *
	 * @return array<int,array{key:string,label:string,weeks:array<int,array<int,?array{day:int,date:string,state:string,today:bool}>>}>
	 */
	public static function months( int $id, int $count = self::CALENDAR_MONTHS ): array {

		$today  = self::today();
		$from   = self::available_from( $id );
		$ranges = self::ranges( $id );
		$start  = '' !== $from && $from > $today ? $from : $today;
		$first  = self::day( substr( $start, 0, 7 ) . '-01' );
		$week   = (int) get_option( 'start_of_week', 0 );
		$utc    = new \DateTimeZone( 'UTC' );
		$months = [];

		for ( $m = 0; $m < max( 1, $count ); $m++ ) {

			$month = $first->modify( "+{$m} months" );
			$days  = (int) $month->format( 't' );
			$lead  = ( (int) $month->format( 'w' ) - $week + 7 ) % 7;
			$cells = array_fill( 0, $lead, null );

			for ( $d = 1; $d <= $days; $d++ ) {
				$date = $month->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );

				if ( $date < $today ) {
					$state = 'past';
				} elseif ( ( '' !== $from && $date < $from ) || self::inside( $ranges, $date ) ) {
					$state = 'unavailable';
				} else {
					$state = 'free';
				}

				$cells[] = [ 'day' => $d, 'date' => $date, 'state' => $state, 'today' => $date === $today ];
			}

			while ( count( $cells ) % 7 ) {
				$cells[] = null;
			}

			$months[] = [
				'key'   => $month->format( 'Y-m' ),
				'label' => (string) wp_date( 'F Y', $month->getTimestamp(), $utc ),
				'weeks' => array_chunk( $cells, 7 ),
			];
		}

		return $months;
	}

	/** Is this day inside one of the periods? */
	private static function inside( array $ranges, string $date ): bool {
		foreach ( $ranges as $r ) {
			if ( $r['from'] <= $date && $r['to'] >= $date ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * The dashboard's quick edit
	 * ------------------------------------------------------------------ */

	/**
	 * Save availability from the panel on My listings — no wizard needed to
	 * block a month. Owner or staff, like every dashboard action; someone
	 * else's home gets the answer a missing one gets.
	 */
	public function handle(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, before anything is stored.
		if ( 'listing_availability' !== sanitize_key( wp_unslash( (string) ( $_POST['tdh_action'] ?? '' ) ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Accounts::url( 'login' ) );
			exit;
		}

		$id      = (int) ( $_POST['tdh_listing'] ?? 0 );
		$context = 'overview' === sanitize_key( wp_unslash( (string) ( $_POST['tdh_return'] ?? '' ) ) ) ? 'overview' : 'listings';
		$page    = max( 1, (int) ( $_POST['tdh_page'] ?? 1 ) );
		$listing = Listing_Actions::find( $id );

		if ( ! $listing || 'trash' === $listing->post_status ) {
			self::back( $context, $page, [ 'tdh_done' => 'missing' ] );
		}

		if ( ! isset( $_POST['tdh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tdh_nonce'] ) ), self::NONCE ) ) {
			// Nothing saved — but nothing typed is lost either.
			$rows = [];
			foreach ( array_values( (array) ( $_POST['tdh_blocked_from'] ?? [] ) ) as $i => $first ) {
				$rows[] = [
					'from'  => sanitize_text_field( wp_unslash( (string) $first ) ),
					'to'    => sanitize_text_field( wp_unslash( (string) ( ( (array) ( $_POST['tdh_blocked_to'] ?? [] ) )[ $i ] ?? '' ) ) ),
					'error' => '',
				];
			}
			$rows = array_values( array_filter( $rows, static fn( array $r ): bool => '' !== $r['from'] || '' !== $r['to'] ) );

			self::stash(
				(int) $listing->ID,
				sanitize_text_field( wp_unslash( (string) ( $_POST['tdh_available'] ?? '' ) ) ),
				$rows,
				[ __( 'That page was open too long, so nothing was saved. Your dates are still here — press Save availability again.', 'thirtydayhomes' ) ],
				true
			);

			self::back( $context, $page, [ 'availability' => $listing->ID ], (int) $listing->ID );
		}
		// phpcs:enable

		$problems = self::save_posted( (int) $listing->ID );

		/**
		 * Fires when a home's availability is saved from the dashboard.
		 *
		 * @param int $listing_id
		 */
		do_action( 'tdh_listing_availability_saved', (int) $listing->ID );

		if ( $problems ) {
			// The good periods are saved; the panel opens again with the rest.
			$kept = self::take_kept( (int) $listing->ID );
			self::stash( (int) $listing->ID, $kept['from'], $kept['rows'], array_merge( $problems, [ __( 'Everything else is saved.', 'thirtydayhomes' ) ] ) );
			self::back( $context, $page, [ 'availability' => $listing->ID ], (int) $listing->ID );
		}

		// No jump to the row: the page opens at the top, where the message
		// saying what was saved is — as after Pause, Resume and Delete. (It
		// jumped to the row once, and the message sat unseen above it.)
		self::back( $context, $page, [ 'tdh_done' => 'availability', 'tdh_home' => $listing->ID ] );
	}

	/**
	 * Back to the list the panel was opened from.
	 *
	 * @param array<string,int|string> $args
	 */
	private static function back( string $context, int $page, array $args, int $anchor = 0 ): void {

		$url = Listing_Manage_Render::list_url( $context, $page );

		if ( Accounts::is_staff() ) {
			$url = add_query_arg( 'view', 'listings', Accounts::url( 'account' ) );
		}

		$url = add_query_arg( $args, $url );

		wp_safe_redirect( $anchor > 0 ? $url . '#listing-' . $anchor : $url );
		exit;
	}
}
