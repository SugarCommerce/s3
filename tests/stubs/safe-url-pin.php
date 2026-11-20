<?php
/**
 * curl_setopt() as network's SafeUrl sees it: CURLOPT_RESOLVE recorded in
 * $GLOBALS['s3_pin'], since a real handle cannot be asked for it afterwards.
 *
 * An unqualified call resolves to a function in the caller's namespace
 * before the global one.
 *
 * @package SugarCommerce\S3
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Remote;

/**
 * Record CURLOPT_RESOLVE; pass anything else through.
 *
 * @param mixed $handle The handle.
 * @param int   $option The option.
 * @param mixed $value  Its value.
 *
 * @return bool
 */
function curl_setopt( $handle, int $option, $value ): bool {
	if ( CURLOPT_RESOLVE === $option ) {
		$GLOBALS['s3_pin'] = $value;

		return true;
	}

	return \curl_setopt( $handle, $option, $value );
}
