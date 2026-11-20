<?php
/**
 * Presigned URLs for the browser: download, play inline, cache, upload.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Signer;

use InvalidArgumentException;
use SugarCommerce\S3\Models\Method;

/**
 * Trait Presigning
 *
 * Presigned URLs for the browser: download, play inline, cache, upload.
 *
 * Each is presign() with the query an S3 operation needs. The signature is
 * in the query string, so the URL can be handed to a browser and the bytes
 * never pass through PHP.
 *
 * @since 1.0.0
 */
trait Presigning {

	/**
	 * Mint a presigned GET URL.
	 *
	 * Optionally override the filename the browser saves as, without
	 * touching the stored object — S3 and R2 both honour
	 * `response-content-disposition` on a presigned GET. That lets you
	 * store opaque keys (`files/8a3f2c.zip`) and still deliver
	 * `Drum Kit Vol. 2.zip`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket            Bucket name.
	 * @param string $key               Object key. Leading slash optional.
	 * @param int    $expires_seconds   Validity window from now. Default 60.
	 * @param string $download_filename Filename to force. '' leaves the
	 *                                  browser to infer it from the key.
	 *
	 * @return string Fully-qualified HTTPS URL.
	 */
	public function presign_get( string $bucket, string $key, int $expires_seconds = 60, string $download_filename = '' ): string {
		$query = array();

		if ( '' !== $download_filename ) {
			$query['response-content-disposition'] = self::content_disposition( $download_filename );
		}

		return $this->presign( Method::GET, $bucket, $key, $query, $expires_seconds );
	}

	/**
	 * Mint a presigned GET URL that plays or shows in the browser.
	 *
	 * The counterpart to presign_get() with a filename: that one makes the
	 * browser save the object, this one makes it render it. An `<audio>` or
	 * `<video>` element needs a content type it recognises, and an object
	 * uploaded as `application/octet-stream` has none -- so the type is set on
	 * the response rather than on the object, the same way the filename is.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket          Bucket name.
	 * @param string $key             Object key.
	 * @param int    $expires_seconds Validity window. Long enough for the range
	 *                                requests a media element makes while it
	 *                                plays and seeks.
	 * @param string $content_type    What to call it, e.g. `audio/mpeg`. Never
	 *                                one a visitor chose: `text/html` served
	 *                                inline from the bucket's origin is a page
	 *                                on that origin.
	 *
	 * @return string Fully-qualified HTTPS URL.
	 *
	 * @throws InvalidArgumentException On a content type with a line break in
	 *                                   it, or as presign() does.
	 */
	public function presign_inline( string $bucket, string $key, int $expires_seconds, string $content_type ): string {
		self::assert_header_value( $content_type );

		return $this->presign(
			Method::GET,
			$bucket,
			$key,
			array(
				'response-content-type'        => $content_type,
				'response-content-disposition' => 'inline',
			),
			$expires_seconds
		);
	}

	/**
	 * Mint an inline GET URL that stays the same for a while, so a browser
	 * can cache what it fetched.
	 *
	 * A presigned URL normally carries the second it was signed, so every
	 * page load mints a new one and a browser can never reuse what it fetched
	 * last time -- each view is another billable read. Signed at the start of
	 * the current window instead, the URL is identical for the whole window,
	 * and the response says it may be kept that long.
	 *
	 * Valid for two windows from the start of this one, so a copy fetched in
	 * the last second of the window is still fetchable for a full window
	 * after -- and never cached past the moment the URL stops working.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $bucket       Bucket name.
	 * @param string   $key          Object key.
	 * @param int      $window       Seconds the URL stays the same, at most
	 *                               half of MAX_EXPIRES.
	 * @param string   $content_type What to call it, e.g. `image/png`.
	 * @param int|null $now          Unix time, for tests. Defaults to the
	 *                               signer's fixed time, then the clock.
	 *
	 * @return string Fully-qualified HTTPS URL.
	 *
	 * @throws InvalidArgumentException On a window outside 1 second to half of
	 *                                   MAX_EXPIRES, a content type with a line
	 *                                   break in it, or as presign() does.
	 */
	public function presign_cacheable( string $bucket, string $key, int $window, string $content_type, ?int $now = null ): string {
		if ( $window < 1 || 2 * $window > self::MAX_EXPIRES ) {
			throw new InvalidArgumentException( 'A cacheable URL window must be between 1 second and half of seven days.' );
		}

		self::assert_header_value( $content_type );

		$now    = $now ?? $this->timestamp ?? time();
		$signer = new self(
			$this->access_key,
			$this->secret_key,
			$this->endpoint,
			$this->region,
			intdiv( $now, $window ) * $window,
			$this->addressing
		);

		return $signer->presign(
			Method::GET,
			$bucket,
			$key,
			array(
				'response-content-type'        => $content_type,
				'response-content-disposition' => 'inline',
				'response-cache-control'       => 'private, max-age=' . $window,
			),
			2 * $window
		);
	}

	/**
	 * Mint a presigned PUT URL for a direct browser-to-storage upload.
	 *
	 * The bytes never pass through PHP, so upload size is bounded by the
	 * storage provider rather than by `post_max_size`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket          Bucket name.
	 * @param string $key             Object key to create.
	 * @param int    $expires_seconds Validity window. Default 3600.
	 *
	 * @return string Fully-qualified HTTPS URL to PUT to.
	 */
	public function presign_put( string $bucket, string $key, int $expires_seconds = 3600 ): string {
		return $this->presign( Method::PUT, $bucket, $key, array(), $expires_seconds );
	}

	/**
	 * Mint a presigned PUT URL for one part of a multipart upload.
	 *
	 * The client PUTs the chunk and keeps the `ETag` from the response;
	 * those ETags are then passed to
	 * {@see sign_complete_multipart_upload()} to assemble the object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket          Bucket name.
	 * @param string $key             Object key being assembled.
	 * @param string $upload_id       Upload id from the create call.
	 * @param int    $part_number     1-based part index (1–10000 per the S3 spec).
	 * @param int    $expires_seconds Validity window. Default 3600.
	 *
	 * @return string Fully-qualified HTTPS URL to PUT the chunk to.
	 */
	public function presign_upload_part( string $bucket, string $key, string $upload_id, int $part_number, int $expires_seconds = 3600 ): string {
		return $this->presign(
			Method::PUT,
			$bucket,
			$key,
			array(
				'partNumber' => (string) $part_number,
				'uploadId'   => $upload_id,
			),
			$expires_seconds
		);
	}
}
