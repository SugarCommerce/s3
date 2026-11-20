<?php
declare( strict_types=1 );

namespace SugarCommerce\S3\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Support\Xml;

/**
 * Reading a provider's answers.
 */
final class XmlTest extends TestCase {

	public function test_an_answer_is_read(): void {
		$document = Xml::load( '<ListBucketResult><Name>files</Name></ListBucketResult>' );

		$this->assertNotNull( $document );
		$this->assertSame( 'files', Xml::text( $document, 'Name' ) );
	}

	/**
	 * No S3 answer carries a document type. One that does is declaring
	 * entities -- the billion laughs, or an external file -- and is not read.
	 */
	public function test_a_document_type_is_refused(): void {
		$laughs = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><r><Name>&b;</Name></r>';
		$file   = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r><Name>&x;</Name></r>';

		$this->assertNull( Xml::load( $laughs ) );
		$this->assertNull( Xml::load( $file ) );
		$this->assertNull( Xml::load( "<!doctype r>\n<r/>" ) );
	}

	public function test_a_cors_answer_with_a_document_type_is_not_a_policy(): void {
		$this->expectException( \RuntimeException::class );

		Policy::from_xml( '<!DOCTYPE CORSConfiguration [<!ENTITY o "https://evil.example">]><CORSConfiguration><CORSRule><AllowedOrigin>&o;</AllowedOrigin><AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>' );
	}
}
