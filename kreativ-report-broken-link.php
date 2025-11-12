<?php
/**
 * Plugin Name: Kreativ Report Broken Link
 * Description: Automatically adds a 'Report Broken Link' button at the end of every post. When clicked, it auto-submits a report to the admin.
 * Version: 1.1.0
 * Author: Andrei Olaru
 * License: GPL-2.0+
 * Text Domain: kreativ-report-broken-link
 */

if (!defined('ABSPATH')) exit;

class KRBL_Plugin {
    const TABLE = 'broken_link_reports';
    const NONCE = 'krbl_nonce';
    const OPTION_NOTIFY_EMAIL = 'krbl_notify_email';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rbl_submit_report', [$this, 'handle_submit']);
        add_action('wp_ajax_nopriv_rbl_submit_report', [$this, 'handle_submit']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_filter('the_content', [$this, 'add_report_button']);
    }

    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
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
        dbDelta($sql);

        if (!get_option(self::OPTION_NOTIFY_EMAIL)) {
            update_option(self::OPTION_NOTIFY_EMAIL, get_option('admin_email'));
        }
    }

    public function register_settings() {
        register_setting('krbl_settings', self::OPTION_NOTIFY_EMAIL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
        ]);

        add_settings_section('krbl_main', __('Notifications', 'kreativ-report-broken-link'), function () {
            echo '<p>'.esc_html__('Choose where to send broken link notifications.', 'kreativ-report-broken-link').'</p>';
        }, 'krbl_settings');

        add_settings_field(self::OPTION_NOTIFY_EMAIL, __('Notify Email', 'kreativ-report-broken-link'), function () {
            $val = esc_attr(get_option(self::OPTION_NOTIFY_EMAIL, get_option('admin_email')));
            echo '<input type="email" name="' . esc_attr(self::OPTION_NOTIFY_EMAIL) . '" value="' . esc_attr($val) . '" class="regular-text" />';

        }, 'krbl_settings', 'krbl_main');
    }

    public function enqueue_assets() {
        wp_register_script('krbl-js', '', [], '0.0.1', true);
        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
        ];
        wp_localize_script('krbl-js', 'KRBL', $data);

        $inline = 'document.addEventListener("click", function(e){' . "\n" .
            '  var btn = e.target.closest(".krbl-report-btn");' . "\n" .
            '  if(!btn) return;' . "\n" .
            '  e.preventDefault();' . "\n" .
            '  var container = btn.closest(".krbl-report-container");' . "\n" .
            '  var out = container.querySelector(".krbl-output");' . "\n" .
            '  btn.disabled = true;' . "\n" .
            '  btn.textContent = "⏳ Sending...";' . "\n" .
            '  var fd = new FormData();' . "\n" .
            '  fd.append("action","rbl_submit_report");' . "\n" .
            '  fd.append("_wpnonce", KRBL.nonce);' . "\n" .
            '  fd.append("post_id", btn.dataset.post);' . "\n" .
            '  fetch(KRBL.ajaxUrl, {method:"POST", credentials:"same-origin", body:fd})' . "\n" .
            '    .then(r=>r.json())' . "\n" .
            '    .then(function(res){' . "\n" .
            '      if(res && res.success){' . "\n" .
            '        out.innerHTML = "<div style=\'color:green;\'>✅ Thanks, your report has been sent!</div>";' . "\n" .
            '      }else{' . "\n" .
            '        out.innerHTML = "<div style=\'color:red;\'>⚠️ Failed to send. Try again later.</div>";' . "\n" .
            '      }' . "\n" .
            '    })' . "\n" .
            '    .catch(function(){' . "\n" .
            '      out.innerHTML = "<div style=\'color:red;\'>⚠️ Error. Please try again.</div>";' . "\n" .
            '    })' . "\n" .
            '    .finally(function(){ btn.remove(); });' . "\n" .
            '});';

        wp_add_inline_script('krbl-js', $inline);
        wp_enqueue_script('krbl-js');
    }

    public function add_report_button($content) {
        if (is_singular('post') && in_the_loop() && is_main_query()) {
            $post_id = get_the_ID();
            $button = '
            <div class="krbl-report-container" style="margin-top:20px;padding:10px;border-top:1px solid #eee;">
                <button type="button" class="krbl-report-btn" data-post="'.esc_attr($post_id).'" style="padding:6px;border-radius:3px;background:#cccccc;color:#fff;border:none;cursor:pointer;">
                    🚩 '.esc_html__('Click to Report Broken Links on This Page', 'kreativ-report-broken-link').'
                </button>
                <div class="krbl-output" style="margin-top:8px;"></div>
            </div>';
            return $content . $button;
        }
        return $content;
    }

    public function handle_submit() {
        check_ajax_referer(self::NONCE);
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID.', 'kreativ-report-broken-link'));
        }

        $url = get_permalink($post_id);
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->insert($table, [
            'post_id'    => $post_id,
            'url'        => $url,
            'user_ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'status'     => 'new',
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%s','%s']);

        $notify = get_option(self::OPTION_NOTIFY_EMAIL, get_option('admin_email'));
        if ($notify) {

            /* translators: %s: site name */
            $subject = sprintf(__('Broken link reported on %s', 'kreativ-report-broken-link'), get_bloginfo('name'));
            $post_title = get_the_title($post_id);
            $body = "A broken link was reported automatically:\n\n".
                "Post: {$post_title} (ID: {$post_id})\n".
                "URL:  {$url}\n".
                "IP: ".($_SERVER['REMOTE_ADDR'] ?? 'N/A')."\n".
                "Time: ".current_time('mysql')."\n\n".
                "View reports: ".admin_url('admin.php?page=rbl_reports');
            wp_mail($notify, $subject, $body);
        }

        wp_send_json_success(true);
    }

    public function admin_menu() {
        add_menu_page(
            __('Kreativ Broken Links', 'kreativ-report-broken-link'),
            __('Kreativ Broken Links', 'kreativ-report-broken-link'),
            'manage_options',
            'rbl_reports',
            [$this, 'render_admin_page'],
            'dashicons-flag',
            65
        );

        add_submenu_page(
            'rbl_reports',
            __('Reports', 'kreativ-report-broken-link'),
            __('Reports', 'kreativ-report-broken-link'),
            'manage_options',
            'rbl_reports',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'rbl_reports',
            __('Settings', 'kreativ-report-broken-link'),
            __('Settings', 'kreativ-report-broken-link'),
            'manage_options',
            'krbl_settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>'.esc_html__('Kreativ Broken Links – Settings', 'kreativ-report-broken-link').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('krbl_settings');
        do_settings_sections('krbl_settings');
        submit_button();
        echo '</form></div>';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Handle actions (resolve, ignore, reopen)
        if (isset($_GET['krbl_action'], $_GET['id'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'krbl_row_action')) {
            $id = intval($_GET['id']);
            $action = sanitize_text_field($_GET['krbl_action']);
            if (in_array($action, ['resolve', 'ignore', 'reopen'], true)) {
                $new_status = $action === 'resolve' ? 'resolved' : ($action === 'ignore' ? 'ignored' : 'new');
                $wpdb->update($table, ['status' => $new_status], ['id' => $id], ['%s'], ['%d']);
                echo '<div class="updated notice"><p>Status updated.</p></div>';
            }
        }

        // Filtering
        $status = isset($_GET['status']) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

        $where  = $status ? $wpdb->prepare('WHERE status = %s', $status) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Safe because $table is a known, static table name and $where is prepared above.
        //$rows   = $wpdb->get_results("SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 100");

        // Pagination setup
        $per_page = 100;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        // Count total
        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        $total_pages = ceil($total_items / $per_page);

        // Fetch current page rows
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset)
        );

        // Pagination End

        $base_url = admin_url('admin.php?page=rbl_reports');
        $nonce    = wp_create_nonce('krbl_row_action');

        echo '<div class="wrap"><h1>Kreativ Broken Link Reports</h1>';

        echo '<p>';
        echo paginate_links([
            'base'    => add_query_arg('paged', '%#%', $base_url_pagination),
            'format'  => '',
            'current' => $paged,
            'total'   => max(1, $total_pages),
        ]);
        echo '</p>';

        // Filters
        echo '<p>';
        echo '<a class="button'.($status===''?' button-primary':'').'" href="' . esc_url($base_url) . '">All</a> ';
        echo '<a class="button'.($status==='new'?' button-primary':'').'" href="' . esc_url($base_url) . '&status=new">New</a> ';
        echo '<a class="button'.($status==='resolved'?' button-primary':'').'" href="' . esc_url($base_url) . '&status=resolved">Resolved</a> ';
        echo '<a class="button'.($status==='ignored'?' button-primary':'').'" href="' . esc_url($base_url) . '&status=ignored">Ignored</a>';
        echo '</p>';

        if (!$rows) {
            echo '<p>No reports yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Time</th><th>Post</th><th>URL</th><th>IP</th><th>Status</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $post_title = $r->post_id ? get_the_title($r->post_id) : '(no post)';
            $post_link  = $r->post_id ? '<a href="'.get_edit_post_link($r->post_id).'">'.esc_html($post_title).'</a>' : esc_html($post_title);

            $actions = '';
            if ($r->status === 'new') {
                $actions .= '<a class="button button-small" href="'.wp_nonce_url($base_url.'&krbl_action=resolve&id='.$r->id, 'krbl_row_action').'">✅ Resolve</a> ';
                $actions .= '<a class="button button-small" href="'.wp_nonce_url($base_url.'&krbl_action=ignore&id='.$r->id, 'krbl_row_action').'">🚫 Ignore</a>';
            } else {
                $actions .= '<a class="button button-small" href="'.wp_nonce_url($base_url.'&krbl_action=reopen&id='.$r->id, 'krbl_row_action').'">🔄 Reopen</a>';
            }

            echo '<tr>';
            echo '<td>'.intval($r->id).'</td>';
            echo '<td>'.esc_html($r->created_at).'</td>';
            echo '<td>' . wp_kses_post($post_link) . '</td>';
            echo '<td><a href="'.esc_url($r->url).'" target="_blank">'.esc_html($r->url).'</a></td>';
            echo '<td>'.esc_html($r->user_ip).'</td>';
            echo '<td>'.esc_html($r->status).'</td>';
            echo '<td>' . wp_kses_post($actions) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<p>';
        echo paginate_links([
            'base'    => add_query_arg('paged', '%#%', $base_url_pagination),
            'format'  => '',
            'current' => $paged,
            'total'   => max(1, $total_pages),
        ]);
        echo '</p>';
    }
}
new KRBL_Plugin();
