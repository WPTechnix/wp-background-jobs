<?php
/**
 * Safe serialization of jobs to and from their stored representation.
 */

declare(strict_types=1);

namespace WPTechnix\WP_Background_Jobs\Support;

use WPTechnix\WP_Background_Jobs\Contracts\Job_Interface;

/**
 * Class Serializer.
 *
 * Wraps native PHP serialization with an allowed classes guard so untrusted or
 * stale rows cannot instantiate arbitrary objects during unserialization.
 */
final class Serializer {

	/**
	 * Classes permitted during unserialization.
	 *
	 * True allows any class, false allows none, and an array restricts to the
	 * listed class names.
	 *
	 * @var list<class-string>|bool
	 */
	private array|bool $allowed_classes;

	/**
	 * Serializer constructor.
	 *
	 * @param list<class-string>|bool $allowed_classes Classes allowed during unserialization.
	 */
	public function __construct( array|bool $allowed_classes = true ) {
		$this->allowed_classes = $allowed_classes;
	}

	/**
	 * Serializes a job into a storable string.
	 *
	 * @param Job_Interface $job The job to serialize.
	 *
	 * @return string The serialized payload.
	 */
	public function serialize( Job_Interface $job ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return serialize( $job );
	}

	/**
	 * Restores a job from its stored string.
	 *
	 * @param string $payload The serialized payload.
	 *
	 * @return Job_Interface|null The restored job, or null when the payload is not a valid job.
	 */
	public function unserialize( string $payload ): ?Job_Interface {
		if ( '' === $payload ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$value = @unserialize( $payload, [ 'allowed_classes' => $this->allowed_classes ] );

		if ( ! $value instanceof Job_Interface ) {
			return null;
		}

		return $value;
	}
}
