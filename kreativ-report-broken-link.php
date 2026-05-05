<?php
/**
 * Plugin Name:       Kreativ Report Broken Link
 * Plugin URI:        https://wordpress.org/plugins/kreativ-report-broken-link/
 * Description:       Adds a “Report Broken Link” button on selected post types and stores reports in the dashboard. Optional email notifications.
 * Version:           1.4.0
 * Author:            Andrei Olaru
 * Author URI:        https://kreativfont.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kreativ-report-broken-link
 * Domain Path:       /languages
 *
 * Requires at least: 5.8
 * Tested up to:      6.9
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class KRBL_Plugin {

	const VERSION             = '1.4.0';
	const TABLE               = 'broken_link_reports';
	const NONCE               = 'krbl_nonce';
	const OPTION_NOTIFY_EMAIL = 'krbl_notify_email';
	const OPTION_POST_TYPES   = 'krbl_enabled_post_types';
	const OPTION_RETENTION    = 'krbl_retention_days';
	const OPTION_ANONYMIZE_IP = 'krbl_anonymize_ip';
	const OPTION_LAST_CLEANUP = 'krbl_last_cleanup';

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private $cache_group = 'krbl';

	/**
	 * Full DB table name.
	 *
	 * @var string
	 */
	private $table_name = '';

	public function __construct() {

		$this->table_name = $this->get_table_name();

		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Handle row actions early (before output) to ensure redirects work reliably.
		add_action( 'admin_init', array( $this, 'handle_row_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_settings_page' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_krbl_submit_report', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_krbl_submit_report', array( $this, 'handle_submit' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// CSV export (admin only) — stream download.
		add_action( 'wp_ajax_krbl_export_csv', array( $this, 'ajax_export_csv' ) );

		add_filter( 'the_content', array( $this, 'add_report_button' ) );

		// Plugin row meta.
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );

		// Admin CSS.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}


	/**
	 * Add a small, non-intrusive "Visit Kreativ Font" link under the plugin on Plugins page.
	 *
	 * @param array  $links Existing meta links.
	 * @param string $file  Plugin file basename.
	 * @return array
	 */
	public function add_plugin_row_meta( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://kreativfont.com/' ),
			esc_html__( 'Visit Kreativ Font', 'kreativ-report-broken-link' )
		);

		return $links;
	}

	/**
	 * Enqueue admin CSS only on our plugin pages.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin page slug (not sensitive form processing).
		if ( empty( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin page slug (not sensitive form processing).
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( ! in_array( $page, array( 'krbl_reports', 'krbl_settings' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'krbl-admin-css',
			plugins_url( 'assets/css/krbl-admin.css', __FILE__ ),
			array(),
			self::VERSION
		);
	}

	/**
	 * Get safe full table name.
	 *
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// Extra-safety: ensure only expected chars.
		$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );

		return $table;
	}

	/**
	 * Cache version getter (used to invalidate all cached keys instantly).
	 *
	 * @return int
	 */
	private function get_cache_version() {
		$ver = wp_cache_get( 'cache_ver', $this->cache_group );
		if ( false === $ver ) {
			$ver = 1;
			wp_cache_set( 'cache_ver', $ver, $this->cache_group, 0 );
		}
		return (int) $ver;
	}

	/**
	 * Bump cache version to invalidate row/total/export caches.
	 *
	 * @return void
	 */
	private function bump_cache_version() {
		$ver = $this->get_cache_version() + 1;
		wp_cache_set( 'cache_ver', $ver, $this->cache_group, 0 );
	}

	/**
	 * Clear cached counters (and invalidate row/total/export caches via cache_ver).
	 *
	 * @return void
	 */
	private function bust_cache() {
		wp_cache_delete( 'total_all', $this->cache_group );
		wp_cache_delete( 'total_new', $this->cache_group );
		wp_cache_delete( 'total_resolved', $this->cache_group );
		wp_cache_delete( 'total_ignored', $this->cache_group );

		$this->bump_cache_version();
	}

	/**
	 * Render plugin author footer credit on plugin pages.
	 *
	 * @return void
	 */
	private function render_kreativ_footer() {

		echo '<hr style="margin:18px 0 10px;">';
		echo '<p style="font-size:13px;color:#646970;margin:0;">';

		/* translators: 1: author name, 2: link open tag, 3: link close tag */
		$template = __( 'Created by %1$s from %2$sKreativ Font%3$s.', 'kreativ-report-broken-link' );

		$link_open  = '<a href="' . esc_url( 'https://kreativfont.com/' ) . '" target="_blank" rel="noopener noreferrer">';
		$link_close = '</a>';

		echo wp_kses_post(
			sprintf(
				$template,
				'<strong>Andrei Olaru</strong>',
				$link_open,
				$link_close
			)
		);

		echo '</p>';
	}


	/**
	 * Plugin activation: create table + set defaults.
	 *
	 * @return void
	 */
	public function activate() {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			url TEXT NOT NULL,
			user_ip VARCHAR(45) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY post_id (post_id)
		) $charset_collate;";

		dbDelta( $sql );

		if ( ! get_option( self::OPTION_NOTIFY_EMAIL ) ) {
			update_option( self::OPTION_NOTIFY_EMAIL, get_option( 'admin_email' ) );
		}

		if ( ! get_option( self::OPTION_POST_TYPES ) ) {
			update_option( self::OPTION_POST_TYPES, array( 'post' ) );
		}

		if ( false === get_option( self::OPTION_RETENTION, false ) ) {
			update_option( self::OPTION_RETENTION, 0 );
		}

		if ( false === get_option( self::OPTION_ANONYMIZE_IP, false ) ) {
			update_option( self::OPTION_ANONYMIZE_IP, 0 );
		}

		// Ensure cache version exists.
		$this->get_cache_version();
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {

		register_setting(
			'krbl_settings',
			self::OPTION_NOTIFY_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_option( 'admin_email' ),
			)
		);

		register_setting(
			'krbl_settings',
			self::OPTION_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => array( 'post' ),
			)
		);

		register_setting(
			'krbl_settings',
			self::OPTION_RETENTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => 0,
			)
		);

		register_setting(
			'krbl_settings',
			self::OPTION_ANONYMIZE_IP,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => 0,
			)
		);

		add_settings_section(
			'krbl_main',
			__( 'Notifications', 'kreativ-report-broken-link' ),
			function () {
				echo '<p>' . esc_html__( 'Choose where to send broken link notifications.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings'
		);

		add_settings_field(
			self::OPTION_NOTIFY_EMAIL,
			__( 'Notify Email', 'kreativ-report-broken-link' ),
			function () {
				$val = esc_attr( get_option( self::OPTION_NOTIFY_EMAIL, get_option( 'admin_email' ) ) );
				echo '<input type="email" name="' . esc_attr( self::OPTION_NOTIFY_EMAIL ) . '" value="' . esc_attr( $val ) . '" class="regular-text" />';
				echo '<p class="description">' . esc_html__( 'Leave blank to disable emails.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings',
			'krbl_main'
		);

		add_settings_section(
			'krbl_display',
			__( 'Display', 'kreativ-report-broken-link' ),
			function () {
				echo '<p>' . esc_html__( 'Choose where the report button should appear.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings'
		);

		add_settings_field(
			self::OPTION_POST_TYPES,
			__( 'Enabled Post Types', 'kreativ-report-broken-link' ),
			function () {

				$enabled = (array) get_option( self::OPTION_POST_TYPES, array( 'post' ) );
				$pts     = get_post_types( array( 'public' => true ), 'objects' );

				echo '<fieldset>';
				foreach ( $pts as $slug => $pt ) {
					echo '<label style="display:block;margin:3px 0;">';
					echo '<input type="checkbox" name="' . esc_attr( self::OPTION_POST_TYPES ) . '[]" value="' . esc_attr( $slug ) . '" ';
					checked( in_array( $slug, $enabled, true ) );
					echo ' /> ';
					echo esc_html( $pt->labels->singular_name ) . ' <code>(' . esc_html( $slug ) . ')</code>';
					echo '</label>';
				}
				echo '</fieldset>';

				echo '<p class="description">' . esc_html__( 'Tip: enable Pages if your site has static content pages where users might hit broken links.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings',
			'krbl_display'
		);

		add_settings_section(
			'krbl_privacy',
			__( 'Privacy & Retention', 'kreativ-report-broken-link' ),
			function () {
				echo '<p>' . esc_html__( 'Control report data privacy and cleanup behavior.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings'
		);

		add_settings_field(
			self::OPTION_ANONYMIZE_IP,
			__( 'Anonymize Visitor IP', 'kreativ-report-broken-link' ),
			function () {
				$enabled = (int) get_option( self::OPTION_ANONYMIZE_IP, 0 );
				echo '<label>';
				echo '<input type="checkbox" name="' . esc_attr( self::OPTION_ANONYMIZE_IP ) . '" value="1" ' . checked( 1, $enabled, false ) . ' />';
				echo ' ' . esc_html__( 'Store anonymized IP addresses in reports.', 'kreativ-report-broken-link' );
				echo '</label>';
			},
			'krbl_settings',
			'krbl_privacy'
		);

		add_settings_field(
			self::OPTION_RETENTION,
			__( 'Auto-Delete Reports After (days)', 'kreativ-report-broken-link' ),
			function () {
				$days = (int) get_option( self::OPTION_RETENTION, 0 );
				echo '<input type="number" min="0" step="1" class="small-text" name="' . esc_attr( self::OPTION_RETENTION ) . '" value="' . esc_attr( (string) $days ) . '" />';
				echo '<p class="description">' . esc_html__( 'Set to 0 to keep reports indefinitely.', 'kreativ-report-broken-link' ) . '</p>';
			},
			'krbl_settings',
			'krbl_privacy'
		);
	}

	/**
	 * Sanitize post types option.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_post_types( $value ) {
		$value = is_array( $value ) ? $value : array();
		$out   = array();

		foreach ( $value as $pt ) {
			$pt = sanitize_key( $pt );
			if ( post_type_exists( $pt ) ) {
				$out[] = $pt;
			}
		}

		$out = array_values( array_unique( $out ) );

		if ( empty( $out ) ) {
			$out = array( 'post' );
		}

		return $out;
	}

	/**
	 * Sanitize retention days option.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_retention_days( $value ) {
		return max( 0, absint( $value ) );
	}

	/**
	 * Sanitize checkbox-like setting to 0|1.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Enqueue frontend assets only where needed.
	 *
	 * @return void
	 */
	public function enqueue_assets() {

		if ( ! is_singular() ) {
			return;
		}

		$post_type = get_post_type();
		$enabled   = (array) get_option( self::OPTION_POST_TYPES, array( 'post' ) );

		if ( ! in_array( $post_type, $enabled, true ) ) {
			return;
		}

		wp_enqueue_style(
			'krbl-css',
			plugins_url( 'assets/css/krbl.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'krbl-js',
			plugins_url( 'assets/js/krbl.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			'krbl-js',
			'KRBL',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'sending' => __( 'Sending...', 'kreativ-report-broken-link' ),
					'thanks'  => __( 'Thanks, your report has been sent!', 'kreativ-report-broken-link' ),
					'fail'    => __( 'Failed to send. Try again later.', 'kreativ-report-broken-link' ),
					'error'   => __( 'Error. Please try again.', 'kreativ-report-broken-link' ),
					'dup'     => __( 'Already reported recently. Thank you!', 'kreativ-report-broken-link' ),
				),
			)
		);
	}

	/**
	 * Append report button to content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_report_button( $content ) {

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_type = get_post_type();
		$enabled   = (array) get_option( self::OPTION_POST_TYPES, array( 'post' ) );

		if ( ! in_array( $post_type, $enabled, true ) ) {
			return $content;
		}

		$post_id = get_the_ID();

		$button = '
		<div class="krbl-report-container">
			<button type="button" class="krbl-report-btn" data-post="' . esc_attr( $post_id ) . '">
				<span class="krbl-flag" aria-hidden="true">🚩</span>
				<span class="krbl-text">' . esc_html__( 'Report broken links on this page', 'kreativ-report-broken-link' ) . '</span>
			</button>
			<div class="krbl-output" aria-live="polite"></div>
		</div>';

		return $content . $button;
	}

	/**
	 * Get visitor IP (basic).
	 *
	 * @return string
	 */
	private function get_ip_address() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Optionally anonymize IP addresses for privacy.
	 *
	 * @param string $ip Raw IP.
	 * @return string
	 */
	private function maybe_anonymize_ip( $ip ) {
		if ( empty( $ip ) || ! (bool) get_option( self::OPTION_ANONYMIZE_IP, 0 ) ) {
			return $ip;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return $ip;
			}

			$masked = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
			$out    = inet_ntop( $masked );
			return false !== $out ? $out : $ip;
		}

		return $ip;
	}

	/**
	 * Cleanup old reports using retention setting (runs max once per day).
	 *
	 * @return void
	 */
	private function maybe_cleanup_old_reports() {
		$days = (int) get_option( self::OPTION_RETENTION, 0 );
		if ( $days <= 0 ) {
			return;
		}

		$last_cleanup = (int) get_option( self::OPTION_LAST_CLEANUP, 0 );
		if ( $last_cleanup > 0 && ( time() - $last_cleanup ) < DAY_IN_SECONDS ) {
			return;
		}

		global $wpdb;
		$table = $this->table_name;

		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table cleanup is required.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
				"DELETE FROM {$table} WHERE created_at < %s",
				$threshold
			)
		);

		update_option( self::OPTION_LAST_CLEANUP, time(), false );

		if ( is_int( $deleted ) && $deleted > 0 ) {
			$this->bust_cache();
		}
	}

	/**
	 * Handle Resolve/Ignore/Reopen actions early (before output).
	 *
	 * @return void
	 */
	public function handle_row_actions() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only run on our reports page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin page slug.
		if ( empty( $_GET['page'] ) || 'krbl_reports' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// Only run when an action is present.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading action params.
		if ( ! isset( $_GET['krbl_action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$redirect_args = array(
			'page' => 'krbl_reports',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( $status ) {
			$redirect_args['status'] = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page number.
		if ( isset( $_GET['paged'] ) ) {
			$redirect_args['paged'] = max( 1, absint( wp_unslash( $_GET['paged'] ) ) );
		}

		$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		$action = sanitize_key( wp_unslash( $_GET['krbl_action'] ) );
		$id     = absint( wp_unslash( $_GET['id'] ) );

		if ( ! wp_verify_nonce( $nonce, 'krbl_row_action' ) ) {
			$redirect_args['krbl_msg'] = 'nonce';
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! $id || ! in_array( $action, array( 'resolve', 'ignore', 'reopen' ), true ) ) {
			$redirect_args['krbl_msg'] = 'invalid';
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$new_status = ( 'resolve' === $action ) ? 'resolved' : ( ( 'ignore' === $action ) ? 'ignored' : 'new' );

		global $wpdb;
		$table = $this->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update is required.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Update query; caching not applicable. Cache is invalidated via bust_cache().
		$wpdb->update(
			$table,
			array( 'status' => $new_status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->bust_cache();

		$redirect_args['krbl_msg'] = 'updated';

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect legacy settings page links to the settings tab on the main screen.
	 *
	 * @return void
	 */
	public function handle_legacy_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'krbl_settings' !== $page ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'krbl_reports',
					'tab'  => 'settings',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle report submit (AJAX).
	 *
	 * @return void
	 */
	public function handle_submit() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wp_send_json_error( __( 'Invalid nonce.', 'kreativ-report-broken-link' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'kreativ-report-broken-link' ) );
		}

		$this->maybe_cleanup_old_reports();

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( __( 'Invalid post.', 'kreativ-report-broken-link' ) );
		}

		$enabled = (array) get_option( self::OPTION_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $enabled, true ) ) {
			wp_send_json_error( __( 'Reporting is not enabled for this content type.', 'kreativ-report-broken-link' ) );
		}

		$url = esc_url_raw( get_permalink( $post_id ) );
		if ( ! $url ) {
			wp_send_json_error( __( 'Invalid URL.', 'kreativ-report-broken-link' ) );
		}

		global $wpdb;
		$table = $this->table_name;

		$ip = $this->get_ip_address();
		if ( '' === $ip ) {
			$ip = 'unknown';
		}
		$ip = $this->maybe_anonymize_ip( $ip );

		$cache_key = 'dup_' . md5( (string) $post_id . '|' . (string) $ip );

		$cached = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			wp_send_json_error( array( 'code' => 'duplicate' ) );
		}

		// Prevent duplicates: one report per post per IP per 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
				"SELECT COUNT(*) FROM {$table}
				 WHERE post_id = %d AND user_ip = %s
				 AND created_at > DATE_SUB(%s, INTERVAL 24 HOUR)",
				$post_id,
				$ip,
				current_time( 'mysql' )
			)
		);

		if ( $exists > 0 ) {
			wp_cache_set( $cache_key, 1, $this->cache_group, DAY_IN_SECONDS );
			wp_send_json_error( array( 'code' => 'duplicate' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
		$inserted = $wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'url'        => $url,
				'user_ip'    => $ip,
				'status'     => 'new',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( __( 'Failed to save report. Please try again later.', 'kreativ-report-broken-link' ) );
		}

		$this->bust_cache();

		$notify = sanitize_email( (string) get_option( self::OPTION_NOTIFY_EMAIL, get_option( 'admin_email' ) ) );

		if ( $notify ) {

			/* translators: %s: site name */
			$subject    = sprintf( __( 'Broken link reported on %s', 'kreativ-report-broken-link' ), get_bloginfo( 'name' ) );
			$post_title = get_the_title( $post_id );

			$body  = __( 'A broken link was reported by a visitor:', 'kreativ-report-broken-link' ) . "\n\n";

			/* translators: 1: post title, 2: post ID */
			$body .= sprintf( __( 'Post: %1$s (ID: %2$d)', 'kreativ-report-broken-link' ), $post_title, $post_id ) . "\n";

			$body .= __( 'URL:', 'kreativ-report-broken-link' ) . ' ' . $url . "\n";
			$body .= __( 'IP:', 'kreativ-report-broken-link' ) . ' ' . ( $ip ? $ip : 'N/A' ) . "\n";
			$body .= __( 'Time:', 'kreativ-report-broken-link' ) . ' ' . current_time( 'mysql' ) . "\n\n";
			$body .= __( 'View reports:', 'kreativ-report-broken-link' ) . ' ' . admin_url( 'admin.php?page=krbl_reports' );

			wp_mail( $notify, $subject, $body );
		}

		wp_send_json_success( true );
	}

	/**
	 * Escape a field for CSV output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function csv_field( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = str_replace( '"', '""', $value );
		return '"' . $value . '"';
	}

	/**
	 * Export reports to CSV (AJAX) — STREAM DOWNLOAD (no uploads folder files).
	 *
	 * @return void
	 */
	public function ajax_export_csv() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'kreativ-report-broken-link' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'krbl_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'kreativ-report-broken-link' ), '', array( 'response' => 403 ) );
		}

		global $wpdb;

		$table = $this->table_name;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( $status && ! in_array( $status, array( 'new', 'resolved', 'ignored' ), true ) ) {
			$status = '';
		}

		$ver       = $this->get_cache_version();
		$cache_key = 'export_' . $ver . '_' . md5( (string) $status );

		$rows = wp_cache_get( $cache_key, $this->cache_group );

		if ( false === $rows ) {

			if ( $status ) {

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is safe (sanitized via get_table_name()).
				$sql = $wpdb->prepare("SELECT id, post_id, url, user_ip, status, created_at FROM " . $table . " WHERE status = %s ORDER BY created_at DESC", $status);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; Plugin Check can't always infer this.
				$rows = $wpdb->get_results( $sql, ARRAY_A );

			} else {

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
				$sql = "SELECT id, post_id, url, user_ip, status, created_at
					FROM {$table}
					ORDER BY created_at DESC";

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; Plugin Check requires explicit ignore for $sql.
				$rows = $wpdb->get_results( $sql, ARRAY_A );
			}

			wp_cache_set( $cache_key, $rows, $this->cache_group, 60 );
		}

		$lines   = array();
		$lines[] = '"ID","Post ID","URL","IP","Status","Date"';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$lines[] = implode(
					',',
					array(
						$this->csv_field( (int) $row['id'] ),
						$this->csv_field( (int) $row['post_id'] ),
						$this->csv_field( $row['url'] ),
						$this->csv_field( $row['user_ip'] ),
						$this->csv_field( $row['status'] ),
						$this->csv_field( $row['created_at'] ),
					)
				);
			}
		}

		$csv = implode( "\n", $lines ) . "\n";

		$filename = 'krbl-reports-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output is intended.
		exit;
	}

	/**
	 * Admin menu.
	 *
	 * @return void
	 */
	public function admin_menu() {

		add_menu_page(
			__( 'Broken Links', 'kreativ-report-broken-link' ),
			__( 'Broken Links', 'kreativ-report-broken-link' ),
			'manage_options',
			'krbl_reports',
			array( $this, 'render_admin_page' ),
			'dashicons-flag',
			65
		);

		add_submenu_page(
			'krbl_reports',
			__( 'Reports', 'kreativ-report-broken-link' ),
			__( 'Reports', 'kreativ-report-broken-link' ),
			'manage_options',
			'krbl_reports',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render navigation tabs for the admin page.
	 *
	 * @param string $active_tab Active tab slug.
	 * @return void
	 */
	private function render_admin_tabs( $active_tab ) {
		$tabs = array(
			'reports'  => __( 'Reports', 'kreativ-report-broken-link' ),
			'settings' => __( 'Settings', 'kreativ-report-broken-link' ),
		);

		echo '<nav class="nav-tab-wrapper krbl-nav-tabs" aria-label="' . esc_attr__( 'Broken Links sections', 'kreativ-report-broken-link' ) . '">';

		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg(
				array(
					'page' => 'krbl_reports',
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			$class = ( $active_tab === $slug ) ? ' nav-tab-active' : '';

			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';
	}

	/**
	 * Render settings tab content.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		echo '<h1>' . esc_html__( 'Broken Links - Settings', 'kreativ-report-broken-link' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'krbl_settings' );
		do_settings_sections( 'krbl_settings' );
		submit_button();
		echo '</form>';
	}

	/**
	 * Get cached overview count by status.
	 *
	 * @param string $status new|resolved|ignored
	 * @return int
	 */
	private function get_overview_count( $status ) {
		$ver = $this->get_cache_version();
		$key = 'overview_' . $ver . '_' . sanitize_key( $status );

		$val = wp_cache_get( $key, $this->cache_group );
		if ( false !== $val ) {
			return (int) $val;
		}

		global $wpdb;
		$table = $this->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
		$val = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				$status
			)
		);

		wp_cache_set( $key, $val, $this->cache_group, 60 );
		return (int) $val;
	}

	/**
	 * Reports admin page output.
	 *
	 * @return void
	 */
	public function render_admin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_cleanup_old_reports();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab selector.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'reports';
		if ( ! in_array( $tab, array( 'reports', 'settings' ), true ) ) {
			$tab = 'reports';
		}

		echo '<div class="wrap">';
		$this->render_admin_tabs( $tab );

		if ( 'settings' === $tab ) {
			$this->render_settings_tab();
			$this->render_kreativ_footer();
			echo '</div>';
			return;
		}

		global $wpdb;
		$table = $this->table_name;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter parameter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$per_page = 100;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading page number.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$offset = ( $paged - 1 ) * $per_page;

		$ver = $this->get_cache_version();

		$total_key   = 'total_' . $ver . '_' . ( $status ? $status : 'all' );
		$total_items = wp_cache_get( $total_key, $this->cache_group );

		if ( false === $total_items ) {

			if ( $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				$total_items = (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
						"SELECT COUNT(*) FROM {$table} WHERE status = %s",
						$status
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				$total_items = (int) $wpdb->get_var(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
					"SELECT COUNT(*) FROM {$table}"
				);
			}

			wp_cache_set( $total_key, (int) $total_items, $this->cache_group, 60 );
		}

		$total_pages = max( 1, (int) ceil( (int) $total_items / $per_page ) );

		$rows_key = 'rows_' . $ver . '_' . md5( (string) $status . '|' . (int) $per_page . '|' . (int) $offset );
		$rows     = wp_cache_get( $rows_key, $this->cache_group );

		if ( false === $rows ) {

			if ( $status ) {

				$sql = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
					"SELECT * FROM {$table}
					 WHERE status = %s
					 ORDER BY created_at DESC
					 LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; Plugin Check can't always infer this.
				$rows = $wpdb->get_results( $sql );

			} else {

				$sql = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (sanitized via get_table_name()).
					"SELECT * FROM {$table}
					 ORDER BY created_at DESC
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table required.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; Plugin Check can't always infer this.
				$rows = $wpdb->get_results( $sql );
			}

			wp_cache_set( $rows_key, $rows, $this->cache_group, 60 );
		}

		$base_url = admin_url( 'admin.php?page=krbl_reports' );

		$new_count      = $this->get_overview_count( 'new' );
		$resolved_count = $this->get_overview_count( 'resolved' );
		$ignored_count  = $this->get_overview_count( 'ignored' );

		$query_args = array();
		if ( $status ) {
			$query_args['status'] = $status;
		}

		$base_url_with_args  = add_query_arg( $query_args, $base_url );
		$base_url_pagination = remove_query_arg( 'paged', $base_url_with_args );

		$ajax_export_nonce = wp_create_nonce( 'krbl_export' );

		echo '<h1>' . esc_html__( 'Broken Link Reports', 'kreativ-report-broken-link' ) . '</h1>';

		// Notices (shown after redirect).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading message param.
		if ( isset( $_GET['krbl_msg'] ) && 'updated' === sanitize_key( wp_unslash( $_GET['krbl_msg'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Status updated.', 'kreativ-report-broken-link' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading message param.
		if ( isset( $_GET['krbl_msg'] ) && 'nonce' === sanitize_key( wp_unslash( $_GET['krbl_msg'] ) ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Please try again.', 'kreativ-report-broken-link' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading message param.
		if ( isset( $_GET['krbl_msg'] ) && 'invalid' === sanitize_key( wp_unslash( $_GET['krbl_msg'] ) ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Invalid action.', 'kreativ-report-broken-link' ) . '</p></div>';
		}

		echo '<div class="krbl-overview" style="margin:12px 0 16px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1;font-size:14px">';
		echo '<strong>' . esc_html__( 'Overview', 'kreativ-report-broken-link' ) . '</strong> — ';
		echo esc_html__( 'New', 'kreativ-report-broken-link' ) . ': ' . (int) $new_count . ' | ';
		echo esc_html__( 'Resolved', 'kreativ-report-broken-link' ) . ': ' . (int) $resolved_count . ' | ';
		echo esc_html__( 'Ignored', 'kreativ-report-broken-link' ) . ': ' . (int) $ignored_count;
		echo '</div>';

		echo '<div class="krbl-actions-bar">';
		echo '<div>';

		echo '<a class="button' . ( '' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $base_url ) . '">' . esc_html__( 'All', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'new' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'new', $base_url ) ) . '">' . esc_html__( 'New', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'resolved' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'resolved', $base_url ) ) . '">' . esc_html__( 'Resolved', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'ignored' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'ignored', $base_url ) ) . '">' . esc_html__( 'Ignored', 'kreativ-report-broken-link' ) . '</a>';

		echo '</div>';

		echo '<div>';
		echo '<button id="krbl-export-csv" class="button button-secondary">⬇️ ' . esc_html__( 'Export CSV', 'kreativ-report-broken-link' ) . '</button>';
		echo '<input type="hidden" id="krbl-export-nonce" value="' . esc_attr( $ajax_export_nonce ) . '">';
		echo '</div>';
		echo '</div>';

		if ( $total_pages > 1 ) {

			echo '<div class="krbl-pagination">';
			echo '<span class="krbl-page-info">';

			/* translators: 1: current page number, 2: total number of pages */
			$page_template = __( 'Page %1$d of %2$d', 'kreativ-report-broken-link' );

			echo esc_html(
				sprintf(
					$page_template,
					(int) $paged,
					(int) $total_pages
				)
			);

			echo '</span>';

			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $base_url_pagination ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '‹',
						'next_text' => '›',
						'type'      => 'list',
					)
				)
			);

			echo '</div>';
		}

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No reports yet.', 'kreativ-report-broken-link' ) . '</p>';
			$this->render_kreativ_footer();
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'Post', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'kreativ-report-broken-link' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'kreativ-report-broken-link' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {

			$post_title = $r->post_id ? get_the_title( $r->post_id ) : __( '(no post)', 'kreativ-report-broken-link' );
			$post_link  = $r->post_id ? '<a href="' . esc_url( get_edit_post_link( $r->post_id ) ) . '">' . esc_html( $post_title ) . '</a>' : esc_html( $post_title );

			$row_url_base = add_query_arg(
				array(
					'page' => 'krbl_reports',
					'id'   => (int) $r->id,
				),
				admin_url( 'admin.php' )
			);

			if ( $status ) {
				$row_url_base = add_query_arg( 'status', $status, $row_url_base );
			}
			if ( $paged > 1 ) {
				$row_url_base = add_query_arg( 'paged', $paged, $row_url_base );
			}

			if ( 'new' === $r->status ) {
				$resolve_url = wp_nonce_url( add_query_arg( 'krbl_action', 'resolve', $row_url_base ), 'krbl_row_action' );
				$ignore_url  = wp_nonce_url( add_query_arg( 'krbl_action', 'ignore', $row_url_base ), 'krbl_row_action' );

				$actions  = '<span class="krbl-actions-inline">';
				$actions .= '<a class="button button-small krbl-btn-resolve" href="' . esc_url( $resolve_url ) . '">' . esc_html__( 'Resolve', 'kreativ-report-broken-link' ) . '</a> ';
				$actions .= '<a class="button button-small krbl-btn-ignore" href="' . esc_url( $ignore_url ) . '">' . esc_html__( 'Ignore', 'kreativ-report-broken-link' ) . '</a>';
				$actions .= '</span>';
			} else {
				$reopen_url = wp_nonce_url( add_query_arg( 'krbl_action', 'reopen', $row_url_base ), 'krbl_row_action' );

				$actions  = '<span class="krbl-actions-inline">';
				$actions .= '<a class="button button-small krbl-btn-reopen" href="' . esc_url( $reopen_url ) . '">' . esc_html__( 'Reopen', 'kreativ-report-broken-link' ) . '</a>';
				$actions .= '</span>';
			}

			echo '<tr>';
			echo '<td>' . (int) $r->id . '</td>';
			echo '<td>' . esc_html( $r->created_at ) . '</td>';
			echo '<td>' . wp_kses_post( $post_link ) . '</td>';
			echo '<td><a href="' . esc_url( $r->url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $r->url ) . '</a></td>';
			echo '<td>' . esc_html( $r->user_ip ) . '</td>';
			echo '<td>' . esc_html( $r->status ) . '</td>';
			echo '<td>' . wp_kses_post( $actions ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<script>
		(function(){
			var btn = document.getElementById("krbl-export-csv");
			if(!btn) return;

			btn.addEventListener("click", function(){
				var nonce = document.getElementById("krbl-export-nonce").value || "";
				var status = ' . wp_json_encode( (string) $status ) . ';
				var url = (window.ajaxurl || ' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ');
				url += (url.indexOf("?") === -1 ? "?" : "&");
				url += "action=krbl_export_csv";
				url += "&nonce=" + encodeURIComponent(nonce);
				if(status){
					url += "&status=" + encodeURIComponent(status);
				}
				window.location.href = url;
			});
		})();
		</script>';

		$this->render_kreativ_footer();

		echo '</div>';
	}
}

new KRBL_Plugin();
