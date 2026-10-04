<?php
/**
 * Test Environment Bootstrap and WordPress / WooCommerce Test Doubles.
 *
 * All WordPress and WooCommerce core stubs MUST live in the global namespace.
 *
 * @package WPCalibrate\WooPagesRecovery\Tests
 */

declare(strict_types=1);

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! defined( 'WPCALIBRATE_WCPR_VERSION' ) ) {
		define( 'WPCALIBRATE_WCPR_VERSION', '1.0.0' );
		define( 'WPCALIBRATE_WCPR_PLUGIN_FILE', dirname( __DIR__ ) . '/wpcalibrate-wc-pages-recovery.php' );
		define( 'WPCALIBRATE_WCPR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
		define( 'WPCALIBRATE_WCPR_PLUGIN_URL', 'http://example.com/wp-content/plugins/wpcalibrate-wc-pages-recovery/' );
		define( 'WPCALIBRATE_WCPR_MIN_PHP', '8.2.0' );
		define( 'WPCALIBRATE_WCPR_MIN_WP', '7.0' );
		define( 'WPCALIBRATE_WCPR_MIN_WC', '11.1.0' );
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	/**
	 * In-memory simulated WordPress database state.
	 */
	class WCPR_Test_State {
		public static array $options = [];
		public static array $transients = [];
		public static array $posts = [];
		public static array $post_meta = [];
		public static array $actions = [];
		public static array $filters = [];
		public static array $scheduled_actions = [];
		public static array $cron_events = [];
		public static array $current_user_caps = [ 'manage_options' => true, 'activate_plugins' => true ];
		public static int $post_auto_id = 100;
		public static array $menu = [];
		public static array $submenu = [];
		public static array $admin_page_hooks = [];
		public static array $logs = [];
		public static array $http_responses = [];
		public static bool $simulate_option_write_failure = false;
		public static bool $simulate_post_insert_failure = false;

		public static function reset(): void {
			self::$options = [];
			self::$transients = [];
			self::$posts = [];
			self::$post_meta = [];
			self::$actions = [];
			self::$filters = [];
			self::$scheduled_actions = [];
			self::$cron_events = [];
			self::$current_user_caps = [ 'manage_options' => true, 'activate_plugins' => true ];
			self::$post_auto_id = 100;
			self::$menu = [];
			self::$submenu = [];
			self::$admin_page_hooks = [];
			self::$logs = [];
			self::$http_responses = [];
			self::$simulate_option_write_failure = false;
			self::$simulate_post_insert_failure = false;
		}
	}

	/* ================= WORDPRESS CORE STUBS ================= */

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID = 0;
			public string $post_title = '';
			public string $post_name = '';
			public string $post_content = '';
			public string $post_status = 'publish';
			public string $post_type = 'page';
			public string $post_password = '';

			public function __construct( array $data = [] ) {
				foreach ( $data as $key => $val ) {
					$this->$key = $val;
				}
			}
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			protected string $code;
			protected string $message;
			public function __construct( string $code, string $message ) {
				$this->code = $code;
				$this->message = $message;
			}
			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	function get_option( string $name, $default = false ) {
		return WCPR_Test_State::$options[ $name ] ?? $default;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		if ( WCPR_Test_State::$simulate_option_write_failure && str_starts_with( $name, 'woocommerce_' ) ) {
			return false;
		}
		WCPR_Test_State::$options[ $name ] = $value;
		return true;
	}

	function add_option( string $name, $value, string $deprecated = '', $autoload = 'yes' ): bool {
		if ( isset( WCPR_Test_State::$options[ $name ] ) ) {
			return false;
		}
		WCPR_Test_State::$options[ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		unset( WCPR_Test_State::$options[ $name ] );
		return true;
	}

	function get_transient( string $name ) {
		$t = WCPR_Test_State::$transients[ $name ] ?? null;
		if ( ! $t ) {
			return false;
		}
		if ( $t['expires'] > 0 && $t['expires'] < time() ) {
			unset( WCPR_Test_State::$transients[ $name ] );
			return false;
		}
		return $t['val'];
	}

	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		WCPR_Test_State::$transients[ $name ] = [
			'val'     => $value,
			'expires' => $expiration > 0 ? time() + $expiration : 0,
		];
		return true;
	}

	function delete_transient( string $name ): bool {
		unset( WCPR_Test_State::$transients[ $name ] );
		return true;
	}

	function get_post( $post_id ) {
		$id = absint( is_object( $post_id ) ? $post_id->ID : $post_id );
		return WCPR_Test_State::$posts[ $id ] ?? null;
	}

	function get_posts( array $args = [] ): array {
		$results = [];
		$status = $args['post_status'] ?? 'publish';
		$type = $args['post_type'] ?? 'page';
		$not_in = $args['post__not_in'] ?? [];

		foreach ( WCPR_Test_State::$posts as $post ) {
			if ( $post->post_type !== $type ) {
				continue;
			}
			if ( 'any' !== $status && $post->post_status !== $status ) {
				continue;
			}
			if ( in_array( $post->ID, $not_in, true ) ) {
				continue;
			}
			$results[] = $post;
		}
		return $results;
	}

	function wp_insert_post( array $data, bool $wp_error = false ) {
		if ( WCPR_Test_State::$simulate_post_insert_failure ) {
			return $wp_error ? new \WP_Error( 'insert_failed', 'Simulated failure' ) : 0;
		}
		$id = ++WCPR_Test_State::$post_auto_id;
		$post = new \WP_Post( array_merge( [ 'ID' => $id ], $data ) );
		WCPR_Test_State::$posts[ $id ] = $post;
		return $id;
	}

	function wp_update_post( array $data ) {
		$id = absint( $data['ID'] ?? 0 );
		if ( isset( WCPR_Test_State::$posts[ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				WCPR_Test_State::$posts[ $id ]->$k = $v;
			}
			return $id;
		}
		return 0;
	}

	function wp_trash_post( int $post_id ) {
		if ( isset( WCPR_Test_State::$posts[ $post_id ] ) ) {
			$post = WCPR_Test_State::$posts[ $post_id ];
			update_post_meta( $post_id, '_wp_trash_meta_status', $post->post_status );
			$post->post_status = 'trash';
			\WPCalibrate\WooPagesRecovery\Scheduler::on_post_trashed( $post_id );
			return $post;
		}
		return false;
	}

	function wp_untrash_post( int $post_id ) {
		if ( isset( WCPR_Test_State::$posts[ $post_id ] ) ) {
			$post = WCPR_Test_State::$posts[ $post_id ];
			$pre = get_post_meta( $post_id, '_wp_trash_meta_status', true ) ?: 'publish';
			$post->post_status = $pre;
			\WPCalibrate\WooPagesRecovery\Scheduler::on_post_untrashed( $post_id );
			return $post;
		}
		return false;
	}

	function clean_post_cache( int $id ) {}

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return WCPR_Test_State::$post_meta[ $post_id ][ $key ] ?? ( $single ? '' : [] );
	}

	function update_post_meta( int $post_id, string $key, $value ): bool {
		WCPR_Test_State::$post_meta[ $post_id ][ $key ] = $value;
		return true;
	}

	function delete_post_meta( int $post_id, string $key ): bool {
		unset( WCPR_Test_State::$post_meta[ $post_id ][ $key ] );
		return true;
	}

	function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		WCPR_Test_State::$actions[ $tag ][] = [ 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args ];
	}

	function do_action( string $tag, ...$args ): void {
		if ( isset( WCPR_Test_State::$actions[ $tag ] ) ) {
			foreach ( WCPR_Test_State::$actions[ $tag ] as $hook ) {
				call_user_func_array( $hook['callback'], array_slice( $args, 0, $hook['args'] ) );
			}
		}
	}

	function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		WCPR_Test_State::$filters[ $tag ][] = [ 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args ];
	}

	function apply_filters( string $tag, $value, ...$args ) {
		if ( isset( WCPR_Test_State::$filters[ $tag ] ) ) {
			foreach ( WCPR_Test_State::$filters[ $tag ] as $hook ) {
				$value = call_user_func_array( $hook['callback'], array_merge( [ $value ], array_slice( $args, 0, $hook['args'] - 1 ) ) );
			}
		}
		return $value;
	}

	function wp_generate_password( int $length = 12, bool $special = true ): string {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}

	function wp_generate_uuid4(): string {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}

	function wp_json_encode( $data, int $flags = 0 ): string|false {
		return json_encode( $data, $flags );
	}

	function wp_parse_args( $args, $defaults = [] ): array {
		if ( is_object( $args ) ) {
			$r = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$r = &$args;
		} else {
			$r = [];
		}
		if ( is_array( $defaults ) ) {
			return array_merge( $defaults, $r );
		}
		return $r;
	}

	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}

	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	function sanitize_text_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}

	function sanitize_title( $title ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '-', (string) $title ) ) );
	}

	function current_user_can( string $cap ): bool {
		return ! empty( WCPR_Test_State::$current_user_caps[ $cap ] );
	}

	function wp_cache_delete( string $key, string $group = '' ) {}

	function wp_die( string $msg = '' ) {
		throw new \RuntimeException( 'wp_die called: ' . $msg );
	}

	function register_setting( string $group, string $name, array $args = [] ) {}

	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		global $admin_page_hooks;
		$admin_page_hooks[ $menu_slug ] = true;
		WCPR_Test_State::$admin_page_hooks[ $menu_slug ] = true;
		WCPR_Test_State::$menu[ $menu_slug ] = [ 'title' => $menu_title, 'slug' => $menu_slug, 'callback' => $callback, 'icon' => $icon_url ];
	}

	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		WCPR_Test_State::$submenu[ $parent_slug ][ $menu_slug ] = [ 'title' => $menu_title, 'slug' => $menu_slug, 'callback' => $callback ];
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( string $url ): string {
		return $url;
	}

	function admin_url( string $path = '' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}

	function wp_safe_redirect( string $location ): void {}

	function check_admin_referer( string $action, string $name = '_wpnonce' ): bool {
		return true;
	}

	function wp_create_nonce( string $action ): string {
		return substr( md5( $action . 'secret' ), 0, 10 );
	}

	function wp_nonce_field( int|string $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
		$nonce_field = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( (string) $action ) ) . '" />';
		if ( $display ) {
			echo $nonce_field;
		}
		return $nonce_field;
	}

	function wp_verify_nonce( string $nonce, string $action ): bool {
		return hash_equals( wp_create_nonce( $action ), $nonce );
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}

	function has_shortcode( string $content, string $tag ): bool {
		return false !== stripos( $content, '[' . $tag );
	}

	function parse_blocks( string $content ): array {
		$blocks = [];
		if ( preg_match_all( '/<!--\s+wp:([a-z0-9_\-\/]+)(\s+(\{.*?\}))?\s+(\/)?-->/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$block_name = $m[1];
				$attrs = ! empty( $m[3] ) ? ( json_decode( $m[3], true ) ?: [] ) : [];
				$blocks[] = [
					'blockName'   => $block_name,
					'attrs'       => $attrs,
					'innerBlocks' => [],
				];
			}
		}
		return $blocks;
	}

	function wp_clear_scheduled_hook( string $hook ): void {
		unset( WCPR_Test_State::$cron_events[ $hook ] );
	}

	function wp_next_scheduled( string $hook ) {
		return WCPR_Test_State::$cron_events[ $hook ] ?? false;
	}

	function wp_schedule_single_event( int $timestamp, string $hook ): bool {
		WCPR_Test_State::$cron_events[ $hook ] = $timestamp;
		return true;
	}

	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		WCPR_Test_State::$cron_events[ $hook ] = $timestamp;
		return true;
	}

	function get_edit_post_link( int $id ): string {
		return "http://example.com/wp-admin/post.php?post={$id}&action=edit";
	}

	function get_permalink( int $id ): string {
		return "http://example.com/?p={$id}";
	}

	/* ================= WOOCOMMERCE & ACTION SCHEDULER STUBS ================= */

	if ( ! class_exists( 'WooCommerce' ) ) {
		class WooCommerce {}
	}

	function WC(): WooCommerce {
		return new WooCommerce();
	}

	function wc_get_logger() {
		return new class {
			public function log( string $level, string $message, array $context = [] ): void {
				WCPR_Test_State::$logs[] = [ 'level' => $level, 'message' => $message, 'context' => $context ];
			}
		};
	}

	function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
		return isset( WCPR_Test_State::$scheduled_actions[ $hook ] );
	}

	function wp_remote_get( string $url, array $args = [] ) {
		if ( isset( WCPR_Test_State::$http_responses[ $url ] ) ) {
			return WCPR_Test_State::$http_responses[ $url ];
		}
		return [ 'response' => [ 'code' => 200 ], 'body' => '[]' ];
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? ( $response['response']['code'] ?? 200 ) : 200;
	}

	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}

	function plugin_basename( string $file ): string {
		$file = str_replace( '\\', '/', $file );
		if ( str_contains( $file, 'wpcalibrate-wc-pages-recovery' ) ) {
			return 'wpcalibrate-wc-pages-recovery/wpcalibrate-wc-pages-recovery.php';
		}
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	function untrailingslashit( string $string ): string {
		return rtrim( $string, '/\\' );
	}

	function wp_kses_post( string $content ): string {
		return $content;
	}

	function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '' ): int {
		WCPR_Test_State::$scheduled_actions[ $hook ] = [ 'timestamp' => $timestamp, 'recurring' => false, 'group' => $group ];
		return 1;
	}

	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = [], string $group = '' ): int {
		WCPR_Test_State::$scheduled_actions[ $hook ] = [ 'timestamp' => $timestamp, 'recurring' => true, 'interval' => $interval, 'group' => $group ];
		return 1;
	}

	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
		unset( WCPR_Test_State::$scheduled_actions[ $hook ] );
	}

	function as_next_scheduled_action( string $hook, array $args = [], string $group = '' ) {
		return isset( WCPR_Test_State::$scheduled_actions[ $hook ] ) ? 1 : false;
	}

	if ( ! class_exists( 'ActionScheduler' ) ) {
		class ActionScheduler {
			public static function store() {
				return new class {
					public function fetch_action( $id ) {
						return new class {
							public function get_schedule() {
								return new class {
									public function get_date() {
										return new class {
											public function getTimestamp() {
												return time() + 3600;
											}
										};
									}
								};
							}
						};
					}
				};
			}
		}
	}

	// Autoload plugin classes.
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-lock.php';
	require_once dirname( __DIR__ ) . '/includes/class-history.php';
	require_once dirname( __DIR__ ) . '/includes/class-page-inspector.php';
	require_once dirname( __DIR__ ) . '/includes/class-recovery-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-scheduler.php';
	require_once dirname( __DIR__ ) . '/includes/class-admin.php';
	require_once dirname( __DIR__ ) . '/includes/class-lifecycle.php';
	require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
}
