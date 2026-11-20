# S3

Signed links, uploads, listings and resumable multipart uploads for any
S3-compatible bucket, through WordPress's HTTP API and with no SDK: R2, S3,
B2, Wasabi, DigitalOcean, MinIO and a dozen more.

## What it does

A plugin that sells files from a bucket has to make a link that works for the
buyer and nobody else, send a forty-gigabyte file without a request that
times out, find out whether a key it stored still names something, and tell
the person setting it up which of six pasted values is wrong.

Each of those is easy to get almost right. SigV4 fails silently if one step is
off; S3 reports some failures inside a 200; R2 refuses a multipart upload
whose parts differ in size; a CORS rule that allows PUT but does not expose
`ETag` lets every part arrive and then fails at assembly. This does each of
them once, properly.

There is no admin screen here, so it works the same from a REST route, a
job, a WP-CLI command or a cron event. The bucket browser and the "Choose from
your bucket" picker are `sugarcommerce/storage-browser`, built on this.

## Features

* Sign presigned download, inline, upload and multipart links for R2, S3, B2, Wasabi, DigitalOcean, MinIO and a dozen more providers
* Send files up to the 5 TB object limit in parts, from the server or straight from the browser, each part checksummed, resuming after a timed-out request or a closed tab from what storage really kept
* Find and abort abandoned uploads, whose parts are billed and invisible
* Check storage settings when they are saved, naming the field to fix, and test a connection the way a store will use it: list, write, read back, delete, large uploads, CORS
* Read a byte range, the real stored size or an object's current ETag without downloading it
* List a bucket a page at a time, delete a thousand keys a request, copy in place, and read where it is
* Read, write and explain a bucket's CORS rules, including whether a multipart upload will work
* One parser for `s3://bucket/key` addresses, so every plugin agrees on the edge cases

## Installation

```bash
composer require sugarcommerce/s3
```

It requires `sugarcommerce/storage`, whose `Files\Filename` and
`Files\ContentDisposition` name a download the same way whether it comes from
a bucket or from a protected folder on disk, and `sugarcommerce/plugin`, which
names this build's option keys.

## Quick start

A sixty-second link to a private object, saved under a name of your choosing:

```php
use SugarCommerce\S3\Provider;

$signer = Provider::R2->signer( $access_key, $secret_key, account_id: $account_id );

$url = $signer->presign_get( 'my-bucket', 'masters/a1.wav', 60, 'Album Master.wav' );
```

A file on this server into the bucket, in parts, carried on by a job until it
is all there:

```php
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Multipart;

$multipart = new Multipart( new Bucket( $signer, 'my-bucket' ) );
$upload    = $multipart->begin( 'packs/Library.zip', filesize( $path ), [ 'source' => $path ] );

$multipart->run( $upload->id, 20 ); // an Upload while there is more to send; the s3:// address when done
```

## Signing and links

`Signer` does AWS Signature Version 4 for any S3-compatible provider and
never sends anything. `Provider` knows each provider's endpoint, default
region and where the bucket goes, so a settings screen stores an enum value
rather than a hostname somebody typed wrong:

```php
use SugarCommerce\S3\Provider;

$signer = Provider::R2->signer( $access_key, $secret_key, account_id: $account_id );

// A sixty-second link, saved as "Album Master.wav" whatever the key is.
$url = $signer->presign_get( 'my-bucket', 'masters/a1.wav', 60, 'Album Master.wav' );

// Plays in an <audio> element instead of downloading.
$play = $signer->presign_inline( 'my-bucket', 'previews/a1.mp3', 3600, 'audio/mpeg' );

// The same URL for a whole hour, so the browser's cached copy is used.
$thumb = $signer->presign_cacheable( 'my-bucket', 'covers/a1.webp', 3600, 'image/webp' );

// For a Content-Security-Policy.
$origin = $signer->origin( 'my-bucket' );
```

The object stays private; the link works until it expires and then does not.
`presign_put()` and `presign_upload_part()` let a browser send a file straight
to storage, and the `sign_*()` methods sign the requests a server makes itself:
head, delete, list, copy, multipart start, complete, abort and ListParts, and
the bucket's CORS configuration.

A region or account id that is not one DNS label is refused before it is
spliced into a hostname, and a key with a `.` or `..` segment is refused before
it is signed: something between here and the bucket would collapse it, and
`uploads/u1/../u2/x` is somebody else's file. `var_dump()` of a signer shows
no keys.

## `s3://` addresses

A plugin that keeps some files in a bucket and some on disk stores the bucket
ones as `s3://bucket/key`. One parser, so every plugin agrees on the edge
cases:

```php
use SugarCommerce\S3\Address;

Address::is( $path );                         // is it ours to sign at all?
Address::parse( 's3://bucket//packs/a.zip' ); // [ 'bucket' => 'bucket', 'key' => 'packs/a.zip' ]
Address::parse( 'bucket/a.zip' );             // null: without the scheme it is a local path
Address::parse( ' bucket/a.zip', true );      // typed input: the scheme is optional
Address::to( 'bucket', 'packs/a.zip' );       // "s3://bucket/packs/a.zip"
Address::host( 'https://key@S3.example.com/x' ); // "s3.example.com"
```

## A bucket

`Bucket` is one bucket on one signer, through WordPress's HTTP API. Failures
come back as a `WP_Error` carrying the status and storage's own error code
(`SignatureDoesNotMatch`, `NoSuchBucket`) in its data, because which one it was
decides the fix.

```php
use SugarCommerce\S3\Bucket;

$bucket = new Bucket( $signer, 'my-bucket' );

$address = $bucket->upload_file( 'packs/Drum Kit.zip', $temp_file, [
	'mime_type'  => 'application/zip',
	'error_code' => 'my_plugin_upload_failed',
	'messages'   => [
		/* translators: %d: an HTTP status code. */
		'refused' => __( 'Your storage refused the file (%d).', 'my-plugin' ),
	],
] );

$info = $bucket->head( 'packs/Drum Kit.zip' ); // [ 'size' => 256901120, 'mime_type' => 'application/zip' ]
```

A file no larger than one part (16 MB by default, `part_size` to change it)
goes in a single PUT. A larger one goes as a multipart upload read from disk a
part at a time, with the part size raised so it never needs more than 9,000
parts. Every request carries a Content-MD5, which storage checks the bytes
against. A part refused, a part answered without an ETag, or an assembly
refused -- including inside a 200, which is how S3 reports some failures --
abandons the upload at the provider, so half a file is not left costing
storage. A missing file is an error, never an empty object.

`upload_file()` does the whole file in one request. For a file that will not
fit in one -- many gigabytes, a host that stops PHP after thirty seconds --
use `Multipart`, below, which carries on where a request stopped.

### The multipart calls themselves

```php
$upload = $bucket->start_upload( $key, 'audio/wav' );          // the id, or a WP_Error
$urls   = $bucket->sign_parts( $key, $upload, range( 1, $parts ) ); // hand to the browser
$etag   = $bucket->upload_part( $key, $upload, 3, $bytes );    // from this server: SHA-256 signed, Content-MD5 too

if ( is_wp_error( $bucket->finish_upload( $key, $upload, $etags ) ) ) {
	$bucket->abort_upload( $key, $upload );
}

$bucket->uploads( 'packs/' );        // unfinished uploads: [ [ 'key', 'upload_id', 'initiated' ] ], every page
$bucket->abort_stale( 7, 'packs/' ); // abort those started more than 7 days ago: [ 'aborted' => [...], 'failed' => [...] ]
```

`parts()` asks storage which parts it really kept (ListParts, every page), for
resuming after a reload. Null means the upload is finished, aborted or gone:
start again, rather than "nothing sent yet".

## Large files: resumable multipart uploads

One PUT carries at most 5 GB, and a multipart upload is the only way past it:
started once, sent in up to 10,000 parts, then assembled. Done in one request,
a 40 GB sample library never finishes -- PHP times out, the browser tab is
closed, the connection drops at 80%. `Multipart` makes each of those a
pause rather than a failure.

| Limit | Value | Here |
|---|---|---|
| One PUT | 5 GB | `Bucket::MAX_PUT_SIZE` |
| One object | 5 TB | `Bucket::MAX_OBJECT_SIZE`; larger is refused before anything is sent |
| Parts | 10,000 | `Bucket::MAX_PARTS`; uploads plan for at most `PART_LIMIT` (9,000) |
| Part size | 5 MiB to 5 GiB | `Multipart::PART_SIZE` (16 MiB) at least, raised by `Bucket::part_size()` for a file that needs it: 583 MiB parts for 5 TB |

Every part but the last is the same size. R2 refuses an upload whose parts are
not, and it is what lets a part number be turned back into a byte range on
any later request.

### From this server, through a job

```php
use SugarCommerce\S3\Multipart;

$multipart = new Multipart( $bucket );
$upload    = $multipart->begin( 'packs/Library.zip', filesize( $path ), [
	'source'    => $path,              // the file on this server
	'mime_type' => 'application/zip',
] );

$result = $multipart->run( $upload->id, 20 ); // send for 20 seconds, finish when every part is in
// an Upload: more to send -- run it again; a string: 's3://bucket/packs/Library.zip'; a WP_Error: why not
```

`run()` sends the missing parts for as long as it is given, never starting a
part that would not finish at the pace of the last, and always sending at
least one. Each part is remembered the moment storage takes it, so a request
killed half way loses at most the part it was sending. Hand it to
`sugarcommerce/jobs`, which is how long work runs in this family:

```php
use SugarCommerce\Jobs\Scheduler;
use SugarCommerce\S3\Upload;

$queue = Scheduler::group( 'myplugin' );

$queue->job( 'myplugin_send_file', function ( string $id ) use ( $multipart, $queue ) {
	$result = $multipart->run( $id, 20 );

	if ( $result instanceof Upload ) {
		$queue->later( 'myplugin_send_file', [ $id ] );   // more to send: carry on in the next run
	} elseif ( is_wp_error( $result ) ) {
		myplugin_log( $result );                          // nothing is lost: run it again to carry on
	}
} );

$queue->later( 'myplugin_send_file', [ $upload->id ] );
```

One run at a time: a second while one is going gets `s3_upload_busy`. A file
that changes after the upload began -- its size or modified time -- is
refused rather than sent half old and half new.

### From the browser

```php
$upload = $multipart->match( $key, $size, $fingerprint, get_current_user_id() ) // the same file, dropped again
	?? $multipart->begin( $key, $size, [ 'fingerprint' => $fingerprint, 'owner' => get_current_user_id() ] );

$urls = $multipart->sign( $upload, [ 1, 2, 3 ] );   // part => presigned PUT URL, an hour each
// the browser PUTs each part, with a Content-MD5 header storage checks

$address = $multipart->finish( $upload );           // assembled from storage's own list of parts
```

`sugarcommerce/storage-browser` does all of this already, with progress,
retries and resuming, in its bucket browser.

### What storage kept, not what anybody remembers

```php
$multipart->stored( $upload );   // [ 3 => [ 'etag' => '…', 'size' => 16777216 ], … ] from ListParts
$multipart->resume( $upload );   // this side's record brought into line with that
$upload->missing();              // [ 1, 2, 4 ]
$upload->progress();             // [ 'bytes', 'total', 'parts', 'of', 'percent' ]
```

A request that timed out after storage took the part, and a browser that
never heard back, both leave a part this side does not know about; a part
this side recorded may never have been kept. Every resume and every finish
asks storage. A part held at another size than the upload cuts is counted as
missing and sent again.

`finish()` checks every part is there at its size before asking storage to
assemble them, then that the object is as long as the file. While parts are
missing it answers `s3_upload_incomplete` with their numbers in
`missing` and keeps the upload; it never aborts on a refusal, so a finish
refused for a moment can be asked again. A finish whose answer was lost is
recognised the second time by the whole object being there. `abort()` gives
an upload up and frees its parts.

### Checksums

From this server every part is signed over its SHA-256 and carries a
Content-MD5; storage refuses one whose bytes changed on the way
(`XAmzContentSHA256Mismatch`, `BadDigest`) rather than keeping it. From the
browser each part carries a Content-MD5 (`assets/js/browser/md5.js`), which
the bucket's CORS rules have to allow: `AllowedHeaders` of `*`, or
`Content-MD5` named. When they do not, the server finds out by a preflight
before the first part, and the parts go without one -- the upload still
works, and says so.

### Clearing up

```php
$multipart->prune( 7 ); // [ 'aborted' => 2, 'failed' => 0, 'forgotten' => 3 ]
```

Aborts every unfinished upload in the bucket started more than seven days
ago, whoever started it, and forgets every upload remembered here that was
aborted or has not moved in that long. R2 aborts after seven days by itself;
most other providers only with a lifecycle rule. `sugarcommerce/storage-browser`
runs it daily for each browser. R2 lists an upload under a different id from the one it gave at the
start, so uploads are matched by key and then asked about, never by id.

Uploads are remembered as one option each (not autoloaded), named from the
build's prefix: `sugarcommerce_s3_upload_{id}`.

## Checking storage settings when they are saved

A wrong account id or region otherwise shows up when a link is signed, and
for a buyer that is a download that fails. `Connection` checks the values
a settings screen collects, then tries them.

```php
use SugarCommerce\S3\Connection;

$connection = Connection::from_array( [
	'provider'   => 'r2',          // an Provider value
	'bucket'     => $bucket,
	'access_key' => $access_key,
	'secret_key' => $secret_key,
	'account_id' => $account_id,   // R2
	'region'     => '',            // others
	'endpoint'   => '',            // MinIO and Other
] );

$checked = is_wp_error( $connection ) ? $connection : $connection->verify();

if ( is_wp_error( $checked ) ) {
	add_settings_error( 'myplugin', $checked->get_error_data()['field'], $checked->get_error_message() );
}
```

`from_array()` trims everything, lower-cases the bucket, region and account
id, cuts a pasted endpoint to its host, and keeps an account id or endpoint
only for a provider that uses one. Its error is `s3_connection_invalid`,
before anything is sent: an account id that is not the letters and numbers
at the start of an R2 endpoint, a bucket name storage would refuse, a region with a slash in it
or a region for R2, a region that does not give the provider's own address,
keys with spaces in them, an endpoint missing or malformed for MinIO.

`verify()` then lists the bucket, writes a test object, reads it back,
deletes it, and starts and aborts a multipart upload. A failure is
`s3_connection_{fault}` -- `key`, `secret`, `missing`, `elsewhere`, `refused`,
`unreachable`, `altered` and the rest of `Probe\Fault` -- with the `field` to
fix, the `check` that failed, its `status`, storage's `code` and every step
in its data, and a message that says what to change: "The secret key does
not match the access key. Paste the secret again; most providers show it
only once, when the token is created." With no bucket chosen yet it asks for
the bucket list instead, where a key scoped to buckets being refused is
fine. `var_dump()` of a connection shows no secret.

## Testing a connection

```php
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\S3\Probe;

$steps = ( new Probe() )->run( $bucket, [ Origin::current() ] );

Probe::passed( $steps );               // bool
Probe::failure( $steps )?->message;    // what to fix, translated

foreach ( $steps as $step ) {
	$step->check;    // Probe\Check::Reach | Write | Read | Delete | Multipart | Cors
	$step->fault;    // null, or Probe\Fault::Key, Secret, Refused, Missing, Elsewhere, Altered, CorsOrigin, EtagHidden, …
	$step->note;     // on a pass: something worth knowing, such as checksums being off
	$step->to_array(); // for a REST answer
}
```

With a tiny object under `connection-test/` it does everything a store will:
list one key, PUT with the uploader's `Origin`, a presigned GET checked byte
for byte, DELETE, start, list and abort a multipart upload, and the CORS
question -- the stored policy when the keys may read it, otherwise a
browser's preflight per origin, plus whether the PUT's answer exposed
`ETag`. Steps stop at the first failure, and the test object is removed
whatever happened after it was written.

A wrong region names the right one, from the `x-amz-bucket-region` header or
the error document: "The bucket "files" is in the eu-west-2 region. Set the
region to eu-west-2." No answer at all is said in terms of the address, as
`Transport::explain()` says it. R2 refuses a bucket that is not there the same
way as one the keys may not touch, and the message says both.
`Probe::preflight( $url, $origin, 'content-type,content-md5' )` is the
browser's question on its own.

### Reading, checking, copying

```php
$size = $bucket->size( $key );           // what really arrived, not what the browser said
$head = $bucket->read( $key, 0, 65535 ); // for an inspection; a WP_Error, never '', on failure
$now  = $bucket->stat( $key );           // [ 'etag', 'size', 'modified' ], or null

if ( null === $now || $now !== $checked ) {
	// somebody else's bucket changed under you: check it again
}

$public->copy_from( $private, $key, 'previews/' . $key, 'audio/mpeg', 'public, max-age=31536000, immutable' );
$bucket->put( 'previews/' . $sha . '.json', $peaks, 'application/json' );
$bucket->download_file( 'backups/site.sql', $path ); // streamed to disk, never over a file
```

`read()`, `size()` and `stat()` never take in more than they asked for: a
backwards range is refused before any request, and the answer is capped with
`limit_response_size`, so a server that ignores the Range header cannot pull a
six-gigabyte object into memory to answer a one-byte question.

### Listing and tidying

```php
$page = $bucket->list( 'packs/' );                    // one level: objects and the folders under it
$page = $bucket->list( 'packs/', '', $page['next_token'] ); // no delimiter: everything, a page at a time

// [ 'objects' => [ [ 'key', 'size', 'last_modified', 'etag', 'storage_class' ] ],
//   'prefixes' => [ 'packs/drums/' ], 'truncated' => true, 'next_token' => '…' ]

$done = $bucket->delete_many( $keys );                // [ 'deleted' => [...], 'errors' => [ [ 'key', 'code', 'message' ] ] ]
$bucket->copy( 'packs/old.zip', 'packs/new.zip' );    // in place, type and metadata kept
$bucket->region();                                    // 'eu-west-2'; S3's empty answer is 'us-east-1'
$bucket->delete_cors();

Bucket::all( $signer );                               // every bucket the key may list: [ [ 'name', 'created' ] ]
```

`delete_many()` sends DeleteObjects a thousand keys at a time and reports key
by key. A provider that does not implement it refuses the request whole --
sometimes inside a 200 -- and that is a `WP_Error`, so a caller can fall back
to `delete()`. A key scoped to one bucket cannot call ListBuckets, and the 403
`Bucket::all()` gets is how a scoped key is told from a wrong one.
`Account::signer( $access, $secret )` gives the signer for an `Account`.

## CORS

Uploading from the browser is a cross-origin request, so the bucket has to
allow it -- and has to expose `ETag`, or every part of a multipart upload
arrives and the upload then fails at assembly with an error that mentions
neither.

```php
use SugarCommerce\S3\Cors\Analysis;
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\S3\Cors\Policy;
use SugarCommerce\S3\Cors\Rules;

$bucket->set_cors( Policy::for_browser_uploads( [ Origin::current() ] ) );

$policy = $bucket->cors()['policy'];               // an empty policy when none is set
$policy->supports_multipart_upload( Origin::current() ); // [ 'ok' => false, 'reason' => 'PUT is allowed but ETag is not exposed…' ]
$policy->to_console_json();                        // to paste into the R2 or S3 console

Rules::generate( 'mixed', [ Origin::current() ] ); // ready-made rules for a settings screen
Analysis::describe( $rules, Origin::current() );   // what they permit, in words
```

Setting CORS needs a key allowed to: one scoped to object read and write
cannot call PutBucketCors, and the AccessDenied it gets reads like a bucket
problem. `Origin::current()` reads the site's configured URL, never the Host
header, because the value is written into the bucket and stays there.

## Option keys

Nothing here fires a hook. The one thing it stores is each multipart upload's
record, as an option (not autoloaded) named from the build's prefix, so two
plugins bundling their own copy never read each other's uploads:
`sugarcommerce_s3_upload_{id}` unprefixed, `myplugin_sugarcommerce_s3_upload_{id}`
in a Strauss build prefixed `MyPlugin\`. `Utils\Runtime::key()` gives the
name; `Runtime::prefix()`, `hook()` and `handle()` follow the family's rule.

## Why `Multipart::run()` has no job behind it

`run()` sends parts for as many seconds as it is given and returns, keeping
each part the moment storage takes it -- so it is a single step any scheduler
can repeat: a `sugarcommerce/jobs` job (above), Action Scheduler, a WP-CLI
loop, a REST route the browser polls. Depending on `sugarcommerce/jobs` here
would make every plugin that only signs links carry a queue it never uses.
The bucket browser's own large uploads go from the browser, and its daily
clear-up is one cron event; neither needs a queue either.

## How it is laid out

The classes a plugin uses sit at the top of `src/`: `Provider`, `Signer`,
`Bucket`, `Multipart`, `Upload`, `Connection`, `Probe`, `Account`, `Address`,
`Transport`, `Validate` and `Method`. Beside them:

* `Cors/` -- a bucket's CORS policy, its rules, what they allow, and the site's origin
* `Probe/` -- a connection test's steps (`Step`), the checks (`Check`) and what went wrong (`Fault`)
* `Signing/` -- the parts of SigV4: endpoints and canonical paths, headers, regions, the signed request
* `Support/` -- reading a provider's XML and its error documents
* `Traits/` -- `Bucket`, `Signer` and `Multipart`, one trait per job
* `Utils/` -- the build's prefix, for option names

## Security

* Signs and sends nothing a caller did not ask for: no SDK, no redirects followed, and every answer that could be large read only up to a cap
* Refuses a cloud metadata service as an endpoint (169.254.169.254 in any spelling, metadata.google.internal, 100.100.100.200, 0.0.0.0), so a connection test cannot read back the server's own credentials
* Reads no provider answer that declares a document type, and fetches nothing an answer names, so entity expansion and external entities never run
* Keeps the keys out of stack traces (`#[\SensitiveParameter]`) and out of `var_dump()` of a signer or a connection
* Refuses a header value with a line break, a key or bucket with a `.` or `..` segment, and a byte range that is not one, before anything is signed
* Treats a 200 that carries an error document as the refusal it is, so a failed copy or assembly is never recorded as done
* Holds an upload against a second sender, and never overwrites a file on download

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/storage` and `sugarcommerce/plugin`
* Credentials for an S3-compatible provider

## License

GPL-2.0-or-later
