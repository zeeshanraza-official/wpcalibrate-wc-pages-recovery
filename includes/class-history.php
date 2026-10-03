<?php
/**
 * Recovery History and State Storage.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class History
 *
 * Manages the bounded operational log (maximum 100 entries) and diagnostic states.
 */
class History {

	public const OPTION_HISTORY    = 'wpcalibrate_wcpr_history';
	public const OPTION_LAST_CHECK = 'wpcalibrate_wcpr_last_check';
	public const MAX_ENTRIES       = 100;

	/**
	 * Append a new operational record to history.
	 *
	 * @param array<string, mixed> $entry Entry details.
	 */
	public static function add( array $entry ): void {
		$history = self::get_all();

		$clean_entry = [
			'id'          => wp_generate_uuid4(),
			'timestamp'   => time(),
			'trigger'     => sanitize_key( $entry['trigger'] ?? 'system' ),
			'role'        => sanitize_key( $entry['role'] ?? 'general' ),
			'action'      => sanitize_key( $entry['action'] ?? 'check' ),
			'old_page_id' => isset( $entry['old_page_id'] ) ? absint( $entry['old_page_id'] ) : 0,
			'new_page_id' => isset( $entry['new_page_id'] ) ? absint( $entry['new_page_id'] ) : 0,
			'status'      => sanitize_key( $entry['status'] ?? 'info' ),
			'result_code' => sanitize_key( $entry['result_code'] ?? 'ok' ),
			'message'     => sanitize_text_field( $entry['message'] ?? '' ),
		];

		// Prepend new entry so latest is first.
		array_unshift( $history, $clean_entry );

		// Enforce maximum bounded size.
		if ( count( $history ) > self::MAX_ENTRIES ) {
			$history = array_slice( $history, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * Retrieve all operational history entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		$entries = get_option( self::OPTION_HISTORY, [] );
		return is_array( $entries ) ? $entries : [];
	}

	/**
	 * Clear all history entries.
	 */
	public static function clear(): void {
		delete_option( self::OPTION_HISTORY );
	}

	/**
	 * Record the most recent diagnostic check outcome.
	 *
	 * @param array<string, mixed> $summary Diagnostic check summary.
	 */
	public static function record_last_check( array $summary ): void {
		$data = [
			'timestamp' => time(),
			'summary'   => $summary,
		];
		update_option( self::OPTION_LAST_CHECK, $data, false );
	}

	/**
	 * Retrieve the most recent diagnostic check outcome.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_last_check(): ?array {
		$data = get_option( self::OPTION_LAST_CHECK, null );
		return is_array( $data ) ? $data : null;
	}
}
