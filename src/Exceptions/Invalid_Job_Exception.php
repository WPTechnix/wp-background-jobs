<?php
/**
 * Exception thrown when a stored payload cannot be resolved to a valid job.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Exceptions;

/**
 * Class Invalid_Job_Exception.
 *
 * Raised when a queued payload unserializes to something that is not a job,
 * for example because the class no longer exists or was removed from the
 * list of allowed classes. Such payloads are routed to the failures table.
 */
final class Invalid_Job_Exception extends Background_Jobs_Exception {

}
