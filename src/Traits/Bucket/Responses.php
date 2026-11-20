<?php
/**
 * Reading storage's answers: success, refusal, and the fields inside.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Traits\Bucket;

use SugarCommerce\S3\Models\StorageError;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * Trait Responses
 *
 * Reading storage's answers: success, refusal, and the fields inside.
 *
 * Shared by every other part of Bucket. A 2xx is not always a success --
 * CompleteMultipartUpload and CopyObject answer 200 and report a failure in
 * the body -- and a refusal carries storage's own code, because why storage
 * said no is what somebody has to fix next.
 *
 * @since 1.0.0
 */
trait Responses {

	/**
	 * The Content-MD5 of some bytes: their MD5, base64 encoded.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string
	 */
	private static function md5( string $bytes ): string {
		return base64_encode( (string) hash( 'md5', $bytes, true ) );
	}

	/**
	 * Whether an answer is a success all the way through: a 2xx that is not
	 * an error document. CompleteMultipartUpload and CopyObject both answer
	 * 200 and then report a failure in the body.
	 *
	 * @param array{status: int, body: string} $response An answer.
	 *
	 * @return bool
	 */
	private static function confirmed( array $response ): bool {
		return Transport::is_success( $response['status'] ) && ! str_contains( $response['body'], '<Error>' );
	}

	/**
	 * A refusal as a WP_Error, with storage's own code in its data.
	 *
	 * @param string $operation What was being done.
	 * @param array  $response  What Transport::request() returned.
	 *
	 * @return WP_Error
	 */
	private static function refusal( string $operation, array $response ): WP_Error {
		$body = '' !== ( $response['body'] ?? '' ) ? (string) $response['body'] : (string) ( $response['error'] ?? '' );

		return StorageError::refused( $operation, (int) $response['status'], $body )->to_wp_error();
	}

	/**
	 * A refusal, for the calls that answer with `ok` rather than a WP_Error:
	 * the line, the status, and storage's own code and words for it.
	 *
	 * @param array $response What Transport::request() returned.
	 *
	 * @return array{ok: false, error: string, status: int, code: string, reason: string}
	 */
	private static function failed( array $response ): array {
		$refusal = StorageError::refused( 'request', (int) $response['status'], '' !== $response['body'] ? $response['body'] : $response['error'] );

		return array(
			'ok'     => false,
			'error'  => self::describe( $response ),
			'status' => $refusal->status,
			'code'   => $refusal->error_code,
			'reason' => $refusal->reason,
		);
	}

	/**
	 * A refusal, as one line.
	 *
	 * @param array $response What Transport::request() returned.
	 *
	 * @return string
	 */
	private static function describe( array $response ): string {
		$body = '' !== $response['body'] ? $response['body'] : $response['error'];

		return 'HTTP ' . (int) $response['status'] . ': ' . substr( trim( strip_tags( (string) $body ) ), 0, 160 );
	}

	/**
	 * The text of the first element with this name, or ''.
	 *
	 * @param string $xml  Document or fragment.
	 * @param string $name Element name.
	 *
	 * @return string
	 */
	private static function field( string $xml, string $name ): string {
		return 1 === preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', $xml, $match ) ? $match[1] : '';
	}

	/**
	 * Arguments merged over defaults, with the wording merged a level down.
	 *
	 * @param array $args     What the caller passed.
	 * @param array $defaults What this call defaults to.
	 *
	 * @return array
	 */
	private static function args( array $args, array $defaults ): array {
		$merged             = array_merge( $defaults, $args );
		$merged['messages'] = array_merge( self::MESSAGES, (array) ( $args['messages'] ?? array() ) );

		return $merged;
	}
}
