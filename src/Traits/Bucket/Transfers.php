<?php
/**
 * Files between this server and the bucket.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use SugarCommerce\S3\Address;
use SugarCommerce\S3\Method;
use SugarCommerce\S3\Signing\SignedRequest;
use SugarCommerce\S3\Transport;
use Throwable;
use WP_Error;

/**
 * Trait Transfers
 *
 * Files between this server and the bucket.
 *
 * A file up from disk -- in one PUT, or in checksummed parts read one at a
 * time -- an object down to disk as it arrives, a small object from memory,
 * and a HEAD for what an object says it is.
 *
 * @since 1.0.0
 */
trait Transfers {

	/**
	 * Put a file from this server into the bucket.
	 *
	 * A file no larger than one part goes in a single PUT; a larger one as
	 * S3's multipart upload, read from disk one part at a time, so a file of
	 * many gigabytes is never in memory whole. A part refused, a part that
	 * came back without an ETag, or an assembly the provider will not finish
	 * -- including one it refuses inside a 200 -- abandons the upload at the
	 * provider, so half a file is not left costing storage.
	 *
	 * Arguments:
	 *
	 *   - `part_size`  Bytes a part (default PART_SIZE, never less than
	 *                  MIN_PART_SIZE, and raised by part_size() for a file
	 *                  that would otherwise need more than PART_LIMIT parts).
	 *   - `mime_type`  The finished object's content type (default
	 *                  `application/octet-stream`).
	 *   - `timeout`    Seconds to wait for each request (default 300).
	 *   - `error_code` The code on a returned WP_Error (default
	 *                  `s3_upload_failed`).
	 *   - `messages`   Wording for `unreadable`, `refused` (with `%d` for the
	 *                  HTTP status), `not_started` and `not_finished`.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  The object's key.
	 * @param string               $path The local file.
	 * @param array<string, mixed> $args See above.
	 *
	 * @return string|WP_Error The object's `s3://bucket/key` address.
	 */
	public function upload_file( string $key, string $path, array $args = array() ): string|WP_Error {
		$args = self::args(
			$args,
			array(
				'part_size'  => self::PART_SIZE,
				'mime_type'  => 'application/octet-stream',
				'timeout'    => 300,
				'error_code' => 's3_upload_failed',
			)
		);

		// Without this, a missing file reads as zero bytes and goes up as an
		// empty object that looks like a success.
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( $args['error_code'], $args['messages']['unreadable'] );
		}

		$size = (int) filesize( $path );
		$part = self::part_size( $size, (int) $args['part_size'] );

		try {
			$sent = $size <= $part
				? $this->put_part( $this->signer->presign_put( $this->name, $key ), $path, $size, 0, $args, (string) $args['mime_type'] )
				: $this->in_parts( $key, $path, $size, $part, $args );
		} catch ( Throwable $exception ) {
			$sent = new WP_Error( $args['error_code'], $exception->getMessage() );
		}

		return $sent instanceof WP_Error ? $sent : Address::to( $this->name, $key );
	}

	/**
	 * How large an object is and what it says it is, without fetching it.
	 *
	 * One signed HEAD request, with a short timeout by default: the usual
	 * caller is somebody waiting on a Save button, and the answer is only a
	 * nicety.
	 *
	 * Arguments: `timeout` (default 5), `error_code` (default
	 * `s3_head_failed`) and `messages.head` (with `%d` for the HTTP status).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  The object's key.
	 * @param array<string, mixed> $args See above.
	 *
	 * @return array{size: int, mime_type: string}|WP_Error Zero and an empty
	 *                                                      string are "the
	 *                                                      provider did not
	 *                                                      say".
	 */
	public function head( string $key, array $args = array() ): array|WP_Error {
		$args = self::args(
			$args,
			array(
				'timeout'    => 5,
				'error_code' => 's3_head_failed',
			)
		);

		try {
			$response = Transport::send( $this->signer->sign_head_object( $this->name, $key ), array( 'timeout' => (int) $args['timeout'] ) );
		} catch ( Throwable $exception ) {
			return new WP_Error( $args['error_code'], $exception->getMessage() );
		}

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		// The status goes in the data: a 404 is "not there", which is an
		// answer, and anything else is a refusal.
		if ( ! Transport::is_success( $response['status'] ) ) {
			return new WP_Error( $args['error_code'], sprintf( $args['messages']['head'], $response['status'] ), array( 'status' => $response['status'] ) );
		}

		// Header names arrive in whatever case the provider sent them.
		$headers = array_change_key_case( $response['headers'], CASE_LOWER );
		$length  = $headers['content-length'] ?? '';
		$type    = $headers['content-type'] ?? '';

		return array(
			'size'      => is_scalar( $length ) && ctype_digit( (string) $length ) ? (int) $length : 0,
			'mime_type' => is_scalar( $type ) ? (string) $type : '',
		);
	}

	/**
	 * Write a small object from memory.
	 *
	 * The small case, deliberately. A real upload goes browser to bucket so
	 * the bytes never enter PHP, or a file goes up with upload_file(); this is
	 * for what is already in memory -- a JSON sidecar, a generated preview.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Object key.
	 * @param string $body          Contents.
	 * @param string $mime_type     Content-Type to store.
	 * @param string $cache_control Cache-Control to store, or ''. For an
	 *                              object served straight from a public
	 *                              bucket, where this is the only caching
	 *                              anybody gets to set.
	 *
	 * @return true|WP_Error
	 */
	public function put( string $key, string $body, string $mime_type = 'application/octet-stream', string $cache_control = '' ): true|WP_Error {
		$headers = array( 'content-type' => $mime_type );

		if ( '' !== $cache_control ) {
			$headers['cache-control'] = $cache_control;
		}

		$request  = $this->signer->sign( Method::PUT, $this->name, $key, array(), $body, $headers );
		$response = Transport::request( 'PUT', $request->url, $request->headers, $body );

		return Transport::is_success( $response['status'] ) ? true : self::refusal( 'put', $response );
	}

	/**
	 * Read an object into a file on disk, as it arrives.
	 *
	 * The way back for upload_file(): a backup, an export, too big to hold, so
	 * WordPress streams the body to the file and it is never in memory whole.
	 * The file is created, never replaced, so a download cannot land on top
	 * of the file it was meant to sit beside. Anything short of the whole
	 * object -- a refusal, a transfer cut off, fewer bytes than the
	 * Content-Length promised -- removes what was written, rather than
	 * leaving a file that looks like a download.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Object key.
	 * @param string $path    Where to write it. Must not exist.
	 * @param int    $timeout Seconds to allow. Default 300.
	 *
	 * @return true|WP_Error `s3_download_exists` when the file is already
	 *                       there, `s3_download_failed` otherwise.
	 */
	public function download_file( string $key, string $path, int $timeout = 300 ): true|WP_Error {
		if ( file_exists( $path ) || is_link( $path ) ) {
			return new WP_Error( 's3_download_exists', 'A download will not overwrite ' . $path );
		}

		// Created first, exclusively, so two downloads cannot both claim it.
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the false return is the answer.

		if ( false === $handle ) {
			return new WP_Error( 's3_download_failed', 'Cannot create ' . $path );
		}

		fclose( $handle );

		$response = Transport::request(
			'GET',
			$this->url( $key, max( 60, $timeout ) ),
			array(),
			null,
			array(
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $path,
			)
		);

		clearstatcache( true, $path );

		$length = Transport::header( $response, 'content-length' );
		$whole  = 200 === $response['status'] && is_file( $path )
			&& ( '' === $length || ! ctype_digit( $length ) || (int) $length === (int) filesize( $path ) );

		if ( $whole ) {
			return true;
		}

		wp_delete_file( $path );

		return self::refusal( 'download', $response );
	}

	/**
	 * One PUT of part or all of a file, to a presigned address.
	 *
	 * @param string $url       The presigned address.
	 * @param string $path      The file.
	 * @param int    $length    How many bytes.
	 * @param int    $offset    From where.
	 * @param array  $args      The upload's arguments.
	 * @param string $mime_type Content-Type to send, for a whole object; ''
	 *                          for a part, which has none of its own.
	 *
	 * @return string|WP_Error The part's ETag.
	 */
	private function put_part( string $url, string $path, int $length, int $offset, array $args, string $mime_type = '' ): string|WP_Error {
		// One part's worth, never more: this is the only place the bytes are
		// in memory, and the part size is what bounds it.
		$body = (string) file_get_contents( $path, false, null, $offset, max( 0, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a byte range of a local file.

		// Content-Type is not among a presigned URL's signed headers, so it
		// may be sent alongside, and storage keeps it. So may Content-MD5,
		// which storage checks the bytes against and refuses (BadDigest)
		// when they were changed on the way.
		$headers = array( 'content-md5' => self::md5( $body ) );

		if ( '' !== $mime_type ) {
			$headers['content-type'] = $mime_type;
		}

		$response = Transport::send(
			new SignedRequest( Method::PUT, $url, $headers, $body ),
			array( 'timeout' => (int) $args['timeout'] )
		);

		unset( $body );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$etag = array_change_key_case( $response['headers'], CASE_LOWER )['etag'] ?? '';
		$etag = is_scalar( $etag ) ? (string) $etag : '';

		// A part without an ETag cannot be assembled, so it counts as refused.
		if ( ! Transport::is_success( $response['status'] ) || ( '' === $mime_type && '' === trim( $etag, '" ' ) ) ) {
			return new WP_Error( $args['error_code'], sprintf( $args['messages']['refused'], $response['status'] ) );
		}

		return $etag;
	}

	/**
	 * A multipart upload from disk: start it, send each part, put them
	 * together. Abandoned at the provider if any of that fails.
	 *
	 * @param string $key  The object's key.
	 * @param string $path The file.
	 * @param int    $size Its size in bytes.
	 * @param int    $part Bytes a part.
	 * @param array  $args The upload's arguments.
	 *
	 * @return true|WP_Error
	 */
	private function in_parts( string $key, string $path, int $size, int $part, array $args ): true|WP_Error {
		$started = Transport::send( $this->signer->sign_create_multipart_upload( $this->name, $key, (string) $args['mime_type'] ), array( 'timeout' => (int) $args['timeout'] ) );

		if ( $started instanceof WP_Error ) {
			return $started;
		}

		$upload = Transport::is_success( $started['status'] ) ? self::field( $started['body'], 'UploadId' ) : '';

		if ( '' === $upload ) {
			return new WP_Error( $args['error_code'], $args['messages']['not_started'] );
		}

		$parts = array();

		for ( $number = 1, $offset = 0; $offset < $size; $number++, $offset += $part ) {
			$etag = $this->put_part(
				$this->signer->presign_upload_part( $this->name, $key, $upload, $number ),
				$path,
				min( $part, $size - $offset ),
				$offset,
				$args
			);

			if ( $etag instanceof WP_Error ) {
				$this->abort_upload( $key, $upload );

				return $etag;
			}

			$parts[] = array(
				'PartNumber' => $number,
				'ETag'       => $etag,
			);
		}

		$request = $this->signer->sign_complete_multipart_upload( $this->name, $key, $upload, $parts );
		$done    = Transport::send( $request, array( 'timeout' => (int) $args['timeout'] ) );

		if ( $done instanceof WP_Error || ! self::confirmed( $done ) ) {
			$this->abort_upload( $key, $upload );

			return $done instanceof WP_Error ? $done : new WP_Error( $args['error_code'], $args['messages']['not_finished'] );
		}

		return true;
	}
}
