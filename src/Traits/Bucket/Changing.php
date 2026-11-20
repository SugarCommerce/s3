<?php
/**
 * Copying and deleting objects inside storage.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use InvalidArgumentException;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Models\Method;
use SugarCommerce\S3\Signing\Endpoint;
use SugarCommerce\S3\Support\Xml;
use SugarCommerce\S3\Transport;
use SugarCommerce\S3\Validate;
use WP_Error;

/**
 * Trait Changing
 *
 * Copying and deleting objects inside storage.
 *
 * @since 1.0.0
 */
trait Changing {

	/**
	 * Copy an object from another bucket into this one, inside storage.
	 *
	 * Nothing passes through PHP, whatever the size. Both buckets must be
	 * reachable with these credentials. The copy gets its own type and
	 * caching, which is the point of copying something private into a public
	 * bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Bucket $source        Where it is now.
	 * @param string $source_key    Its key there.
	 * @param string $key           Its key here.
	 * @param string $content_type  Content-Type for the copy.
	 * @param string $cache_control Cache-Control for the copy, or ''.
	 *
	 * @return true|WP_Error
	 */
	public function copy_from( Bucket $source, string $source_key, string $key, string $content_type, string $cache_control = '' ): true|WP_Error {
		$request  = $this->signer->sign_copy_object( $source->name, $source_key, $this->name, $key, $content_type, $cache_control );
		$response = Transport::request( 'PUT', $request->url, $request->headers, '' );

		// A copy, like a multipart completion, can fail inside a 200.
		return self::confirmed( $response ) ? true : self::refusal( 'copy', $response );
	}

	/**
	 * Delete an object.
	 *
	 * S3 answers 204 whether or not the key existed, so success is not proof
	 * the object was there -- only that it is not there now.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return true|WP_Error
	 */
	public function delete( string $key ): true|WP_Error {
		$request  = $this->signer->sign_delete_object( $this->name, $key );
		$response = Transport::request( 'DELETE', $request->url, $request->headers );

		return Transport::is_success( $response['status'] ) ? true : self::refusal( 'delete', $response );
	}

	/**
	 * Copy an object to another key in this bucket, keeping its type and
	 * metadata. Half of a rename; the other half is delete().
	 *
	 * @since 1.0.0
	 *
	 * @param string $from The key it has.
	 * @param string $to   The key the copy gets.
	 *
	 * @return true|WP_Error A copy refused inside a 200 is refused.
	 *
	 * @throws InvalidArgumentException When either key has a "." or ".." segment.
	 */
	public function copy( string $from, string $to ): true|WP_Error {
		if ( ! Validate::segments( $from ) ) {
			throw new InvalidArgumentException( 'An S3 key cannot contain a "." or ".." path segment.' );
		}

		$request  = $this->signer->sign(
			Method::PUT,
			$this->name,
			$to,
			array(),
			'',
			// Signed, as every x-amz-* header must be: AWS refuses one added
			// afterwards. Each segment encoded, the slashes kept.
			array( 'x-amz-copy-source' => '/' . rawurlencode( $this->name ) . '/' . Endpoint::encode_key( $from ) )
		);
		$response = Transport::request( 'PUT', $request->url, $request->headers, '' );

		return self::confirmed( $response ) ? true : self::refusal( 'copy', $response );
	}

	/**
	 * Delete many objects, a thousand to a request.
	 *
	 * DeleteObjects answers 200 and lists, key by key, what it deleted and
	 * what it would not. A provider that does not implement it at all --
	 * some S3-compatible stores do not -- refuses the whole request, and
	 * that comes back as a WP_Error so the caller can fall back to delete()
	 * one key at a time.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $keys Object keys.
	 *
	 * @return array{deleted: array<int, string>, errors: array<int, array{key: string, code: string, message: string}>}|WP_Error
	 *         `s3_delete_many_failed` when a request was refused whole.
	 */
	public function delete_many( array $keys ): array|WP_Error {
		$result = array(
			'deleted' => array(),
			'errors'  => array(),
		);

		foreach ( array_chunk( array_values( array_unique( array_map( 'strval', $keys ) ) ), 1000 ) as $chunk ) {
			$body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Delete><Quiet>false</Quiet>';

			foreach ( $chunk as $key ) {
				$body .= '<Object><Key>' . htmlspecialchars( $key, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key></Object>';
			}

			$body    .= '</Delete>';
			$request  = $this->signer->sign(
				Method::POST,
				$this->name,
				'',
				array( 'delete' => '' ),
				$body,
				array(
					'content-type' => 'application/xml',
					'content-md5'  => base64_encode( (string) hash( 'md5', $body, true ) ),
				)
			);
			$response = Transport::send( $request, array( 'timeout' => 60 ) );

			if ( $response instanceof WP_Error ) {
				return $response;
			}

			$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

			if ( null === $document || 'Error' === $document->getName() ) {
				return self::refusal( 'delete_many', $response );
			}

			foreach ( Xml::children( $document, 'Deleted' ) as $node ) {
				$result['deleted'][] = Xml::text( $node, 'Key' );
			}

			foreach ( Xml::children( $document, 'Error' ) as $node ) {
				$result['errors'][] = array(
					'key'     => Xml::text( $node, 'Key' ),
					'code'    => Xml::text( $node, 'Code' ),
					'message' => Xml::text( $node, 'Message' ),
				);
			}
		}

		return $result;
	}
}
