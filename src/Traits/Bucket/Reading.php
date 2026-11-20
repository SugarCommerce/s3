<?php
/**
 * What an object is, and some of its bytes, without fetching it whole.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use InvalidArgumentException;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait Reading
 *
 * What an object is, and some of its bytes, without fetching it whole.
 *
 * @since 1.0.0
 */
trait Reading {

	/**
	 * What the bucket says about an object now: its ETag, whole length and
	 * last modification.
	 *
	 * A one-byte ranged read of a presigned URL -- the URL is signed for GET,
	 * so a HEAD would not verify -- whose headers carry all three. For telling
	 * whether an object somebody else can write to is still the one you
	 * checked: an overwrite changes the length or the time whatever it does
	 * to the ETag, so compare all three.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return array{etag: string, size: int, modified: string}|null Null when
	 *         storage would not answer, or did not say all of it: without a
	 *         Content-Range there is no length, and without an ETag nothing to
	 *         compare.
	 */
	public function stat( string $key ): ?array {
		$response = $this->first_byte( $key );

		if ( ! Transport::is_success( $response['status'] ) || 1 !== preg_match( '#/(\d+)$#', Transport::header( $response, 'content-range' ), $total ) ) {
			return null;
		}

		$etag = trim( Transport::header( $response, 'etag' ), "\" \t" );

		if ( '' === $etag ) {
			return null;
		}

		return array(
			'etag'     => $etag,
			'size'     => (int) $total[1],
			'modified' => Transport::header( $response, 'last-modified' ),
		);
	}

	/**
	 * How many bytes the bucket holds for an object.
	 *
	 * A one-byte ranged read, whose Content-Range carries the total.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return int|null Bytes, or null when storage would not say -- including
	 *                  when it ignored the range for an object bigger than
	 *                  STAT_BYTES, which is cut off rather than read whole.
	 */
	public function size( string $key ): ?int {
		$response = $this->first_byte( $key );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return null;
		}

		if ( 1 === preg_match( '#/(\d+)$#', Transport::header( $response, 'content-range' ), $matches ) ) {
			return (int) $matches[1];
		}

		// A 200 means the range was ignored and the whole object came back --
		// believable only when it was all there was.
		if ( 200 !== $response['status'] ) {
			return null;
		}

		$length = Transport::header( $response, 'content-length' );

		return '' === $length || (int) $length === strlen( $response['body'] ) ? strlen( $response['body'] ) : null;
	}

	/**
	 * Some bytes of an object.
	 *
	 * Never takes in more than it asked for. A backwards or negative range is
	 * refused before any request -- storage answers a range it cannot parse
	 * with the whole object -- and the answer is cut off in transit past the
	 * range length. A server that ignores the range and sends the whole
	 * object is only believed when the whole object fit under that cap.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Object key.
	 * @param int    $from First byte.
	 * @param int    $to   Last byte, inclusive.
	 *
	 * @return string|WP_Error The bytes. An error rather than '' when storage
	 *                         did not answer, because an empty read and an
	 *                         empty file look the same afterwards.
	 *
	 * @throws InvalidArgumentException On a range that is not one.
	 */
	public function read( string $key, int $from, int $to ): string|WP_Error {
		if ( $from < 0 || $to < $from || PHP_INT_MAX === $to - $from ) {
			throw new InvalidArgumentException( sprintf( 'Not a byte range: %d-%d.', $from, $to ) );
		}

		$length   = $to - $from + 1;
		$response = Transport::request(
			'GET',
			$this->url( $key, 60 ),
			array( 'Range' => sprintf( 'bytes=%d-%d', $from, $to ) ),
			null,
			array( 'limit_response_size' => $length )
		);

		if ( 206 === $response['status'] ) {
			return $response['body'];
		}

		if ( 200 === $response['status'] ) {
			// The range was ignored. Cut from the whole object only when the
			// whole object is what arrived; otherwise the cap cut it off, and
			// the bytes asked for are not here.
			$whole = Transport::header( $response, 'content-length' );

			if ( 0 === $from || ( '' !== $whole && (int) $whole === strlen( $response['body'] ) ) ) {
				return substr( $response['body'], $from, $length );
			}

			return new WP_Error( 's3_read_failed', 'Storage ignored the byte range and sent the whole object.', array( 'status' => 200 ) );
		}

		return self::refusal( 'read', $response );
	}

	/**
	 * One-byte ranged read, capped, for stat() and size().
	 *
	 * @param string $key Object key.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	private function first_byte( string $key ): array {
		return Transport::request(
			'GET',
			$this->url( $key, 60 ),
			array( 'Range' => 'bytes=0-0' ),
			null,
			array( 'limit_response_size' => self::STAT_BYTES )
		);
	}
}
