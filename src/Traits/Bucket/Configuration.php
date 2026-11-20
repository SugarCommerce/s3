<?php
/**
 * The bucket itself: whether it answers, where it is, and its CORS policy.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use InvalidArgumentException;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Models\Method;
use SugarCommerce\S3\Support\Xml;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait Configuration
 *
 * The bucket itself: whether it answers, where it is, and its CORS policy.
 *
 * @since 1.0.0
 */
trait Configuration {

	/**
	 * Whether the bucket answers these credentials at all.
	 *
	 * A one-key listing: the cheapest call that proves the endpoint, the
	 * bucket name and the keys are all right, for a health check.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function reachable(): array {
		$request  = $this->signer->sign_list_objects( $this->name, 1 );
		$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 65536 ) );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * The bucket's CORS policy.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, policy: Policy|null, error: string, status?: int, code?: string, reason?: string} A bucket
	 *         with no configuration is ok, with an empty policy.
	 */
	public function cors(): array {
		$request  = $this->signer->sign_get_bucket_cors( $this->name );
		$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 262144 ) );

		// No configuration at all answers 404 -- an answer, not a failure.
		if ( 404 === $response['status'] && ! str_contains( $response['body'], 'NoSuchBucket' ) ) {
			return array(
				'ok'     => true,
				'policy' => new Policy(),
				'error'  => '',
			);
		}

		if ( 200 !== $response['status'] ) {
			return array( 'policy' => null ) + self::failed( $response );
		}

		try {
			return array(
				'ok'     => true,
				'policy' => Policy::from_xml( $response['body'] ),
				'error'  => '',
			);
		} catch ( \RuntimeException | InvalidArgumentException $e ) {
			return array(
				'ok'     => false,
				'policy' => null,
				'error'  => $e->getMessage(),
			);
		}
	}

	/**
	 * Replace the bucket's CORS policy. There is no way to add one rule.
	 *
	 * Setting it needs a key allowed to: one scoped to object read and write
	 * cannot call PutBucketCors, and the AccessDenied it gets reads like a
	 * bucket problem rather than a key one.
	 *
	 * @since 1.0.0
	 *
	 * @param Policy $policy The policy to store.
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function set_cors( Policy $policy ): array {
		$request  = $this->signer->sign_put_bucket_cors( $this->name, $policy );
		$response = Transport::request( 'PUT', $request->url, $request->headers, $request->body );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * Remove the bucket's CORS policy altogether.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function delete_cors(): array {
		$request  = $this->signer->sign_delete_bucket_cors( $this->name );
		$response = Transport::request( 'DELETE', $request->url, $request->headers );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * Where the bucket is, as the provider names it.
	 *
	 * GetBucketLocation. S3 answers an empty LocationConstraint for
	 * us-east-1 rather than naming it, so an empty one is that region.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error `s3_location_failed` with storage's code in its data.
	 */
	public function region(): string|WP_Error {
		$request  = $this->signer->sign( Method::GET, $this->name, '', array( 'location' => '' ) );
		$response = Transport::send( $request, array( 'limit_response_size' => 65536 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

		if ( null === $document ) {
			return self::refusal( 'location', $response );
		}

		$region = trim( (string) $document );

		return '' === $region ? 'us-east-1' : $region;
	}
}
