<?php
/**
 * Signed requests for the S3 operations a server makes itself.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Signer;

use InvalidArgumentException;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Method;
use SugarCommerce\S3\Signing\Endpoint;
use SugarCommerce\S3\Signing\SignedRequest;
use SugarCommerce\S3\Validate;

/**
 * Trait Operations
 *
 * Signed requests for the S3 operations a server makes itself.
 *
 * Each is sign() with the method, query, body and headers an operation
 * needs -- objects, multipart uploads, copies, listings and a bucket's CORS.
 *
 * @since 1.0.0
 */
trait Operations {

	/**
	 * Sign a server-side object upload.
	 *
	 * Hashing the payload is O(body size), which is fine up to a few
	 * hundred MB. Past that, prefer a presigned PUT or a multipart
	 * upload so the bytes never enter PHP's memory at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $key       Object key to create.
	 * @param string $body      Raw object contents.
	 * @param string $mime_type Content-Type to store against the object.
	 *
	 * @return SignedRequest
	 */
	public function sign_put_object( string $bucket, string $key, string $body, string $mime_type = 'application/octet-stream' ): SignedRequest {
		return $this->sign( Method::PUT, $bucket, $key, array(), $body, array( 'content-type' => $mime_type ) );
	}

	/**
	 * Sign an object deletion.
	 *
	 * S3 returns 204 whether or not the key existed, so a success here
	 * is not proof the object was there.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Object key to delete.
	 *
	 * @return SignedRequest
	 */
	public function sign_delete_object( string $bucket, string $key ): SignedRequest {
		return $this->sign( Method::DELETE, $bucket, $key );
	}

	/**
	 * Sign a metadata-only request for a single object.
	 *
	 * Cheapest way to read size and ETag without transferring the body.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Object key.
	 *
	 * @return SignedRequest
	 */
	public function sign_head_object( string $bucket, string $key ): SignedRequest {
		return $this->sign( Method::HEAD, $bucket, $key );
	}

	/**
	 * Sign a ListObjectsV2 call.
	 *
	 * Responses are XML and capped at 1000 keys. When the response
	 * carries `<IsTruncated>true</IsTruncated>`, pass its
	 * `<NextContinuationToken>` back in for the following page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket             Bucket name.
	 * @param int    $max_keys           Page size, clamped to 1–1000.
	 * @param string $prefix             Restrict to keys with this prefix.
	 * @param string $delimiter          Roll keys up into `CommonPrefixes`
	 *                                   at this character. '' disables it.
	 * @param string $continuation_token Token from the previous page.
	 *
	 * @return SignedRequest
	 */
	public function sign_list_objects( string $bucket, int $max_keys = 1000, string $prefix = '', string $delimiter = '/', string $continuation_token = '' ): SignedRequest {
		$query = array(
			'list-type' => '2',
			'max-keys'  => (string) max( 1, min( 1000, $max_keys ) ),
		);

		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}

		if ( '' !== $delimiter ) {
			$query['delimiter'] = $delimiter;
		}

		if ( '' !== $continuation_token ) {
			$query['continuation-token'] = $continuation_token;
		}

		return $this->sign( Method::GET, $bucket, '', $query );
	}

	/**
	 * Sign the start of a multipart upload.
	 *
	 * The response XML carries an `<UploadId>`, which every subsequent
	 * part and the final assembly reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $key       Object key being assembled.
	 * @param string $mime_type Content-Type for the finished object.
	 *
	 * @return SignedRequest
	 */
	public function sign_create_multipart_upload( string $bucket, string $key, string $mime_type = 'application/octet-stream' ): SignedRequest {
		return $this->sign(
			Method::POST,
			$bucket,
			$key,
			array( 'uploads' => '' ),
			'',
			array( 'content-type' => $mime_type )
		);
	}

	/**
	 * Sign the assembly of a completed multipart upload.
	 *
	 * Parts are sorted by part number and their ETags normalised —
	 * S3 returns ETags already quoted, and double-quoting them is the
	 * usual cause of a `MalformedXML` rejection here.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                     $bucket    Bucket name.
	 * @param string                                     $key       Object key being assembled.
	 * @param string                                     $upload_id Upload id from the create call.
	 * @param array<int, array{PartNumber:int, ETag:string}> $parts Every uploaded part.
	 *
	 * @return SignedRequest
	 *
	 * @throws InvalidArgumentException When no parts are supplied.
	 */
	public function sign_complete_multipart_upload( string $bucket, string $key, string $upload_id, array $parts ): SignedRequest {
		if ( array() === $parts ) {
			throw new InvalidArgumentException( 'A multipart upload cannot be completed with zero parts.' );
		}

		usort( $parts, static fn( array $a, array $b ): int => ( (int) $a['PartNumber'] ) <=> ( (int) $b['PartNumber'] ) );

		$xml = '<CompleteMultipartUpload>';

		foreach ( $parts as $part ) {
			$etag = trim( (string) $part['ETag'], '"' );
			$xml .= '<Part>'
				. '<PartNumber>' . (int) $part['PartNumber'] . '</PartNumber>'
				. '<ETag>&quot;' . htmlspecialchars( $etag, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '&quot;</ETag>'
				. '</Part>';
		}

		$xml .= '</CompleteMultipartUpload>';

		return $this->sign(
			Method::POST,
			$bucket,
			$key,
			array( 'uploadId' => $upload_id ),
			$xml,
			array( 'content-type' => 'application/xml' )
		);
	}

	/**
	 * Sign the abandonment of a multipart upload.
	 *
	 * Worth calling on failure: unaborted uploads leave parts billing
	 * against the bucket indefinitely.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $key       Object key that was being assembled.
	 * @param string $upload_id Upload id to abandon.
	 *
	 * @return SignedRequest
	 */
	public function sign_abort_multipart_upload( string $bucket, string $key, string $upload_id ): SignedRequest {
		return $this->sign( Method::DELETE, $bucket, $key, array( 'uploadId' => $upload_id ) );
	}

	/**
	 * Sign a server-side copy from one object to another.
	 *
	 * The bytes move inside the storage provider and never pass through PHP:
	 * one request whatever the size. Both buckets must be reachable with these
	 * credentials -- for R2, the same account.
	 *
	 * The metadata is replaced rather than copied, which is what lets a copy
	 * published to a public bucket carry long-lived cache headers the private
	 * original never had.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source_bucket Bucket to copy from.
	 * @param string $source_key    Object to copy.
	 * @param string $bucket        Bucket to copy into.
	 * @param string $key           Object to create.
	 * @param string $content_type  Content-Type for the copy.
	 * @param string $cache_control Cache-Control for the copy, or ''.
	 *
	 * @return SignedRequest
	 *
	 * @throws InvalidArgumentException As sign() does, including for the
	 *                                   source bucket and key.
	 */
	public function sign_copy_object(
		string $source_bucket,
		string $source_key,
		string $bucket,
		string $key,
		string $content_type = 'application/octet-stream',
		string $cache_control = ''
	): SignedRequest {
		if ( ! Validate::segments( $source_bucket ) || ! Validate::segments( $source_key ) ) {
			throw new InvalidArgumentException( 'An S3 bucket or key cannot contain a "." or ".." path segment.' );
		}

		$headers = array(
			// Each segment encoded, the slashes between them kept: the source
			// is a path, and an encoded slash names a different key.
			'x-amz-copy-source'        => '/' . rawurlencode( $source_bucket ) . '/' . Endpoint::encode_key( $source_key ),
			'x-amz-metadata-directive' => 'REPLACE',
			'content-type'             => $content_type,
		);

		if ( '' !== $cache_control ) {
			$headers['cache-control'] = $cache_control;
		}

		return $this->sign( Method::PUT, $bucket, $key, array(), '', $headers );
	}

	/**
	 * Sign a read of the bucket's CORS configuration.
	 *
	 * A bucket with no configuration answers 404 with a
	 * `NoSuchCORSConfiguration` body rather than an empty 200, so treat that
	 * status as "none set" rather than as a failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return SignedRequest
	 */
	public function sign_get_bucket_cors( string $bucket ): SignedRequest {
		return $this->sign( Method::GET, $bucket, '', array( 'cors' => '' ) );
	}

	/**
	 * Sign a replacement of the bucket's CORS configuration.
	 *
	 * PutBucketCors replaces the whole configuration; there is no way to add
	 * one rule. Read the current policy first if you mean to keep any of it.
	 *
	 * Content-MD5 is sent because AWS requires it on this call specifically
	 * and refuses the request without it. Other providers ignore it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 * @param Policy $policy The configuration to store.
	 *
	 * @return SignedRequest
	 */
	public function sign_put_bucket_cors( string $bucket, Policy $policy ): SignedRequest {
		$body = $policy->to_xml();

		return $this->sign(
			Method::PUT,
			$bucket,
			'',
			array( 'cors' => '' ),
			$body,
			array(
				'content-type' => 'application/xml',
				'content-md5'  => base64_encode( (string) hash( 'md5', $body, true ) ),
			)
		);
	}

	/**
	 * Sign a removal of the bucket's CORS configuration.
	 *
	 * After this the bucket refuses every cross-origin request, so anything
	 * uploading or reading from a browser stops working.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return SignedRequest
	 */
	public function sign_delete_bucket_cors( string $bucket ): SignedRequest {
		return $this->sign( Method::DELETE, $bucket, '', array( 'cors' => '' ) );
	}

	/**
	 * Sign a request for the parts a multipart upload already holds
	 * (ListParts).
	 *
	 * What resuming an upload needs. A browser that reloads halfway through
	 * remembers which parts it sent, but storage decides what counts: a part
	 * the browser saw finish may not have been kept, and one it never heard
	 * back about may have been. The answer is a `ListPartsResult`, paged by
	 * part number.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket    Bucket name.
	 * @param string $key       Object key being assembled.
	 * @param string $upload_id Upload id from the create call.
	 * @param int    $max_parts Parts per page, clamped to S3's 1-1,000.
	 * @param int    $marker    List the parts after this part number; 0 for
	 *                          the first page.
	 *
	 * @return SignedRequest
	 */
	public function sign_list_parts( string $bucket, string $key, string $upload_id, int $max_parts = 1000, int $marker = 0 ): SignedRequest {
		$query = array(
			'uploadId'  => $upload_id,
			'max-parts' => (string) max( 1, min( 1000, $max_parts ) ),
		);

		if ( $marker > 0 ) {
			$query['part-number-marker'] = (string) $marker;
		}

		return $this->sign( Method::GET, $bucket, $key, $query );
	}

	/**
	 * Sign a ListMultipartUploads call: the uploads started in a bucket and
	 * neither finished nor aborted.
	 *
	 * What clearing up abandoned uploads needs, since their parts are billed
	 * and nothing else lists them. The answer is a
	 * `ListMultipartUploadsResult`, paged by key and then upload id: when it
	 * is truncated, pass its `NextKeyMarker` and `NextUploadIdMarker` back.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket           Bucket name.
	 * @param string $prefix           Only uploads of keys starting with this.
	 * @param string $key_marker       The previous page's NextKeyMarker, or ''.
	 * @param string $upload_id_marker The previous page's NextUploadIdMarker, or ''.
	 * @param int    $max_uploads      Uploads per page, clamped to S3's 1-1,000.
	 *
	 * @return SignedRequest
	 */
	public function sign_list_multipart_uploads( string $bucket, string $prefix = '', string $key_marker = '', string $upload_id_marker = '', int $max_uploads = 1000 ): SignedRequest {
		$query = array(
			'uploads'     => '',
			'max-uploads' => (string) max( 1, min( 1000, $max_uploads ) ),
		);

		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}

		if ( '' !== $key_marker ) {
			$query['key-marker'] = $key_marker;

			// Meaningless without a key marker, and S3 refuses it alone.
			if ( '' !== $upload_id_marker ) {
				$query['upload-id-marker'] = $upload_id_marker;
			}
		}

		return $this->sign( Method::GET, $bucket, '', $query );
	}
}
