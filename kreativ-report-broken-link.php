<?php
/**
 * Plugin Name: Kreativ Report Broken Link
 * Description: Automatically adds a 'Report Broken Link' button at the end of every post. When clicked, it auto-submits a report to the admin.
 * Version: 1.2.0
 * Author: Andrei Olaru
 * License: GPL-2.0+
 * Text Domain: kreativ-report-broken-link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KRBL_Plugin {
	const TABLE               = 'broken_link_reports';
	const NONCE               = 'krbl_nonce';
	const OPTION_NOTIFY_EMAIL = 'krbl_notify_email';

	public function __construct() {
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_krbl_submit_report', [ $this, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_krbl_submit_report', [ $this, 'handle_submit' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_filter( 'the_content', [ $this, 'add_report_button' ] );
	}

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
	}

	public function register_settings() {
		register_setting(
			'krbl_settings',
			self::OPTION_NOTIFY_EMAIL,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_option( 'admin_email' ),
			]
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
			},
			'krbl_settings',
			'krbl_main'
		);
	}

	public function enqueue_assets() {
		wp_register_script( 'krbl-js', '', [], '1.2.0', true );

		$data = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
		];

		wp_localize_script( 'krbl-js', 'KRBL', $data );

		$inline  = 'document.addEventListener("click", function(e){' . "\n";
		$inline .= '  var btn = e.target.closest(".krbl-report-btn");' . "\n";
		$inline .= '  if(!btn) return;' . "\n";
		$inline .= '  e.preventDefault();' . "\n";
		$inline .= '  var container = btn.closest(".krbl-report-container");' . "\n";
		$inline .= '  var out = container.querySelector(".krbl-output");' . "\n";
		$inline .= '  btn.disabled = true;' . "\n";
		$inline .= '  btn.textContent = "⏳ Sending...";' . "\n";
		$inline .= '  var fd = new FormData();' . "\n";
		$inline .= '  fd.append("action","krbl_submit_report");' . "\n";
		$inline .= '  fd.append("_wpnonce", KRBL.nonce);' . "\n";
		$inline .= '  fd.append("post_id", btn.dataset.post);' . "\n";
		$inline .= '  fetch(KRBL.ajaxUrl, {method:"POST", credentials:"same-origin", body:fd})' . "\n";
		$inline .= '    .then(r=>r.json())' . "\n";
		$inline .= '    .then(function(res){' . "\n";
		$inline .= '      if(res && res.success){' . "\n";
		$inline .= '        out.innerHTML = "<div style=\'color:green;\'>✅ Thanks, your report has been sent!</div>";' . "\n";
		$inline .= '      }else{' . "\n";
		$inline .= '        out.innerHTML = "<div style=\'color:red;\'>⚠️ Failed to send. Try again later.</div>";' . "\n";
		$inline .= '      }' . "\n";
		$inline .= '    })' . "\n";
		$inline .= '    .catch(function(){' . "\n";
		$inline .= '      out.innerHTML = "<div style=\'color:red;\'>⚠️ Error. Please try again.</div>";' . "\n";
		$inline .= '    })' . "\n";
		$inline .= '    .finally(function(){ btn.remove(); });' . "\n";
		$inline .= '});';

		wp_add_inline_script( 'krbl-js', $inline );
		wp_enqueue_script( 'krbl-js' );
	}

	public function add_report_button( $content ) {
		if ( is_singular( 'post' ) && in_the_loop() && is_main_query() ) {
			$post_id = get_the_ID();
			$button  = '
            <div class="krbl-report-container" style="margin-top:20px;padding:10px;border-top:1px solid #eee;">
                <button type="button" class="krbl-report-btn" data-post="' . esc_attr( $post_id ) . '" style="padding:6px;border-radius:3px;background:#cccccc;color:#fff;border:none;cursor:pointer;">
                    🚩 ' . esc_html__( 'Click to Report Broken Links on This Page', 'kreativ-report-broken-link' ) . '
                </button>
                <div class="krbl-output" style="margin-top:8px;"></div>
            </div>';

			return $content . $button;
		}

		return $content;
	}

	public function handle_submit() {
		// AJAX nonce verification with explicit field name.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wp_send_json_error( __( 'Invalid nonce.', 'kreativ-report-broken-link' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'kreativ-report-broken-link' ) );
		}

		$url = esc_url_raw( get_permalink( $post_id ) );

		if ( ! $url ) {
			wp_send_json_error( __( 'Invalid URL.', 'kreativ-report-broken-link' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Insert into custom table. Safe values and format array used.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'post_id'    => $post_id,
				'url'        => $url,
				'user_ip'    => $ip,
				'status'     => 'new',
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		$notify = get_option( self::OPTION_NOTIFY_EMAIL, get_option( 'admin_email' ) );

		if ( $notify ) {
			/* translators: %s: site name */
			$subject    = sprintf( __( 'Broken link reported on %s', 'kreativ-report-broken-link' ), get_bloginfo( 'name' ) );
			$post_title = get_the_title( $post_id );
			$body       = "A broken link was reported automatically:\n\n" .
				"Post: {$post_title} (ID: {$post_id})\n" .
				"URL:  {$url}\n" .
				"IP: " . ( $ip ? $ip : 'N/A' ) . "\n" .
				"Time: " . current_time( 'mysql' ) . "\n\n" .
				"View reports: " . admin_url( 'admin.php?page=krbl_reports' );

			wp_mail( $notify, $subject, $body );
		}

		wp_send_json_success( true );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'Kreativ Broken Links', 'kreativ-report-broken-link' ),
			__( 'Kreativ Broken Links', 'kreativ-report-broken-link' ),
			'manage_options',
			'krbl_reports',
			[ $this, 'render_admin_page' ],
			'dashicons-flag',
			65
		);

		add_submenu_page(
			'krbl_reports',
			__( 'Reports', 'kreativ-report-broken-link' ),
			__( 'Reports', 'kreativ-report-broken-link' ),
			'manage_options',
			'krbl_reports',
			[ $this, 'render_admin_page' ]
		);

		add_submenu_page(
			'krbl_reports',
			__( 'Settings', 'kreativ-report-broken-link' ),
			__( 'Settings', 'kreativ-report-broken-link' ),
			'manage_options',
			'krbl_settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Kreativ Broken Links – Settings', 'kreativ-report-broken-link' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'krbl_settings' );
		do_settings_sections( 'krbl_settings' );
		submit_button();
		echo '</form></div>';
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Handle actions (resolve, ignore, reopen) – with nonce & sanitization.
		if ( isset( $_GET['krbl_action'], $_GET['id'], $_GET['_wpnonce'] ) ) {

			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

			if ( wp_verify_nonce( $nonce, 'krbl_row_action' ) ) {
				$action = sanitize_key( wp_unslash( $_GET['krbl_action'] ) );
				$id     = absint( wp_unslash( $_GET['id'] ) );

				if ( $id && in_array( $action, [ 'resolve', 'ignore', 'reopen' ], true ) ) {
					if ( 'resolve' === $action ) {
						$new_status = 'resolved';
					} elseif ( 'ignore' === $action ) {
						$new_status = 'ignored';
					} else {
						$new_status = 'new';
					}

					// Direct database update for custom table. Safe and intentional.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						[ 'status' => $new_status ],
						[ 'id' => $id ],
						[ '%s' ],
						[ '%d' ]
					);

					echo '<div class="updated notice"><p>' . esc_html__( 'Status updated.', 'kreativ-report-broken-link' ) . '</p></div>';
				}
			}
		}

		// Filtering.
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		if ( $status ) {
			$where = $wpdb->prepare( 'WHERE status = %s', $status );
		} else {
			$where = '';
		}

		// Pagination setup.
		$per_page = 100;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		// Count total items.
		$sql_total = "SELECT COUNT(*) FROM {$table} {$where}";

		// Custom table COUNT query. Safe without caching.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total_items = (int) $wpdb->get_var( $sql_total );

		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		// Fetch current page rows.
		$sql  = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC";
		$sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $per_page, $offset );

		// Custom table query. Caching not applicable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );

		$base_url = admin_url( 'admin.php?page=krbl_reports' );

		// Preserve status filter in pagination links.
		$query_args = [];
		if ( $status ) {
			$query_args['status'] = $status;
		}

		$base_url_with_args   = add_query_arg( $query_args, $base_url );
		$base_url_pagination  = remove_query_arg( 'paged', $base_url_with_args );
		$row_action_nonce     = wp_create_nonce( 'krbl_row_action' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Kreativ Broken Link Reports', 'kreativ-report-broken-link' ) . '</h1>';

		// Top pagination.
		if ( $total_pages > 1 ) {
			echo '<p>';
			echo wp_kses_post(
				paginate_links(
					[
						'base'      => add_query_arg( 'paged', '%#%', $base_url_pagination ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					]
				)
			);
			echo '</p>';
		}

		// Filters.
		echo '<p>';
		echo '<a class="button' . ( '' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $base_url ) . '">' . esc_html__( 'All', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'new' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'new', $base_url ) ) . '">' . esc_html__( 'New', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'resolved' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'resolved', $base_url ) ) . '">' . esc_html__( 'Resolved', 'kreativ-report-broken-link' ) . '</a> ';
		echo '<a class="button' . ( 'ignored' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'status', 'ignored', $base_url ) ) . '">' . esc_html__( 'Ignored', 'kreativ-report-broken-link' ) . '</a>';
		echo '</p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No reports yet.', 'kreativ-report-broken-link' ) . '</p></div>';
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
			$post_link  = $r->post_id
				? '<a href="' . esc_url( get_edit_post_link( $r->post_id ) ) . '">' . esc_html( $post_title ) . '</a>'
				: esc_html( $post_title );

			$actions = '';

			$row_url_base = add_query_arg(
				[
					'page' => 'krbl_reports',
					'id'   => (int) $r->id,
				],
				admin_url( 'admin.php' )
			);

			if ( 'new' === $r->status ) {
				$resolve_url = wp_nonce_url(
					add_query_arg( 'krbl_action', 'resolve', $row_url_base ),
					'krbl_row_action'
				);
				$ignore_url  = wp_nonce_url(
					add_query_arg( 'krbl_action', 'ignore', $row_url_base ),
					'krbl_row_action'
				);

				$actions .= '<a class="button button-small" href="' . esc_url( $resolve_url ) . '">✅ ' . esc_html__( 'Resolve', 'kreativ-report-broken-link' ) . '</a> ';
				$actions .= '<a class="button button-small" href="' . esc_url( $ignore_url ) . '">🚫 ' . esc_html__( 'Ignore', 'kreativ-report-broken-link' ) . '</a>';
			} else {
				$reopen_url = wp_nonce_url(
					add_query_arg( 'krbl_action', 'reopen', $row_url_base ),
					'krbl_row_action'
				);

				$actions .= '<a class="button button-small" href="' . esc_url( $reopen_url ) . '">🔄 ' . esc_html__( 'Reopen', 'kreativ-report-broken-link' ) . '</a>';
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

		// Bottom pagination.
		if ( $total_pages > 1 ) {
			echo '<p>';
			echo wp_kses_post(
				paginate_links(
					[
						'base'      => add_query_arg( 'paged', '%#%', $base_url_pagination ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					]
				)
			);
			echo '</p>';
		}

		echo '</div>';
	}
}

new KRBL_Plugin();
