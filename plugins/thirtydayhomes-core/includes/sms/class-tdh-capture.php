<?php
/**
 * Texts written to a file instead of sent.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Sms;

defined( 'ABSPATH' ) || exit;

/**
 * The SMS equivalent of TDH\Mail's capture folder.
 *
 * On a local or development site with no Twilio credentials, every text —
 * a verification code, an inquiry alert — is written to a file in the
 * system temp directory, one per message. That is what lets the whole
 * landlord journey be walked on localhost: send the code, open the file,
 * type the code, see "Verified".
 *
 * Never on production. Mail::should_capture() has the same rule for the
 * same reason: quietly swallowing a real customer's message is worse than
 * any convenience. Sms::provider() only reaches for this class when that
 * rule allows it.
 */
final class Capture implements Provider {

	public static function dir(): string {
		return rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'thirtydayhomes-sms';
	}

	public function name(): string {
		return 'capture';
	}

	/**
	 * @inheritDoc
	 */
	public function send( string $to, string $body ): array {

		$dir = self::dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => sprintf( 'Could not create the capture folder %s.', $dir ),
			];
		}

		$id   = 'CAP' . wp_generate_password( 12, false, false );
		$file = $dir . DIRECTORY_SEPARATOR . gmdate( 'Ymd-His' ) . '-' . substr( $id, 3, 6 ) . '.txt';

		$written = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$file,
			implode(
				"\n",
				[
					'Date: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
					'To:   ' . $to,
					'Id:   ' . $id,
					str_repeat( '-', 60 ),
					$body,
					'',
				]
			)
		);

		if ( false === $written ) {
			return [
				'ok'    => false,
				'id'    => '',
				'error' => sprintf( 'Could not write to %s.', $file ),
			];
		}

		/**
		 * A text was captured to a file instead of sent.
		 *
		 * @param string $to   E.164.
		 * @param string $file Where it went.
		 */
		do_action( 'tdh_sms_captured', $to, $file );

		return [
			'ok'    => true,
			'id'    => $id,
			'error' => '',
		];
	}

	/**
	 * The most recent captured text, for tests and the walk.
	 *
	 * @return array{path:string,body:string,to:string}|null
	 */
	public static function latest(): ?array {

		$files = glob( self::dir() . DIRECTORY_SEPARATOR . '*.txt' );

		if ( ! $files ) {
			return null;
		}

		usort( $files, static fn( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );

		$body = (string) file_get_contents( $files[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$to   = '';

		if ( preg_match( '/^To:\s+(\S+)/m', $body, $m ) ) {
			$to = $m[1];
		}

		return [
			'path' => $files[0],
			'body' => $body,
			'to'   => $to,
		];
	}
}
