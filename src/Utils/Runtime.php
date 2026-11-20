<?php
/**
 * Runtime names
 *
 * @package     SugarCommerce\S3
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\S3\Utils;

/**
 * Every runtime string this package registers -- hooks, keys, handles --
 * named for the build it was compiled into, by sugarcommerce/plugin's
 * Runtime, from this class's own namespace.
 *
 * Unprefixed, a hook is `sugarcommerce_s3_{name}`; in a build prefixed
 * `MyPlugin\` it is `myplugin_sugarcommerce_s3_{name}`.
 */
final class Runtime extends \SugarCommerce\Plugin\Runtime {

	/**
	 * This package's own identifier, used when running unprefixed.
	 */
	protected const string LIBRARY = 'sugarcommerce_s3';

	/**
	 * How many segments this class's own namespace has --
	 * SugarCommerce\S3\Utils -- before any prefix.
	 */
	protected const int OWN_SEGMENTS = 3;
}
