<?php
/**
 * Concurrency Lock Management.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lock
 *
 * Provides atomic, site-scoped locking with unique owner tokens,
 * automatic expiration, safe stale-lock resolution, and owner-only release.
 */
class Lock {

	public const LOCK_OPTION = 'wpcalibrate_wcpr_lock';
	public const DEFAULT_TTL  = 120; // 2 minutes.

	/**
	 * Attempt to acquire the lock.
	 *
	 * @param int $ttl Lock lifetime in seconds.
	 * @return string|null Owner token string if lock was successfully acquired, null otherwise.
	 */
	public static function acquire( int $ttl = self::DEFAULT_TTL ): ?string {
		global $wpdb;

		$now     = time();
		$expires = $now + $ttl;
		$token   = wp_generate_password( 32, false );

		$lock_payload = (string) wp_json_encode( [
			'token'   => $token,
			'expires' => $expires,
			'created' => $now,
		] );

		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );

			// Check existing lock row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$current = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					self::LOCK_OPTION
				)
			);

			if ( null === $current ) {
				// No lock exists: attempt atomic insert.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						self::LOCK_OPTION,
						$lock_payload
					)
				);

				if ( 1 === $inserted ) {
					wp_cache_delete( self::LOCK_OPTION, 'options' );
					return $token;
				}
			} else {
				// Lock row exists: inspect expiry.
				$data = json_decode( (string) $current, true );
				$existing_expires = is_array( $data ) && isset( $data['expires'] ) ? (int) $data['expires'] : 0;

				if ( $existing_expires > $now ) {
					// Lock is still active and owned by another worker.
					return null;
				}

				// Lock is stale: perform atomic Compare-And-Swap (CAS).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
						$lock_payload,
						self::LOCK_OPTION,
						$current
					)
				);

				if ( 1 === $updated ) {
					wp_cache_delete( self::LOCK_OPTION, 'options' );
					return $token;
				}
			}

			return null;
		}

		// Fallback for environments where $wpdb direct query is not available (e.g. lightweight unit tests).
		$current = get_option( self::LOCK_OPTION, null );
		if ( null !== $current ) {
			$data = is_string( $current ) ? json_decode( $current, true ) : $current;
			if ( is_array( $data ) && isset( $data['expires'] ) && (int) $data['expires'] > $now ) {
				return null;
			}
		}

		update_option( self::LOCK_OPTION, $lock_payload, false );
		return $token;
	}

	/**
	 * Release the lock if and only if the token matches the current owner.
	 *
	 * @param string $token The owner token obtained from acquire().
	 * @return bool True if released, false if token did not match or lock already expired.
	 */
	public static function release( string $token ): bool {
		global $wpdb;

		if ( empty( $token ) ) {
			return false;
		}

		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );

			// Delete only if option_value matches our token.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
					self::LOCK_OPTION,
					'%' . $wpdb->esc_like( '"token":"' . $token . '"' ) . '%'
				)
			);

			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return $deleted > 0;
		}

		// Fallback for test doubles.
		$current = get_option( self::LOCK_OPTION, null );
		if ( null !== $current ) {
			$data = is_string( $current ) ? json_decode( $current, true ) : $current;
			if ( is_array( $data ) && isset( $data['token'] ) && hash_equals( (string) $data['token'], $token ) ) {
				delete_option( self::LOCK_OPTION );
				return true;
			}
		}

		return false;
	}

	/**
	 * Force release any lock (used during deactivation or explicit administrator reset).
	 */
	public static function force_release(): void {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					self::LOCK_OPTION
				)
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
		} else {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Check if lock is currently held.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		$current = get_option( self::LOCK_OPTION, null );
		if ( null === $current ) {
			return false;
		}

		$data = is_string( $current ) ? json_decode( $current, true ) : $current;
		if ( is_array( $data ) && isset( $data['expires'] ) ) {
			return (int) $data['expires'] > time();
		}

		return false;
	}
}
