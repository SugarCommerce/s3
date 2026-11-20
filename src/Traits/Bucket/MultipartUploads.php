<?php
/**
 * S3's multipart upload, one call at a time.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use InvalidArgumentException;
use SugarCommerce\S3\Method;
use SugarCommerce\S3\Support\Xml;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait MultipartUploads
 *
 * S3's multipart upload, one call at a time.
 *
 * Start, sign parts for a browser, send a part from here, list what storage
 * kept, finish or abandon -- and find and clear the uploads nobody finished,
 * whose parts are billed and invisible to an object listing.
 *
 * @since 1.0.0
 */
trait MultipartUploads {

	/**
	 * Open a multipart upload and return its id.
	 *
	 * Server-side, because the id only exists once storage has answered and
	 * every part needs it. Parsed with a pattern rather than an XML parser:
	 * one field, from a contract that has not changed in fifteen years.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $mime_type Content-Type to store.
	 *
	 * @return string|WP_Error The upload id, or why storage refused: a rolled
	 *                         secret, a revoked key and a missing bucket are
	 *                         three different fixes.
	 */
	public function start_upload( string $key, string $mime_type = 'application/octet-stream' ): string|WP_Error {
		$request  = $this->signer->sign_create_multipart_upload( $this->name, $key, $mime_type );
		$response = Transport::request( 'POST', $request->url, $request->headers, '' );
		$upload   = Transport::is_success( $response['status'] ) ? self::field( $response['body'], 'UploadId' ) : '';

		return '' === $upload ? self::refusal( 'start_upload', $response ) : $upload;
	}

	/**
	 * Presigned URLs for a batch of parts, for the browser to PUT to.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $key       Object key.
	 * @param string          $upload_id Multipart upload id.
	 * @param array<int, int> $parts     Part numbers, 1-based.
	 * @param int             $expires   Seconds each URL works for.
	 *
	 * @return array<int, string> Part number => URL. Numbers out of range are
	 *                            left out.
	 */
	public function sign_parts( string $key, string $upload_id, array $parts, int $expires = 3600 ): array {
		$urls = array();

		foreach ( $parts as $number ) {
			$number = (int) $number;

			if ( $number >= 1 && $number <= 10000 ) {
				$urls[ $number ] = $this->signer->presign_upload_part( $this->name, $key, $upload_id, $number, $expires );
			}
		}

		return $urls;
	}

	/**
	 * Finish a multipart upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                           $key       Object key.
	 * @param string                                           $upload_id Multipart upload id.
	 * @param array<int, array{PartNumber: int, ETag: string}> $parts     The parts, with their ETags.
	 *
	 * @return true|WP_Error An error on any refusal -- including a 200 carrying
	 *                       an error document, which is how S3 reports a
	 *                       failure it found after it had started answering.
	 *                       Abort the upload then, or its parts stay and are
	 *                       billed.
	 */
	public function finish_upload( string $key, string $upload_id, array $parts ): true|WP_Error {
		if ( array() === $parts ) {
			return new WP_Error( 's3_finish_upload_failed', 'A multipart upload cannot be finished with no parts.' );
		}

		$request  = $this->signer->sign_complete_multipart_upload( $this->name, $key, $upload_id, $parts );
		$response = Transport::request( 'POST', $request->url, $request->headers, $request->body );

		return self::confirmed( $response ) ? true : self::refusal( 'finish_upload', $response );
	}

	/**
	 * Abandon a multipart upload and free its parts.
	 *
	 * An upload started and never finished keeps every part it received, and
	 * they are billed, until this is called or a lifecycle rule clears them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return bool False means the parts are still in storage, still billed.
	 */
	public function abort_upload( string $key, string $upload_id ): bool {
		$request = $this->signer->sign_abort_multipart_upload( $this->name, $key, $upload_id );

		return Transport::is_success( Transport::request( 'DELETE', $request->url, $request->headers )['status'] );
	}

	/**
	 * Every part storage holds for an unfinished upload, in order.
	 *
	 * What resuming needs. A browser that reloads halfway through a 6 GB
	 * upload remembers which parts it sent, but storage decides what counts:
	 * a part the browser saw finish may not have been kept, and one it never
	 * heard back about may have been. So the browser's list is a hint and this
	 * is the answer. Followed page by page, a thousand parts at a time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return array<int, array{PartNumber: int, ETag: string, Size: int}>|null
	 *         Null when storage would not say -- most often because the upload
	 *         was finished or aborted and no longer exists, which a caller
	 *         should treat as "start again", not as "nothing sent yet".
	 */
	public function parts( string $key, string $upload_id ): ?array {
		$parts  = array();
		$marker = 0;

		for ( $page = 0; $page < self::PARTS_PAGES; $page++ ) {
			$request  = $this->signer->sign_list_parts( $this->name, $key, $upload_id, self::PARTS_PAGE, $marker );
			$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 8388608 ) );

			// The 200-with-an-error-document case again: an answer is only a
			// listing if it says it is one.
			if ( ! Transport::is_success( $response['status'] ) || ! str_contains( $response['body'], '<ListPartsResult' ) ) {
				return null;
			}

			$listing = self::parse_list_parts( $response['body'] );
			$parts   = array_merge( $parts, $listing['parts'] );

			if ( ! $listing['more'] || $listing['next'] <= $marker ) {
				return $parts;
			}

			$marker = $listing['next'];
		}

		return null;
	}

	/**
	 * Read a ListPartsResult document.
	 *
	 * Patterns rather than an XML parser, the way an upload id is read: a
	 * handful of fields, from a contract that has not changed in fifteen
	 * years, and no entity expansion to think about. ETags come back without
	 * their quotes, ready to hand to finish_upload().
	 *
	 * @since 1.0.0
	 *
	 * @param string $xml Response body.
	 *
	 * @return array{parts: array<int, array{PartNumber: int, ETag: string, Size: int}>, more: bool, next: int}
	 */
	public static function parse_list_parts( string $xml ): array {
		$parts = array();

		preg_match_all( '#<Part>(.*?)</Part>#s', $xml, $blocks );

		foreach ( $blocks[1] as $block ) {
			$number = (int) self::field( $block, 'PartNumber' );
			$etag   = trim( html_entity_decode( self::field( $block, 'ETag' ), ENT_QUOTES | ENT_XML1 ), '"' );

			if ( $number >= 1 && '' !== $etag ) {
				$parts[] = array(
					'PartNumber' => $number,
					'ETag'       => $etag,
					'Size'       => (int) self::field( $block, 'Size' ),
				);
			}
		}

		usort( $parts, static fn( array $a, array $b ): int => $a['PartNumber'] <=> $b['PartNumber'] );

		return array(
			'parts' => $parts,
			'more'  => 'true' === strtolower( self::field( $xml, 'IsTruncated' ) ),
			'next'  => (int) self::field( $xml, 'NextPartNumberMarker' ),
		);
	}

	/**
	 * Send one part of a multipart upload from this server.
	 *
	 * Signed over the part's own SHA-256, with its Content-MD5 alongside, so
	 * storage checks the bytes twice and refuses a part that changed on the
	 * way (XAmzContentSHA256Mismatch, BadDigest) rather than keeping it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 * @param int    $number    Part number, 1 to MAX_PARTS.
	 * @param string $bytes     The part.
	 * @param int    $timeout   Seconds to allow. Default 300.
	 *
	 * @return string|WP_Error The part's ETag, unquoted. `s3_upload_part_failed`
	 *                         on a refusal, or a part answered without one.
	 *
	 * @throws InvalidArgumentException On a part number S3 would refuse.
	 */
	public function upload_part( string $key, string $upload_id, int $number, string $bytes, int $timeout = 300 ): string|WP_Error {
		if ( $number < 1 || $number > self::MAX_PARTS ) {
			throw new InvalidArgumentException( sprintf( 'Not a part number: %d.', $number ) );
		}

		$request  = $this->signer->sign(
			Method::PUT,
			$this->name,
			$key,
			array(
				'partNumber' => (string) $number,
				'uploadId'   => $upload_id,
			),
			$bytes,
			array( 'content-md5' => self::md5( $bytes ) )
		);
		$response = Transport::request( 'PUT', $request->url, $request->headers, $bytes, array( 'timeout' => $timeout ) );
		$etag     = trim( Transport::header( $response, 'etag' ), "\" \t" );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::refusal( 'upload_part', $response );
		}

		// A part without an ETag cannot be assembled.
		return '' === $etag
			? new WP_Error( 's3_upload_part_failed', 'Storage kept the part but did not say its ETag.', array( 'status' => $response['status'] ) )
			: $etag;
	}

	/**
	 * Every multipart upload started in the bucket and neither finished nor
	 * aborted, oldest page first.
	 *
	 * ListMultipartUploads, followed page by page. Their parts are billed
	 * and invisible to an object listing, so this is the only way to find
	 * them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Only uploads of keys starting with this.
	 *
	 * @return array<int, array{key: string, upload_id: string, initiated: string}>|WP_Error
	 *         `s3_uploads_failed` with storage's code in its data.
	 */
	public function uploads( string $prefix = '' ): array|WP_Error {
		$uploads   = array();
		$key       = '';
		$upload_id = '';

		for ( $page = 0; $page < self::UPLOADS_PAGES; $page++ ) {
			$request  = $this->signer->sign_list_multipart_uploads( $this->name, $prefix, $key, $upload_id, self::UPLOADS_PAGE );
			$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 8388608 ) );
			$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

			// An answer is only a listing if it says it is one.
			if ( null === $document || 'ListMultipartUploadsResult' !== $document->getName() ) {
				return self::refusal( 'uploads', $response );
			}

			foreach ( Xml::children( $document, 'Upload' ) as $node ) {
				$entry = array(
					'key'       => Xml::text( $node, 'Key' ),
					'upload_id' => Xml::text( $node, 'UploadId' ),
					'initiated' => Xml::text( $node, 'Initiated' ),
				);

				if ( '' !== $entry['key'] && '' !== $entry['upload_id'] ) {
					$uploads[] = $entry;
				}
			}

			$next_key = Xml::text( $document, 'NextKeyMarker' );
			$next_id  = Xml::text( $document, 'NextUploadIdMarker' );

			// A page that names no way on, or the same way as last time,
			// would only be asked for again.
			if ( 'true' !== strtolower( Xml::text( $document, 'IsTruncated' ) ) || '' === $next_key || ( $next_key === $key && $next_id === $upload_id ) ) {
				break;
			}

			$key       = $next_key;
			$upload_id = $next_id;
		}

		return $uploads;
	}

	/**
	 * Abort every unfinished multipart upload older than some days.
	 *
	 * What a provider's AbortIncompleteMultipartUpload lifecycle rule does,
	 * for a bucket that has none -- or keys that may not set one. An upload
	 * a browser or a job is still sending is days younger than this, so the
	 * age is the whole test.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $days   Abort uploads started more than this many days
	 *                       ago. At least 1.
	 * @param string $prefix Only uploads of keys starting with this.
	 * @param int    $now    The time to measure from. Default now.
	 *
	 * @return array{aborted: array<int, array{key: string, upload_id: string, initiated: string}>, failed: array<int, array{key: string, upload_id: string, initiated: string}>}|WP_Error
	 *         An error when the uploads could not be listed.
	 */
	public function abort_stale( int $days, string $prefix = '', ?int $now = null ): array|WP_Error {
		$uploads = $this->uploads( $prefix );

		if ( $uploads instanceof WP_Error ) {
			return $uploads;
		}

		$before = ( $now ?? time() ) - max( 1, $days ) * 86400;
		$result = array(
			'aborted' => array(),
			'failed'  => array(),
		);

		foreach ( $uploads as $upload ) {
			$started = strtotime( $upload['initiated'] );

			// A time that does not parse is not old enough to throw away.
			if ( false === $started || $started > $before ) {
				continue;
			}

			$result[ $this->abort_upload( $upload['key'], $upload['upload_id'] ) ? 'aborted' : 'failed' ][] = $upload;
		}

		return $result;
	}
}
