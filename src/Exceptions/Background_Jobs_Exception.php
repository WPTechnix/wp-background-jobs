<?php
/**
 * Base exception for the background jobs library.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Exceptions;

use RuntimeException;

/**
 * Class Background_Jobs_Exception.
 *
 * All exceptions thrown by this library extend from this base class, so consumers
 * can catch every library specific error with a single catch block.
 */
abstract class Background_Jobs_Exception extends RuntimeException {

}
