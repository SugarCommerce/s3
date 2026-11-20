<?php
/**
 * Executes a signed request through the WordPress HTTP API.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3;

use SugarCommerce\Network\Remote\SafeUrl;
use SugarCommerce\S3\Models\SignedRequest;
use WP_Error;

/**
 * Class Transport
 *
 * The signer itself performs no I/O, which is what makes it testable. This is
 * the one place that does, and it is deliberately thin: it hands a
 * {@see SignedRequest} to `wp_remote_request()` and returns the raw status,
 * headers and body. Interpreting an S3 XML error body, retrying, or mapping
 * onto a response object is the caller's business.
 *
 * Kept in this package rather than the consuming plugin so the signing and
 * sending rules stay adjacent — in particular the requirement not to let
 * anything modify the headers between the two, which would invalidate the
 * signature.
 *
 * Every request goes through network's {@see SafeUrl}, not only the
 * endpoint as it is configured: each address is resolved and checked as it
 * is asked, the connection is pinned to the address that passed, and no
 * redirect is followed. A metadata service, link-local or "no address" is
 * never reached, whatever the address. A private or loopback answer is let
 * through for one thing only: the endpoint the owner chose -- a MinIO on the
 * LAN, a development setup -- and a bucket's name under it. Every
 * {@see Signer} names its endpoint here as it is built; anything else (a
 * URL handed to {@see Probe::preflight()}, a host nobody configured) must
 * resolve to the public internet.
 *
 * @since 1.0.0
 */
final class Transport {

	/**
	 * Endpoints the owner chose, host and optional port, lower-cased.
	 *
	 * @var array<string, true>
	 */
	private static array $endpoints = array();

	/**
	 * How a name is resolved, for tests: fn( string $host ): string[].
	 * DNS when null.
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Name an endpoint the owner chose, so it may be on a private network.
	 *
	 * {@see Signer} calls this with its endpoint. It lets a private or
	 * loopback answer through for that host (and a bucket under it) only;
	 * the metadata, link-local and unspecified addresses stay refused, and
	 * the connection is still pinned.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint Normalised host, optionally with a port.
	 *
	 * @return void
	 */
	public static function allow_endpoint( string $endpoint ): void {
		$endpoint = strtolower( trim( $endpoint ) );

		if ( '' !== $endpoint ) {
			self::$endpoints[ $endpoint ] = true;
		}
	}

	/**
	 * Resolve names with a function instead of DNS, or DNS again with null.
	 *
	 * For tests: a suite runs against hosts that do not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param callable|null $resolver fn( string $host ): string[].
	 *
	 * @return void
	 */
	public static function resolve_with( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Send a signed request.
	 *
	 * The signature covers exactly the headers on the request, so they are
	 * passed through untouched. `wp_remote_request()` adds its own
	 * `User-Agent` and `Accept-Encoding`, which is safe: neither is in
	 * SignedHeaders, and S3 only verifies the headers the signature names.
	 *
	 * @since 1.0.0
	 *
	 * @param SignedRequest $request The request to send.
	 * @param array         $args    Extra arguments for wp_remote_request(),
	 *                               e.g. ['timeout' => 30]. `method`, `headers`
	 *                               and `body` are set from the request and
	 *                               cannot be overridden.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}|WP_Error
	 */
	public static function send( SignedRequest $request, array $args = array() ) {
		// Overriding any of these would either change what was signed or send
		// something other than the signed request, so they win over $args.
		$args = array_merge(
			array(
				'timeout'     => 30,
				'redirection' => 0,
			),
			$args,
			array(
				'method'  => $request->method->value,
				'headers' => $request->headers,
				'body'    => '' === $request->body ? null : $request->body,
			)
		);

		$response = self::ask( $request->url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = wp_remote_retrieve_headers( $response );

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' )
				? $headers->getAll()
				: (array) $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Whether a status code represents success.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status HTTP status code.
	 *
	 * @return bool
	 */
	public static function is_success( int $status ): bool {
		return $status >= 200 && $status < 300;
	}

	/**
	 * Make a request and always get an answer back, never a WP_Error.
	 *
	 * For callers that read a status the way a person does: a connection
	 * test, a probe, a client whose methods each decide what an answer means.
	 * A request that never completed comes back as status 0 with the
	 * transport's reason in `error`, so "no answer" and "refused" can be told
	 * apart without two return types.
	 *
	 * Header names come back lower-cased, and a header sent more than once is
	 * joined with commas, as HTTP says it may be.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Where to send it.
	 * @param array<string, string> $headers Request headers, exactly as signed.
	 * @param string|null           $body    Request body, or null.
	 * @param array<string, mixed>  $args    Extra arguments for wp_remote_request(),
	 *                                       such as `timeout`,
	 *                                       `limit_response_size`, `stream` and
	 *                                       `filename`.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	public static function request( string $method, string $url, array $headers = array(), ?string $body = null, array $args = array() ): array {
		$response = self::ask(
			$url,
			array_merge(
				array(
					'timeout'     => 30,
					'redirection' => 0,
				),
				$args,
				array(
					'method'  => $method,
					'headers' => $headers,
					'body'    => $body,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => $response->get_error_message(),
			);
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => self::headers( wp_remote_retrieve_headers( $response ) ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => '',
		);
	}

	/**
	 * Ask through the guard: the owner's endpoint may be private, nothing
	 * else may; nothing reaches metadata; no redirect is followed.
	 *
	 * @param string               $url  Where to send it.
	 * @param array<string, mixed> $args For wp_remote_request(), `method`
	 *                                   among them.
	 *
	 * @return array|WP_Error
	 */
	private static function ask( string $url, array $args ): array|WP_Error {
		$guard = new SafeUrl( allow_private: self::is_chosen( $url ), resolver: self::$resolver );

		return $guard->request( (string) $args['method'], $url, $args );
	}

	/**
	 * Whether a URL is on an endpoint the owner chose: the endpoint itself,
	 * or a bucket under it (virtual-hosted), on the same port.
	 *
	 * @param string $url The address.
	 *
	 * @return bool
	 */
	private static function is_chosen( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$host = null === $port ? $host : $host . ':' . $port;

		if ( '' === $host ) {
			return false;
		}

		foreach ( array_keys( self::$endpoints ) as $endpoint ) {
			if ( $host === $endpoint || str_ends_with( $host, '.' . $endpoint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One header of an answer from request(), or ''.
	 *
	 * @since 1.0.0
	 *
	 * @param array{headers: array<string, string>} $response What request() returned.
	 * @param string                                $name     Header name, any case.
	 *
	 * @return string
	 */
	public static function header( array $response, string $name ): string {
		return (string) ( $response['headers'][ strtolower( $name ) ] ?? '' );
	}

	/**
	 * Response headers as lower-cased names to single strings.
	 *
	 * @param mixed $headers What wp_remote_retrieve_headers() gave: an array,
	 *                       or core's case-insensitive dictionary.
	 *
	 * @return array<string, string>
	 */
	private static function headers( mixed $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$flat = array();

		foreach ( (array) $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return $flat;
	}

	/**
	 * Whether a failure happened below the S3 protocol.
	 *
	 * WordPress reports every cURL failure under one code, so the code alone
	 * says a request never completed -- which is exactly the distinction worth
	 * drawing, because no S3 error can exist yet.
	 * So is an address network's SafeUrl refused to ask
	 * (`http_request_not_executed`).
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code from the WP_Error.
	 *
	 * @return bool
	 */
	public static function is_transport_error( string $code ): bool {
		return in_array( $code, array( 'http_request_failed', 'connect_error', 'http_request_not_executed' ), true );
	}

	/**
	 * Describe a failure that happened before a request was ever answered.
	 *
	 * DNS, TLS, a refused connection: these arrive as a WP_Error carrying
	 * whatever cURL said, and cURL says things like "error:1404B410:SSL
	 * routines:ST_CONNECT:sslv3 alert handshake failure". That is a fact about
	 * a TLS record layer, not about the store, and it used to be shown
	 * verbatim in the connection test and the file browser alike. This says
	 * it in terms of the thing that failed. The caller keeps the original
	 * text, since it is what makes a genuine network fault diagnosable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message  Original error text.
	 * @param string $endpoint Endpoint that was being reached, if known.
	 *
	 * @return string
	 */
	public static function explain( string $message, string $endpoint = '' ): string {
		$host = Address::host( $endpoint );
		$name = '' === $host ? __( 'the storage endpoint', 'sugarcommerce' ) : $host;

		// A wrong account id is the usual cause: on providers that put it in
		// the hostname, it produces an address that exists but cannot present
		// a certificate for the name being asked for.
		if ( self::mentions( $message, array( 'sslv3 alert', 'handshake', 'SSL routines', 'SSL connect error', 'certificate', 'CERTIFICATE_VERIFY' ) ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( 'Could not open a secure connection to %s. The address answered but not as this provider\'s endpoint, which usually means the Account ID or endpoint setting is wrong.', 'sugarcommerce' ),
				$name
			);
		}

		if ( self::mentions( $message, array( 'Could not resolve host', 'name or service not known', 'nodename nor servname', 'Temporary failure in name resolution' ) ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( 'There is no server at %s. Check the Account ID or endpoint setting for a typo.', 'sugarcommerce' ),
				$name
			);
		}

		if ( self::mentions( $message, array( 'timed out', 'Connection refused', 'Failed to connect', 'Empty reply', 'Connection reset' ) ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( '%s did not respond. It may be temporarily unavailable, or blocked by this server\'s outbound firewall.', 'sugarcommerce' ),
				$name
			);
		}

		return sprintf(
			/* translators: %s: hostname of the storage endpoint */
			__( 'Could not reach %s.', 'sugarcommerce' ),
			$name
		);
	}

	/**
	 * Whether any of the needles appear in the message.
	 *
	 * @param string   $message Message.
	 * @param string[] $needles Needles.
	 *
	 * @return bool
	 */
	private static function mentions( string $message, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
