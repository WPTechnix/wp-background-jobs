<?php
/**
 * Exception recorded when a job exhausts its retry budget.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Exceptions;

/**
 * Class Max_Attempts_Exceeded_Exception.
 *
 * Used as the failure reason when a job is moved to the failures table because
 * it reached its maximum number of attempts without a captured exception of its
 * own (for example a fatal error that left no catchable exception).
 */
final class Max_Attempts_Exceeded_Exception extends Background_Jobs_Exception {

}
