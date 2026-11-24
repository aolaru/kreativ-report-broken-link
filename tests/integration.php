<?php
/**
 * Run only against a disposable WordPress installation:
 * KRBL_TEST_WP_ROOT=/path/to/disposable/wordpress php tests/integration.php
 * The installation must have krbl_isolated_test_site set to 'yes'.
 */
$wp_root = getenv( 'KRBL_TEST_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set KRBL_TEST_WP_ROOT to a disposable WordPress installation.\n" );
	exit( 1 );
}
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'krbl.test';
$_SERVER['REQUEST_URI'] = '/';
require $wp_root . '/wp-load.php';
if ( 'yes' !== get_option( 'krbl_isolated_test_site' ) ) {
	fwrite( STDERR, "Refusing to modify a site without the isolated-test marker.\n" );
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require dirname( __DIR__ ) . '/kreativ-report-broken-link.php';
wp_set_current_user( get_user_by( 'login', 'testadmin' )->ID );
$wpdb->suppress_errors( true );
$table = $wpdb->prefix . KRBL_Plugin::TABLE;
$passed = 0;
$emails = 0;
add_filter( 'pre_wp_mail', function () use ( &$emails ) { ++$emails; return true; } );
class KRBL_Test_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new KRBL_Test_Response(); };
} );
if ( '--export' === ( $argv[1] ?? '' ) ) {
	$_GET = array( 'nonce' => wp_create_nonce( 'krbl_export' ), 'status' => $argv[2] ?? '', 'link_type' => $argv[3] ?? '' );
	( new KRBL_Plugin() )->ajax_export_csv();
}
function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$GLOBALS['passed'];
	echo 'PASS: ' . $label . PHP_EOL;
}
function fresh_plugin() {
	return new KRBL_Plugin();
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $menu;
$original_menu = $menu ?? array();
$menu = array();
fresh_plugin()->admin_menu();
$menu_positions = array_keys( array_filter( $menu, function ( $item ) {
	return isset( $item[2] ) && 'krbl_reports' === $item[2];
} ) );
check( 1 === count( $menu_positions ) && 81.0 === (float) $menu_positions[0], 'Broken Links menu is positioned after Settings' );
check( 1 === count( $menu_positions ) && 'Report Broken Links' === $menu[ $menu_positions[0] ][0], 'sidebar menu uses the Report Broken Links label' );
$menu = $original_menu;
function submit_report( $target, $ip = '192.0.2.10', $post_id = null ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$_POST = array(
		'_wpnonce' => wp_create_nonce( KRBL_Plugin::NONCE ),
		'post_id' => $post_id ?? $GLOBALS['post_id'],
		'target_url' => $target,
	);
	$plugin = fresh_plugin();
	ob_start();
	try {
		$plugin->handle_submit();
	} catch ( KRBL_Test_Response $response ) {
		return json_decode( ob_get_clean(), true );
	} catch ( Throwable $error ) {
		ob_end_clean();
		throw $error;
	}
	throw new RuntimeException( 'Submission did not terminate with JSON.' );
}
function moderate_report( $action, $id ) {
	$_GET = array(
		'page' => 'krbl_reports', 'krbl_action' => $action, 'id' => $id,
		'_wpnonce' => wp_create_nonce( 'krbl_row_action' ),
		'status' => 'new', 'link_type' => 'internal', 'paged' => 2,
	);
	$redirect = function ( $url ) { throw new KRBL_Test_Response( $url ); };
	add_filter( 'wp_redirect', $redirect );
	try {
		fresh_plugin()->handle_row_actions();
	} catch ( KRBL_Test_Response $response ) {
		parse_str( wp_parse_url( $response->getMessage(), PHP_URL_QUERY ), $query );
		return $query;
	} finally {
		remove_filter( 'wp_redirect', $redirect );
	}
}

// Simulate the pre-1.5.0 table, including an existing report and disabled emails.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
$wpdb->query( "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT UNSIGNED NULL,
	url TEXT NOT NULL,
	user_ip VARCHAR(45) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'new',
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id), KEY status (status), KEY post_id (post_id)
)" );
$wpdb->insert( $table, array( 'post_id' => 1, 'url' => 'http://krbl.test/legacy', 'user_ip' => '192.0.2.1' ) );
delete_option( KRBL_Plugin::OPTION_DB_VERSION );
update_option( KRBL_Plugin::OPTION_NOTIFY_EMAIL, '' );
update_option( KRBL_Plugin::OPTION_POST_TYPES, array( 'post' ) );
update_option( KRBL_Plugin::OPTION_RETENTION, 0 );
$block_alter = function ( $sql ) use ( $table ) {
	return preg_match( '/^ALTER TABLE ' . preg_quote( $table, '/' ) . '\\b/i', $sql ) ? 'INVALID KRBL MIGRATION' : $sql;
};
add_filter( 'query', $block_alter );
check( ! fresh_plugin()->maybe_upgrade_schema(), 'failed migration reports failure' );
check( KRBL_Plugin::DB_VERSION !== get_option( KRBL_Plugin::OPTION_DB_VERSION ), 'failed migration is not marked complete' );
remove_filter( 'query', $block_alter );
check( fresh_plugin()->maybe_upgrade_schema(), 'next request retries migration successfully' );
check( KRBL_Plugin::DB_VERSION === get_option( KRBL_Plugin::OPTION_DB_VERSION ), 'verified migration saves schema revision' );
check( '' === get_option( KRBL_Plugin::OPTION_NOTIFY_EMAIL ), 'upgrade preserves disabled notifications' );
check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE url = 'http://krbl.test/legacy'" ), 'legacy report survives migration' );
fresh_plugin()->activate();
check( '' === get_option( KRBL_Plugin::OPTION_NOTIFY_EMAIL ), 'reactivation also preserves disabled notifications' );

$post_id = wp_insert_post( array( 'post_title' => 'KRBL tests', 'post_status' => 'publish', 'post_type' => 'post' ) );
delete_option( KRBL_Plugin::OPTION_TARGET_URL );
delete_option( KRBL_Plugin::OPTION_BUTTON_LABEL );
delete_option( KRBL_Plugin::OPTION_PLACEMENT );
global $wp_query, $wp_the_query, $post;
$original_query = $wp_query;
$original_main_query = $wp_the_query;
$original_post = $post;
$wp_query = new WP_Query( array( 'p' => $post_id, 'post_type' => 'post' ) );
$wp_the_query = $wp_query;
$wp_query->the_post();
$markup = fresh_plugin()->add_report_button( 'Content' );
check( false !== strpos( $markup, 'krbl-report-btn' ) && false === strpos( $markup, 'krbl-target-url' ), 'URL field and toggle are absent by default' );
check( 0 === strpos( $markup, 'Content' ) && false !== strpos( $markup, 'Report broken links on this page' ), 'default button follows content and retains its original text' );
update_option( KRBL_Plugin::OPTION_PLACEMENT, 'before' );
update_option( KRBL_Plugin::OPTION_BUTTON_LABEL, 'Report <b>this</b> & more' );
$markup = fresh_plugin()->add_report_button( 'Content' );
check( strpos( $markup, 'krbl-report-container' ) < strpos( $markup, 'Content' ) && false !== strpos( $markup, 'krbl-placement-before' ), 'before placement renders button before content' );
check( false !== strpos( $markup, 'Report this &amp; more' ) && false === strpos( $markup, '<b>this</b>' ), 'custom button label is sanitized and escaped' );
update_option( KRBL_Plugin::OPTION_PLACEMENT, 'invalid' );
update_option( KRBL_Plugin::OPTION_BUTTON_LABEL, '' );
$markup = fresh_plugin()->add_report_button( 'Content' );
check( 0 === strpos( $markup, 'Content' ) && false !== strpos( $markup, 'Report broken links on this page' ), 'invalid placement and blank label fall back to defaults' );
check( 120 === strlen( fresh_plugin()->sanitize_button_label( str_repeat( 'x', 130 ) ) ), 'custom labels are limited to 120 characters' );
delete_option( KRBL_Plugin::OPTION_PLACEMENT );
delete_option( KRBL_Plugin::OPTION_BUTTON_LABEL );
update_option( KRBL_Plugin::OPTION_TARGET_URL, 1 );
$markup = fresh_plugin()->add_report_button( 'Content' );
check( false !== strpos( $markup, '<details class="krbl-target-details">' ) && false !== strpos( $markup, 'krbl-target-url' ), 'enabled URL field is inside a collapsed disclosure' );
update_option( KRBL_Plugin::OPTION_TARGET_URL, 0 );
check( false === strpos( fresh_plugin()->add_report_button( 'Content' ), 'krbl-target-details' ), 'disabling URL field hides disclosure' );
$wp_query = $original_query;
$wp_the_query = $original_main_query;
$post = $original_post;
update_option( KRBL_Plugin::OPTION_NOTIFY_EMAIL, 'admin@example.com' );
check( submit_report( home_url( '/missing' ) )['success'], 'valid internal report is stored' );
check( 'internal' === $wpdb->get_var( "SELECT link_type FROM {$table} WHERE post_id = {$post_id} ORDER BY id DESC LIMIT 1" ), 'internal target is classified' );
$before = $emails;
check( 'duplicate' === submit_report( home_url( '/missing' ) )['data']['code'], 'same target is blocked as duplicate' );
check( $before === $emails, 'duplicate sends no email' );
check( submit_report( 'https://example.net/missing' )['success'], 'same visitor can report a second target' );
check( 'external' === $wpdb->get_var( "SELECT link_type FROM {$table} WHERE post_id = {$post_id} ORDER BY id DESC LIMIT 1" ), 'external target is classified' );
check( submit_report( '' )['success'], 'page-only reporting remains supported' );
foreach ( array( 'https://', '/relative', 'javascript:alert(1)', 'https://user:pass@example.net/a', array( 'url' ) ) as $invalid ) {
	check( ! submit_report( $invalid )['success'], 'invalid target is rejected: ' . json_encode( $invalid ) );
}

$before = $emails;
for ( $i = 0; $i < 5; ++$i ) {
	check( submit_report( 'https://example.net/a?nonce=' . $i, '192.0.2.20' )['success'], 'throttle permits report ' . ( $i + 1 ) );
}
$limited = submit_report( 'https://example.net/a?nonce=6', '192.0.2.20' );
check( 'rate_limited' === $limited['data']['code'], 'changed target cannot bypass short-term limit' );
check( 5 === $emails - $before, 'throttled request sends no extra email' );
$wpdb->query( "UPDATE {$table} SET created_at = DATE_SUB('" . current_time( 'mysql' ) . "', INTERVAL 11 MINUTE) WHERE user_ip = '192.0.2.20'" );
for ( $i = 0; $i < 15; ++$i ) {
	$wpdb->insert( $table, array( 'post_id' => $post_id, 'url' => home_url(), 'target_url' => 'https://example.net/seed/' . $i,
		'ip_hash' => hash( 'sha256', '192.0.2.20|' . wp_salt( 'nonce' ) ), 'user_ip' => '192.0.2.20',
		'created_at' => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
}
check( 'rate_limited' === submit_report( 'https://example.net/new', '192.0.2.20' )['data']['code'], 'daily limit applies after short-term window expires' );

update_option( KRBL_Plugin::OPTION_ANONYMIZE_IP, 1 );
check( submit_report( home_url( '/shared' ), '192.0.2.30' )['success'], 'first masked visitor accepted' );
check( submit_report( home_url( '/shared' ), '192.0.2.31' )['success'], 'second visitor in masked subnet accepted' );
check( 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_ip = '192.0.2.0' AND target_url = '" . home_url( '/shared' ) . "'" ), 'raw IPs remain masked in storage' );
update_option( KRBL_Plugin::OPTION_ANONYMIZE_IP, 0 );

$older = wp_date( 'Y-m-d H:i:s', time() - 23 * HOUR_IN_SECONDS );
$wpdb->update( $table, array( 'created_at' => $older ), array( 'user_ip' => '192.0.2.10', 'target_url' => home_url( '/missing' ) ) );
check( 'duplicate' === submit_report( home_url( '/missing' ) )['data']['code'], '23-hour-old report remains a duplicate' );
$wpdb->update( $table, array( 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'user_ip' => '192.0.2.10', 'target_url' => home_url( '/missing' ) ) );
check( submit_report( home_url( '/missing' ) )['success'], 'duplicate retry cannot extend original 24-hour window' );

$block_insert = function ( $sql ) use ( $table ) {
	return preg_match( '/^INSERT INTO `' . preg_quote( $table, '/' ) . '`/i', $sql ) ? 'INVALID KRBL INSERT' : $sql;
};
$before = $emails;
add_filter( 'query', $block_insert );
check( ! submit_report( 'https://example.net/failure', '192.0.2.40' )['success'], 'insert failure returns an error' );
remove_filter( 'query', $block_insert );
check( $emails === $before, 'insert failure sends no email' );
update_option( KRBL_Plugin::OPTION_POST_TYPES, array( 'page' ) );
check( ! submit_report( '' )['success'], 'disabled post type is rejected' );
update_option( KRBL_Plugin::OPTION_POST_TYPES, array( 'post' ) );

$id = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" );
$block_update = function ( $sql ) use ( $table ) {
	return preg_match( '/^UPDATE `' . preg_quote( $table, '/' ) . '`/i', $sql ) ? 'INVALID KRBL UPDATE' : $sql;
};
add_filter( 'query', $block_update );
$redirect = moderate_report( 'resolve', $id );
remove_filter( 'query', $block_update );
check( 'update_failed' === $redirect['krbl_msg'], 'moderation failure redirects with an error' );
$redirect = moderate_report( 'resolve', $id );
check( 'updated' === $redirect['krbl_msg'], 'successful moderation redirects with success' );
check( 'internal' === $redirect['link_type'] && '2' === $redirect['paged'] && 'new' === $redirect['status'], 'moderation retains type, status and pagination' );
check( 'resolved' === $wpdb->get_var( "SELECT status FROM {$table} WHERE id = {$id}" ), 'moderation persists new status' );

// Force an empty filtered result without removing other regression fixtures.
$_GET = array( 'page' => 'krbl_reports', 'status' => 'ignored', 'link_type' => 'external' );
ob_start();
fresh_plugin()->render_admin_page();
$html = ob_get_clean();
check( false !== strpos( $html, 'Getting started: setup checklist' ) && false !== strpos( $html, 'tab=settings' ), 'reports page offers a setup checklist with settings link' );
check( false !== strpos( $html, 'https://wordpress.org/support/plugin/kreativ-report-broken-link/' ) && false !== strpos( $html, 'https://wordpress.org/support/plugin/kreativ-report-broken-link/reviews/#new-post' ), 'reports page includes feedback and review links' );
$_GET = array( 'page' => 'krbl_reports', 'tab' => 'settings' );
ob_start();
fresh_plugin()->render_admin_page();
$settings_html = ob_get_clean();
check( false !== strpos( $settings_html, 'https://wordpress.org/support/plugin/kreativ-report-broken-link/' ) && false !== strpos( $settings_html, 'https://wordpress.org/support/plugin/kreativ-report-broken-link/reviews/#new-post' ), 'settings page includes feedback and review links' );
$dom = new DOMDocument();
@$dom->loadHTML( $html );
$export = $dom->getElementById( 'krbl-export-csv' );
check( $export && 'a' === $export->nodeName && $export->hasAttribute( 'href' ), 'empty results retain a working CSV download link' );
parse_str( wp_parse_url( $export->getAttribute( 'href' ), PHP_URL_QUERY ), $export_args );
check( 'external' === $export_args['link_type'] && 'ignored' === $export_args['status'] && wp_verify_nonce( $export_args['nonce'], 'krbl_export' ), 'export link retains filters and a valid nonce' );
$process = proc_open( array( PHP_BINARY, __FILE__, '--export', 'ignored', 'external' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
$csv = stream_get_contents( $pipes[1] );
$errors = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
check( 0 === proc_close( $process ) && '' === $errors, 'CSV endpoint completes without errors for empty results' );
check( array( 'ID', 'Post ID', 'Page URL', 'Reported URL', 'Link Type', 'IP', 'Status', 'Date' ) === str_getcsv( trim( $csv ), ',', '"', '' ), 'empty export downloads the correct header row' );

$process = proc_open( array( PHP_BINARY, __FILE__, '--export', '', 'external' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
$csv = stream_get_contents( $pipes[1] );
$errors = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
check( 0 === proc_close( $process ) && '' === $errors, 'filtered CSV endpoint completes successfully' );
$csv_rows = array_map( function ( $line ) { return str_getcsv( $line, ',', '"', '' ); }, explode( "\n", trim( $csv ) ) );
array_shift( $csv_rows );
check( count( $csv_rows ) > 0 && count( $csv_rows ) === count( array_filter( $csv_rows, function ( $row ) { return count( $row ) === 8 && 'external' === $row[4]; } ) ), 'filtered export contains only matching complete rows' );

update_option( KRBL_Plugin::OPTION_RETENTION, 30 );
check( (bool) wp_next_scheduled( KRBL_Plugin::CLEANUP_HOOK ), 'enabling retention schedules cleanup' );
fresh_plugin()->sync_cleanup_schedule();
$events = array_filter( _get_cron_array(), function ( $hooks ) { return isset( $hooks[ KRBL_Plugin::CLEANUP_HOOK ] ); } );
check( 1 === count( $events ), 'repeated scheduling does not duplicate jobs' );
$wpdb->insert( $table, array( 'url' => 'http://krbl.test/expired', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ) );
$wpdb->insert( $table, array( 'url' => 'http://krbl.test/recent', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS ) ) );
delete_option( KRBL_Plugin::OPTION_LAST_CLEANUP );
do_action( KRBL_Plugin::CLEANUP_HOOK );
check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE url = 'http://krbl.test/expired'" ), 'scheduled cleanup removes expired rows' );
check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE url = 'http://krbl.test/recent'" ), 'scheduled cleanup preserves unexpired rows in site timezone' );
fresh_plugin()->deactivate();
check( ! wp_next_scheduled( KRBL_Plugin::CLEANUP_HOOK ), 'deactivation removes cleanup event' );
fresh_plugin()->sync_cleanup_schedule();
check( (bool) wp_next_scheduled( KRBL_Plugin::CLEANUP_HOOK ), 'initialization restores enabled retention job' );
update_option( KRBL_Plugin::OPTION_RETENTION, 0 );
check( ! wp_next_scheduled( KRBL_Plugin::CLEANUP_HOOK ), 'disabling retention removes cleanup event' );
echo "Completed {$passed} WordPress integration checks.\n";
