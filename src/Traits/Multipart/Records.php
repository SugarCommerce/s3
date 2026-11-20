<?php
/**
 * Where uploads are remembered between requests, and held against a second sender.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Multipart;

use SugarCommerce\S3\Models\Upload;
use SugarCommerce\S3\Utils\Runtime;

/**
 * Trait Records
 *
 * Where uploads are remembered between requests, and held against a second sender.
 *
 * One non-autoloaded option an upload, named from the build's prefix, and
 * a second option as the hold one send() takes -- add_option() refusing a row
 * that is there is the test, so two requests cannot send the same parts.
 *
 * @since 1.0.0
 */
trait Records {

	/**
	 * A stored upload to this bucket, by its id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id What begin() gave it.
	 *
	 * @return Upload|null Null when there is none -- finished, aborted,
	 *                     pruned, never begun, or begun for another bucket.
	 */
	public function find( string $id ): ?Upload {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		$upload = Upload::from_array( get_option( self::option( $id ) ) );

		return null !== $upload && $upload->id === $id && $upload->bucket === $this->bucket->name() ? $upload : null;
	}

	/**
	 * Every stored upload to this bucket, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Upload>
	 */
	public function pending(): array {
		global $wpdb;

		// Finding options by name has no API; nothing is cached, because the
		// answer changes with every part.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::option( '' ) ) . '%' ) );
		$uploads = array();

		foreach ( $names as $name ) {
			$upload = $this->find( substr( (string) $name, strlen( self::option( '' ) ) ) );

			if ( null !== $upload ) {
				$uploads[] = $upload;
			}
		}

		usort( $uploads, static fn( Upload $a, Upload $b ): int => $a->started <=> $b->started );

		return $uploads;
	}

	/**
	 * The unfinished upload of this same file, if there is one, to resume.
	 *
	 * Same key, same size, same fingerprint and the same owner: a file
	 * dropped into the browser again after a reload, or the same file handed
	 * to a job twice. An empty fingerprint never matches, since then there
	 * is nothing to say it is the same file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key         Object key.
	 * @param int    $size        Bytes in the file.
	 * @param string $fingerprint What identifies the file to its sender.
	 * @param int    $owner       The user asking.
	 *
	 * @return Upload|null The newest match.
	 */
	public function match( string $key, int $size, string $fingerprint, int $owner = 0 ): ?Upload {
		if ( '' === $fingerprint ) {
			return null;
		}

		$found = null;

		foreach ( $this->pending() as $upload ) {
			if ( $upload->key === $key && $upload->size === $size && $upload->fingerprint === substr( $fingerprint, 0, 255 ) && $upload->owner === $owner ) {
				$found = $upload;
			}
		}

		return $found;
	}

	/**
	 * Remember an upload.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function save( Upload $upload ): void {
		update_option( self::option( $upload->id ), $upload->to_array(), false );
	}

	/**
	 * Forget an upload, and any hold on it.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function forget( Upload $upload ): void {
		delete_option( self::option( $upload->id ) );
	}

	/**
	 * Hold an upload for one send(), unless another holds it.
	 *
	 * The insert is the test: add_option() fails when the row is there. A
	 * hold past its time is from a request that died, and is taken over.
	 *
	 * @param Upload $upload  The upload.
	 * @param int    $seconds The send's time limit.
	 *
	 * @return bool
	 */
	private function lock( Upload $upload, int $seconds ): bool {
		$name  = self::lock_option( $upload->id );
		$until = time() + max( 0, $seconds ) + self::LOCK_GRACE;

		if ( add_option( $name, $until, '', false ) ) {
			return true;
		}

		if ( (int) get_option( $name, 0 ) >= time() ) {
			return false;
		}

		delete_option( $name );

		return add_option( $name, $until, '', false );
	}

	/**
	 * Let go of an upload.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function unlock( Upload $upload ): void {
		delete_option( self::lock_option( $upload->id ) );
	}

	/**
	 * Where an upload is stored.
	 *
	 * @param string $id Its id.
	 *
	 * @return string
	 */
	private static function option( string $id ): string {
		return Runtime::key( self::OPTION . $id );
	}

	/**
	 * Where an upload's hold is stored. Not under option(), so pending()
	 * does not read it as an upload.
	 *
	 * @param string $id Its id.
	 *
	 * @return string
	 */
	private static function lock_option( string $id ): string {
		return Runtime::key( 'upload-lock_' . $id );
	}
}
