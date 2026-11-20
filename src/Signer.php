<?php
/**
 * AWS Signature Version 4 signing for S3-compatible object storage.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3;

use InvalidArgumentException;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Signing\AddressingStyle;
use SugarCommerce\S3\Signing\Endpoint;
use SugarCommerce\S3\Signing\Headers;
use SugarCommerce\S3\Signing\SignedRequest;
use SugarCommerce\S3\Traits\Signer\Operations;
use SugarCommerce\S3\Traits\Signer\Presigning;
use SugarCommerce\Storage\Files\ContentDisposition;

/**
 * Class Signer
 *
 * Produces AWS SigV4 signatures for S3-compatible storage — Cloudflare
 * R2, AWS S3, Backblaze B2, DigitalOcean Spaces, MinIO, and anything
 * else speaking the same protocol.
 *
 * This library signs. It never performs I/O, so there is no HTTP client
 * to configure, no SDK to update, and nothing to mock in your tests.
 * Two families of method:
 *
 *   - `presign_*()` returns a URL with the signature in the query
 *     string. Hand it to a browser — that's how downloads and
 *     direct-to-storage uploads work without proxying bytes through PHP.
 *   - `sign_*()` returns a {@see SignedRequest} with the signature in an
 *     `Authorization` header, for calls your server makes itself.
 *
 * Where the bucket goes — the path or the hostname — is the provider's
 * business, and {@see Provider} holds it along with every endpoint and
 * default region. This class knows how to sign and nothing about who it is
 * signing for. There used to be five static factories here carrying a
 * smaller, second copy of that table.
 *
 * Construction is free — no network, no state beyond the credentials.
 *
 * Typical use:
 *
 *   use SugarCommerce\S3\Signer;
 *
 *   $signer = Provider::R2->signer( $key, $secret, account_id: $account );
 *
 *   // 60-second download link, saved as "Album Master.wav"
 *   $url = $signer->presign_get( 'my-bucket', 'masters/a1.wav', 60, 'Album Master.wav' );
 *
 *   // A DELETE your server executes
 *   $req = $signer->sign_delete_object( 'my-bucket', 'masters/a1.wav' );
 *
 * The presign_*() helpers live in Traits/Signer/Presigning and the sign_*()
 * ones in Traits/Signer/Operations; the signing itself is here.
 *
 * @since 1.0.0
 */
final readonly class Signer {

	use Presigning;
	use Operations;

	/**
	 * SigV4 algorithm identifier. First line of every string-to-sign.
	 */
	private const string ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Service name in the credential scope. Always 's3' here.
	 */
	private const string SERVICE = 's3';

	/**
	 * Payload placeholder for query-string signing.
	 *
	 * Presigned URLs are handed to a browser, so the body cannot be
	 * hashed ahead of time. S3 defines this literal for that case.
	 */
	private const string UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

	/**
	 * Longest presigned-URL lifetime SigV4 permits, in seconds (7 days).
	 */
	public const int MAX_EXPIRES = 604800;

	/**
	 * Normalised endpoint host, without scheme, port-path or trailing slash.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Build the signer.
	 *
	 * Credentials carry `#[\SensitiveParameter]` so they are scrubbed
	 * from stack traces if anything downstream throws.
	 *
	 * Rarely called directly. {@see Provider} knows every provider's
	 * endpoint, default region and addressing style, so
	 * `Provider::R2->signer( $key, $secret, account_id: $id )` is shorter and
	 * harder to get wrong — and, being an enum case, is a value a settings
	 * screen can store and enumerate.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $access_key Access key ID.
	 * @param string          $secret_key Secret access key.
	 * @param string          $endpoint   Host, e.g.
	 *                                    `<account>.r2.cloudflarestorage.com`.
	 *                                    A scheme or trailing slash is
	 *                                    tolerated and stripped.
	 * @param string          $region     Region name. `auto` suits
	 *                                    Cloudflare R2; AWS needs the real
	 *                                    region (`eu-west-2`).
	 * @param int|null        $timestamp  Fixed Unix time to sign at. For
	 *                                    tests and reproducible fixtures —
	 *                                    leave null in production or every
	 *                                    signature shares one eventually
	 *                                    expired date.
	 * @param AddressingStyle $addressing Where the bucket goes. Defaults to
	 *                                    path-style; AWS, Backblaze and
	 *                                    Spaces want virtual-hosted.
	 *
	 * @throws InvalidArgumentException On empty credentials, an unusable
	 *                                   endpoint, or a region containing
	 *                                   characters that would corrupt the
	 *                                   credential scope.
	 */
	public function __construct(
		#[\SensitiveParameter]
		private string $access_key,
		#[\SensitiveParameter]
		private string $secret_key,
		string $endpoint,
		private string $region = 'auto',
		private ?int $timestamp = null,
		private AddressingStyle $addressing = AddressingStyle::Path,
	) {
		if ( ! Validate::credential( $access_key ) || ! Validate::credential( $secret_key ) ) {
			throw new InvalidArgumentException( 'S3 credentials cannot be empty.' );
		}

		if ( ! Validate::region( $region ) ) {
			throw new InvalidArgumentException( 'S3 region must contain only letters, digits and hyphens.' );
		}

		$this->endpoint = Endpoint::normalize( $endpoint );
	}

	/**
	 * The scheme and host requests for a bucket go to.
	 *
	 * For a Content-Security-Policy: a page that plays an object from a
	 * presigned URL has to name where that URL points.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return string e.g. `https://files.acct.r2.cloudflarestorage.com`.
	 *
	 * @throws InvalidArgumentException When the bucket cannot be a DNS label
	 *                                   under virtual-hosted addressing.
	 */
	public function origin( string $bucket ): string {
		return 'https://' . $this->host_for( $bucket );
	}

	/**
	 * Sign an arbitrary request into the query string.
	 *
	 * The escape hatch behind every `presign_*()` helper. Reach for it
	 * when you need an operation this library doesn't wrap.
	 *
	 * @since 1.0.0
	 *
	 * @param Method                $method          HTTP verb.
	 * @param string                $bucket          Bucket name.
	 * @param string                $key             Object key. '' addresses the bucket itself.
	 * @param array<string, string> $query           Extra query parameters, unencoded.
	 * @param int                   $expires_seconds Validity window. Default 3600.
	 *
	 * @return string Fully-qualified HTTPS URL.
	 */
	public function presign( Method $method, string $bucket, string $key = '', array $query = array(), int $expires_seconds = 3600 ): string {
		if ( $expires_seconds < 1 || $expires_seconds > self::MAX_EXPIRES ) {
			throw new InvalidArgumentException(
				'Presigned URL lifetime must be between 1 and ' . (int) self::MAX_EXPIRES . ' seconds (7 days).'
			);
		}

		[ $amz_date, $date ] = $this->stamp();

		$scope         = $this->scope( $date );
		$host          = $this->host_for( $bucket );
		$canonical_uri = Endpoint::canonical_uri( $bucket, $key, $this->addressing );

		$query['X-Amz-Algorithm']     = self::ALGORITHM;
		$query['X-Amz-Credential']    = $this->access_key . '/' . $scope;
		$query['X-Amz-Date']          = $amz_date;
		$query['X-Amz-Expires']       = (string) $expires_seconds;
		$query['X-Amz-SignedHeaders'] = 'host';

		$canonical_query = Endpoint::canonical_query( $query );

		$canonical_request = implode(
			"\n",
			array(
				$method->value,
				$canonical_uri,
				$canonical_query,
				'host:' . $host,
				'',
				'host',
				self::UNSIGNED_PAYLOAD,
			)
		);

		$signature = $this->signature( $this->string_to_sign( $amz_date, $scope, $canonical_request ), $date );

		return 'https://' . $host . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/* ─── Signed requests (header signing) ──────────────────────────── */

	/**
	 * Sign a request your own server will execute.
	 *
	 * The returned headers are exhaustive and order-sensitive in the
	 * signature — send them as given. Adding an `x-amz-*` header
	 * afterwards invalidates the signature.
	 *
	 * @since 1.0.0
	 *
	 * @param Method                $method  HTTP verb.
	 * @param string                $bucket  Bucket name.
	 * @param string                $key     Object key. '' addresses the bucket itself.
	 * @param array<string, string> $query   Query parameters, unencoded.
	 * @param string                $payload Raw request body. Hashed in full,
	 *                                       so this is O(body size).
	 * @param array<string, string> $headers Extra headers to sign, e.g.
	 *                                       `['content-type' => 'audio/wav']`.
	 *
	 * @return SignedRequest Ready to hand to any HTTP client.
	 */
	public function sign( Method $method, string $bucket, string $key = '', array $query = array(), string $payload = '', array $headers = array() ): SignedRequest {
		[ $amz_date, $date ] = $this->stamp();

		$scope         = $this->scope( $date );
		$host          = $this->host_for( $bucket );
		$canonical_uri = Endpoint::canonical_uri( $bucket, $key, $this->addressing );
		$digest        = hash( 'sha256', $payload );

		// The caller's own headers go in first, so the three mandatory ones
		// always win. Everything about the shape — lower-cased names,
		// collapsed values, sorted — is Headers', because getting any part
		// of it wrong produces a SignatureDoesNotMatch that says nothing
		// about which header it was.
		$signed = Headers::canonicalize(
			array_merge(
				$headers,
				array(
					'host'                 => $host,
					'x-amz-content-sha256' => $digest,
					'x-amz-date'           => $amz_date,
				)
			)
		);

		$canonical_headers = Headers::block( $signed );
		$signed_headers    = Headers::names( $signed );
		$canonical_query   = array() === $query ? '' : Endpoint::canonical_query( $query );

		$canonical_request = implode(
			"\n",
			array(
				$method->value,
				$canonical_uri,
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				$digest,
			)
		);

		$signature = $this->signature( $this->string_to_sign( $amz_date, $scope, $canonical_request ), $date );

		$signed['Authorization'] = self::ALGORITHM
			. ' Credential=' . $this->access_key . '/' . $scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		$url = 'https://' . $host . $canonical_uri
			. ( '' === $canonical_query ? '' : '?' . $canonical_query );

		return new SignedRequest( $method, $url, $signed, $payload );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * Build a `Content-Disposition` value that forces a download under a
	 * chosen filename.
	 *
	 * Delegates to {@see ContentDisposition::attachment()}, which handles
	 * transliteration for non-Latin scripts, Unicode normalisation, bidi
	 * spoofing, and path injection. Kept here as a convenience so callers
	 * with a Signer in hand don't need a second import.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Desired download filename, any script.
	 *
	 * @return string Complete header value.
	 */
	public static function content_disposition( string $name ): string {
		return ContentDisposition::attachment( $name );
	}

	/**
	 * What var_dump() and print_r() show: everything but the keys.
	 *
	 * `#[\SensitiveParameter]` keeps the keys out of stack traces only. A
	 * signer dumped into a log or onto an error page would otherwise print the
	 * secret in full.
	 *
	 * @since 1.0.0
	 *
	 * @return array{endpoint: string, region: string, addressing: string, timestamp: int|null}
	 */
	public function __debugInfo(): array {
		return array(
			'endpoint'   => $this->endpoint,
			'region'     => $this->region,
			'addressing' => $this->addressing->name,
			'timestamp'  => $this->timestamp,
		);
	}

	/**
	 * Refuse a value that would split a response header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Header value.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On CR, LF or NUL.
	 */
	private static function assert_header_value( string $value ): void {
		if ( ! Validate::header_value( $value ) ) {
			throw new InvalidArgumentException( 'A header value cannot contain a line break.' );
		}
	}

	/**
	 * Current signing timestamps as `[ amz_date, date ]`.
	 *
	 * @since 1.0.0
	 *
	 * @return array{0: string, 1: string} ISO basic timestamp and YYYYMMDD.
	 */
	private function stamp(): array {
		$amz_date = gmdate( 'Ymd\THis\Z', $this->timestamp ?? time() );

		return array( $amz_date, substr( $amz_date, 0, 8 ) );
	}

	/**
	 * Credential scope for a given signing date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date YYYYMMDD.
	 *
	 * @return string
	 */
	private function scope( string $date ): string {
		return $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
	}

	/**
	 * The `Host` header for a request against a given bucket.
	 *
	 * Under virtual-hosted addressing the bucket becomes a DNS label, so
	 * it is validated here — an incompatible name would otherwise be
	 * signed into a hostname that cannot resolve or whose certificate
	 * will not match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the bucket cannot be a DNS label.
	 */
	private function host_for( string $bucket ): string {
		return Endpoint::host( $this->endpoint, $bucket, $this->addressing );
	}

	/**
	 * Assemble the string-to-sign from a canonical request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $amz_date          ISO basic timestamp.
	 * @param string $scope             Credential scope.
	 * @param string $canonical_request The canonical request.
	 *
	 * @return string
	 */
	private function string_to_sign( string $amz_date, string $scope, string $canonical_request ): string {
		return implode(
			"\n",
			array(
				self::ALGORITHM,
				$amz_date,
				$scope,
				hash( 'sha256', $canonical_request ),
			)
		);
	}

	/**
	 * Sign a string-to-sign with the derived key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string_to_sign The string to sign.
	 * @param string $date           YYYYMMDD of the signing key.
	 *
	 * @return string Lowercase hex signature.
	 */
	private function signature( string $string_to_sign, string $date ): string {
		return hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );
	}

	/**
	 * Derive the date/region/service scoped signing key.
	 *
	 * Four chained HMACs, per the SigV4 spec. Returns raw bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date YYYYMMDD.
	 *
	 * @return string 32 raw bytes.
	 */
	private function signing_key( string $date ): string {
		$k = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$k = hash_hmac( 'sha256', $this->region, $k, true );
		$k = hash_hmac( 'sha256', self::SERVICE, $k, true );

		return hash_hmac( 'sha256', 'aws4_request', $k, true );
	}
}
