<?php
/**
 * The buckets a key can see, and what is in one.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use SugarCommerce\S3\Models\Method;
use SugarCommerce\S3\Signer;
use SugarCommerce\S3\Support\Xml;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait Listing
 *
 * The buckets a key can see, and what is in one.
 *
 * @since 1.0.0
 */
trait Listing {

	/**
	 * Every bucket these credentials can list.
	 *
	 * Asks the service, not a bucket, so it is static: a key scoped to one
	 * bucket is refused (403 AccessDenied) even though it works, and that
	 * refusal is how a caller tells a scoped key from a wrong one.
	 *
	 * @since 1.0.0
	 *
	 * @param Signer $signer A signer for the provider.
	 *
	 * @return array<int, array{name: string, created: string}>|WP_Error
	 *         `s3_buckets_failed` with storage's code in its data.
	 */
	public static function all( Signer $signer ): array|WP_Error {
		$request  = $signer->sign( Method::GET, '' );
		$response = Transport::send( $request, array( 'limit_response_size' => 1048576 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::refusal( 'buckets', $response );
		}

		$document = Xml::load( $response['body'] );

		if ( null === $document ) {
			return self::refusal( 'buckets', $response );
		}

		$buckets = array();

		foreach ( Xml::children( $document, 'Buckets' ) as $list ) {
			foreach ( Xml::children( $list, 'Bucket' ) as $bucket ) {
				$name = Xml::text( $bucket, 'Name' );

				if ( '' !== $name ) {
					$buckets[] = array(
						'name'    => $name,
						'created' => Xml::text( $bucket, 'CreationDate' ),
					);
				}
			}
		}

		return $buckets;
	}

	/**
	 * One page of what is in the bucket.
	 *
	 * ListObjectsV2. With a delimiter, keys below the next one are rolled up
	 * into `prefixes` -- the folders -- so a listing is one level of the
	 * tree; with none, everything under the prefix comes back, a page at a
	 * time, which is what deleting a folder needs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix    Only keys starting with this.
	 * @param string $delimiter Roll keys up at this character; '' for none.
	 * @param string $token     The previous page's `next_token`, or ''.
	 * @param int    $max_keys  Keys a page, 1 to 1000.
	 *
	 * @return array{objects: array<int, array{key: string, size: int, last_modified: string, etag: string, storage_class: string}>, prefixes: array<int, string>, truncated: bool, next_token: string}|WP_Error
	 *         `s3_list_failed` with storage's code in its data.
	 */
	public function list( string $prefix = '', string $delimiter = '/', string $token = '', int $max_keys = 1000 ): array|WP_Error {
		$request  = $this->signer->sign_list_objects( $this->name, $max_keys, $prefix, $delimiter, $token );
		$response = Transport::send( $request, array( 'limit_response_size' => 8388608 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

		if ( null === $document ) {
			return self::refusal( 'list', $response );
		}

		$objects = array();

		foreach ( Xml::children( $document, 'Contents' ) as $node ) {
			$key = Xml::text( $node, 'Key' );

			if ( '' === $key ) {
				continue;
			}

			$class     = Xml::text( $node, 'StorageClass' );
			$objects[] = array(
				'key'           => $key,
				'size'          => (int) Xml::text( $node, 'Size' ),
				'last_modified' => Xml::text( $node, 'LastModified' ),
				'etag'          => trim( Xml::text( $node, 'ETag' ), '"' ),
				'storage_class' => '' === $class ? 'STANDARD' : $class,
			);
		}

		$prefixes = array();

		foreach ( Xml::children( $document, 'CommonPrefixes' ) as $node ) {
			$folder = Xml::text( $node, 'Prefix' );

			if ( '' !== $folder ) {
				$prefixes[] = $folder;
			}
		}

		$truncated = 'true' === strtolower( Xml::text( $document, 'IsTruncated' ) );

		return array(
			'objects'    => $objects,
			'prefixes'   => $prefixes,
			'truncated'  => $truncated,
			'next_token' => $truncated ? Xml::text( $document, 'NextContinuationToken' ) : '',
		);
	}
}
