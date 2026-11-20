<?php
/**
 * Every outbound request, checked as it is asked.
 *
 * @package SugarCommerce\S3
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\S3\Models\Method;
use SugarCommerce\S3\Models\SignedRequest;
use SugarCommerce\S3\Probe;
use SugarCommerce\S3\Signer;
use SugarCommerce\S3\Tests\Support\FakeHttp;
use SugarCommerce\S3\Transport;
use WP_Error;

/**
 * The endpoint was checked by name when it was configured; that is not the
 * request. DNS can answer differently at request time, a URL can come from
 * elsewhere, and a redirect can point anywhere. Each request is held to
 * network's SafeUrl -- with the owner's own endpoint allowed to be private.
 */
final class TransportGuardTest extends TestCase {

	/**
	 * Fresh hooks and HTTP; DNS from a table, and an IP literal is itself.
	 */
	protected function setUp(): void {
		wp_test_reset();
		FakeHttp::reset();
		$GLOBALS['s3_pin'] = null;

		Transport::resolve_with(
			static fn( string $host ): array => match ( true ) {
				false !== filter_var( $host, FILTER_VALIDATE_IP ) => [ $host ],
				str_ends_with( $host, 'minio.lan' )     => [ '192.168.1.20' ],
				'loopback.example' === $host            => [ '127.0.0.1' ],
				'inside.example' === $host              => [ '10.0.0.5' ],
				'rebind.example' === $host              => [ '93.184.216.34', '10.0.0.5' ],
				'metadata.minio-like.example' === $host => [ '169.254.169.254' ],
				default                                 => [ '93.184.216.34' ],
			}
		);
	}

	/**
	 * Back to the suite's resolver.
	 */
	protected function tearDown(): void {
		Transport::resolve_with( static fn(): array => [ '93.184.216.34' ] );
	}

	/**
	 * A signer for an endpoint, which names it as the owner's choice.
	 *
	 * @param string $endpoint Host, optionally with a port.
	 *
	 * @return Signer
	 */
	private function signer( string $endpoint ): Signer {
		return new Signer( 'AKID', 'secret', $endpoint, 'us-east-1' );
	}

	/**
	 * A host nobody configured that resolves inside the network is never
	 * asked -- for request() and send() alike.
	 */
	public function test_a_private_host_nobody_chose_is_refused(): void {
		$response = Transport::request( 'GET', 'https://inside.example/bucket/key' );
		$sent     = Transport::send( new SignedRequest( Method::PUT, 'https://inside.example/bucket/key', array(), 'x' ) );

		$this->assertSame( 0, $response['status'] );
		$this->assertNotSame( '', $response['error'] );
		$this->assertInstanceOf( WP_Error::class, $sent );
		$this->assertTrue( Transport::is_transport_error( $sent->get_error_code() ) );
		$this->assertSame( array(), FakeHttp::requests() );
	}

	/**
	 * Loopback, by name or by literal, is somebody else's server.
	 */
	public function test_loopback_is_refused(): void {
		Transport::request( 'GET', 'https://loopback.example/' );
		Transport::request( 'GET', 'http://127.0.0.1:9000/' );
		Transport::request( 'GET', 'http://[::1]/' );

		$this->assertSame( array(), FakeHttp::requests() );
	}

	/**
	 * One private answer among public ones is a rebinding attempt.
	 */
	public function test_one_private_answer_among_public_ones_is_refused(): void {
		$this->assertSame( 0, Transport::request( 'GET', 'https://rebind.example/' )['status'] );
		$this->assertSame( array(), FakeHttp::requests() );
	}

	/**
	 * A metadata service is never reached: not by literal, not by a name
	 * that resolves there, not even on the owner's own endpoint.
	 */
	public function test_metadata_is_refused_even_on_the_chosen_endpoint(): void {
		$this->signer( 'metadata.minio-like.example' );

		Transport::request( 'GET', 'http://169.254.169.254/latest/meta-data/' );
		Transport::request( 'GET', 'https://metadata.minio-like.example/bucket/key' );
		Probe::preflight( 'http://169.254.169.254/latest/', 'https://shop.example', 'content-type' );

		$this->assertSame( array(), FakeHttp::requests() );
	}

	/**
	 * The endpoint the owner chose may be on the LAN -- itself, on its port,
	 * and a bucket under it -- through core's plain call, which would
	 * otherwise refuse the private address this let through.
	 */
	public function test_the_chosen_endpoint_may_be_private(): void {
		$this->signer( 'minio.lan:9000' );

		$this->assertSame( 200, Transport::request( 'GET', 'https://minio.lan:9000/bucket/key' )['status'] );
		$this->assertSame( 200, Transport::request( 'PUT', 'https://files.minio.lan:9000/key', array(), 'x' )['status'] );

		$requests = FakeHttp::requests();

		$this->assertCount( 2, $requests );
		$this->assertSame( 'PUT', $requests[1]['args']['method'] );
		$this->assertFalse( $requests[0]['args']['reject_unsafe_urls'] );
	}

	/**
	 * Only that endpoint: not another port on it, not a name that merely ends
	 * the same way, not a name that starts with it.
	 */
	public function test_only_the_chosen_endpoint_may_be_private(): void {
		$this->signer( 'minio.lan:9000' );

		Transport::request( 'GET', 'https://minio.lan:22/' );
		Transport::request( 'GET', 'https://evilminio.lan:9000/' );
		Transport::request( 'GET', 'https://minio.lan.9000.minio.lan/' );

		$this->assertSame( array(), FakeHttp::requests() );
	}

	/**
	 * A public host goes out through core's safe call.
	 */
	public function test_a_public_host_goes_through_core_safe_call(): void {
		$this->assertSame( 200, Transport::request( 'DELETE', 'https://store.example/bucket/key' )['status'] );

		$this->assertArrayNotHasKey( 'reject_unsafe_urls', FakeHttp::last()['args'] );
		$this->assertSame( 'DELETE', FakeHttp::last()['args']['method'] );
	}

	/**
	 * A redirect is the answer, never followed -- whatever the caller asks.
	 */
	public function test_redirects_are_off(): void {
		FakeHttp::queue( 307, '', array( 'Location' => 'http://10.0.0.5/' ) );

		$response = Transport::request( 'GET', 'https://store.example/key', array(), null, array( 'redirection' => 5 ) );

		$this->assertSame( 307, $response['status'] );
		$this->assertCount( 1, FakeHttp::requests() );
		$this->assertSame( 0, FakeHttp::last()['args']['redirection'] );
	}

	/**
	 * The connection is pinned to the address that was checked, for that
	 * request only.
	 */
	public function test_the_connection_is_pinned_to_the_checked_address(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'No curl.' );
		}

		FakeHttp::respond_with(
			static function (): array {
				do_action( 'http_api_curl', curl_init() );

				return FakeHttp::response( 200 );
			}
		);

		$this->signer( 'minio.lan:9000' );

		Transport::request( 'GET', 'https://minio.lan:9000/bucket/key' );

		$this->assertSame( array( 'minio.lan:9000:192.168.1.20' ), $GLOBALS['s3_pin'] );
		$this->assertSame( array(), array_values( $GLOBALS['wp_test_hooks']['http_api_curl'] ?? array() ) );
	}
}
