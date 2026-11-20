<?php
/**
 * One S3-compatible bucket.
 *
 * @package   SugarCommerce\S3
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3;

use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Support\StorageError;
use SugarCommerce\S3\Traits\Bucket\Changing;
use SugarCommerce\S3\Traits\Bucket\Configuration;
use SugarCommerce\S3\Traits\Bucket\Listing;
use SugarCommerce\S3\Traits\Bucket\MultipartUploads;
use SugarCommerce\S3\Traits\Bucket\Reading;
use SugarCommerce\S3\Traits\Bucket\Responses;
use SugarCommerce\S3\Traits\Bucket\Transfers;
use WP_Error;

/**
 * Class Bucket
 *
 * The Signer signs and deliberately does no I/O. This is the I/O, through
 * WordPress's HTTP API, for the handful of calls a plugin that stores files in
 * a bucket actually makes -- and the S3 behaviours each of them has to know
 * about:
 *
 *   - CompleteMultipartUpload, and a copy, can answer **200 with an error
 *     document**. Checking the status alone records an upload that failed.
 *   - An upload that is started and never finished keeps its parts, and is
 *     billed for them, until it is aborted.
 *   - A size a browser declared is a claim; size() asks the bucket.
 *   - A bucket with no CORS configuration answers 404, which is an answer.
 *
 * Failures come back as a WP_Error with storage's own error code in its data
 * (see StorageError), because why storage said no is what somebody has to
 * fix next. A byte range that is not one is a programming error and throws.
 *
 * Every answer that could be large and is not wanted whole is capped with
 * `limit_response_size`, so a server that ignores a Range header cannot pull
 * a six-gigabyte object into memory to answer a one-byte question.
 *
 *     $bucket = new Bucket( Provider::R2->signer( $key, $secret, account_id: $account ), 'files' );
 *
 *     $address = $bucket->upload_file( 'packs/Drum Kit.zip', $temp_file );
 *     $url     = $bucket->url( 'packs/Drum Kit.zip', 60, 'Drum Kit.zip' );
 *
 * One trait per job, in Traits/Bucket: Transfers (files to and from this
 * server), MultipartUploads, Reading, Listing, Changing (copy and delete),
 * Configuration (the bucket itself and its CORS) and Responses (reading
 * storage's answers). The links stay here.
 *
 * @since 1.0.0
 */
final readonly class Bucket {

	use Transfers;
	use MultipartUploads;
	use Reading;
	use Listing;
	use Changing;
	use Configuration;
	use Responses;

	/**
	 * The default size of one part of a server-side multipart upload (16 MB).
	 *
	 * S3 wants at least 5 MB for every part but the last, and allows ten
	 * thousand parts, so sixteen covers a file of 160 GB while keeping one
	 * part's worth of memory small.
	 *
	 * @since 1.0.0
	 */
	public const int PART_SIZE = 16 * 1048576;

	/**
	 * The smallest part S3 accepts, except for the last (5 MB).
	 *
	 * A smaller part size is raised to this rather than refused: the provider
	 * would reject every part but the last otherwise.
	 *
	 * @since 1.0.0
	 */
	public const int MIN_PART_SIZE = 5 * 1048576;

	/**
	 * Parts per upload, with headroom under S3's 10,000 so a miscounted final
	 * part cannot push a large upload over.
	 *
	 * @since 1.0.0
	 */
	public const int PART_LIMIT = 9000;

	/**
	 * The most parts S3, R2 and the rest accept in one upload.
	 *
	 * @since 1.0.0
	 */
	public const int MAX_PARTS = 10000;

	/**
	 * The largest part S3 accepts (5 GiB).
	 *
	 * @since 1.0.0
	 */
	public const int MAX_PART_SIZE = 5 * 1073741824;

	/**
	 * The largest object S3 and R2 store (5 TiB), and so the largest upload.
	 *
	 * Split into PART_LIMIT parts that is parts of about 583 MiB, well inside
	 * MAX_PART_SIZE.
	 *
	 * @since 1.0.0
	 */
	public const int MAX_OBJECT_SIZE = 5 * 1099511627776;

	/**
	 * The largest object one PUT may carry (5 GiB). Past it, only a multipart
	 * upload will do.
	 *
	 * @since 1.0.0
	 */
	public const int MAX_PUT_SIZE = 5 * 1073741824;

	/**
	 * Uploads ListMultipartUploads returns in one page, at most.
	 */
	private const int UPLOADS_PAGE = 1000;

	/**
	 * Pages of unfinished uploads to follow before stopping: a bucket with
	 * more than fifty thousand of them has a problem a page of this will not
	 * fix, and a clean-up that runs daily gets the rest tomorrow.
	 */
	private const int UPLOADS_PAGES = 50;

	/**
	 * Parts ListParts returns in one page, at most.
	 */
	private const int PARTS_PAGE = 1000;

	/**
	 * Pages of parts to follow before giving up: S3 allows 10,000 parts,
	 * which is ten pages, and a listing still going after that is broken.
	 */
	private const int PARTS_PAGES = 11;

	/**
	 * Most bytes stat() and size() read. One is asked for; a server that
	 * ignores the Range header is cut off here rather than read whole.
	 */
	private const int STAT_BYTES = 1024;

	/**
	 * Wording for the upload and head failures that have no message of their
	 * own. A plugin passes its own, translated, through `messages`.
	 */
	private const array MESSAGES = array(
		'unreadable'   => 'The file to upload cannot be read.',
		'refused'      => 'Storage refused the file (%d).',
		'not_started'  => 'Storage would not start the upload.',
		'not_finished' => 'Storage would not finish the upload.',
		'head'         => 'Storage answered %d.',
	);

	/**
	 * Point at a bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Signer $signer A signer for the provider.
	 * @param string $name   The bucket's name.
	 */
	public function __construct(
		private Signer $signer,
		private string $name
	) {}

	/**
	 * The part size for a file of a given size.
	 *
	 * Scales up so a very large file still fits inside PART_LIMIT, rather than
	 * failing at part 10,001 after an hour of uploading.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes File size.
	 * @param int $floor Smallest part to use, when larger than S3's minimum --
	 *                   fewer round trips for an uploader that can afford to
	 *                   retry bigger parts. Never below MIN_PART_SIZE, and
	 *                   never above MAX_PART_SIZE.
	 *
	 * @return int
	 */
	public static function part_size( int $bytes, int $floor = self::MIN_PART_SIZE ): int {
		return min( self::MAX_PART_SIZE, max( self::MIN_PART_SIZE, $floor, (int) ceil( $bytes / self::PART_LIMIT ) ) );
	}

	/**
	 * The bucket's name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The signer it was built with.
	 *
	 * @since 1.0.0
	 *
	 * @return Signer
	 */
	public function signer(): Signer {
		return $this->signer;
	}

	/* ─── Links ─────────────────────────────────────────────────────── */

	/**
	 * A presigned URL to download an object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      Object key.
	 * @param int    $ttl      Seconds it works for.
	 * @param string $filename What the browser should save it as, or ''.
	 *
	 * @return string
	 */
	public function url( string $key, int $ttl = 300, string $filename = '' ): string {
		return $this->signer->presign_get( $this->name, $key, $ttl, $filename );
	}

	/**
	 * A presigned URL that plays or shows in the browser, rather than
	 * downloading -- for an `<audio>` or `<video>` element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key          Object key.
	 * @param int    $ttl          Seconds it works for.
	 * @param string $content_type What the browser should treat it as.
	 *
	 * @return string
	 */
	public function stream_url( string $key, int $ttl, string $content_type ): string {
		return $this->signer->presign_inline( $this->name, $key, $ttl, $content_type );
	}

	/**
	 * A URL that shows an object in the browser and stays the same for a
	 * window, so a page shown twice in that window is fetched once.
	 *
	 * For thumbnails and previews somebody may look at again and again: every
	 * fetch from storage is a billable read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key          Object key.
	 * @param string $content_type What the browser should treat it as.
	 * @param int    $window       Seconds the URL stays the same. Default an hour.
	 *
	 * @return string
	 */
	public function view_url( string $key, string $content_type, int $window = 3600 ): string {
		return $this->signer->presign_cacheable( $this->name, $key, $window, $content_type );
	}

	/**
	 * Where this bucket's URLs point, for a Content-Security-Policy.
	 *
	 * @since 1.0.0
	 *
	 * @return string e.g. `https://acct.r2.cloudflarestorage.com`.
	 */
	public function origin(): string {
		return $this->signer->origin( $this->name );
	}
}
