<?php
/**
 * Plugin Name: eWebCity Education
 * Plugin URI: https://www.facebook.com/eWebcity
 * Description: Complete school system: classes, students, admissions, attendance, fees, exams, library, plus student/parent/teacher/committee portals, Teacher Insights, Student 360°, Gradebook, approvals, activity log and live alerts. eWebCity Education © Mahim. Includes the School Engine (GPL).
 * Version: 2.2.0
 * Author: Mahim
 * Author URI: https://www.facebook.com/eWebcity
 * License: GPLv2 or later
 * Text Domain: ewebcity-education
 */

/* School currency symbol from the engine settings. */
function ewc_currency_symbol() {
	static $sym = null;
	if ( null !== $sym ) {
		return $sym;
	}
	$sym = get_option( 'ewc_currency', '' );
	if ( class_exists( 'WLSM_Config' ) && class_exists( 'WLSM_Helper' ) && function_exists( 'ewc_school_id' ) ) {
		$code = WLSM_Config::currency( ewc_school_id() );
		$all  = WLSM_Helper::currency_symbols();
		$sym  = isset( $all[ $code ] ) ? $all[ $code ] : $code;
	}
	return $sym ? $sym : '$';
}

/* Portal page URL: the page created by the Setup Wizard, else /dashboard/. */
function ewc_portal_url() {
	$map = (array) get_option( 'ewc_pages', array() );
	if ( ! empty( $map['portal'] ) && get_post_status( $map['portal'] ) ) {
		return get_permalink( $map['portal'] );
	}
	return home_url( '/dashboard/' );
}


/*
 * eWebCity Education bundles the School Engine.
 * The engine (in ./engine/) is GPL software © Weblizar; its copyright notices are kept.
 * eWebCity Education loads it, so the whole school system is one plugin.
 */
if ( ! defined( 'WLSM_VERSION' ) ) {
	$tms_engine_file = __DIR__ . '/engine/school-management.php';
	if ( ! file_exists( $tms_engine_file ) ) { // fallback while migrating.
		$tms_engine_file = WP_PLUGIN_DIR . '/school-management-pro/school-management.php';
	}
	if ( file_exists( $tms_engine_file ) ) {
		require_once $tms_engine_file;
	}
	unset( $tms_engine_file );
}
register_activation_hook( __FILE__, 'tms_activate_engine' );
function tms_activate_engine() {
	if ( class_exists( 'WLSM_Database' ) && method_exists( 'WLSM_Database', 'activation' ) ) {
		WLSM_Database::activation();
	}
}
add_action( 'admin_init', function () {
	if ( get_option( 'tms_engine_bundled' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$core = 'school-management-pro/school-management.php';
	$act  = (array) get_option( 'active_plugins', array() );
	if ( in_array( $core, $act, true ) ) {
		// Remove from the separate plugin list without running its deactivation hook.
		update_option( 'active_plugins', array_values( array_diff( $act, array( $core ) ) ) );
	}
	update_option( 'tms_engine_bundled', 1 );
} );


defined( 'ABSPATH' ) || exit;

/**
 * Keep local All-in-One WP Migration archives out of the public web root.
 * Existing backups are preserved for administrators, but HTTP downloads are
 * denied by the web server.
 */
function tms_protect_local_backups() {
	$directory = WP_CONTENT_DIR . '/ai1wm-backups';
	$file      = $directory . '/.htaccess';
	$marker    = '# eWebCity Education: protect local backup archives';
	$rules     = "\n{$marker}\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
		return;
	}

	$current = file_exists( $file ) ? @file_get_contents( $file ) : '';
	if ( false === $current || false !== strpos( $current, $marker ) ) {
		return;
	}

	@file_put_contents( $file, rtrim( $current ) . $rules, LOCK_EX );
}
add_action( 'plugins_loaded', 'tms_protect_local_backups', 1 );

/**
 * Prevent inactive students and their parents from using the portal until an
 * administrator activates the corresponding student record.
 */
if ( ! function_exists( 'tms_portal_approval_gate' ) ) {
function tms_portal_approval_gate( $output, $tag ) {
	if ( 'school_management_account' !== $tag || ! is_user_logged_in() ) {
		return $output;
	}

	global $wpdb;
	$user_id = get_current_user_id();
	$table   = $wpdb->prefix . 'wlsm_student_records';
	$records = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT is_active FROM {$table} WHERE user_id = %d OR parent_user_id = %d",
			$user_id,
			$user_id
		)
	);

	// Administrators and staff do not have a student record.
	if ( empty( $records ) || in_array( '1', $records, true ) ) {
		return $output;
	}

	$logout_url = wp_logout_url( ewc_portal_url() );

	return '<section class="wlsm tms-pending-approval" aria-labelledby="tms-pending-title">'
		. '<h2 id="tms-pending-title">' . esc_html__( 'Your account is awaiting approval', 'tms-school-portal-controls' ) . '</h2>'
		. '<p>' . esc_html__( 'Thank you for registering. The school office is reviewing your details. After approval, you can view fees, timetables, notices and results here.', 'tms-school-portal-controls' ) . '</p>'
		. '<p>' . esc_html( sprintf( 'Need help? Contact the school office%s at %s.', get_option( 'ewc_school_phone' ) ? ' (' . get_option( 'ewc_school_phone' ) . ')' : '', get_option( 'admin_email' ) ) ) . '</p>'
		. '<p><a class="wlsm-btn" href="' . esc_url( $logout_url ) . '">' . esc_html__( 'Log out', 'tms-school-portal-controls' ) . '</a></p>'
		. '</section>';
}
add_filter( 'do_shortcode_tag', 'tms_portal_approval_gate', 10, 2 );
}

/**
 * Optional (option ewc_disable_online_gateways): for schools that take bank
 * transfer and cash only, remove unused third-party checkout scripts.
 */
function tms_remove_unused_payment_assets() {
	if ( ! get_option( 'ewc_disable_online_gateways' ) ) {
		return;
	}
	foreach ( array( 'razorpay-checkout-js', 'paystack-checkout-js' ) as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'tms_remove_unused_payment_assets', 9999 );
add_action( 'wp_print_scripts', 'tms_remove_unused_payment_assets', 0 );
add_action( 'wp_print_footer_scripts', 'tms_remove_unused_payment_assets', 0 );

function tms_remove_unused_payment_hints( $urls, $relation_type ) {
	if ( 'dns-prefetch' !== $relation_type || ! get_option( 'ewc_disable_online_gateways' ) ) {
		return $urls;
	}

	return array_values(
		array_filter(
			$urls,
			static function ( $url ) {
				$url = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
				return false === strpos( $url, 'checkout.razorpay.com' ) && false === strpos( $url, 'js.paystack.co' );
			}
		)
	);
}
add_filter( 'wp_resource_hints', 'tms_remove_unused_payment_hints', 9999, 2 );

/**
 * Some third-party payment add-ons print their checkout tags directly in the
 * footer, after WordPress has run the normal dequeue hooks. Remove only those
 * known tags from public HTML as a final safeguard.
 */
function tms_strip_unused_payment_markup( $html ) {
	$patterns = array(
		'#<link[^>]+(?:checkout\.razorpay\.com|js\.paystack\.co)[^>]*>\s*#i',
		'#<script[^>]+id=["\'](?:razorpay-checkout-js|paystack-checkout-js)["\'][^>]*>\s*</script>\s*#i',
	);

	if ( get_option( 'ewc_disable_online_gateways' ) ) {
		$html = preg_replace( $patterns, '', $html );
	}

	return $html;
}

function tms_start_payment_markup_filter() {
	if ( ! is_admin() && ! wp_doing_ajax() ) {
		ob_start( 'tms_strip_unused_payment_markup' );
	}
}
add_action( 'template_redirect', 'tms_start_payment_markup_filter', 0 );

function tms_portal_controls_styles() {
	if ( is_page( 'dashboard' ) ) {
		echo '<style>.tms-pending-approval{max-width:42rem;margin:2rem auto;padding:2rem;border:1px solid #c8d8e5;border-radius:12px;background:#f6fbff;text-align:center}.tms-pending-approval h2{margin-top:0;color:#043353}.tms-pending-approval p{line-height:1.6}</style>';
	}

	if ( is_page( 'fee-submission' ) ) {
		echo '<style>
		body.page-id-157 #wlsm-get-pending-invoices-students-section{max-width:820px;margin:0 auto;padding:34px 40px 38px;border:1px solid #dce7ef;border-radius:20px;background:#fff;box-shadow:0 18px 40px rgba(4,51,83,.10);color:#17324a}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-header-title{display:block;margin:0 0 28px!important;padding:0 0 16px;border-bottom:3px solid #f5b82e;color:#043353;font-size:24px;line-height:1.25}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-header-title span{border:0!important;padding:0!important}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-form-group{display:flex;align-items:center;gap:20px;margin:0 0 18px!important}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-col-4{flex:0 0 170px;max-width:170px;padding:0!important}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-col-8{flex:1 1 auto;max-width:none;padding:0!important}
		body.page-id-157 #wlsm-get-pending-invoices-students-section label{color:#043353;font-size:16px;font-weight:700}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-form-control{width:100%;min-height:48px;border:1px solid #cbd9e4;border-radius:10px;background:#fff;color:#17324a;font-size:16px;padding:10px 14px}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-form-control:focus{border-color:#0b5a8a;outline:3px solid rgba(11,90,138,.16);box-shadow:none}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-form-group:first-of-type .wlsm-font-normal{display:block;padding:13px 14px;border-radius:10px;background:#eef6fb;color:#043353;font-weight:700;line-height:1.4}
		body.page-id-157 #wlsm-get-pending-invoices-students-section > p{width:max-content;max-width:100%;margin:4px auto 18px!important;padding:4px 12px;border-radius:999px;background:#f4f7f9;color:#526878;font-size:13px;font-weight:700;letter-spacing:.08em}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-border-top{margin-top:26px!important;padding-top:22px!important}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-btn{min-width:132px;min-height:48px;border:0;border-radius:999px;background:#e9454a;color:#fff;font-size:16px;font-weight:700;padding:10px 24px;transition:background .2s ease,transform .2s ease}
		body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-btn:hover{background:#c83238;transform:translateY(-1px)}
		@media(max-width:767px){body.page-id-157 #wlsm-get-pending-invoices-students-section{padding:25px 20px;border-radius:14px}body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-form-group{display:block;margin-bottom:17px!important}body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-col-4,body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-col-8{max-width:none;padding:0!important}body.page-id-157 #wlsm-get-pending-invoices-students-section label{display:block;margin-bottom:7px}body.page-id-157 #wlsm-get-pending-invoices-students-section .wlsm-btn{width:100%}}
		</style>';
	}
}
add_action( 'wp_head', 'tms_portal_controls_styles' );

/* ---- Branded login / lost-password screen ---- */
add_filter( 'login_headerurl', function () { return home_url( '/' ); } );
add_filter( 'login_headertext', function () { return get_bloginfo( 'name' ); } );
add_action( 'login_enqueue_scripts', function () {
	$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' );
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">';
	?>
	<style id="tms-login">
	body.login{background:#FFF8E6 radial-gradient(circle at 10% 10%,rgba(245,184,46,.35),transparent 30%),radial-gradient(circle at 90% 80%,rgba(228,69,70,.18),transparent 30%);font-family:Nunito,sans-serif;min-height:100vh;}
	body.login::before{content:"";position:fixed;left:0;right:0;top:0;height:8px;background:linear-gradient(90deg,#E44546,#F5B82E,#34D399,#38BDF8,#A78BFA);}
	#login{width:380px;max-width:calc(100% - 32px);padding:6vh 0 30px;}
	.login h1 a{<?php if ( $logo ) : ?>background-image:url(<?php echo esc_url( $logo ); ?>)!important;<?php endif; ?>width:120px!important;height:120px!important;background-size:contain!important;border-radius:50%;border:6px solid #F5B82E;background-color:#fff;box-shadow:0 10px 30px rgba(4,51,83,.18);margin-bottom:18px;}
	.login form{border:0!important;border-radius:26px;padding:28px 26px!important;box-shadow:0 20px 50px rgba(4,51,83,.12)!important;border-top:8px solid #043353!important;}
	.login label{font-family:Fredoka,sans-serif;color:#043353;font-size:15px;font-weight:600;}
	.login input[type=text],.login input[type=password],.login input[type=email]{border:2px solid #E2E8F0!important;border-radius:14px!important;padding:8px 14px!important;font-size:16px!important;background:#F8FAFC!important;box-shadow:none!important;}
	.login input[type=text]:focus,.login input[type=password]:focus,.login input[type=email]:focus{border-color:#F5B82E!important;box-shadow:0 0 0 4px rgba(245,184,46,.25)!important;background:#fff!important;}
	.wp-core-ui .button-primary{background:#E44546!important;border:0!important;border-radius:999px!important;padding:4px 24px!important;font-weight:800;font-family:Fredoka,sans-serif;font-size:15px!important;box-shadow:0 4px 0 #B92F31!important;text-shadow:none!important;}
	.wp-core-ui .button-primary:hover{transform:translateY(-1px);}
	.login .message,.login .notice,.login #login_error{border-radius:16px;border-left-width:6px!important;box-shadow:0 6px 18px rgba(4,51,83,.08);}
	.login .message,.login .notice-info{border-left-color:#F5B82E!important;}
	.login #nav,.login #backtoblog{text-align:center;padding:0!important;}
	.login #nav a,.login #backtoblog a{color:#043353!important;font-weight:700;}
	.login .language-switcher{display:none;}
	</style>
	<?php
} );

/* ---- Student / parent portal experience ---- */
function tms_is_portal_user( $user_id ) {
	global $wpdb;
	$user_id = (int) $user_id;
	if ( ! $user_id || user_can( $user_id, 'edit_posts' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'wlsm_student_records';
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE user_id = %d OR parent_user_id = %d LIMIT 1", $user_id, $user_id ) );
}
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && tms_is_portal_user( $user->ID ) ) {
		return ewc_portal_url();
	}
	return $redirect;
}, 20, 3 );
add_filter( 'show_admin_bar', function ( $show ) {
	return tms_is_portal_user( get_current_user_id() ) ? false : $show;
} );
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( tms_is_portal_user( get_current_user_id() ) ) {
		wp_safe_redirect( ewc_portal_url() );
		exit;
	}
} );
add_action( 'wp_footer', function () {
	?>
	<script id="tms-portal-fixes">
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[class*="wlsm-st-print"], a[class*="wlsm-print"]');
		if (a && !/[?&]action=|\.pdf/i.test(a.getAttribute('href') || '')) { e.preventDefault(); }
	}, true);
	<?php if ( is_user_logged_in() ) : ?>
	document.querySelectorAll('.elementor-location-header a').forEach(function (a) {
		if (/student\s*login/i.test(a.textContent)) {
			a.setAttribute('href', '<?php echo esc_url( ewc_portal_url() ); ?>');
			a.querySelectorAll('.elementor-icon-list-text').forEach(function (t) { t.textContent = 'My Portal'; });
			if (!a.querySelector('.elementor-icon-list-text')) { a.lastChild && (a.lastChild.textContent = 'My Portal'); }
		}
	});
	<?php endif; ?>
	</script>
	<?php
}, 99 );

/* ---- Logged-in labels rendered server-side (no flash) ---- */
add_filter( 'elementor/widget/render_content', 'tms_logged_in_labels', 10, 2 );
function tms_logged_in_labels( $content, $widget ) {
	if ( ! is_user_logged_in() || is_admin() ) {
		return $content;
	}
	if ( false !== stripos( $content, 'Student Login' ) ) {
		$content = str_ireplace( 'Student Login', 'My Portal', $content );
	}
	if ( false !== strpos( $content, 'Log in to see attendance' ) ) {
		$content = str_replace( 'Log in to see attendance', 'See attendance', $content );
	}
	return $content;
}

/* ---- Parent portal shows "$" instead of the school currency: use the school symbol ---- */
add_filter( 'elementor/widget/render_content', 'tms_parent_currency', 11, 2 );
function tms_parent_currency( $content, $widget ) {
	if ( is_admin() || false === strpos( $content, 'wlsm-container-parent' ) ) {
		return $content;
	}
	$sym = ewc_currency_symbol();
	return '$' === $sym ? $content : preg_replace( '/\$\s?(?=\d)/', $sym, $content );
}

/* ==== Branded Teacher Panel (School Management staff, non-administrators) ==== */
function tms_is_staff_user( $user_id = 0 ) {
	global $wpdb;
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
		return false;
	}
	$tms_u = get_userdata( $user_id );
	if ( $tms_u && in_array( 'tms_committee', (array) $tms_u->roles, true ) ) {
		return false; // committee members use the Committee Panel, not the Teacher Panel.
	}
	static $cache = array();
	if ( ! isset( $cache[ $user_id ] ) ) {
		$table = $wpdb->prefix . 'wlsm_staff';
		$cache[ $user_id ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) );
	}
	return $cache[ $user_id ];
}
function tms_staff_panel_url() {
	return admin_url( 'admin.php?page=sm-staff-dashboard' );
}
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && tms_is_staff_user( $user->ID ) ) {
		return tms_staff_panel_url();
	}
	return $redirect;
}, 21, 3 );
/* Keep staff inside School Management pages only. */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! tms_is_staff_user() ) {
		return;
	}
	global $pagenow;
	$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$allowed = array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'media-upload.php' );
	if ( 'admin.php' === $pagenow && '' !== $page ) { /* checked against the staff menu in admin_menu */
		return;
	}
	if ( in_array( $pagenow, $allowed, true ) ) {
		return;
	}
	wp_safe_redirect( tms_staff_panel_url() );
	exit;
}, 5 );
add_action( 'admin_menu', function () {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	global $menu;
	foreach ( (array) $menu as $item ) {
		$slug = isset( $item[2] ) ? $item[2] : '';
		if ( 0 !== strpos( $slug, 'sm-staff' ) ) {
			remove_menu_page( $slug );
		}
	}
}, 9999 );
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'customize', 'search', 'edit-profile', 'user-info' ) as $node ) {
		$bar->remove_node( $node );
	}
	$bar->add_node( array( 'id' => 'tms-view-site', 'title' => 'View Website', 'href' => home_url( '/' ) ) );
}, 999 );
add_filter( 'admin_footer_text', function ( $text ) {
	return tms_is_staff_user() ? esc_html( get_bloginfo( 'name' ) ) . ' &middot; Teacher Panel' : $text;
} );
add_filter( 'update_footer', function ( $text ) {
	return tms_is_staff_user() ? '' : $text;
}, 99 );
add_action( 'admin_head', function () {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'thumbnail' );
	echo '<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">';
	?>
	<style id="tms-teacher-panel">
	body.wp-admin{background:#F8FAFC;font-family:Nunito,sans-serif;}
	#wpadminbar{background:#043353;}
	#adminmenuback,#adminmenuwrap,#adminmenu{background:#043353;}
	#adminmenu a{color:#E2E8F0;font-family:Nunito,sans-serif;font-weight:700;}
	#adminmenu li.menu-top:hover,#adminmenu li > a.menu-top:focus{background:#0B4A73;}
	#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu .current a.menu-top{background:#F5B82E!important;color:#043353!important;}
	#adminmenu li.wp-has-current-submenu div.wp-menu-image:before,#adminmenu .current div.wp-menu-image:before{color:#043353!important;}
	#adminmenu .wp-submenu,#adminmenu .wp-has-current-submenu .wp-submenu{background:#022A44!important;}
	#adminmenu .wp-submenu a{color:#CBD5E1!important;font-weight:600;}
	#adminmenu .wp-submenu a:hover,#adminmenu .wp-submenu li.current a{color:#F5B82E!important;}
	#adminmenuwrap::before{content:"";display:block;height:110px;margin:14px auto 4px;width:110px;border-radius:50%;background:#fff <?php echo $logo ? 'url(' . esc_url( $logo ) . ') center/86% no-repeat' : ''; ?>;border:5px solid #F5B82E;}
	#adminmenuwrap::after{content:"Teacher Panel";display:block;text-align:center;color:#F5B82E;font-family:Fredoka,sans-serif;font-weight:700;font-size:15px;margin-bottom:10px;}
	.folded #adminmenuwrap::before{width:28px;height:28px;border-width:2px;}
	.folded #adminmenuwrap::after{display:none;}
	.wrap h1,.wrap h2,.wlsm h1,.wlsm h2,.wlsm-main-header,.wlsm .card-header{font-family:Fredoka,sans-serif!important;color:#043353;}
	.wlsm .card,.wlsm .wlsm-page-heading-box,.postbox{border-radius:18px!important;border:2px solid #EEF2FF!important;box-shadow:0 8px 24px rgba(4,51,83,.07)!important;overflow:hidden;}
	.wlsm .btn-primary,.wlsm .btn-success,.wp-core-ui .button-primary{background:#E44546!important;border-color:#E44546!important;border-radius:999px!important;font-weight:800;}
	.wlsm .btn-outline-primary{border-radius:999px!important;}
	.wlsm table thead th,.wlsm table.dataTable thead th{background:#043353!important;color:#fff!important;}
	#wpfooter{color:#64748B;}
	.wlsm-section-heading-block,.wlsm .bg-primary,.bg-primary{background:linear-gradient(135deg,#043353,#0B5C8E)!important;border-radius:16px!important;box-shadow:0 8px 20px rgba(4,51,83,.15)!important;font-family:Fredoka,sans-serif!important;}
	.btn-primary,.btn-success,.btn-info{background:#E44546!important;border-color:#E44546!important;border-radius:999px!important;font-weight:800!important;box-shadow:0 3px 0 #B92F31!important;}
	.btn-outline-primary{color:#043353!important;border-color:#043353!important;border-radius:999px!important;font-weight:700;}
	.btn-outline-primary:hover{background:#043353!important;color:#fff!important;}
	.table thead th,table.dataTable thead th,thead.bg-primary th,tr.bg-primary th{background:#043353!important;color:#fff!important;border-color:#0B4A73!important;}
	.table tbody tr:nth-child(even){background:#F8FAFC;}
	.page-item.active .page-link{background:#E44546!important;border-color:#E44546!important;}
	.page-link{color:#043353!important;border-radius:10px!important;margin:0 2px;}
	.form-control,.custom-select,select,input[type=text],input[type=email],input[type=number],textarea{border-radius:12px!important;border:2px solid #E2E8F0!important;box-shadow:none!important;}
	.form-control:focus,select:focus,input:focus,textarea:focus{border-color:#F5B82E!important;box-shadow:0 0 0 3px rgba(245,184,46,.25)!important;}
	.wlsm-main-header,.wlsm-page-heading-box{background:#fff!important;border-radius:18px!important;border:2px solid #EEF2FF!important;}
	.text-primary{color:#043353!important;}
	.badge-primary,.badge-success{background:#10B981!important;}
	.alert-info{background:#FFF8E6!important;border-color:#F5B82E!important;color:#043353!important;border-radius:14px!important;}
	#adminmenuwrap::after{display:none!important;}
	#adminmenu::before{content:'Teacher Panel';display:block;text-align:center;color:#F5B82E;font-family:Fredoka,sans-serif;font-weight:700;font-size:15px;margin:0 0 10px;}
	.folded #adminmenu::before{display:none;}
	.notice:not(.wlsm-notice),.update-nag,#screen-meta-links{display:none!important;}
	</style>
	<?php
} );
/* On the website, send staff to their panel instead of the student/parent portal. */
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( 'school_management_account' !== $tag || ! tms_is_staff_user() ) {
		return $output;
	}
	$user = wp_get_current_user();
	return '<div class="tms-teacher-card" style="text-align:center;background:#fff;border-radius:24px;padding:36px 24px;border:2px solid #EEF2FF;box-shadow:0 10px 30px rgba(4,51,83,.08)">'
		. '<div style="font-size:44px">&#127891;</div>'
		. '<h3 style="font-family:Fredoka,sans-serif;color:#043353;margin:8px 0">Welcome, ' . esc_html( $user->display_name ) . '</h3>'
		. '<p style="color:#475569;margin:0 0 18px">Manage your classes, students, attendance, homework and results in the Teacher Panel.</p>'
		. '<a href="' . esc_url( tms_staff_panel_url() ) . '" style="display:inline-block;background:#E44546;color:#fff;padding:12px 28px;border-radius:999px;font-weight:800;text-decoration:none;box-shadow:0 4px 0 #B92F31">Open Teacher Panel</a> '
		. '<a href="' . esc_url( wp_logout_url( ewc_portal_url() ) ) . '" style="display:inline-block;margin-left:8px;color:#043353;font-weight:700">Logout</a>'
		. '</div>';
}, 20, 2 );

/* ---- Teacher Panel: page allow-list from the plugin's own staff menu ---- */
add_action( 'admin_menu', function () {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	global $pagenow, $submenu, $menu;
	if ( 'admin.php' !== $pagenow || empty( $_GET['page'] ) ) {
		return;
	}
	$page    = sanitize_key( wp_unslash( $_GET['page'] ) );
	$allowed = array();
	foreach ( (array) $menu as $item ) {
		if ( isset( $item[2] ) && 0 === strpos( $item[2], 'sm-staff' ) ) {
			$allowed[] = $item[2];
		}
	}
	foreach ( (array) $submenu as $parent => $items ) {
		if ( 0 !== strpos( $parent, 'sm-staff' ) ) {
			continue;
		}
		$allowed[] = $parent;
		foreach ( (array) $items as $sub ) {
			if ( isset( $sub[2] ) ) {
				$allowed[] = $sub[2];
			}
		}
	}
	if ( 0 === strpos( $page, 'sm-staff' ) || in_array( $page, $allowed, true ) ) {
		return;
	}
	wp_safe_redirect( tms_staff_panel_url() );
	exit;
}, 10001 );

/* ---- Teacher Panel: no WordPress toolbar; Website + Logout live in the side menu ---- */
add_action( 'admin_head', function () {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	echo '<style id="tms-teacher-nobar">#wpadminbar{display:none!important}html.wp-toolbar{padding-top:0!important}#adminmenuwrap{padding-top:0}.tms-side-link a{color:#E2E8F0!important;font-weight:700}.tms-side-link a:hover{color:#F5B82E!important}.tms-side-link .wp-menu-image:before{color:#F5B82E!important}li.tms-side-sep{border-top:1px solid rgba(255,255,255,.15);margin:10px 12px 6px}</style>';
}, 99 );
add_action( 'admin_footer', function () {
	if ( ! tms_is_staff_user() ) {
		return;
	}
	$user = wp_get_current_user();
	$site = esc_url( home_url( '/' ) );
	$out  = esc_url( wp_logout_url( ewc_portal_url() ) );
	?>
	<script>
	(function () {
		var m = document.getElementById('adminmenu');
		if (!m) { return; }
		var html = '<li class="tms-side-sep"></li>'
			+ '<li class="menu-top tms-side-link"><a class="menu-top" href="<?php echo $site; ?>"><div class="wp-menu-image dashicons-before dashicons-admin-home"><br></div><div class="wp-menu-name">View Website</div></a></li>'
			+ '<li class="menu-top tms-side-link"><a class="menu-top" href="<?php echo $out; ?>"><div class="wp-menu-image dashicons-before dashicons-migrate"><br></div><div class="wp-menu-name">Logout</div></a></li>'
			+ '<li class="tms-side-link" style="padding:6px 12px;color:#94A3B8;font-size:12px"><?php echo esc_js( $user->display_name ); ?></li>';
		var c = document.getElementById('collapse-menu');
		if (c) { c.insertAdjacentHTML('beforebegin', html); } else { m.insertAdjacentHTML('beforeend', html); }
	})();
	</script>
	<?php
} );

/* ---- Hide the WordPress login screen: everyone signs in through the school portal ---- */
add_action( 'login_init', function () {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
	if ( 'login' !== $action || 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		return; // logout, lost password, reset password and form posts keep working.
	}
	if ( isset( $_GET['tms_admin'] ) || isset( $_REQUEST['interim-login'] ) || isset( $_GET['loggedout'] ) && is_user_logged_in() ) {
		return; // secret admin door + in-dashboard session re-login.
	}
	$target = ewc_portal_url();
	if ( ! empty( $_GET['redirect_to'] ) ) {
		$target = add_query_arg( 'redirect_to', rawurlencode( wp_unslash( $_GET['redirect_to'] ) ), $target );
	}
	wp_safe_redirect( $target );
	exit;
} );
add_action( 'wp_login_failed', function () {
	$ref = wp_get_referer();
	if ( $ref && false !== strpos( $ref, 'tms_admin' ) ) {
		return;
	}
	wp_safe_redirect( add_query_arg( 'login', 'failed', ewc_portal_url() ) );
	exit;
} );
add_filter( 'logout_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
		return $redirect;
	}
	return ewc_portal_url();
}, 10, 3 );
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( 'school_management_account' === $tag && ! is_user_logged_in() && isset( $_GET['login'] ) && 'failed' === $_GET['login'] ) {
		$output = '<div style="background:#FEF2F2;border-left:6px solid #E44546;border-radius:14px;padding:14px 18px;margin-bottom:16px;color:#991B1B;font-weight:700">Wrong username or password. Please try again.</div>' . $output;
	}
	return $output;
}, 30, 2 );

/* Empty username/password from the portal form: back to the portal, not wp-login.php */
add_filter( 'authenticate', 'tms_empty_login', 99, 3 );
function tms_empty_login( $user, $username, $password ) {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ( '' !== (string) $username && '' !== (string) $password ) ) {
		return $user;
	}
	$ref = wp_get_referer();
	if ( ! $ref || false !== strpos( $ref, 'tms_admin' ) || false !== strpos( $ref, 'wp-login.php' ) ) {
		return $user;
	}
	wp_safe_redirect( add_query_arg( 'login', 'failed', ewc_portal_url() ) );
	exit;
}

/* ==== Teacher Insights dashboard (Power BI style) on the Teacher Panel home ==== */
function tms_teacher_dashboard_data( $user_id ) {
	global $wpdb;
	$p     = $wpdb->prefix . 'wlsm_';
	$sess  = class_exists( 'WLSM_Config' ) ? WLSM_Config::current_session() : array();
	$sid   = isset( $sess['ID'] ) ? (int) $sess['ID'] : (int) get_option( 'wlsm_current_session' );
	$sec   = $wpdb->get_row( $wpdb->prepare( "SELECT a.section_id, se.label AS section_label, se.class_school_id, c.label AS class_label FROM {$p}staff s JOIN {$p}admins a ON a.staff_id = s.ID LEFT JOIN {$p}sections se ON se.ID = a.section_id LEFT JOIN {$p}class_school cs ON cs.ID = se.class_school_id LEFT JOIN {$p}classes c ON c.ID = cs.class_id WHERE s.user_id = %d LIMIT 1", $user_id ) );
	$d     = array( 'session' => isset( $sess['label'] ) ? $sess['label'] : '', 'has_class' => false );
	if ( ! $sec || ! $sec->section_id ) {
		return $d;
	}
	$sec_id             = (int) $sec->section_id;
	$d['has_class']     = true;
	$d['class']         = $sec->class_label . ' · Section ' . $sec->section_label;
	$students           = $wpdb->get_results( $wpdb->prepare( "SELECT ID, name, gender FROM {$p}student_records WHERE section_id = %d AND session_id = %d AND is_active = 1", $sec_id, $sid ) );
	$d['students']      = count( $students );
	$d['boys']          = 0;
	$d['girls']         = 0;
	foreach ( $students as $s ) {
		if ( 'female' === strtolower( (string) $s->gender ) ) { $d['girls']++; } else { $d['boys']++; }
	}
	$att_where          = $wpdb->prepare( "sr.section_id = %d AND sr.session_id = %d AND ( a.subject_id IS NULL OR a.subject_id = 0 )", $sec_id, $sid );
	$days               = $wpdb->get_results( "SELECT a.attendance_date AS d, SUM(a.status = 'p') AS p, SUM(a.status = 'a') AS ab, COUNT(*) AS t FROM {$p}attendance a JOIN {$p}student_records sr ON sr.ID = a.student_record_id WHERE {$att_where} GROUP BY a.attendance_date ORDER BY a.attendance_date DESC LIMIT 14" );
	$d['trend']         = array_reverse( (array) $days );
	$tot                = $wpdb->get_row( "SELECT SUM(a.status = 'p') AS p, SUM(a.status = 'a') AS ab, SUM(a.status NOT IN ('p','a')) AS o, COUNT(*) AS t FROM {$p}attendance a JOIN {$p}student_records sr ON sr.ID = a.student_record_id WHERE {$att_where}" );
	$d['att_p']         = (int) $tot->p;
	$d['att_a']         = (int) $tot->ab;
	$d['att_o']         = (int) $tot->o;
	$d['att_rate']      = $tot->t ? round( 100 * $tot->p / $tot->t, 1 ) : 0;
	$today              = current_time( 'Y-m-d' );
	$last               = $days ? $days[0] : null;
	$d['today_taken']   = $last && $last->d === $today;
	$d['last_day']      = $last;
	$d['low']           = $wpdb->get_results( "SELECT sr.name, ROUND(100 * SUM(a.status = 'p') / COUNT(*), 1) AS rate, SUM(a.status = 'a') AS absences FROM {$p}attendance a JOIN {$p}student_records sr ON sr.ID = a.student_record_id WHERE {$att_where} GROUP BY sr.ID HAVING rate < 90 ORDER BY rate ASC LIMIT 6" );
	$d['homework']      = $wpdb->get_results( $wpdb->prepare( "SELECT h.title, h.subject, h.homework_date FROM {$p}homework h JOIN {$p}homework_section hs ON hs.homework_id = h.ID WHERE hs.section_id = %d AND h.session_id = %d ORDER BY h.homework_date DESC LIMIT 5", $sec_id, $sid ) );
	$d['hw_week']       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}homework h JOIN {$p}homework_section hs ON hs.homework_id = h.ID WHERE hs.section_id = %d AND h.homework_date >= %s", $sec_id, gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ) ) );
	$d['exams']         = $wpdb->get_results( $wpdb->prepare( "SELECT e.label, e.start_date, e.end_date FROM {$p}exams e JOIN {$p}class_school_exam ce ON ce.exam_id = e.ID WHERE ce.class_school_id = %d AND e.end_date >= %s ORDER BY e.start_date ASC LIMIT 4", (int) $sec->class_school_id, $today ) );
	$d['events']        = $wpdb->get_results( $wpdb->prepare( "SELECT title, event_date FROM {$p}events WHERE is_active = 1 AND event_date >= %s ORDER BY event_date ASC LIMIT 4", $today ) );
	$d['notices']       = $wpdb->get_results( "SELECT title, created_at FROM {$p}notices WHERE is_active = 1 ORDER BY created_at DESC LIMIT 4" );
	$d['leaves']        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}leaves l JOIN {$p}student_records sr ON sr.ID = l.student_record_id WHERE sr.section_id = %d AND ( l.is_approved IS NULL OR l.is_approved = 0 )", $sec_id ) );
	return $d;
}

/* Who the Teacher Insights are for: the logged-in teacher, or (admins only) a teacher picked with &tdb_user=ID for preview. */
function tms_tdb_viewer() {
	if ( tms_is_staff_user() ) {
		return get_current_user_id();
	}
	if ( current_user_can( 'manage_options' ) && ! empty( $_GET['tdb_user'] ) ) {
		return absint( $_GET['tdb_user'] );
	}
	return 0;
}

function tms_tdb_donut( $parts, $label, $value ) {
	$total = array_sum( wp_list_pluck( $parts, 'v' ) );
	$stops = array();
	$acc   = 0;
	foreach ( $parts as $pt ) {
		$from    = $total ? $acc / $total * 100 : 0;
		$acc    += $pt['v'];
		$to      = $total ? $acc / $total * 100 : 0;
		$stops[] = $pt['c'] . ' ' . round( $from, 2 ) . '% ' . round( $to, 2 ) . '%';
	}
	$bg = $total ? 'conic-gradient(' . implode( ',', $stops ) . ')' : '#E2E8F0';
	$h  = '<div class="tdb-donut-wrap"><div class="tdb-donut" style="background:' . esc_attr( $bg ) . '"><div class="tdb-donut-hole"><b>' . esc_html( $value ) . '</b><span>' . esc_html( $label ) . '</span></div></div><ul class="tdb-legend">';
	foreach ( $parts as $pt ) {
		$pc = $total ? round( 100 * $pt['v'] / $total ) : 0;
		$h .= '<li><i style="background:' . esc_attr( $pt['c'] ) . '"></i>' . esc_html( $pt['l'] ) . '<em>' . esc_html( $pt['v'] ) . ' · ' . $pc . '%</em></li>';
	}
	return $h . '</ul></div>';
}

add_action( 'admin_notices', function () {
	if ( ! tms_tdb_viewer() || empty( $_GET['page'] ) || 'sm-staff-dashboard' !== $_GET['page'] ) {
		return;
	}
	$u   = get_userdata( tms_tdb_viewer() );
	$d   = tms_teacher_dashboard_data( $u->ID );
	$url = function ( $page, $extra = '' ) { return esc_url( admin_url( 'admin.php?page=' . $page . $extra ) ); };
	$hr  = (int) current_time( 'G' );
	$greet = $hr < 12 ? 'Good morning' : ( $hr < 17 ? 'Good afternoon' : 'Good evening' );
	$name  = $u->display_name;
	if ( is_email( $name ) ) {
		global $wpdb;
		$staff_name = $wpdb->get_var( $wpdb->prepare( "SELECT a.name FROM {$wpdb->prefix}wlsm_staff s JOIN {$wpdb->prefix}wlsm_admins a ON a.staff_id = s.ID WHERE s.user_id = %d", $u->ID ) );
		if ( $staff_name ) { $name = $staff_name; }
	}
	echo '<div class="tdb">';
	echo '<section class="tdb-hero"><div><p class="tdb-eyebrow">Teacher Insights · ' . esc_html( $d['session'] ) . '</p><h1>' . esc_html( $greet . ', ' . $name ) . ' 👋</h1><p class="tdb-sub">' . ( $d['has_class'] ? esc_html( $d['class'] ) . ' · ' : '' ) . esc_html( date_i18n( 'l, j F Y' ) ) . '</p></div>';
	echo '<div class="tdb-actions"><a class="tdb-btn tdb-btn-gold" href="' . $url( 'sm-staff-attendance' ) . '">✅ Take Attendance</a><a class="tdb-btn" href="' . $url( 'sm-staff-study-homework' ) . '">✏️ Homework</a><a class="tdb-btn" href="' . $url( 'sm-staff-notices' ) . '">📢 Notices</a><a class="tdb-btn" href="' . $url( 'sm-staff-exam-results' ) . '">🏆 Results</a></div></section>';
	if ( ! $d['has_class'] ) {
		echo '<div class="tdb-card tdb-empty">No class is assigned to you yet. Ask the school admin to set your Class and Section in Staff settings.</div></div>';
		return;
	}
	$today_txt = $d['today_taken'] ? round( 100 * $d['last_day']->p / max( 1, $d['last_day']->t ) ) . '%' : 'Not taken';
	$kpis = array(
		array( '👧', 'Students', $d['students'], $d['boys'] . ' boys · ' . $d['girls'] . ' girls', 'navy', $url( 'sm-staff-students' ) ),
		array( '📊', 'Attendance rate', $d['att_rate'] . '%', 'This session', 'green', $url( 'sm-staff-attendance' ) ),
		array( '📅', 'Today', $today_txt, $d['today_taken'] ? ( $d['last_day']->ab . ' absent' ) : 'Tap to take attendance', $d['today_taken'] ? 'teal' : 'red', $url( 'sm-staff-attendance' ) ),
		array( '✏️', 'Homework', $d['hw_week'], 'Set in last 7 days', 'gold', $url( 'sm-staff-study-homework' ) ),
		array( '🏆', 'Upcoming exams', count( $d['exams'] ), $d['exams'] ? esc_html( $d['exams'][0]->label ) : 'None scheduled', 'purple', $url( 'sm-staff-exams' ) ),
		array( '📝', 'Leave requests', $d['leaves'], 'Waiting for review', $d['leaves'] ? 'red' : 'slate', $url( 'sm-staff-student-leaves' ) ),
	);
	echo '<section class="tdb-kpis">';
	foreach ( $kpis as $k ) {
		echo '<a class="tdb-kpi tdb-' . esc_attr( $k[4] ) . '" href="' . $k[5] . '"><span class="tdb-kpi-ic">' . $k[0] . '</span><span class="tdb-kpi-l">' . esc_html( $k[1] ) . '</span><b class="tdb-kpi-v">' . esc_html( $k[2] ) . '</b><span class="tdb-kpi-s">' . esc_html( $k[3] ) . '</span></a>';
	}
	echo '</section>';

	/* Attendance trend (SVG area chart). */
	$pts = array();
	$n   = count( $d['trend'] );
	$w   = 640; $h = 200; $pad = 28;
	foreach ( $d['trend'] as $i => $row ) {
		$rate  = $row->t ? 100 * $row->p / $row->t : 0;
		$x     = $n > 1 ? $pad + $i * ( $w - 2 * $pad ) / ( $n - 1 ) : $w / 2;
		$y     = $h - $pad - ( $rate / 100 ) * ( $h - 2 * $pad );
		$pts[] = array( round( $x, 1 ), round( $y, 1 ), round( $rate ), mysql2date( 'j M', $row->d ) );
	}
	$line = implode( ' ', array_map( function ( $q ) { return $q[0] . ',' . $q[1]; }, $pts ) );
	$area = $pts ? $pts[0][0] . ',' . ( $h - $pad ) . ' ' . $line . ' ' . end( $pts )[0] . ',' . ( $h - $pad ) : '';
	echo '<section class="tdb-grid"><div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Attendance trend</h3><span>Last ' . (int) $n . ' school days</span></div>';
	if ( $pts ) {
		echo '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="tdb-chart" preserveAspectRatio="none"><defs><linearGradient id="tdbg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F5B82E" stop-opacity=".45"/><stop offset="1" stop-color="#F5B82E" stop-opacity="0"/></linearGradient></defs>';
		foreach ( array( 100, 75, 50 ) as $g ) {
			$gy = $h - $pad - ( $g / 100 ) * ( $h - 2 * $pad );
			echo '<line x1="' . $pad . '" x2="' . ( $w - $pad ) . '" y1="' . $gy . '" y2="' . $gy . '" class="tdb-gridline"/><text x="2" y="' . ( $gy + 4 ) . '" class="tdb-axis">' . $g . '%</text>';
		}
		echo '<polygon points="' . esc_attr( $area ) . '" fill="url(#tdbg)"/><polyline points="' . esc_attr( $line ) . '" class="tdb-line"/>';
		foreach ( $pts as $q ) {
			echo '<g class="tdb-pt"><circle cx="' . $q[0] . '" cy="' . $q[1] . '" r="5"/><title>' . esc_html( $q[3] . ': ' . $q[2] . '% present' ) . '</title></g>';
		}
		echo '</svg><div class="tdb-xlabels">';
		foreach ( $pts as $q ) { echo '<span>' . esc_html( $q[3] ) . '</span>'; }
		echo '</div>';
	} else {
		echo '<p class="tdb-muted">No attendance taken yet this session.</p>';
	}
	echo '</div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Attendance mix</h3><span>Session total</span></div>' . tms_tdb_donut( array( array( 'l' => 'Present', 'v' => $d['att_p'], 'c' => '#10B981' ), array( 'l' => 'Absent', 'v' => $d['att_a'], 'c' => '#E44546' ), array( 'l' => 'Other', 'v' => $d['att_o'], 'c' => '#F5B82E' ) ), 'present', $d['att_rate'] . '%' ) . '</div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Class profile</h3><span>' . (int) $d['students'] . ' pupils</span></div>' . tms_tdb_donut( array( array( 'l' => 'Boys', 'v' => $d['boys'], 'c' => '#38BDF8' ), array( 'l' => 'Girls', 'v' => $d['girls'], 'c' => '#F472B6' ) ), 'pupils', $d['students'] ) . '</div>';

	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Needs attention</h3><span>Below 90% attendance</span></div>';
	if ( $d['low'] ) {
		echo '<ul class="tdb-list">';
		foreach ( $d['low'] as $s ) {
			$cls = $s->rate < 75 ? 'bad' : 'warn';
			echo '<li><span>' . esc_html( $s->name ) . '<small>' . (int) $s->absences . ' absences</small></span><span class="tdb-bar"><i class="' . $cls . '" style="width:' . esc_attr( max( 4, (float) $s->rate ) ) . '%"></i></span><b class="' . $cls . '">' . esc_html( $s->rate ) . '%</b></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="tdb-good">🎉 Every pupil is above 90%. Great work!</p>';
	}
	echo '</div>';

	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Coming up</h3><span>Exams & events</span></div><ul class="tdb-timeline">';
	$items = array();
	foreach ( $d['exams'] as $x ) { $items[] = array( $x->start_date, '🏆 ' . $x->label, 'exam' ); }
	foreach ( $d['events'] as $x ) { $items[] = array( $x->event_date, '🎉 ' . $x->title, 'event' ); }
	usort( $items, function ( $a, $b ) { return strcmp( $a[0], $b[0] ); } );
	foreach ( array_slice( $items, 0, 5 ) as $it ) {
		echo '<li class="' . esc_attr( $it[2] ) . '"><time>' . esc_html( mysql2date( 'j M', $it[0] ) ) . '</time><span>' . esc_html( $it[1] ) . '</span></li>';
	}
	if ( ! $items ) { echo '<li><span class="tdb-muted">Nothing scheduled.</span></li>'; }
	echo '</ul></div>';

	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Recent homework</h3><a href="' . $url( 'sm-staff-study-homework' ) . '">View all →</a></div><ul class="tdb-mini">';
	foreach ( $d['homework'] as $hw ) {
		echo '<li><b>' . esc_html( $hw->title ) . '</b><span>' . esc_html( trim( $hw->subject . ' · ' . mysql2date( 'j M', $hw->homework_date ), ' ·' ) ) . '</span></li>';
	}
	if ( ! $d['homework'] ) { echo '<li class="tdb-muted">No homework set yet.</li>'; }
	echo '</ul></div>';

	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Noticeboard</h3><a href="' . $url( 'sm-staff-notices' ) . '">Manage →</a></div><ul class="tdb-mini">';
	foreach ( $d['notices'] as $no ) {
		echo '<li><b>' . esc_html( $no->title ) . '</b><span>' . esc_html( mysql2date( 'j M Y', $no->created_at ) ) . '</span></li>';
	}
	if ( ! $d['notices'] ) { echo '<li class="tdb-muted">No notices.</li>'; }
	echo '</ul></div></section>';
	echo '<h2 class="tdb-section-title">School overview</h2></div>';
} );

add_action( 'admin_head', function () {
	if ( empty( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'sm-staff-dashboard', 'sm-staff-student-360', 'sm-staff-gradebook', 'tms-committee', 'tms-approvals', 'tms-activity' ), true ) || ( 'sm-staff-dashboard' === $_GET['page'] && ! tms_tdb_viewer() ) ) {
		return;
	}
	?>
	<style id="tms-teacher-insights">
	.tdb{--n:#043353;--n2:#0B5C8E;--r:#E44546;--g:#F5B82E;font-family:Nunito,sans-serif;margin:18px 20px 8px 0;color:#0F172A}
	.tdb *{box-sizing:border-box}
	.tdb-hero{display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;background:linear-gradient(120deg,#043353 0%,#0B5C8E 60%,#1E7FB8 100%);color:#fff;border-radius:26px;padding:28px 30px;position:relative;overflow:hidden;box-shadow:0 18px 40px rgba(4,51,83,.25)}
	.tdb-hero:before{content:"";position:absolute;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(245,184,46,.45),transparent 70%);right:-60px;top:-110px}
	.tdb-hero:after{content:"★";position:absolute;right:38%;bottom:12px;font-size:26px;color:#F5B82E;opacity:.8}
	.tdb-hero h1{font-family:Fredoka,sans-serif;font-size:30px;line-height:1.15;margin:4px 0;color:#fff;padding:0}
	.tdb-eyebrow{margin:0;text-transform:uppercase;letter-spacing:2px;font-size:12px;font-weight:800;color:#FDE68A}
	.tdb-sub{margin:4px 0 0;color:#CFE3F3;font-weight:600}
	.tdb-actions{display:flex;flex-wrap:wrap;gap:10px;position:relative;z-index:1}
	.tdb-btn{display:inline-flex;align-items:center;gap:6px;padding:11px 18px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);color:#fff!important;font-weight:800;text-decoration:none;transition:transform .15s,background .15s}
	.tdb-btn:hover{transform:translateY(-2px);background:rgba(255,255,255,.22)}
	.tdb-btn-gold{background:#F5B82E;border-color:#F5B82E;color:#043353!important;box-shadow:0 4px 0 #C78F12}
	.tdb-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin:20px 0}
	.tdb-kpi{display:flex;flex-direction:column;gap:2px;background:#fff;border-radius:20px;padding:18px;text-decoration:none;color:#0F172A!important;border:1px solid #E7ECF5;box-shadow:0 6px 18px rgba(4,51,83,.06);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
	.tdb-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px;background:var(--k)}
	.tdb-kpi:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(4,51,83,.12)}
	.tdb-kpi-ic{position:absolute;right:14px;top:14px;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:20px;background:color-mix(in srgb,var(--k) 14%,#fff)}
	.tdb-kpi-l{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#64748B}
	.tdb-kpi-v{font-family:Fredoka,sans-serif;font-size:32px;line-height:1.1;color:var(--k);margin-top:6px}
	.tdb-kpi-s{font-size:12.5px;color:#64748B;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
	.tdb-navy{--k:#043353}.tdb-green{--k:#10B981}.tdb-teal{--k:#0EA5E9}.tdb-red{--k:#E44546}.tdb-gold{--k:#E09B0B}.tdb-purple{--k:#8B5CF6}.tdb-slate{--k:#64748B}
	.tdb-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
	.tdb-card{background:#fff;border-radius:22px;padding:20px;border:1px solid #E7ECF5;box-shadow:0 6px 18px rgba(4,51,83,.06);min-width:0}
	.tdb-span2{grid-column:span 2}
	.tdb-card-h{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:12px}
	.tdb-card-h h3{font-family:Fredoka,sans-serif;font-size:18px;margin:0;color:#043353}
	.tdb-card-h span,.tdb-card-h a{font-size:12px;color:#64748B;font-weight:700;text-decoration:none}
	.tdb-card-h a{color:#E44546}
	.tdb-chart{width:100%;height:210px;display:block}
	.tdb-gridline{stroke:#E2E8F0;stroke-dasharray:4 4}
	.tdb-axis{font-size:11px;fill:#94A3B8;font-family:Nunito,sans-serif}
	.tdb-line{fill:none;stroke:#043353;stroke-width:3;stroke-linejoin:round;stroke-linecap:round}
	.tdb-pt circle{fill:#fff;stroke:#E44546;stroke-width:3;transition:r .15s}
	.tdb-pt:hover circle{r:8}
	.tdb-xlabels{display:flex;justify-content:space-between;padding:0 20px;font-size:11px;color:#94A3B8;font-weight:700}
	.tdb-donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
	.tdb-donut{width:140px;height:140px;border-radius:50%;display:grid;place-items:center;flex:none;animation:tdbSpin .9s ease-out}
	@keyframes tdbSpin{from{transform:rotate(-90deg) scale(.85);opacity:0}to{transform:none;opacity:1}}
	.tdb-donut-hole{width:92px;height:92px;border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px #EEF2F7}
	.tdb-donut-hole b{font-family:Fredoka,sans-serif;font-size:22px;color:#043353}
	.tdb-donut-hole span{font-size:11px;color:#64748B;font-weight:700}
	.tdb-legend{list-style:none;margin:0;padding:0;flex:1;min-width:120px}
	.tdb-legend li{display:flex;align-items:center;gap:8px;font-weight:700;font-size:13px;margin:6px 0;color:#334155}
	.tdb-legend i{width:12px;height:12px;border-radius:4px;flex:none}
	.tdb-legend em{margin-left:auto;font-style:normal;color:#64748B;font-size:12px}
	.tdb-list{list-style:none;margin:0;padding:0}
	.tdb-list li{display:grid;grid-template-columns:1.2fr 1fr auto;gap:10px;align-items:center;padding:8px 0;border-bottom:1px dashed #E2E8F0}
	.tdb-list li:last-child{border:0}
	.tdb-list small{display:block;color:#94A3B8;font-size:11px}
	.tdb-bar{height:8px;border-radius:99px;background:#F1F5F9;overflow:hidden}
	.tdb-bar i{display:block;height:100%;border-radius:99px}
	.tdb-bar i.warn,.tdb-list b.warn{background:#F5B82E;color:#B7791F}
	.tdb-bar i.bad{background:#E44546}.tdb-list b.bad{color:#E44546}
	.tdb-list b.warn{background:none}
	.tdb-timeline{list-style:none;margin:0;padding:0 0 0 14px;border-left:3px solid #EEF2FF}
	.tdb-timeline li{position:relative;padding:6px 0 10px 10px;display:flex;gap:10px;align-items:baseline}
	.tdb-timeline li:before{content:"";position:absolute;left:-22px;top:11px;width:12px;height:12px;border-radius:50%;background:#F5B82E;border:3px solid #fff;box-shadow:0 0 0 2px #F5B82E}
	.tdb-timeline li.exam:before{background:#8B5CF6;box-shadow:0 0 0 2px #8B5CF6}
	.tdb-timeline time{font-weight:800;color:#043353;font-size:12px;min-width:46px}
	.tdb-mini{list-style:none;margin:0;padding:0}
	.tdb-mini li{padding:9px 12px;border-radius:12px;background:#F8FAFC;margin-bottom:8px;border-left:4px solid #F5B82E}
	.tdb-mini li:nth-child(2n){border-left-color:#38BDF8}
	.tdb-mini li b{display:block;color:#043353;font-size:13.5px}
	.tdb-mini li span{font-size:12px;color:#64748B}
	.tdb-muted{color:#94A3B8;font-weight:600}
	.tdb-good{background:#ECFDF5;color:#047857;border-radius:14px;padding:14px;font-weight:700;margin:0}
	.tdb-empty{margin-top:18px;font-weight:700;color:#043353;background:#FFF8E6;border:2px dashed #F5B82E}
	.tdb-section-title{font-family:Fredoka,sans-serif;color:#043353;font-size:20px;margin:26px 0 0}
	@media (max-width:1400px){.tdb-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.tdb-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
	@media (max-width:782px){.tdb{margin-right:10px}.tdb-kpis,.tdb-grid{grid-template-columns:1fr 1fr}.tdb-span2{grid-column:1/-1}.tdb-hero{padding:22px}.tdb-hero h1{font-size:24px}}
	@media (max-width:520px){.tdb-kpis,.tdb-grid{grid-template-columns:1fr}}
	</style>
	<?php
}, 100 );

/* ==== 1-click attendance (Teacher Insights) ==== */
function tms_tdb_section( $user_id ) {
	global $wpdb;
	$p    = $wpdb->prefix . 'wlsm_';
	$sess = class_exists( 'WLSM_Config' ) ? WLSM_Config::current_session() : array();
	$sid  = isset( $sess['ID'] ) ? (int) $sess['ID'] : (int) get_option( 'wlsm_current_session' );
	$sec  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT a.section_id FROM {$p}staff s JOIN {$p}admins a ON a.staff_id = s.ID WHERE s.user_id = %d LIMIT 1", $user_id ) );
	return array( $sec, $sid );
}
function tms_tdb_roster( $user_id, $date ) {
	global $wpdb;
	$p = $wpdb->prefix . 'wlsm_';
	list( $sec, $sid ) = tms_tdb_section( $user_id );
	if ( ! $sec ) {
		return array();
	}
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT sr.ID, sr.name, sr.roll_number, sr.gender, MAX(a.status) AS status FROM {$p}student_records sr LEFT JOIN {$p}attendance a ON a.student_record_id = sr.ID AND a.attendance_date = %s AND ( a.subject_id IS NULL OR a.subject_id = 0 ) WHERE sr.section_id = %d AND sr.session_id = %d AND sr.is_active = 1 GROUP BY sr.ID ORDER BY ( sr.roll_number + 0 ), sr.name",
		$date, $sec, $sid
	) );
}
function tms_tdb_render_roster( $user_id ) {
	$today  = current_time( 'Y-m-d' );
	$roster = tms_tdb_roster( $user_id, $today );
	if ( ! $roster ) {
		return;
	}
	$taken = false;
	foreach ( $roster as $r ) { if ( $r->status ) { $taken = true; break; } }
	$palette = array( '#043353', '#E44546', '#0EA5E9', '#8B5CF6', '#10B981', '#E09B0B', '#F472B6' );
	echo '<section class="tdb-card tdb-att" id="tdb-att" data-date="' . esc_attr( $today ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'tms_quick_attendance' ) ) . '" data-user="' . (int) $user_id . '">';
	echo '<div class="tdb-att-head"><div><h3>📋 Today\'s attendance</h3><p>' . esc_html( date_i18n( 'l, j F' ) ) . ' · tap a pupil to switch Present / Absent' . ( $taken ? ' · <b class="tdb-att-saved">Saved ✓</b>' : '' ) . '</p></div>';
	echo '<div class="tdb-att-tools"><button type="button" class="tdb-chip-btn" data-all="p">✅ All present</button><button type="button" class="tdb-chip-btn" data-all="h">🏖️ Holiday</button></div></div>';
	echo '<div class="tdb-att-grid">';
	foreach ( $roster as $i => $r ) {
		$st    = $r->status ? $r->status : 'p';
		$parts = preg_split( '/\s+/', trim( $r->name ) );
		$ini   = strtoupper( mb_substr( $parts[0], 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '' ) );
		echo '<button type="button" class="tdb-pupil is-' . esc_attr( $st ) . '" data-id="' . (int) $r->ID . '" data-status="' . esc_attr( $st ) . '"><span class="tdb-av" style="background:' . esc_attr( $palette[ $i % count( $palette ) ] ) . '">' . esc_html( $ini ) . '</span><span class="tdb-pn">' . esc_html( $r->name ) . '<small>Roll ' . esc_html( $r->roll_number ? $r->roll_number : '–' ) . '</small></span><span class="tdb-st"></span></button>';
	}
	echo '</div><div class="tdb-att-foot"><div class="tdb-att-count"><span class="c-p">Present <b>0</b></span><span class="c-a">Absent <b>0</b></span><span class="c-h">Holiday <b>0</b></span></div><button type="button" class="tdb-save">' . ( $taken ? 'Update attendance' : 'Save attendance' ) . '</button></div></section>';
}
add_action( 'wp_ajax_tms_quick_attendance', function () {
	check_ajax_referer( 'tms_quick_attendance', 'nonce' );
	$is_admin = current_user_can( 'manage_options' );
	if ( ! tms_is_staff_user() && ! $is_admin ) {
		wp_send_json_error( 'Not allowed.', 403 );
	}
	$teacher = tms_is_staff_user() ? get_current_user_id() : absint( isset( $_POST['user'] ) ? $_POST['user'] : 0 );
	$date    = current_time( 'Y-m-d' );
	$items   = isset( $_POST['att'] ) ? json_decode( wp_unslash( $_POST['att'] ), true ) : array();
	if ( ! is_array( $items ) || ! $items ) {
		wp_send_json_error( 'Nothing to save.' );
	}
	$allowed = array();
	foreach ( tms_tdb_roster( $teacher, $date ) as $r ) { $allowed[ (int) $r->ID ] = true; }
	if ( ! $allowed ) {
		wp_send_json_error( 'No class assigned.' );
	}
	global $wpdb;
	$table = $wpdb->prefix . 'wlsm_attendance';
	$saved = 0;
	foreach ( $items as $sid => $st ) {
		$sid = (int) $sid;
		$st  = in_array( $st, array( 'p', 'a', 'h' ), true ) ? $st : '';
		if ( ! $st || empty( $allowed[ $sid ] ) ) {
			continue;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE student_record_id = %d AND attendance_date = %s AND ( subject_id IS NULL OR subject_id = 0 )", $sid, $date ) );
		$ok = $wpdb->insert( $table, array( 'attendance_date' => $date, 'student_record_id' => $sid, 'added_by' => get_current_user_id(), 'status' => $st, 'created_at' => current_time( 'mysql' ) ), array( '%s', '%d', '%d', '%s', '%s' ) );
		if ( $ok ) { $saved++; }
	}
	wp_send_json_success( array( 'saved' => $saved ) );
} );
add_action( 'admin_footer', function () {
	if ( ! tms_tdb_viewer() || empty( $_GET['page'] ) || 'sm-staff-dashboard' !== $_GET['page'] ) {
		return;
	}
	?>
	<style>
	.tdb-att{margin:0 0 16px;border:2px solid #E7ECF5}
	.tdb-att-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
	.tdb-att-head h3{font-family:Fredoka,sans-serif;font-size:20px;margin:0;color:#043353}
	.tdb-att-head p{margin:2px 0 0;color:#64748B;font-weight:600}
	.tdb-att-saved{color:#10B981}
	.tdb-att-tools{display:flex;gap:8px;flex-wrap:wrap}
	.tdb-chip-btn{border:2px solid #E2E8F0;background:#fff;border-radius:999px;padding:8px 16px;font-weight:800;color:#043353;cursor:pointer;font-family:Nunito,sans-serif;transition:all .15s}
	.tdb-chip-btn:hover{border-color:#F5B82E;background:#FFF8E6}
	.tdb-att-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
	.tdb-pupil{display:flex;align-items:center;gap:12px;text-align:left;padding:12px 14px;border-radius:18px;border:2px solid #E2E8F0;background:#fff;cursor:pointer;font-family:Nunito,sans-serif;transition:transform .12s,border-color .15s,background .15s;position:relative}
	.tdb-pupil:active{transform:scale(.97)}
	.tdb-av{width:44px;height:44px;border-radius:14px;color:#fff;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-weight:700;font-size:16px;flex:none}
	.tdb-pn{flex:1;font-weight:800;color:#0F172A;font-size:14px;line-height:1.2}
	.tdb-pn small{display:block;color:#94A3B8;font-weight:700;font-size:11px;margin-top:2px}
	.tdb-st{font-size:12px;font-weight:900;padding:5px 10px;border-radius:999px}
	.tdb-pupil.is-p{border-color:#A7F3D0;background:#F0FDF4}.tdb-pupil.is-p .tdb-st{background:#10B981;color:#fff}.tdb-pupil.is-p .tdb-st:after{content:"Present"}
	.tdb-pupil.is-a{border-color:#FECACA;background:#FEF2F2}.tdb-pupil.is-a .tdb-st{background:#E44546;color:#fff}.tdb-pupil.is-a .tdb-st:after{content:"Absent"}
	.tdb-pupil.is-h{border-color:#FDE68A;background:#FFFBEB}.tdb-pupil.is-h .tdb-st{background:#F5B82E;color:#043353}.tdb-pupil.is-h .tdb-st:after{content:"Holiday"}
	.tdb-att-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:2px dashed #EEF2F7}
	.tdb-att-count{display:flex;gap:16px;font-weight:800;color:#475569;flex-wrap:wrap}
	.tdb-att-count b{font-family:Fredoka,sans-serif;font-size:18px}
	.c-p b{color:#10B981}.c-a b{color:#E44546}.c-h b{color:#E09B0B}
	.tdb-save{background:#E44546;color:#fff;border:0;border-radius:999px;padding:12px 28px;font-weight:900;font-size:15px;cursor:pointer;box-shadow:0 4px 0 #B92F31;font-family:Nunito,sans-serif;transition:transform .15s}
	.tdb-save:hover{transform:translateY(-2px)}
	.tdb-save[disabled]{opacity:.6;cursor:wait}
	.tdb-toast{position:fixed;right:24px;bottom:24px;background:#043353;color:#fff;padding:14px 20px;border-radius:16px;font-weight:800;box-shadow:0 14px 30px rgba(0,0,0,.25);z-index:99999;transform:translateY(20px);opacity:0;transition:all .25s;font-family:Nunito,sans-serif}
	.tdb-toast.show{transform:none;opacity:1}.tdb-toast.err{background:#E44546}
	</style>
	<script>
	(function () {
		var box = document.getElementById('tdb-att');
		if (!box) { return; }
		var order = { p: 'a', a: 'p', h: 'p' };
		function count() {
			var c = { p: 0, a: 0, h: 0 };
			box.querySelectorAll('.tdb-pupil').forEach(function (b) { c[b.dataset.status]++; });
			box.querySelector('.c-p b').textContent = c.p;
			box.querySelector('.c-a b').textContent = c.a;
			box.querySelector('.c-h b').textContent = c.h;
		}
		function set(b, s) { b.dataset.status = s; b.className = 'tdb-pupil is-' + s; }
		box.addEventListener('click', function (e) {
			var p = e.target.closest('.tdb-pupil');
			if (p) { set(p, order[p.dataset.status] || 'p'); count(); return; }
			var all = e.target.closest('[data-all]');
			if (all) { box.querySelectorAll('.tdb-pupil').forEach(function (b) { set(b, all.dataset.all); }); count(); }
		});
		function toast(msg, err) {
			var t = document.createElement('div');
			t.className = 'tdb-toast' + (err ? ' err' : '');
			t.textContent = msg;
			document.body.appendChild(t);
			requestAnimationFrame(function () { t.classList.add('show'); });
			setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2600);
		}
		box.querySelector('.tdb-save').addEventListener('click', function () {
			var btn = this, att = {};
			box.querySelectorAll('.tdb-pupil').forEach(function (b) { att[b.dataset.id] = b.dataset.status; });
			var fd = new FormData();
			fd.append('action', 'tms_quick_attendance');
			fd.append('nonce', box.dataset.nonce);
			fd.append('user', box.dataset.user);
			fd.append('att', JSON.stringify(att));
			btn.disabled = true; btn.textContent = 'Saving…';
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
				btn.disabled = false; btn.textContent = 'Update attendance';
				if (res && res.success) { toast('✅ Attendance saved for ' + res.data.saved + ' pupils'); setTimeout(function () { location.reload(); }, 1200); }
				else { toast('⚠️ ' + ((res && res.data) || 'Could not save'), true); }
			}).catch(function () { btn.disabled = false; btn.textContent = 'Save attendance'; toast('⚠️ Network error, try again', true); });
		});
		count();
	})();
	</script>
	<?php
} );

/* ==== Student 360° and Gradebook pages (Teacher Panel) ==== */
add_action( 'admin_menu', function () {
	add_submenu_page( 'sm-staff-student', 'Student 360°', 'Student 360°', 'read', 'sm-staff-student-360', 'tms_page_360' );
	add_submenu_page( 'sm-staff-examination', 'Gradebook', 'Gradebook', 'read', 'sm-staff-gradebook', 'tms_page_gradebook' );
}, 50 );

/* Which pupils this user may see: a teacher's own section, or everyone for administrators. */
function tms_scope_section() {
	if ( current_user_can( 'manage_options' ) ) {
		return ! empty( $_GET['tdb_user'] ) ? tms_tdb_section( absint( $_GET['tdb_user'] ) ) : array( 0, 0 );
	}
	return tms_is_staff_user() ? tms_tdb_section( get_current_user_id() ) : array( -1, 0 );
}
function tms_scope_students() {
	global $wpdb;
	$p = $wpdb->prefix . 'wlsm_';
	list( $sec, $sid ) = tms_scope_section();
	$sess = class_exists( 'WLSM_Config' ) ? WLSM_Config::current_session() : array();
	$sid  = $sid ? $sid : ( isset( $sess['ID'] ) ? (int) $sess['ID'] : (int) get_option( 'wlsm_current_session' ) );
	if ( $sec < 0 ) {
		return array();
	}
	$where = $sec ? $wpdb->prepare( 'sr.section_id = %d AND', $sec ) : '';
	return $wpdb->get_results( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.roll_number, sr.gender, sr.admission_number, c.label AS class_label, se.label AS section_label, (SELECT ROUND(100 * SUM(a.status = 'p') / NULLIF(SUM(a.status IN ('p','a')), 0)) FROM {$p}attendance a WHERE a.student_record_id = sr.ID) AS rate FROM {$p}student_records sr LEFT JOIN {$p}sections se ON se.ID = sr.section_id LEFT JOIN {$p}class_school cs ON cs.ID = se.class_school_id LEFT JOIN {$p}classes c ON c.ID = cs.class_id WHERE {$where} sr.session_id = %d AND sr.is_active = 1 ORDER BY c.ID, ( sr.roll_number + 0 ), sr.name", $sid ) );
}
function tms_initials( $name ) {
	$parts = preg_split( '/\s+/', trim( $name ) );
	return strtoupper( mb_substr( $parts[0], 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '' ) );
}
function tms_grade( $pc ) {
	if ( null === $pc ) { return array( '–', 'slate' ); }
	if ( $pc >= 80 ) { return array( 'A', 'green' ); }
	if ( $pc >= 65 ) { return array( 'B', 'teal' ); }
	if ( $pc >= 50 ) { return array( 'C', 'gold' ); }
	if ( $pc >= 40 ) { return array( 'D', 'purple' ); }
	return array( 'E', 'red' );
}

function tms_page_360() {
	global $wpdb;
	$p        = $wpdb->prefix . 'wlsm_';
	$students = tms_scope_students();
	$allowed  = wp_list_pluck( $students, 'ID' );
	$id       = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
	$base     = admin_url( 'admin.php?page=sm-staff-student-360' . ( ! empty( $_GET['tdb_user'] ) ? '&tdb_user=' . absint( $_GET['tdb_user'] ) : '' ) );
	$palette  = array( '#043353', '#E44546', '#0EA5E9', '#8B5CF6', '#10B981', '#E09B0B', '#F472B6' );
	echo '<div class="tdb">';
	if ( ! $id || ! in_array( (string) $id, array_map( 'strval', $allowed ), true ) ) {
		echo '<section class="tdb-hero"><div><p class="tdb-eyebrow">Student 360°</p><h1>My pupils</h1><p class="tdb-sub">' . count( $students ) . ' pupils · tap a card to open the full profile</p></div><div class="tdb-actions"><input type="search" id="s360q" class="tdb-search" placeholder="Search name or roll…"></div></section><section class="s360-grid">';
		foreach ( $students as $i => $s ) {
			$rate = null === $s->rate ? '–' : $s->rate . '%';
			$cls  = null === $s->rate ? 'slate' : ( $s->rate >= 90 ? 'green' : ( $s->rate >= 75 ? 'gold' : 'red' ) );
			echo '<a class="s360-card" data-q="' . esc_attr( strtolower( $s->name . ' ' . $s->roll_number . ' ' . $s->admission_number ) ) . '" href="' . esc_url( add_query_arg( 'student', $s->ID, $base ) ) . '"><span class="tdb-av" style="background:' . esc_attr( $palette[ $i % 7 ] ) . '">' . esc_html( tms_initials( $s->name ) ) . '</span><span class="s360-n">' . esc_html( $s->name ) . '<small>' . esc_html( $s->class_label . ' · ' . $s->section_label . ' · Roll ' . ( $s->roll_number ? $s->roll_number : '–' ) ) . '</small></span><span class="s360-rate tdb-' . $cls . '">' . esc_html( $rate ) . '</span></a>';
		}
		if ( ! $students ) { echo '<div class="tdb-card tdb-empty">No pupils found for your class.</div>'; }
		echo '</section></div><script>(function(){var q=document.getElementById("s360q");if(!q)return;q.addEventListener("input",function(){var v=q.value.toLowerCase();document.querySelectorAll(".s360-card").forEach(function(c){c.style.display=c.dataset.q.indexOf(v)>-1?"":"none";});});})();</script>';
		return;
	}
	$s = $wpdb->get_row( $wpdb->prepare( "SELECT sr.*, c.label AS class_label, se.label AS section_label FROM {$p}student_records sr LEFT JOIN {$p}sections se ON se.ID = sr.section_id LEFT JOIN {$p}class_school cs ON cs.ID = se.class_school_id LEFT JOIN {$p}classes c ON c.ID = cs.class_id WHERE sr.ID = %d", $id ) );
	$att = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'p') p, SUM(status = 'a') a, SUM(status = 'h') h FROM {$p}attendance WHERE student_record_id = %d", $id ) );
	$tot = (int) $att->p + (int) $att->a;
	$rate = $tot ? round( 100 * $att->p / $tot, 1 ) : null;
	$months = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(attendance_date, '%%Y-%%m') m, SUM(status = 'p') p, SUM(status IN ('p','a')) t FROM {$p}attendance WHERE student_record_id = %d GROUP BY m ORDER BY m DESC LIMIT 6", $id ) );
	$strip = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT attendance_date d, status FROM {$p}attendance WHERE student_record_id = %d AND ( subject_id IS NULL OR subject_id = 0 ) ORDER BY attendance_date DESC LIMIT 30", $id ) ) );
	$results = $wpdb->get_results( $wpdb->prepare( "SELECT e.label exam, ep.subject_label subject, ep.maximum_marks mx, er.obtained_marks ob FROM {$p}exam_results er JOIN {$p}admit_cards ac ON ac.ID = er.admit_card_id JOIN {$p}exam_papers ep ON ep.ID = er.exam_paper_id JOIN {$p}exams e ON e.ID = ep.exam_id WHERE ac.student_record_id = %d ORDER BY e.start_date DESC, ep.paper_order", $id ) );
	$fees = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$p}invoices WHERE student_record_id = %d GROUP BY status", $id ), OBJECT_K );
	$paid = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}payments WHERE student_record_id = %d", $id ) );
	$leaves = $wpdb->get_results( $wpdb->prepare( "SELECT start_date, end_date, description, is_approved FROM {$p}leaves WHERE student_record_id = %d ORDER BY start_date DESC LIMIT 5", $id ) );
	$hw = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}homework_submission WHERE student_id = %d", $id ) );
	$unpaid = ( isset( $fees['unpaid'] ) ? $fees['unpaid']->c : 0 ) + ( isset( $fees['partially_paid'] ) ? $fees['partially_paid']->c : 0 );
	$sum_ob = 0; $sum_mx = 0;
	foreach ( $results as $r ) { $sum_ob += (float) $r->ob; $sum_mx += (float) $r->mx; }
	$avg = $sum_mx ? round( 100 * $sum_ob / $sum_mx, 1 ) : null;
	$g   = tms_grade( $avg );
	$phone = function ( $n ) { return preg_replace( '/[^0-9+]/', '', (string) $n ); };
	echo '<a class="tdb-back" href="' . esc_url( $base ) . '">← All pupils</a>';
	echo '<section class="tdb-hero s360-hero"><div class="s360-id"><span class="tdb-av s360-big">' . esc_html( tms_initials( $s->name ) ) . '</span><div><p class="tdb-eyebrow">Student 360° · ' . esc_html( $s->class_label . ' · Section ' . $s->section_label ) . '</p><h1>' . esc_html( $s->name ) . '</h1><p class="tdb-sub">Roll ' . esc_html( $s->roll_number ? $s->roll_number : '–' ) . ' · Admission ' . esc_html( $s->admission_number ) . ' · ' . esc_html( ucfirst( (string) $s->gender ) ) . ( $s->dob ? ' · Born ' . esc_html( mysql2date( 'j M Y', $s->dob ) ) : '' ) . ( $s->blood_group ? ' · Blood ' . esc_html( $s->blood_group ) : '' ) . '</p></div></div>';
	echo '<div class="tdb-actions">';
	foreach ( array( array( $s->father_name, $s->father_phone, 'Father' ), array( $s->mother_name, $s->mother_phone, 'Mother' ) ) as $pp ) {
		if ( ! $pp[1] ) { continue; }
		$num = $phone( $pp[1] );
		echo '<a class="tdb-btn" href="tel:' . esc_attr( $num ) . '">📞 ' . esc_html( $pp[2] . ( $pp[0] ? ' · ' . $pp[0] : '' ) ) . '</a><a class="tdb-btn tdb-btn-gold" target="_blank" rel="noopener" href="https://wa.me/' . esc_attr( ltrim( $num, '+' ) ) . '">💬 WhatsApp</a>';
	}
	if ( ! $s->father_phone && ! $s->mother_phone && $s->phone ) {
		echo '<a class="tdb-btn" href="tel:' . esc_attr( $phone( $s->phone ) ) . '">📞 ' . esc_html( $s->phone ) . '</a>';
	}
	echo '</div></section>';
	$kpis = array(
		array( '📊', 'Attendance', null === $rate ? '–' : $rate . '%', (int) $att->p . ' present · ' . (int) $att->a . ' absent', null === $rate ? 'slate' : ( $rate >= 90 ? 'green' : ( $rate >= 75 ? 'gold' : 'red' ) ) ),
		array( '🏆', 'Average mark', null === $avg ? '–' : $avg . '%', count( $results ) . ' papers marked', $g[1] ),
		array( '🎓', 'Grade', $g[0], 'A ≥80 · B ≥65 · C ≥50', $g[1] ),
		array( '💳', 'Fees', $unpaid ? $unpaid . ' due' : 'Clear', 'Paid ' . number_format( $paid ) . ' ' . ewc_currency_symbol(), $unpaid ? 'red' : 'green' ),
		array( '✏️', 'Homework', $hw, 'Submissions', 'teal' ),
		array( '📝', 'Leaves', count( $leaves ), 'Recent requests', 'purple' ),
	);
	echo '<section class="tdb-kpis">';
	foreach ( $kpis as $k ) {
		echo '<div class="tdb-kpi tdb-' . esc_attr( $k[4] ) . '"><span class="tdb-kpi-ic">' . $k[0] . '</span><span class="tdb-kpi-l">' . esc_html( $k[1] ) . '</span><b class="tdb-kpi-v">' . esc_html( $k[2] ) . '</b><span class="tdb-kpi-s">' . esc_html( $k[3] ) . '</span></div>';
	}
	echo '</section><section class="tdb-grid">';
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Last 30 school days</h3><span>🟩 present · 🟥 absent · 🟨 holiday</span></div><div class="s360-strip">';
	foreach ( $strip as $d ) {
		echo '<i class="st-' . esc_attr( $d->status ) . '" title="' . esc_attr( mysql2date( 'D j M', $d->d ) . ' · ' . strtoupper( $d->status ) ) . '"><b>' . esc_html( mysql2date( 'j', $d->d ) ) . '</b></i>';
	}
	if ( ! $strip ) { echo '<p class="tdb-muted">No attendance yet.</p>'; }
	echo '</div><div class="s360-months">';
	foreach ( array_reverse( (array) $months ) as $m ) {
		$pc = $m->t ? round( 100 * $m->p / $m->t ) : 0;
		echo '<div class="s360-m"><div class="s360-mbar"><i style="height:' . (int) $pc . '%" class="' . ( $pc >= 90 ? 'ok' : ( $pc >= 75 ? 'mid' : 'low' ) ) . '"></i></div><b>' . (int) $pc . '%</b><span>' . esc_html( date_i18n( 'M', strtotime( $m->m . '-01' ) ) ) . '</span></div>';
	}
	echo '</div></div>';
	echo '<div class="tdb-card">' . '<div class="tdb-card-h"><h3>Attendance mix</h3><span>All time</span></div>' . tms_tdb_donut( array( array( 'l' => 'Present', 'v' => (int) $att->p, 'c' => '#10B981' ), array( 'l' => 'Absent', 'v' => (int) $att->a, 'c' => '#E44546' ), array( 'l' => 'Holiday', 'v' => (int) $att->h, 'c' => '#F5B82E' ) ), 'present', null === $rate ? '–' : round( $rate ) . '%' ) . '</div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Family & contact</h3></div><ul class="tdb-mini">';
	foreach ( array( 'Father' => array( $s->father_name, $s->father_phone, $s->father_occupation ), 'Mother' => array( $s->mother_name, $s->mother_phone, $s->mother_occupation ) ) as $lbl => $f ) {
		if ( $f[0] || $f[1] ) { echo '<li><b>' . esc_html( $lbl . ': ' . ( $f[0] ? $f[0] : '–' ) ) . '</b><span>' . esc_html( trim( $f[1] . ( $f[2] ? ' · ' . $f[2] : '' ) ) ) . '</span></li>'; }
	}
	if ( $s->address ) { echo '<li><b>Address</b><span>' . esc_html( $s->address ) . '</span></li>'; }
	echo '</ul></div>';
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Exam results</h3><a href="' . esc_url( admin_url( 'admin.php?page=sm-staff-exam-results' ) ) . '">Enter marks →</a></div>';
	if ( $results ) {
		echo '<table class="s360-table"><thead><tr><th>Exam</th><th>Subject</th><th>Marks</th><th>%</th><th>Grade</th></tr></thead><tbody>';
		foreach ( $results as $r ) {
			$pc = $r->mx ? round( 100 * $r->ob / $r->mx ) : null;
			$gg = tms_grade( $pc );
			echo '<tr><td>' . esc_html( $r->exam ) . '</td><td>' . esc_html( $r->subject ) . '</td><td>' . esc_html( $r->ob . ' / ' . $r->mx ) . '</td><td><span class="tdb-bar"><i class="' . ( $pc >= 65 ? 'ok' : ( $pc >= 50 ? 'warn' : 'bad' ) ) . '" style="width:' . (int) $pc . '%"></i></span></td><td><b class="s360-g tdb-' . $gg[1] . '">' . $gg[0] . '</b></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p class="tdb-muted">No marks entered yet.</p>';
	}
	echo '</div><div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Leave requests</h3></div><ul class="tdb-mini">';
	foreach ( $leaves as $l ) {
		$stt = $l->is_approved ? '✅ Approved' : '⏳ Pending';
		echo '<li><b>' . esc_html( mysql2date( 'j M', $l->start_date ) . ( $l->end_date && $l->end_date !== $l->start_date ? ' – ' . mysql2date( 'j M', $l->end_date ) : '' ) . ' · ' . $stt ) . '</b><span>' . esc_html( wp_trim_words( $l->description, 14 ) ) . '</span></li>';
	}
	if ( ! $leaves ) { echo '<li class="tdb-muted">No leave requests.</li>'; }
	echo '</ul></div></section></div>';
}

function tms_page_gradebook() {
	global $wpdb;
	$p = $wpdb->prefix . 'wlsm_';
	list( $sec ) = tms_scope_section();
	$students = tms_scope_students();
	$cs = $sec > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT class_school_id FROM {$p}sections WHERE ID = %d", $sec ) ) : 0;
	$exams = $cs
		? $wpdb->get_results( $wpdb->prepare( "SELECT e.ID, e.label, e.start_date FROM {$p}exams e JOIN {$p}class_school_exam ce ON ce.exam_id = e.ID WHERE ce.class_school_id = %d ORDER BY e.start_date DESC", $cs ) )
		: $wpdb->get_results( "SELECT ID, label, start_date FROM {$p}exams ORDER BY start_date DESC" );
	$exam_id = isset( $_GET['exam'] ) ? absint( $_GET['exam'] ) : ( $exams ? (int) $exams[0]->ID : 0 );
	echo '<div class="tdb"><section class="tdb-hero"><div><p class="tdb-eyebrow">Gradebook</p><h1>Class results at a glance</h1><p class="tdb-sub">Totals, percentages, grades and rank update automatically from the marks you enter.</p></div><div class="tdb-actions"><form method="get"><input type="hidden" name="page" value="sm-staff-gradebook">' . ( ! empty( $_GET['tdb_user'] ) ? '<input type="hidden" name="tdb_user" value="' . absint( $_GET['tdb_user'] ) . '">' : '' ) . '<select name="exam" class="tdb-select" onchange="this.form.submit()">';
	foreach ( $exams as $x ) { echo '<option value="' . (int) $x->ID . '"' . selected( $exam_id, (int) $x->ID, false ) . '>' . esc_html( $x->label ) . '</option>'; }
	if ( ! $exams ) { echo '<option>No exams</option>'; }
	echo '</select></form><a class="tdb-btn tdb-btn-gold" href="' . esc_url( admin_url( 'admin.php?page=sm-staff-exam-results' ) ) . '">✏️ Enter marks</a><button class="tdb-btn" onclick="window.print()">🖨️ Print</button></div></section>';
	if ( ! $exam_id ) { echo '<div class="tdb-card tdb-empty">No exams yet.</div></div>'; return; }
	$papers = $wpdb->get_results( $wpdb->prepare( "SELECT ep.ID, ep.subject_label, ep.maximum_marks FROM {$p}exam_papers ep LEFT JOIN {$p}subjects sb ON sb.ID = ep.subject_id WHERE ep.exam_id = %d" . ( $cs ? $wpdb->prepare( ' AND ( sb.class_school_id = %d OR ep.subject_id IS NULL OR ep.subject_id = 0 )', $cs ) : '' ) . ' ORDER BY ep.paper_order, ep.ID', $exam_id ) );
	$marks = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ac.student_record_id sid, er.exam_paper_id pid, er.obtained_marks ob FROM {$p}exam_results er JOIN {$p}admit_cards ac ON ac.ID = er.admit_card_id WHERE ac.exam_id = %d", $exam_id ) ) as $m ) {
		$marks[ $m->sid ][ $m->pid ] = $m->ob;
	}
	$rows = array();
	foreach ( $students as $st ) {
		$ob = 0; $mx = 0; $any = false;
		foreach ( $papers as $pp ) {
			if ( isset( $marks[ $st->ID ][ $pp->ID ] ) && '' !== $marks[ $st->ID ][ $pp->ID ] ) { $ob += (float) $marks[ $st->ID ][ $pp->ID ]; $mx += (float) $pp->maximum_marks; $any = true; }
		}
		$rows[] = array( 's' => $st, 'ob' => $ob, 'mx' => $mx, 'pc' => $any && $mx ? round( 100 * $ob / $mx, 1 ) : null );
	}
	$ranked = array_filter( $rows, function ( $r ) { return null !== $r['pc']; } );
	usort( $ranked, function ( $a, $b ) { return $b['pc'] <=> $a['pc']; } );
	$rank = array(); $i = 0;
	foreach ( $ranked as $r ) { $rank[ $r['s']->ID ] = ++$i; }
	$pcs  = wp_list_pluck( $ranked, 'pc' );
	$mean = $pcs ? round( array_sum( $pcs ) / count( $pcs ), 1 ) : null;
	$pass = $pcs ? count( array_filter( $pcs, function ( $x ) { return $x >= 50; } ) ) : 0;
	$dist = array( 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0 );
	foreach ( $pcs as $x ) { $dist[ tms_grade( $x )[0] ]++; }
	echo '<section class="tdb-kpis gb-kpis">';
	foreach ( array( array( '👧', 'Pupils', count( $rows ), count( $ranked ) . ' with marks', 'navy' ), array( '📈', 'Class average', null === $mean ? '–' : $mean . '%', 'All subjects', tms_grade( $mean )[1] ), array( '🥇', 'Top score', $pcs ? max( $pcs ) . '%' : '–', $ranked ? $ranked[0]['s']->name : '–', 'gold' ), array( '✅', 'Pass rate', $pcs ? round( 100 * $pass / count( $pcs ) ) . '%' : '–', $pass . ' passed (≥50%)', 'green' ) ) as $k ) {
		echo '<div class="tdb-kpi tdb-' . $k[4] . '"><span class="tdb-kpi-ic">' . $k[0] . '</span><span class="tdb-kpi-l">' . esc_html( $k[1] ) . '</span><b class="tdb-kpi-v">' . esc_html( $k[2] ) . '</b><span class="tdb-kpi-s">' . esc_html( $k[3] ) . '</span></div>';
	}
	echo '<div class="tdb-kpi tdb-purple gb-dist"><span class="tdb-kpi-l">Grade spread</span><div class="gb-bars">';
	$mxd = max( 1, max( $dist ) );
	foreach ( $dist as $gl => $n ) { echo '<span><i style="height:' . ( 8 + 52 * $n / $mxd ) . 'px" class="g-' . $gl . '"></i><b>' . $gl . '</b><em>' . $n . '</em></span>'; }
	echo '</div></div></section>';
	echo '<div class="tdb-card gb-wrap"><table class="gb-table"><thead><tr><th class="gb-sticky">Pupil</th>';
	foreach ( $papers as $pp ) { echo '<th>' . esc_html( $pp->subject_label ) . '<small>/' . esc_html( (float) $pp->maximum_marks ) . '</small></th>'; }
	echo '<th>Total</th><th>%</th><th>Grade</th><th>Rank</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$gg = tms_grade( $r['pc'] );
		echo '<tr><td class="gb-sticky"><a href="' . esc_url( admin_url( 'admin.php?page=sm-staff-student-360&student=' . $r['s']->ID ) ) . '">' . esc_html( $r['s']->name ) . '</a><small>Roll ' . esc_html( $r['s']->roll_number ? $r['s']->roll_number : '–' ) . '</small></td>';
		foreach ( $papers as $pp ) {
			$v = isset( $marks[ $r['s']->ID ][ $pp->ID ] ) ? $marks[ $r['s']->ID ][ $pp->ID ] : null;
			$pc = ( null !== $v && $pp->maximum_marks ) ? 100 * $v / $pp->maximum_marks : null;
			echo '<td class="' . ( null === $pc ? 'gb-empty' : ( $pc >= 65 ? 'gb-hi' : ( $pc >= 50 ? 'gb-mid' : 'gb-lo' ) ) ) . '">' . ( null === $v ? '—' : esc_html( (float) $v ) ) . '</td>';
		}
		echo '<td><b>' . ( $r['mx'] ? esc_html( $r['ob'] . ' / ' . $r['mx'] ) : '—' ) . '</b></td><td>' . ( null === $r['pc'] ? '—' : esc_html( $r['pc'] ) . '%' ) . '</td><td><b class="s360-g tdb-' . $gg[1] . '">' . $gg[0] . '</b></td><td>' . ( isset( $rank[ $r['s']->ID ] ) ? ( $rank[ $r['s']->ID ] <= 3 ? array( '', '🥇', '🥈', '🥉' )[ $rank[ $r['s']->ID ] ] : '#' . $rank[ $r['s']->ID ] ) : '—' ) . '</td></tr>';
	}
	echo '</tbody><tfoot><tr><td class="gb-sticky">Class average</td>';
	foreach ( $papers as $pp ) {
		$vals = array();
		foreach ( $rows as $r ) { if ( isset( $marks[ $r['s']->ID ][ $pp->ID ] ) ) { $vals[] = (float) $marks[ $r['s']->ID ][ $pp->ID ]; } }
		echo '<td>' . ( $vals ? round( array_sum( $vals ) / count( $vals ), 1 ) . '<small>max ' . max( $vals ) . '</small>' : '—' ) . '</td>';
	}
	echo '<td></td><td>' . ( null === $mean ? '—' : $mean . '%' ) . '</td><td></td><td></td></tr></tfoot></table>';
	if ( ! $papers ) { echo '<p class="tdb-muted">This exam has no papers for your class yet.</p>'; }
	echo '</div></div>';
}

add_action( 'admin_head', function () {
	if ( empty( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'sm-staff-student-360', 'sm-staff-gradebook', 'tms-committee', 'tms-approvals', 'tms-activity' ), true ) ) {
		return;
	}
	?>
	<style>
	.tdb-save{background:#E44546;color:#fff;border:0;border-radius:999px;padding:10px 24px;font-weight:900;cursor:pointer;box-shadow:0 4px 0 #B92F31}
	.tdb-back{display:inline-block;margin:14px 0 10px;font-weight:800;color:#043353;text-decoration:none;background:#FFF8E6;padding:8px 16px;border-radius:999px}
	.tdb-search,.tdb-select{border-radius:999px!important;border:0!important;padding:10px 18px!important;min-width:240px;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.15)!important}
	.s360-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-top:20px}
	.s360-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #E7ECF5;border-radius:20px;padding:14px 16px;text-decoration:none;box-shadow:0 6px 18px rgba(4,51,83,.06);transition:transform .18s,box-shadow .18s}
	.s360-card:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(4,51,83,.12)}
	.s360-n{flex:1;font-weight:800;color:#0F172A;font-size:15px}.s360-n small{display:block;color:#94A3B8;font-size:12px;font-weight:700}
	.s360-rate{font-family:Fredoka,sans-serif;font-size:18px;color:var(--k)}
	.s360-hero .s360-id{display:flex;gap:18px;align-items:center}
	.s360-big{width:84px!important;height:84px!important;border-radius:26px!important;font-size:30px!important;background:#F5B82E!important;color:#043353!important;box-shadow:0 8px 20px rgba(0,0,0,.2)}
	.s360-strip{display:flex;flex-wrap:wrap;gap:6px}
	.s360-strip i{width:30px;height:34px;border-radius:9px;display:grid;place-items:center;font-style:normal;font-size:11px;color:#fff}
	.s360-strip .st-p{background:#10B981}.s360-strip .st-a{background:#E44546}.s360-strip .st-h{background:#F5B82E;color:#043353}
	.s360-months{display:flex;gap:16px;align-items:flex-end;margin-top:18px;height:130px}
	.s360-m{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
	.s360-mbar{height:90px;width:100%;max-width:44px;background:#F1F5F9;border-radius:12px;display:flex;align-items:flex-end;overflow:hidden}
	.s360-mbar i{display:block;width:100%;border-radius:12px}.s360-mbar .ok{background:linear-gradient(#34D399,#10B981)}.s360-mbar .mid{background:linear-gradient(#FCD34D,#F5B82E)}.s360-mbar .low{background:linear-gradient(#F87171,#E44546)}
	.s360-m b{font-size:12px;color:#043353}.s360-m span{font-size:11px;color:#94A3B8;font-weight:700}
	.s360-table,.gb-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
	.s360-table th,.gb-table th{background:#043353;color:#fff;text-align:left;padding:11px 12px;font-family:Fredoka,sans-serif;font-weight:600;white-space:nowrap}
	.s360-table th:first-child,.gb-table th:first-child{border-radius:12px 0 0 12px}.s360-table th:last-child,.gb-table th:last-child{border-radius:0 12px 12px 0}
	.s360-table td,.gb-table td{padding:11px 12px;border-bottom:1px solid #EEF2F7}
	.s360-table .tdb-bar{display:block;min-width:90px}
	.tdb-bar i.ok{background:#10B981}
	.s360-g{display:inline-grid;place-items:center;width:32px;height:32px;border-radius:10px;color:#fff!important;background:var(--k);font-family:Fredoka,sans-serif}
	.gb-kpis{grid-template-columns:repeat(5,minmax(0,1fr))}
	.gb-bars{display:flex;gap:10px;align-items:flex-end;margin-top:8px;height:86px}
	.gb-bars span{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:11px}
	.gb-bars i{width:22px;border-radius:7px 7px 3px 3px}.gb-bars em{font-style:normal;color:#64748B}
	.g-A{background:#10B981}.g-B{background:#0EA5E9}.g-C{background:#F5B82E}.g-D{background:#8B5CF6}.g-E{background:#E44546}
	.gb-wrap{overflow-x:auto;padding:0!important}
	.gb-table th small{display:block;font-size:11px;opacity:.7;font-family:Nunito,sans-serif}
	.gb-table td{text-align:center}.gb-table td.gb-sticky{text-align:left}
	.gb-sticky{position:sticky;left:0;background:#fff;z-index:1;min-width:190px}
	.gb-table thead .gb-sticky{background:#043353}
	.gb-table td.gb-sticky a{font-weight:800;color:#043353;text-decoration:none}.gb-table td.gb-sticky small{display:block;color:#94A3B8}
	.gb-hi{background:#ECFDF5;color:#047857;font-weight:800}.gb-mid{background:#FFFBEB;color:#B45309;font-weight:800}.gb-lo{background:#FEF2F2;color:#B91C1C;font-weight:800}.gb-empty{color:#CBD5E1}
	.gb-table tbody tr:hover td{filter:brightness(.97)}
	.gb-table tfoot td{background:#F8FAFC;font-weight:800;color:#043353}.gb-table tfoot small{display:block;font-size:11px;color:#94A3B8}
	@media (max-width:1400px){.gb-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
	@media print{#adminmenumain,#wpadminbar,.tdb-actions,#wpfooter,.tdb-back{display:none!important}#wpcontent{margin:0!important}.tdb-hero{box-shadow:none}}
	</style>
	<?php
}, 101 );

/* ==== Teacher Panel design overhaul (bold look, dark mode) ==== */
function tms_tp_active() {
	return tms_is_staff_user() || ( function_exists( 'tms_is_committee' ) && tms_is_committee() ) || ( current_user_can( 'manage_options' ) && ! empty( $_GET['tdb_user'] ) );
}
add_filter( 'admin_body_class', function ( $classes ) {
	return ( tms_tp_active() && false === strpos( $classes, 'tms-tp' ) ) ? $classes . ' tms-tp' : $classes;
} );
add_action( 'admin_head', function () {
	if ( ! tms_tp_active() ) {
		return;
	}
	?>
	<script>try{if(localStorage.getItem('tmsDark')==='1'){document.documentElement.classList.add('tms-dark');}}catch(e){}</script>
	<style id="tms-tp-overhaul">
	.tdb-av{width:44px;height:44px;border-radius:14px;color:#fff;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-weight:700;font-size:16px;flex:none;line-height:1}
	.tms-tp #wpcontent{padding-left:24px}
	.tms-tp .wlsm.container-fluid{padding:18px 24px 30px 0}
	.tms-tp .wlsm-main-header{flex-direction:row!important;display:flex!important;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff!important;border:1px solid #E7ECF5!important;border-radius:20px!important;padding:14px 22px!important;box-shadow:0 6px 18px rgba(4,51,83,.06)!important;margin-bottom:18px}
	.tms-tp .wlsm-main-header h1{font-family:Fredoka,sans-serif!important;font-size:20px!important;margin:0!important;color:#043353!important;text-align:left!important}
	.tms-tp .wlsm-main-header h1 .fa-school{color:#F5B82E!important}
	.tms-tp .wlsm-main-header .wlsm_user_current_session_block{margin:0!important}
	.tms-tp .wlsm-section-heading-block{display:flex!important;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;text-align:left!important;padding:20px 24px!important;border-radius:22px!important;background:linear-gradient(120deg,#043353 0%,#0B5C8E 60%,#1E7FB8 100%)!important;box-shadow:0 14px 32px rgba(4,51,83,.22)!important;position:relative;overflow:hidden;margin-bottom:18px!important}
	.tms-tp .wlsm-section-heading-block:before{content:"";position:absolute;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(245,184,46,.4),transparent 70%);right:-40px;top:-90px;pointer-events:none}
	.tms-tp .wlsm-section-heading{font-family:Fredoka,sans-serif!important;font-size:24px!important;font-weight:700!important;color:#fff!important;position:relative}
	.tms-tp .wlsm-section-heading i{color:#F5B82E;margin-right:8px}
	.tms-tp .wlsm-section-heading-block .float-right,.tms-tp .wlsm-section-heading-block .btn{position:relative;float:none!important}
	.tms-tp .wlsm-section-heading-block .btn,.tms-tp .wlsm-section-heading-block a.btn{background:#F5B82E!important;border:0!important;color:#043353!important;border-radius:999px!important;font-weight:800!important;padding:8px 16px!important;box-shadow:0 3px 0 #C78F12!important}
	.tms-tp .wlsm-form-section,.tms-tp .wlsm .card:not(.wlsm-main-header),.tms-tp .wlsm-students-block{background:#fff!important;border:1px solid #E7ECF5!important;border-radius:22px!important;padding:20px!important;box-shadow:0 6px 18px rgba(4,51,83,.06)!important}
	.tms-tp .wlsm label,.tms-tp .wlsm .wlsm-font-bold{color:#043353;font-weight:800}
	.tms-tp .dataTables_wrapper .dataTables_filter input,.tms-tp .dataTables_wrapper .dataTables_length select{border-radius:999px!important;padding:6px 14px!important}
	.tms-tp table.dataTable{border-collapse:separate!important;border-spacing:0!important;border:0!important;border-radius:16px;overflow:hidden}
	.tms-tp table.dataTable thead th{background:#043353!important;color:#fff!important;border:0!important;font-family:Fredoka,sans-serif;font-weight:600;padding:13px 12px!important}
	.tms-tp table.dataTable tbody td{border-top:0!important;border-bottom:1px solid #EEF2F7!important;padding:12px!important;vertical-align:middle}
	.tms-tp table.dataTable tbody tr:hover td{background:#FFF8E6!important}
	.tms-tp table.dataTable tbody tr:nth-child(even) td{background:#FAFBFF}
	.tms-tp .dataTables_info{color:#64748B;font-weight:700}
	.tms-tp .btn-sm,.tms-tp .wlsm-btn-xs{border-radius:999px!important;font-weight:800!important}
	.tms-tp .btn-outline-success{color:#047857!important;border-color:#10B981!important}.tms-tp .btn-outline-success:hover{background:#10B981!important;color:#fff!important}
	.tms-tp .btn-outline-dark{border-radius:999px!important}
	.tms-tp .text-success{color:#10B981!important}.tms-tp .text-danger{color:#E44546!important}
	.tms-tp .wlsm-dashboard-card,.tms-tp .wlsm .card-body{border-radius:18px}
	.tms-tp #tms-dark-toggle{position:fixed;right:22px;bottom:22px;z-index:9999;width:50px;height:50px;border-radius:50%;border:0;background:#043353;color:#F5B82E;font-size:22px;cursor:pointer;box-shadow:0 10px 24px rgba(4,51,83,.35);transition:transform .2s}
	.tms-tp #tms-dark-toggle:hover{transform:rotate(-20deg) scale(1.06)}
	/* Dark mode */
	html.tms-dark .tms-tp,html.tms-dark .tms-tp #wpwrap,html.tms-dark .tms-tp #wpcontent,html.tms-dark .tms-tp #wpbody{background:#0B1220!important;color:#E2E8F0}
	html.tms-dark .tms-tp .wlsm-main-header,html.tms-dark .tms-tp .wlsm-form-section,html.tms-dark .tms-tp .wlsm .card:not(.wlsm-main-header),html.tms-dark .tms-tp .wlsm-students-block,html.tms-dark .tms-tp .tdb-card,html.tms-dark .tms-tp .tdb-kpi,html.tms-dark .tms-tp .s360-card,html.tms-dark .tms-tp .tdb-pupil,html.tms-dark .tms-tp .gb-sticky{background:#111A2E!important;border-color:#1F2A44!important;color:#E2E8F0!important}
	html.tms-dark .tms-tp .wlsm-main-header h1,html.tms-dark .tms-tp .tdb-card-h h3,html.tms-dark .tms-tp .tdb-att-head h3,html.tms-dark .tms-tp .wlsm label,html.tms-dark .tms-tp .s360-n,html.tms-dark .tms-tp .tdb-pn,html.tms-dark .tms-tp .tdb-mini li b,html.tms-dark .tms-tp .tdb-timeline time,html.tms-dark .tms-tp .gb-table td.gb-sticky a,html.tms-dark .tms-tp .tdb-donut-hole b{color:#F8FAFC!important}
	html.tms-dark .tms-tp .tdb-donut-hole{background:#111A2E;box-shadow:none}
	html.tms-dark .tms-tp .tdb-mini li,html.tms-dark .tms-tp table.dataTable tbody tr:nth-child(even) td,html.tms-dark .tms-tp .gb-table tfoot td{background:#0F1729!important}
	html.tms-dark .tms-tp table.dataTable tbody td,html.tms-dark .tms-tp .gb-table td,html.tms-dark .tms-tp .s360-table td{background:#111A2E;color:#E2E8F0;border-color:#1F2A44!important}
	html.tms-dark .tms-tp table.dataTable tbody tr:hover td{background:#1A2540!important}
	html.tms-dark .tms-tp .form-control,html.tms-dark .tms-tp select,html.tms-dark .tms-tp input[type=text],html.tms-dark .tms-tp input[type=search],html.tms-dark .tms-tp textarea{background:#0F1729!important;color:#E2E8F0!important;border-color:#1F2A44!important}
	html.tms-dark .tms-tp .tdb-legend li,html.tms-dark .tms-tp .tdb-pn small,html.tms-dark .tms-tp .tdb-kpi-s,html.tms-dark .tms-tp .tdb-kpi-l{color:#94A3B8!important}
	html.tms-dark .tms-tp #tms-dark-toggle{background:#F5B82E;color:#043353}
	@media (max-width:782px){.tms-tp #wpcontent{padding-left:10px}.tms-tp .wlsm.container-fluid{padding-right:10px}.tms-tp .wlsm-section-heading{font-size:19px!important}}
	</style>
	<?php
}, 120 );
add_action( 'admin_footer', function () {
	if ( ! tms_tp_active() ) {
		return;
	}
	echo '<button type="button" id="tms-dark-toggle" title="Dark / light mode" aria-label="Toggle dark mode">🌙</button><script>(function(){var b=document.getElementById("tms-dark-toggle"),h=document.documentElement;function s(){b.textContent=h.classList.contains("tms-dark")?"☀️":"🌙";}s();b.addEventListener("click",function(){h.classList.toggle("tms-dark");try{localStorage.setItem("tmsDark",h.classList.contains("tms-dark")?"1":"0");}catch(e){}s();});})();</script>';
} );

/* ==== Management Committee, Activity Log, Live activity, Alerts ==== */
add_action( 'init', function () {
	if ( ! get_role( 'tms_committee' ) ) {
		add_role( 'tms_committee', 'Committee', array( 'read' => true ) );
	}
} );
add_action( 'admin_init', function () {
	if ( '2' === get_option( 'tms_activity_db' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$wpdb->prefix}tms_activity (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_name varchar(190) NOT NULL DEFAULT '',
		user_role varchar(40) NOT NULL DEFAULT '',
		action varchar(120) NOT NULL DEFAULT '',
		label varchar(190) NOT NULL DEFAULT '',
		details text NULL,
		is_money tinyint(1) NOT NULL DEFAULT 0,
		ip varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY user_id (user_id)
	) $c;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}tms_approvals (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		item_type varchar(30) NOT NULL DEFAULT '',
		item_id bigint(20) unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT '',
		note text NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_name varchar(190) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY item (item_type,item_id)
	) $c;" );
	update_option( 'tms_activity_db', '2' );
}, 1 );

function tms_is_committee( $user_id = 0 ) {
	$u = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
	return $u && $u->exists() && in_array( 'tms_committee', (array) $u->roles, true ) && ! user_can( $u, 'manage_options' );
}
function tms_can_oversee() {
	return current_user_can( 'manage_options' ) || tms_is_committee();
}
function tms_user_role_label( $u ) {
	if ( user_can( $u, 'manage_options' ) ) { return 'Admin'; }
	if ( tms_is_committee( $u->ID ) ) { return 'Committee'; }
	if ( function_exists( 'tms_is_staff_user' ) && tms_is_staff_user( $u->ID ) ) { return 'Teacher'; }
	if ( function_exists( 'tms_is_portal_user' ) && tms_is_portal_user( $u->ID ) ) { return 'Parent/Student'; }
	return 'User';
}
function tms_user_display( $u ) {
	global $wpdb;
	if ( function_exists( 'tms_is_staff_user' ) && tms_is_staff_user( $u->ID ) ) {
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT a.name FROM {$wpdb->prefix}wlsm_staff s JOIN {$wpdb->prefix}wlsm_admins a ON a.staff_id = s.ID WHERE s.user_id = %d", $u->ID ) );
		if ( $n ) { return $n; }
	}
	return $u->display_name ? $u->display_name : $u->user_login;
}
function tms_is_money_action( $action ) {
	return (bool) preg_match( '/invoice|payment|fee|expense|income|collect|pay|discount|refund/i', $action );
}
function tms_log_activity( $action, $label, $details = array(), $user_id = 0 ) {
	global $wpdb;
	$u     = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
	$money = tms_is_money_action( $action );
	$row   = array(
		'user_id'    => $u ? (int) $u->ID : 0,
		'user_name'  => $u && $u->exists() ? tms_user_display( $u ) : 'Guest',
		'user_role'  => $u && $u->exists() ? tms_user_role_label( $u ) : '',
		'action'     => substr( $action, 0, 120 ),
		'label'      => substr( $label, 0, 190 ),
		'details'    => $details ? wp_json_encode( $details ) : '',
		'is_money'   => $money ? 1 : 0,
		'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : '',
		'created_at' => current_time( 'mysql' ),
	);
	$wpdb->insert( $wpdb->prefix . 'tms_activity', $row );
	if ( $money && ! empty( $row['user_id'] ) ) {
		$to = get_option( 'tms_alert_emails', get_option( 'admin_email' ) );
		$body  = "Money-related change on the school system:\n\n";
		$body .= 'Who: ' . $row['user_name'] . ' (' . $row['user_role'] . ")\n";
		$body .= 'What: ' . $row['label'] . "\n";
		$body .= 'When: ' . $row['created_at'] . "\n";
		$body .= 'IP: ' . $row['ip'] . "\n";
		foreach ( $details as $k => $val ) { $body .= ucfirst( str_replace( '_', ' ', $k ) ) . ': ' . $val . "\n"; }
		$body .= "\nFull log: " . admin_url( 'admin.php?page=tms-activity' );
		wp_mail( $to, '[School alert] ' . $row['user_name'] . ' · ' . $row['label'], $body );
	}
}
function tms_humanize_action( $action ) {
	$a = preg_replace( '/^(wlsm-|tms_)/', '', $action );
	$a = str_replace( array( '-', '_' ), ' ', $a );
	$a = preg_replace( '/^staff /', '', $a );
	return ucfirst( trim( $a ) );
}
function tms_safe_post_details() {
	$keep = array();
	foreach ( (array) $_POST as $k => $val ) {
		if ( preg_match( '/pass|nonce|key|secret|token|api|card|cvv|_wp_http_referer|^action$/i', $k ) || is_array( $val ) ) {
			continue;
		}
		if ( preg_match( '/(^|_)(id|name|label|title|amount|discount|date|status|class|section|roll|invoice|receipt|payment|student|admission|session|exam|subject|category|method)(_|$)/i', $k ) ) {
			$keep[ $k ] = mb_substr( sanitize_text_field( wp_unslash( $val ) ), 0, 80 );
		}
		if ( count( $keep ) >= 8 ) { break; }
	}
	return $keep;
}
/* Capture every successful School Management write (AJAX) without touching the plugin. */
add_action( 'admin_init', function () {
	if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	if ( 0 !== strpos( $action, 'wlsm-' ) || preg_match( '/fetch|get-|-get|view|print|search|load|check|keyword|export|download|show|preview|set-school|set-session/', $action ) ) {
		return;
	}
	$details = tms_safe_post_details();
	ob_start( function ( $buf, $phase ) use ( $action, $details ) {
		static $done = false;
		if ( $done ) {
			return $buf;
		}
		if ( false !== strpos( $buf, '"success":true' ) ) {
			$done = true;
			tms_log_activity( $action, tms_humanize_action( $action ), $details );
		} elseif ( ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			if ( false === strpos( $buf, '"success":false' ) ) {
				$done = true; // plugin cleared the buffer itself: still record the attempt.
				tms_log_activity( $action, tms_humanize_action( $action ) . ' (submitted)', $details );
			}
		}
		return $buf;
	} );
}, 0 );
add_action( 'wp_login', function ( $login, $user ) {
	tms_log_activity( 'login', 'Signed in', array(), $user->ID );
}, 10, 2 );
add_action( 'wp_ajax_tms_quick_attendance', function () {
	tms_log_activity( 'tms_quick_attendance', 'Saved today\'s attendance (quick)', array( 'date' => current_time( 'Y-m-d' ) ) );
}, 1 );

/* ---- Committee: login, menus, page lock ---- */
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	return ( $user instanceof WP_User && tms_is_committee( $user->ID ) ) ? admin_url( 'admin.php?page=tms-committee' ) : $redirect;
}, 22, 3 );
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! tms_is_committee() ) {
		return;
	}
	global $pagenow;
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'admin.php' === $pagenow && in_array( $page, array( 'tms-committee', 'tms-approvals', 'tms-activity', 'sm-staff-employees', 'sm-staff-employees-attendance', 'sm-staff-employee-leaves' ), true ) ) {
		return;
	}
	if ( in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=tms-committee' ) );
	exit;
}, 6 );
add_action( 'admin_menu', function () {
	add_menu_page( 'Committee', 'Committee', 'read', 'tms-committee', 'tms_page_committee', 'dashicons-groups', 2 );
	add_submenu_page( 'tms-committee', 'Committee Dashboard', 'Dashboard', 'read', 'tms-committee', 'tms_page_committee' );
	add_submenu_page( 'tms-committee', 'Approvals', 'Approvals', 'read', 'tms-approvals', 'tms_page_approvals' );
	add_submenu_page( 'tms-committee', 'Activity Log', 'Activity Log', 'read', 'tms-activity', 'tms_page_activity' );
	if ( ! tms_can_oversee() ) {
		remove_menu_page( 'tms-committee' );
	}
	if ( tms_is_committee() ) {
		global $menu;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && 'tms-committee' !== $item[2] ) { remove_menu_page( $item[2] ); }
		}
	}
	if ( tms_is_committee() ) {
		global $submenu;
		$submenu['tms-committee'][] = array( '👥 Staff & Teachers', 'read', admin_url( 'admin.php?page=sm-staff-employees' ) );
		$submenu['tms-committee'][] = array( 'Staff Attendance', 'read', admin_url( 'admin.php?page=sm-staff-employees-attendance' ) );
		$submenu['tms-committee'][] = array( 'Staff Leaves', 'read', admin_url( 'admin.php?page=sm-staff-employee-leaves' ) );
	}
}, 9998 );
add_filter( 'admin_body_class', function ( $c ) {
	return tms_is_committee() ? $c . ' tms-tp tms-cm' : $c;
} );
add_action( 'admin_head', function () {
	if ( ! tms_is_committee() ) {
		return;
	}
	$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'thumbnail' );
	echo '<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">';
	echo '<style>#wpadminbar{display:none!important}html.wp-toolbar{padding-top:0!important}body.wp-admin{background:#F8FAFC;font-family:Nunito,sans-serif}#adminmenuback,#adminmenuwrap,#adminmenu{background:#3B0764}#adminmenu a{color:#EDE9FE;font-weight:700}#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu{background:#F5B82E!important;color:#3B0764!important}#adminmenu .wp-submenu{background:#2E1065!important}#adminmenu .wp-submenu a{color:#DDD6FE!important}#adminmenu .wp-submenu li.current a{color:#F5B82E!important}#adminmenuwrap::before{content:"";display:block;height:110px;width:110px;margin:14px auto 4px;border-radius:50%;border:5px solid #F5B82E;background:#fff ' . ( $logo ? 'url(' . esc_url( $logo ) . ') center/86% no-repeat' : '' ) . '}#adminmenu::before{content:"Committee Panel";display:block;text-align:center;color:#F5B82E;font-family:Fredoka,sans-serif;font-weight:700;margin-bottom:10px}.notice,.update-nag,#screen-meta-links{display:none!important}#wpfooter{display:none}</style>';
} );
add_action( 'admin_footer', function () {
	if ( ! tms_is_committee() ) {
		return;
	}
	$out = esc_url( wp_logout_url( ewc_portal_url() ) );
	echo '<script>(function(){var m=document.getElementById("adminmenu");if(!m)return;m.insertAdjacentHTML("beforeend","<li class=\"menu-top\"><a class=\"menu-top\" href=\"' . esc_url( home_url( '/' ) ) . '\"><div class=\"wp-menu-image dashicons-before dashicons-admin-home\"><br></div><div class=\"wp-menu-name\">View Website</div></a></li><li class=\"menu-top\"><a class=\"menu-top\" href=\"' . $out . '\"><div class=\"wp-menu-image dashicons-before dashicons-migrate\"><br></div><div class=\"wp-menu-name\">Logout</div></a></li>");})();</script>';
} );
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( 'school_management_account' !== $tag || ! tms_is_committee() ) {
		return $output;
	}
	return '<div style="text-align:center;background:#fff;border-radius:24px;padding:36px 24px;border:2px solid #EDE9FE"><div style="font-size:44px">🏛️</div><h3 style="font-family:Fredoka,sans-serif;color:#3B0764">Management Committee</h3><p>Open the Committee Panel to see school figures, approvals and the activity log.</p><a href="' . esc_url( admin_url( 'admin.php?page=tms-committee' ) ) . '" style="display:inline-block;background:#7C3AED;color:#fff;padding:12px 28px;border-radius:999px;font-weight:800;text-decoration:none">Open Committee Panel</a></div>';
}, 21, 2 );

/* ---- Committee pages ---- */
function tms_money( $n ) {
	return number_format( (float) $n, 0, '.', ',' ) . ' ' . ewc_currency_symbol();
}
function tms_cm_guard() {
	if ( ! tms_can_oversee() ) {
		wp_die( 'You do not have access to this page.' );
	}
}
function tms_page_committee() {
	tms_cm_guard();
	global $wpdb;
	$p    = $wpdb->prefix . 'wlsm_';
	$sess = class_exists( 'WLSM_Config' ) ? WLSM_Config::current_session() : array();
	$sid  = isset( $sess['ID'] ) ? (int) $sess['ID'] : (int) get_option( 'wlsm_current_session' );
	$from = isset( $sess['start_date'] ) ? $sess['start_date'] : gmdate( 'Y-01-01' );
	$to   = isset( $sess['end_date'] ) ? $sess['end_date'] : gmdate( 'Y-12-31' );
	$today = current_time( 'Y-m-d' );
	$students = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}student_records WHERE session_id = %d AND is_active = 1", $sid ) );
	$girls    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}student_records WHERE session_id = %d AND is_active = 1 AND gender = 'female'", $sid ) );
	$classes  = $wpdb->get_results( $wpdb->prepare( "SELECT c.label, COUNT(sr.ID) n FROM {$p}student_records sr JOIN {$p}sections se ON se.ID = sr.section_id JOIN {$p}class_school cs ON cs.ID = se.class_school_id JOIN {$p}classes c ON c.ID = cs.class_id WHERE sr.session_id = %d AND sr.is_active = 1 GROUP BY c.ID ORDER BY c.ID", $sid ) );
	$staff    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}admins WHERE is_active = 1" );
	$billed   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(i.amount - IFNULL(i.discount,0)) FROM {$p}invoices i JOIN {$p}student_records sr ON sr.ID = i.student_record_id WHERE sr.session_id = %d", $sid ) );
	$collected= (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(py.amount) FROM {$p}payments py JOIN {$p}student_records sr ON sr.ID = py.student_record_id WHERE sr.session_id = %d", $sid ) );
	$other_in = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}income WHERE income_date BETWEEN %s AND %s", $from, $to ) );
	$spent    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}expenses WHERE expense_date BETWEEN %s AND %s", $from, $to ) );
	$due      = max( 0, $billed - $collected );
	$att      = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(a.status = 'p') p, SUM(a.status IN ('p','a')) t FROM {$p}attendance a JOIN {$p}student_records sr ON sr.ID = a.student_record_id WHERE sr.session_id = %d", $sid ) );
	$att_rate = $att && $att->t ? round( 100 * $att->p / $att->t, 1 ) : null;
	$att_today= $wpdb->get_row( $wpdb->prepare( "SELECT SUM(a.status = 'p') p, COUNT(*) t FROM {$p}attendance a WHERE a.attendance_date = %s AND ( a.subject_id IS NULL OR a.subject_id = 0 )", $today ) );
	$months = array();
	for ( $i = 5; $i >= 0; $i-- ) {
		$m = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', strtotime( $today ) ) . " -$i months" ) );
		$months[ $m ] = array( 'in' => 0, 'out' => 0 );
	}
	$keys = array_keys( $months );
	$min  = $keys[0] . '-01';
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at, '%%Y-%%m') m, SUM(amount) s FROM {$p}payments WHERE created_at >= %s GROUP BY m", $min ) ) as $r ) { if ( isset( $months[ $r->m ] ) ) { $months[ $r->m ]['in'] += $r->s; } }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(income_date, '%%Y-%%m') m, SUM(amount) s FROM {$p}income WHERE income_date >= %s GROUP BY m", $min ) ) as $r ) { if ( isset( $months[ $r->m ] ) ) { $months[ $r->m ]['in'] += $r->s; } }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(expense_date, '%%Y-%%m') m, SUM(amount) s FROM {$p}expenses WHERE expense_date >= %s GROUP BY m", $min ) ) as $r ) { if ( isset( $months[ $r->m ] ) ) { $months[ $r->m ]['out'] += $r->s; } }
	$cats = $wpdb->get_results( $wpdb->prepare( "SELECT IFNULL(ec.label,'Other') label, SUM(e.amount) s FROM {$p}expenses e LEFT JOIN {$p}expense_categories ec ON ec.ID = e.expense_category_id WHERE e.expense_date BETWEEN %s AND %s GROUP BY e.expense_category_id ORDER BY s DESC LIMIT 5", $from, $to ) );
	$pending = count( tms_approval_items( 'pending' ) );
	$recent  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tms_activity ORDER BY id DESC LIMIT 8" );
	$u = wp_get_current_user();
	echo '<div class="tdb cm"><section class="tdb-hero cm-hero"><div><p class="tdb-eyebrow">Management Committee · ' . esc_html( isset( $sess['label'] ) ? $sess['label'] : '' ) . '</p><h1>School at a glance</h1><p class="tdb-sub">Welcome, ' . esc_html( tms_user_display( $u ) ) . ' · ' . esc_html( date_i18n( 'l, j F Y' ) ) . '</p></div><div class="tdb-actions"><a class="tdb-btn tdb-btn-gold" href="' . esc_url( admin_url( 'admin.php?page=tms-approvals' ) ) . '">🗳️ Approvals' . ( $pending ? ' <b class="cm-pill">' . $pending . '</b>' : '' ) . '</a><a class="tdb-btn" href="' . esc_url( admin_url( 'admin.php?page=tms-activity' ) ) . '">🛡️ Activity Log</a></div></section>';
	$kpis = array(
		array( '💰', 'Fees collected', tms_money( $collected ), $billed ? round( 100 * $collected / $billed ) . '% of ' . tms_money( $billed ) : 'No invoices yet', 'green' ),
		array( '⏳', 'Fees outstanding', tms_money( $due ), 'Still to collect this session', $due ? 'red' : 'slate' ),
		array( '📉', 'Expenses', tms_money( $spent ), 'This session', 'gold' ),
		array( '📈', 'Net position', tms_money( $collected + $other_in - $spent ), 'Collected + other income − expenses', ( $collected + $other_in - $spent ) >= 0 ? 'teal' : 'red' ),
		array( '👧', 'Pupils', $students, ( $students - $girls ) . ' boys · ' . $girls . ' girls', 'navy' ),
		array( '📊', 'Attendance', null === $att_rate ? '–' : $att_rate . '%', $att_today && $att_today->t ? 'Today ' . round( 100 * $att_today->p / $att_today->t ) . '% present' : 'Not taken today', 'purple' ),
	);
	echo '<section class="tdb-kpis">';
	foreach ( $kpis as $k ) { echo '<div class="tdb-kpi tdb-' . $k[4] . '"><span class="tdb-kpi-ic">' . $k[0] . '</span><span class="tdb-kpi-l">' . esc_html( $k[1] ) . '</span><b class="tdb-kpi-v cm-v">' . esc_html( $k[2] ) . '</b><span class="tdb-kpi-s">' . esc_html( $k[3] ) . '</span></div>'; }
	echo '</section><section class="tdb-grid">';
	$mx = 1;
	foreach ( $months as $m ) { $mx = max( $mx, $m['in'], $m['out'] ); }
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Income vs expenses</h3><span>🟩 income · 🟥 expenses · last 6 months</span></div><div class="cm-bars">';
	foreach ( $months as $k => $m ) {
		echo '<div class="cm-col"><div class="cm-pair"><i class="in" style="height:' . round( 100 * $m['in'] / $mx ) . '%" title="Income ' . esc_attr( tms_money( $m['in'] ) ) . '"></i><i class="out" style="height:' . round( 100 * $m['out'] / $mx ) . '%" title="Expenses ' . esc_attr( tms_money( $m['out'] ) ) . '"></i></div><b>' . esc_html( date_i18n( 'M', strtotime( $k . '-01' ) ) ) . '</b><small>' . esc_html( number_format( ( $m['in'] - $m['out'] ) / 1000 ) ) . 'k</small></div>';
	}
	echo '</div></div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Fee collection</h3><span>This session</span></div>' . tms_tdb_donut( array( array( 'l' => 'Collected', 'v' => round( $collected ), 'c' => '#10B981' ), array( 'l' => 'Outstanding', 'v' => round( $due ), 'c' => '#E44546' ) ), 'collected', $billed ? round( 100 * $collected / $billed ) . '%' : '–' ) . '</div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Pupils</h3><span>' . $students . ' enrolled</span></div>' . tms_tdb_donut( array( array( 'l' => 'Boys', 'v' => $students - $girls, 'c' => '#38BDF8' ), array( 'l' => 'Girls', 'v' => $girls, 'c' => '#F472B6' ) ), 'pupils', $students ) . '</div>';
	$cmx = 1;
	foreach ( $classes as $c ) { $cmx = max( $cmx, $c->n ); }
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Pupils per class</h3><span>' . $staff . ' active staff</span></div><ul class="cm-h">';
	foreach ( $classes as $c ) { echo '<li><span>' . esc_html( $c->label ) . '</span><i style="width:' . round( 100 * $c->n / $cmx ) . '%"></i><b>' . (int) $c->n . '</b></li>'; }
	echo '</ul></div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Top expenses</h3><span>By category</span></div><ul class="tdb-mini">';
	foreach ( $cats as $c ) { echo '<li><b>' . esc_html( $c->label ) . '</b><span>' . esc_html( tms_money( $c->s ) ) . '</span></li>'; }
	if ( ! $cats ) { echo '<li class="tdb-muted">No expenses recorded.</li>'; }
	echo '</ul></div>';
	echo '<div class="tdb-card"><div class="tdb-card-h"><h3>Latest activity</h3><a href="' . esc_url( admin_url( 'admin.php?page=tms-activity' ) ) . '">Full log →</a></div><ul class="tdb-mini">';
	foreach ( $recent as $r ) { echo '<li' . ( $r->is_money ? ' class="cm-money"' : '' ) . '><b>' . esc_html( $r->user_name ) . ' · ' . esc_html( $r->label ) . '</b><span>' . esc_html( human_time_diff( strtotime( $r->created_at ), current_time( 'timestamp' ) ) ) . ' ago · ' . esc_html( $r->user_role ) . '</span></li>'; }
	if ( ! $recent ) { echo '<li class="tdb-muted">No activity yet.</li>'; }
	echo '</ul></div></section></div>';
}

/* ---- Approvals ---- */
function tms_approval_items( $want = 'pending' ) {
	global $wpdb;
	$p     = $wpdb->prefix . 'wlsm_';
	$since = gmdate( 'Y-m-d', strtotime( '-120 days' ) );
	$items = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT e.ID, e.label, e.amount, e.expense_date d, e.added_by FROM {$p}expenses e WHERE e.expense_date >= %s", $since ) ) as $r ) { $items[] = array( 'expense', $r->ID, '📉 Expense: ' . $r->label, tms_money( $r->amount ), $r->d, $r->added_by ); }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.ID, i.label, i.amount, i.income_date d, i.added_by FROM {$p}income i WHERE i.income_date >= %s", $since ) ) as $r ) { $items[] = array( 'income', $r->ID, '📈 Income: ' . $r->label, tms_money( $r->amount ), $r->d, $r->added_by ); }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT py.ID, py.receipt_number, py.amount, py.payment_method, py.created_at d, py.added_by, sr.name FROM {$p}payments py LEFT JOIN {$p}student_records sr ON sr.ID = py.student_record_id WHERE py.created_at >= %s", $since ) ) as $r ) { $items[] = array( 'payment', $r->ID, '💳 Payment #' . $r->receipt_number . ' · ' . $r->name . ' (' . $r->payment_method . ')', tms_money( $r->amount ), substr( $r->d, 0, 10 ), $r->added_by ); }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.ID, i.label, i.discount, i.date_issued d, i.added_by, sr.name FROM {$p}invoices i LEFT JOIN {$p}student_records sr ON sr.ID = i.student_record_id WHERE i.discount > 0 AND i.created_at >= %s", $since ) ) as $r ) { $items[] = array( 'discount', $r->ID, '🏷️ Discount: ' . $r->label . ' · ' . $r->name, tms_money( $r->discount ), $r->d, $r->added_by ); }
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.admission_date d, sr.added_by FROM {$p}student_records sr WHERE sr.created_at >= %s", $since ) ) as $r ) { $items[] = array( 'admission', $r->ID, '🎒 New admission: ' . $r->name, '', $r->d, $r->added_by ); }
	$dec = array();
	foreach ( (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tms_approvals" ) as $a ) { $dec[ $a->item_type . ':' . $a->item_id ] = $a; }
	$out = array();
	foreach ( $items as $it ) {
		$d = isset( $dec[ $it[0] . ':' . $it[1] ] ) ? $dec[ $it[0] . ':' . $it[1] ] : null;
		$st = $d ? $d->status : 'pending';
		if ( $want === $st || 'all' === $want ) { $it[] = $d; $out[] = $it; }
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b[4], $a[4] ); } );
	return $out;
}
add_action( 'wp_ajax_tms_approval', function () {
	check_ajax_referer( 'tms_approval', 'nonce' );
	if ( ! tms_can_oversee() ) { wp_send_json_error( 'Not allowed', 403 ); }
	global $wpdb;
	$type   = sanitize_key( wp_unslash( $_POST['type'] ) );
	$id     = absint( $_POST['id'] );
	$status = in_array( $_POST['status'], array( 'approved', 'rejected', 'pending' ), true ) ? $_POST['status'] : '';
	$note   = sanitize_textarea_field( wp_unslash( isset( $_POST['note'] ) ? $_POST['note'] : '' ) );
	if ( ! $type || ! $id || ! $status ) { wp_send_json_error( 'Bad request' ); }
	$u = wp_get_current_user();
	$wpdb->replace( $wpdb->prefix . 'tms_approvals', array( 'item_type' => $type, 'item_id' => $id, 'status' => $status, 'note' => $note, 'user_id' => $u->ID, 'user_name' => tms_user_display( $u ), 'created_at' => current_time( 'mysql' ) ) );
	tms_log_activity( 'tms_approval_' . $status, ucfirst( $status ) . ' ' . $type . ' #' . $id, array( 'note' => $note ) );
	wp_send_json_success();
} );
function tms_page_approvals() {
	tms_cm_guard();
	$tab   = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'pending', 'approved', 'rejected' ), true ) ? $_GET['tab'] : 'pending';
	$items = tms_approval_items( $tab );
	$base  = admin_url( 'admin.php?page=tms-approvals' );
	echo '<div class="tdb cm"><section class="tdb-hero cm-hero"><div><p class="tdb-eyebrow">Management Committee</p><h1>Approvals</h1><p class="tdb-sub">Review money movements, discounts and new admissions from the last 120 days.</p></div><div class="tdb-actions">';
	foreach ( array( 'pending' => '⏳ Pending', 'approved' => '✅ Approved', 'rejected' => '⛔ Rejected' ) as $k => $l ) { echo '<a class="tdb-btn' . ( $k === $tab ? ' tdb-btn-gold' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $k, $base ) ) . '">' . $l . ( 'pending' === $k ? ' (' . count( tms_approval_items( 'pending' ) ) . ')' : '' ) . '</a>'; }
	echo '</div></section><div class="tdb-card cm-list" data-nonce="' . esc_attr( wp_create_nonce( 'tms_approval' ) ) . '">';
	if ( ! $items ) { echo '<p class="tdb-good">🎉 Nothing here.</p>'; }
	foreach ( $items as $it ) {
		$by = $it[5] ? get_userdata( $it[5] ) : null;
		$dec = $it[6];
		echo '<div class="cm-item" data-type="' . esc_attr( $it[0] ) . '" data-id="' . (int) $it[1] . '"><div class="cm-item-main"><b>' . esc_html( $it[2] ) . '</b><span>' . esc_html( mysql2date( 'j M Y', $it[4] ) ) . ( $by ? ' · added by ' . esc_html( tms_user_display( $by ) ) : '' ) . ( $dec ? ' · ' . esc_html( ucfirst( $dec->status ) . ' by ' . $dec->user_name . ' ' . mysql2date( 'j M H:i', $dec->created_at ) ) . ( $dec->note ? ' — “' . esc_html( $dec->note ) . '”' : '' ) : '' ) . '</span></div><strong class="cm-amt">' . esc_html( $it[3] ) . '</strong><div class="cm-item-act">';
		if ( 'approved' !== $tab ) { echo '<button class="cm-ok" data-s="approved">✅ Approve</button>'; }
		if ( 'rejected' !== $tab ) { echo '<button class="cm-no" data-s="rejected">⛔ Reject</button>'; }
		if ( 'pending' !== $tab ) { echo '<button class="cm-undo" data-s="pending">↩ Undo</button>'; }
		echo '</div></div>';
	}
	echo '</div></div><script>(function(){var l=document.querySelector(".cm-list");if(!l)return;l.addEventListener("click",function(e){var b=e.target.closest("button[data-s]");if(!b)return;var it=b.closest(".cm-item"),s=b.dataset.s,note="";if(s==="rejected"){note=prompt("Reason for rejecting (optional):")||"";}else if(s==="approved"){note=prompt("Note (optional):")||"";}var f=new FormData();f.append("action","tms_approval");f.append("nonce",l.dataset.nonce);f.append("type",it.dataset.type);f.append("id",it.dataset.id);f.append("status",s);f.append("note",note);b.disabled=true;fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(r){if(r.success){it.style.transition="all .3s";it.style.opacity="0";it.style.transform="translateX(30px)";setTimeout(function(){it.remove();},300);}else{alert("Could not save");b.disabled=false;}});});})();</script>';
}

/* ---- Activity log page + CSV ---- */
function tms_activity_query( $args, $limit = 50, $offset = 0 ) {
	global $wpdb;
	$t = $wpdb->prefix . 'tms_activity';
	$w = array( '1=1' );
	if ( ! empty( $args['user'] ) ) { $w[] = $wpdb->prepare( 'user_id = %d', $args['user'] ); }
	if ( ! empty( $args['money'] ) ) { $w[] = 'is_money = 1'; }
	if ( ! empty( $args['from'] ) ) { $w[] = $wpdb->prepare( 'created_at >= %s', $args['from'] . ' 00:00:00' ); }
	if ( ! empty( $args['to'] ) ) { $w[] = $wpdb->prepare( 'created_at <= %s', $args['to'] . ' 23:59:59' ); }
	if ( ! empty( $args['q'] ) ) { $like = '%' . $wpdb->esc_like( $args['q'] ) . '%'; $w[] = $wpdb->prepare( '(label LIKE %s OR user_name LIKE %s OR details LIKE %s)', $like, $like, $like ); }
	$where = implode( ' AND ', $w );
	return array( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ), $wpdb->get_results( "SELECT * FROM $t WHERE $where ORDER BY id DESC" . ( $limit ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset ) : '' ) ) );
}
function tms_activity_args() {
	return array(
		'user'  => isset( $_GET['u'] ) ? absint( $_GET['u'] ) : 0,
		'money' => ! empty( $_GET['money'] ),
		'from'  => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
		'to'    => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
		'q'     => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
	);
}
add_action( 'admin_post_tms_activity_csv', function () {
	if ( ! tms_can_oversee() ) { wp_die( 'No access' ); }
	check_admin_referer( 'tms_activity_csv' );
	list( , $rows ) = tms_activity_query( tms_activity_args(), 0 );
	tms_log_activity( 'tms_export_log', 'Exported activity log (CSV)' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=school-activity-' . current_time( 'Y-m-d' ) . '.csv' );
	$f = fopen( 'php://output', 'w' );
	fputcsv( $f, array( 'Date', 'Who', 'Role', 'What', 'Details', 'Money', 'IP' ) );
	foreach ( $rows as $r ) {
		$det = $r->details ? implode( '; ', array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( (array) json_decode( $r->details, true ) ), (array) json_decode( $r->details, true ) ) ) : '';
		fputcsv( $f, array( $r->created_at, $r->user_name, $r->user_role, $r->label, $det, $r->is_money ? 'yes' : '', $r->ip ) );
	}
	exit;
} );
function tms_page_activity() {
	tms_cm_guard();
	global $wpdb;
	$args  = tms_activity_args();
	$pg    = max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 );
	list( $total, $rows ) = tms_activity_query( $args, 50, ( $pg - 1 ) * 50 );
	$users = $wpdb->get_results( "SELECT DISTINCT user_id, user_name FROM {$wpdb->prefix}tms_activity WHERE user_id > 0 ORDER BY user_name" );
	$csv   = wp_nonce_url( add_query_arg( array_filter( $args ) + array( 'action' => 'tms_activity_csv' ), admin_url( 'admin-post.php' ) ), 'tms_activity_csv' );
	echo '<div class="tdb cm"><section class="tdb-hero cm-hero"><div><p class="tdb-eyebrow">Security</p><h1>🛡️ Activity Log</h1><p class="tdb-sub">Every save, delete, payment and sign-in, with who did it and when. Entries cannot be edited or deleted.</p></div><div class="tdb-actions"><a class="tdb-btn tdb-btn-gold" href="' . esc_url( $csv ) . '">⬇️ Export CSV</a></div></section>';
	echo '<form class="tdb-card cm-filter" method="get"><input type="hidden" name="page" value="tms-activity"><select name="u"><option value="">All people</option>';
	foreach ( $users as $us ) { echo '<option value="' . (int) $us->user_id . '"' . selected( $args['user'], (int) $us->user_id, false ) . '>' . esc_html( $us->user_name ) . '</option>'; }
	echo '</select><input type="date" name="from" value="' . esc_attr( $args['from'] ) . '"><input type="date" name="to" value="' . esc_attr( $args['to'] ) . '"><input type="search" name="q" placeholder="Search…" value="' . esc_attr( $args['q'] ) . '"><label><input type="checkbox" name="money" value="1"' . checked( $args['money'], true, false ) . '> 💰 Money only</label><button class="tdb-save">Filter</button></form>';
	echo '<div class="tdb-card cm-log"><table class="s360-table"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>IP</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$det = $r->details ? (array) json_decode( $r->details, true ) : array();
		echo '<tr' . ( $r->is_money ? ' class="cm-money-row"' : '' ) . '><td><b>' . esc_html( mysql2date( 'j M Y', $r->created_at ) ) . '</b><small>' . esc_html( mysql2date( 'H:i', $r->created_at ) ) . '</small></td><td><b>' . esc_html( $r->user_name ) . '</b><small>' . esc_html( $r->user_role ) . '</small></td><td>' . ( $r->is_money ? '💰 ' : '' ) . esc_html( $r->label ) . '</td><td class="cm-det">';
		foreach ( $det as $k => $val ) { echo '<span>' . esc_html( str_replace( '_', ' ', $k ) ) . ': <b>' . esc_html( $val ) . '</b></span>'; }
		echo '</td><td><small>' . esc_html( $r->ip ) . '</small></td></tr>';
	}
	if ( ! $rows ) { echo '<tr><td colspan="5" class="tdb-muted">No activity matches.</td></tr>'; }
	echo '</tbody></table>';
	$pages = (int) ceil( $total / 50 );
	if ( $pages > 1 ) {
		echo '<div class="cm-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) { echo '<a class="' . ( $i === $pg ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'pg', $i ) ) . '">' . $i . '</a>'; }
		echo '</div>';
	}
	echo '</div></div>';
}

/* ---- Live activity sidebar (admin, committee, teachers) ---- */
add_action( 'wp_ajax_tms_activity_feed', function () {
	check_ajax_referer( 'tms_feed', 'nonce' );
	$oversee = tms_can_oversee();
	if ( ! $oversee && ! tms_is_staff_user() ) { wp_send_json_error( '', 403 ); }
	global $wpdb;
	$where = $oversee ? '1=1' : 'is_money = 0';
	$rows  = $wpdb->get_results( "SELECT id, user_name, user_role, label, is_money, created_at FROM {$wpdb->prefix}tms_activity WHERE $where AND action <> 'login' ORDER BY id DESC LIMIT 10" );
	foreach ( $rows as $r ) { $r->ago = human_time_diff( strtotime( $r->created_at ), current_time( 'timestamp' ) ); }
	wp_send_json_success( $rows );
} );
add_action( 'admin_footer', function () {
	if ( ! tms_can_oversee() && ! tms_is_staff_user() ) {
		return;
	}
	?>
	<div id="tms-live" class="tms-live-closed" data-nonce="<?php echo esc_attr( wp_create_nonce( 'tms_feed' ) ); ?>"><button type="button" id="tms-live-tab">🛡️ Live <b id="tms-live-n"></b></button><div id="tms-live-panel"><div class="tms-live-h">Live activity <span>auto-refresh 30s</span></div><ul id="tms-live-list"><li class="tdb-muted">Loading…</li></ul><?php if ( tms_can_oversee() ) : ?><a class="tms-live-all" href="<?php echo esc_url( admin_url( 'admin.php?page=tms-activity' ) ); ?>">Open full log →</a><?php endif; ?></div></div>
	<style>
	#tms-live{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:9998;display:flex;align-items:center;font-family:Nunito,sans-serif}
	#tms-live-tab{writing-mode:vertical-rl;transform:rotate(180deg);background:#043353;color:#F5B82E;border:0;border-radius:0 14px 14px 0;padding:14px 8px;font-weight:900;cursor:pointer;box-shadow:-4px 6px 18px rgba(4,51,83,.25);font-size:13px}
	#tms-live-n{display:inline-block;background:#E44546;color:#fff;border-radius:999px;padding:2px 6px;margin-top:6px;font-size:11px}
	#tms-live-n:empty{display:none}
	#tms-live-panel{width:300px;max-height:70vh;overflow:auto;background:#fff;border-radius:18px 0 0 18px;box-shadow:-10px 10px 30px rgba(4,51,83,.2);padding:14px;border:1px solid #E7ECF5}
	.tms-live-closed #tms-live-panel{display:none}
	.tms-live-h{font-family:Fredoka,sans-serif;font-weight:700;color:#043353;font-size:16px;display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}.tms-live-h span{font-size:11px;color:#94A3B8;font-family:Nunito}
	#tms-live-list{list-style:none;margin:0;padding:0}
	#tms-live-list li{padding:9px 10px;border-radius:12px;margin-bottom:6px;background:#F8FAFC;border-left:4px solid #38BDF8;font-size:13px}
	#tms-live-list li.m{border-left-color:#E44546}
	#tms-live-list li b{color:#043353}#tms-live-list li small{display:block;color:#94A3B8;font-size:11px}
	#tms-live-list li.new{animation:tmsBlink 1s ease-in-out 6;background:#FFF8E6}
	#tms-live-list li.new b:before{content:"🔴 "}
	@keyframes tmsBlink{0%,100%{box-shadow:0 0 0 0 rgba(228,69,70,0)}50%{box-shadow:0 0 0 4px rgba(228,69,70,.35);background:#FEF2F2}}
	#tms-live-tab.ping{animation:tmsBlink 1s ease-in-out 6}
	.tms-live-all{display:block;text-align:center;font-weight:800;color:#E44546;margin-top:6px;text-decoration:none}
	html.tms-dark #tms-live-panel{background:#111A2E;border-color:#1F2A44}html.tms-dark #tms-live-list li{background:#0F1729}html.tms-dark #tms-live-list li b{color:#F8FAFC}
	</style>
	<script>
	(function () {
		var box = document.getElementById('tms-live'), list = document.getElementById('tms-live-list'), n = document.getElementById('tms-live-n'), tab = document.getElementById('tms-live-tab');
		var seen = 0; try { seen = parseInt(localStorage.getItem('tmsLiveSeen') || '0', 10); } catch (e) {}
		tab.addEventListener('click', function () { box.classList.toggle('tms-live-closed'); n.textContent = ''; tab.classList.remove('ping'); });
		function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
		function load() {
			var f = new FormData(); f.append('action', 'tms_activity_feed'); f.append('nonce', box.dataset.nonce);
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: f }).then(function (r) { return r.json(); }).then(function (res) {
				if (!res || !res.success) { return; }
				var fresh = 0, top = 0;
				list.innerHTML = res.data.map(function (r) { var id = parseInt(r.id, 10); top = Math.max(top, id); var isNew = id > seen; if (isNew) { fresh++; } return '<li class="' + (r.is_money === '1' ? 'm ' : '') + (isNew ? 'new' : '') + '"><b>' + esc(r.user_name) + '</b> · ' + esc(r.label) + '<small>' + esc(r.ago) + ' ago · ' + esc(r.user_role) + '</small></li>'; }).join('') || '<li class="tdb-muted">No activity yet.</li>';
				if (fresh && seen) { n.textContent = fresh; tab.classList.add('ping'); }
				if (top) { seen = top; try { localStorage.setItem('tmsLiveSeen', String(top)); } catch (e) {} }
			}).catch(function () {});
		}
		load(); setInterval(load, 30000);
	})();
	</script>
	<?php
} );

add_action( 'admin_head', function () {
	if ( empty( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'tms-committee', 'tms-approvals', 'tms-activity' ), true ) ) {
		return;
	}
	?>
	<style>
	.cm-hero{background:linear-gradient(120deg,#3B0764 0%,#6D28D9 55%,#7C3AED 100%)!important}
	.cm-v{font-size:22px!important}
	.cm-pill{background:#E44546;color:#fff;border-radius:999px;padding:1px 8px;margin-left:4px}
	.cm-bars{display:flex;gap:18px;align-items:flex-end;height:220px;padding-top:10px}
	.cm-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
	.cm-pair{height:170px;width:100%;display:flex;gap:6px;align-items:flex-end;justify-content:center}
	.cm-pair i{width:22px;border-radius:8px 8px 3px 3px;min-height:3px;transition:height .6s}
	.cm-pair .in{background:linear-gradient(#34D399,#10B981)}.cm-pair .out{background:linear-gradient(#F87171,#E44546)}
	.cm-col b{font-size:12px;color:#043353}.cm-col small{font-size:11px;color:#94A3B8;font-weight:700}
	.cm-h{list-style:none;margin:0;padding:0}
	.cm-h li{display:grid;grid-template-columns:150px 1fr 40px;gap:10px;align-items:center;margin:7px 0;font-weight:700;font-size:13px;color:#334155}
	.cm-h i{height:12px;border-radius:99px;background:linear-gradient(90deg,#7C3AED,#38BDF8);display:block}
	.cm-h b{text-align:right;color:#043353}
	.cm-money{border-left-color:#E44546!important}
	.cm-item{display:flex;align-items:center;gap:14px;padding:14px;border-radius:16px;border:1px solid #EEF2F7;margin-bottom:10px;flex-wrap:wrap}
	.cm-item-main{flex:1;min-width:240px}.cm-item-main b{display:block;color:#043353}.cm-item-main span{font-size:12px;color:#64748B}
	.cm-amt{font-family:Fredoka,sans-serif;font-size:18px;color:#043353}
	.cm-item-act{display:flex;gap:8px}
	.cm-item-act button{border:0;border-radius:999px;padding:9px 16px;font-weight:800;cursor:pointer}
	.cm-ok{background:#10B981;color:#fff}.cm-no{background:#E44546;color:#fff}.cm-undo{background:#E2E8F0;color:#043353}
	.cm-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
	.cm-filter select,.cm-filter input[type=date],.cm-filter input[type=search]{border-radius:12px!important;border:2px solid #E2E8F0!important;padding:6px 12px!important}
	.cm-filter label{font-weight:800;color:#043353}
	.cm-log td small{display:block;color:#94A3B8}
	.cm-det span{display:inline-block;background:#F1F5F9;border-radius:8px;padding:2px 8px;margin:2px;font-size:12px}
	.cm-money-row td{background:#FFF7F7}
	.cm-pages{display:flex;gap:6px;justify-content:center;margin-top:14px}.cm-pages a{padding:6px 12px;border-radius:10px;background:#F1F5F9;text-decoration:none;font-weight:800;color:#043353}.cm-pages a.on{background:#7C3AED;color:#fff}
	</style>
	<?php
}, 102 );

/* ==== eWebCity Education: white-label admin ==== */
add_filter( 'the_generator', '__return_empty_string' );
remove_action( 'wp_head', 'wp_generator' );
add_action( 'admin_bar_menu', function ( $bar ) {
	foreach ( array( 'wp-logo', 'comments', 'updates', 'about', 'wporg', 'documentation', 'support-forums', 'feedback' ) as $n ) { $bar->remove_node( $n ); }
}, 999 );
add_filter( 'admin_footer_text', function ( $t ) {
	return ( function_exists( 'tms_is_staff_user' ) && tms_is_staff_user() ) ? $t : 'eWebCity Education · ' . esc_html( get_bloginfo( 'name' ) );
}, 20 );
add_filter( 'update_footer', '__return_empty_string', 100 );
$tms_title = function ( $t ) { return str_replace( array( ' &#8212; WordPress', ' — WordPress', '&lsaquo; WordPress', 'WordPress' ), array( ' · eWebCity Education', ' · eWebCity Education', '&lsaquo; eWebCity Education', 'eWebCity Education' ), $t ); };
add_filter( 'admin_title', $tms_title, 99 );
add_filter( 'login_title', $tms_title, 99 );
add_filter( 'gettext', function ( $tr, $text ) {
	if ( is_admin() && ( 'Howdy, %s' === $text ) ) { return 'Hi, %s'; }
	return $tr;
}, 10, 2 );
add_action( 'admin_head', function () {
	$screen = get_current_screen();
	if ( $screen ) { $screen->remove_help_tabs(); }
} );
add_action( 'admin_menu', function () {
	global $menu;
	$map = array( 'School Management' => 'eWebCity Setup' );
	foreach ( (array) $menu as $k => $item ) {
		if ( empty( $item[0] ) ) { continue; }
		$label = $item[0];
		foreach ( $map as $from => $to ) { $label = str_replace( $from, $to, $label ); }
		$label = preg_replace( '/^SM /', '', $label );
		if ( 'Administrator' === wp_strip_all_tags( $label ) ) { $label = 'Staff & Admin'; }
		if ( 'Student' === wp_strip_all_tags( $label ) ) { $label = 'Students'; }
		if ( 'Accounting' === wp_strip_all_tags( $label ) ) { $label = 'Accounts'; }
		$menu[ $k ][0] = $label;
	}
}, 10002 );
add_filter( 'all_plugins', function ( $plugins ) {
	$self = plugin_basename( __FILE__ );
	if ( isset( $plugins[ $self ] ) ) { $plugins[ $self ]['Name'] = 'eWebCity Education'; $plugins[ $self ]['Author'] = 'Mahim'; }
	$core = 'school-management-pro/school-management.php';
	if ( isset( $plugins[ $core ] ) && get_option( 'tms_engine_bundled' ) ) {
		unset( $plugins[ $core ] ); // bundled inside eWebCity Education: never activate or delete it separately.
	}
	if ( isset( $plugins[ $self ] ) ) {
		$plugins[ $self ]['Description'] = 'Complete school system: classes, students, admissions, attendance, fees, exams, library, plus student/parent/teacher/committee portals, Teacher Insights, Student 360°, Gradebook, approvals, activity log and live alerts. eWebCity Education © Mahim. Includes the School Engine (GPL).';
		$plugins[ $self ]['PluginURI'] = 'https://www.facebook.com/eWebcity';
	}
	return $plugins;
} );
add_action( 'wp_dashboard_setup', function () {
	global $wp_meta_boxes;
	remove_action( 'welcome_panel', 'wp_welcome_panel' );
	$wp_meta_boxes['dashboard'] = array();
	wp_add_dashboard_widget( 'ewc_overview', 'eWebCity Education', 'tms_ewc_widget' );
}, 9999 );
function tms_ewc_widget() {
	global $wpdb;
	$p    = $wpdb->prefix . 'wlsm_';
	$sess = class_exists( 'WLSM_Config' ) ? WLSM_Config::current_session() : array();
	$sid  = isset( $sess['ID'] ) ? (int) $sess['ID'] : (int) get_option( 'wlsm_current_session' );
	$students = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}student_records WHERE session_id = %d AND is_active = 1", $sid ) );
	$staff    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}admins WHERE is_active = 1" );
	$pending  = function_exists( 'tms_approval_items' ) ? count( tms_approval_items( 'pending' ) ) : 0;
	$today    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tms_activity WHERE created_at >= %s", current_time( 'Y-m-d' ) . ' 00:00:00' ) );
	$a = function ( $page ) { return esc_url( admin_url( 'admin.php?page=' . $page ) ); };
	$tiles = array(
		array( '📊', 'School at a glance', $a( 'tms-committee' ), '#7C3AED' ),
		array( '🎒', 'Students', $a( 'sm-staff-students' ), '#0EA5E9' ),
		array( '📝', 'Admissions', $a( 'sm-staff-admissions' ), '#10B981' ),
		array( '✅', 'Attendance', $a( 'sm-staff-attendance' ), '#22C55E' ),
		array( '🧾', 'Fees & invoices', $a( 'sm-staff-invoices' ), '#E44546' ),
		array( '🏆', 'Exams & results', $a( 'sm-staff-exams' ), '#E09B0B' ),
		array( '👥', 'Staff & teachers', $a( 'sm-staff-employees' ), '#043353' ),
		array( '🗳️', 'Approvals', $a( 'tms-approvals' ), '#9333EA' ),
		array( '🛡️', 'Activity log', $a( 'tms-activity' ), '#475569' ),
		array( '🌐', 'Website pages', esc_url( admin_url( 'edit.php?post_type=page' ) ), '#F472B6' ),
	);
	echo '<div class="ewc"><div class="ewc-hero"><div><small>eWebCity Education · ' . esc_html( isset( $sess['label'] ) ? $sess['label'] : '' ) . '</small><h2>' . esc_html( get_bloginfo( 'name' ) ) . '</h2><p>' . esc_html( date_i18n( 'l, j F Y' ) ) . '</p></div><div class="ewc-stats"><span><b>' . $students . '</b>pupils</span><span><b>' . $staff . '</b>staff</span><span><b>' . $pending . '</b>to approve</span><span><b>' . $today . '</b>actions today</span></div></div><div class="ewc-tiles">';
	foreach ( $tiles as $t ) { echo '<a href="' . $t[2] . '" style="--c:' . esc_attr( $t[3] ) . '"><i>' . $t[0] . '</i>' . esc_html( $t[1] ) . '</a>'; }
	echo '</div></div>';
}
add_action( 'admin_head', function () {
	if ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) { return; }
	$logo = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'thumbnail' );
	?>
	<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
	<style id="ewc-admin">
	body.wp-admin:not(.tms-tp){font-family:Nunito,sans-serif;background:#F4F7FB}
	body:not(.tms-tp) #wpadminbar{background:#043353}
	body:not(.tms-tp) #adminmenuback,body:not(.tms-tp) #adminmenuwrap,body:not(.tms-tp) #adminmenu{background:#043353}
	body:not(.tms-tp) #adminmenu a{color:#E2E8F0;font-weight:700}
	body:not(.tms-tp) #adminmenu div.wp-menu-image:before{color:#93C5FD}
	body:not(.tms-tp) #adminmenu li.menu-top:hover,body:not(.tms-tp) #adminmenu li>a.menu-top:focus{background:#0B4A73}
	body:not(.tms-tp) #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,body:not(.tms-tp) #adminmenu li.current a.menu-top{background:#F5B82E!important;color:#043353!important}
	body:not(.tms-tp) #adminmenu li.wp-has-current-submenu div.wp-menu-image:before{color:#043353!important}
	body:not(.tms-tp) #adminmenu .wp-submenu,body:not(.tms-tp) #adminmenu .wp-has-current-submenu .wp-submenu{background:#022A44!important}
	body:not(.tms-tp) #adminmenu .wp-submenu a{color:#CBD5E1!important}
	body:not(.tms-tp) #adminmenu .wp-submenu a:hover,body:not(.tms-tp) #adminmenu .wp-submenu li.current a{color:#F5B82E!important}
	body:not(.tms-tp) #adminmenuwrap::before{content:"";display:block;width:96px;height:96px;margin:16px auto 6px;border-radius:50%;border:5px solid #F5B82E;background:#fff <?php echo $logo ? 'url(' . esc_url( $logo ) . ') center/86% no-repeat' : ''; ?>}
	body:not(.tms-tp) #adminmenu::before{content:"eWebCity Education";display:block;text-align:center;color:#F5B82E;font-family:Fredoka,sans-serif;font-weight:700;font-size:14px;margin:0 0 10px}
	body.folded:not(.tms-tp) #adminmenuwrap::before{width:28px;height:28px;border-width:2px}body.folded:not(.tms-tp) #adminmenu::before{display:none}
	body:not(.tms-tp) .wrap h1,body:not(.tms-tp) .wrap h2{font-family:Fredoka,sans-serif;color:#043353}
	body:not(.tms-tp) .wp-core-ui .button-primary{background:#E44546;border-color:#E44546;border-radius:999px;font-weight:800;box-shadow:0 3px 0 #B92F31}
	body:not(.tms-tp) .postbox,body:not(.tms-tp) .wp-list-table{border-radius:16px;overflow:hidden;border-color:#E7ECF5;box-shadow:0 6px 18px rgba(4,51,83,.06)}
	#footer-thankyou,#footer-upgrade,#wp-version-message,#contextual-help-link-wrap{display:none!important}
	.wrap .notice:not(.notice-error):not(.error):not(.notice-success):not(.updated):not(.wlsm-notice),#wpbody-content > .notice:not(.notice-error):not(.error):not(.notice-success):not(.updated),.bdt-notice,[class*="-promo"],[class*="promo-notice"],.embedpress-notice,.eael-notice,.ekit-notice,.pa-notice,.ue-notice{display:none!important}
	#wp-admin-bar-updates{display:none!important}
	#dashboard-widgets .postbox-container{width:100%!important}
	#ewc_overview{border:0;background:transparent;box-shadow:none}#ewc_overview .postbox-header{display:none}#ewc_overview .inside{padding:0;margin:0}
	.ewc-hero{display:flex;justify-content:space-between;flex-wrap:wrap;gap:16px;align-items:center;background:linear-gradient(120deg,#043353,#0B5C8E 60%,#1E7FB8);color:#fff;border-radius:24px;padding:26px 28px;box-shadow:0 16px 36px rgba(4,51,83,.25)}
	.ewc-hero small{color:#FDE68A;font-weight:800;letter-spacing:2px;text-transform:uppercase;font-size:11px}
	.ewc-hero h2{color:#fff!important;font-family:Fredoka,sans-serif;font-size:28px!important;margin:4px 0!important;padding:0!important}
	.ewc-hero p{margin:0;color:#CFE3F3;font-weight:600}
	.ewc-stats{display:flex;gap:12px;flex-wrap:wrap}.ewc-stats span{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:16px;padding:10px 16px;text-align:center;font-weight:700;font-size:12px;min-width:88px}
	.ewc-stats b{display:block;font-family:Fredoka,sans-serif;font-size:24px;color:#F5B82E}
	.ewc-tiles{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-top:18px}
	.ewc-tiles a{display:flex;flex-direction:column;gap:10px;background:#fff;border-radius:20px;padding:18px;font-weight:800;color:#043353;text-decoration:none;border:1px solid #E7ECF5;box-shadow:0 6px 18px rgba(4,51,83,.06);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
	.ewc-tiles a:before{content:"";position:absolute;inset:0 auto 0 0;width:6px;background:var(--c)}
	.ewc-tiles a:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(4,51,83,.12)}
	.ewc-tiles i{font-style:normal;font-size:24px;width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:color-mix(in srgb,var(--c) 14%,#fff)}
	@media (max-width:1200px){.ewc-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}}@media (max-width:700px){.ewc-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
	</style>
	<?php
}, 5 );

/* ==== eWebCity Education: portable design, Setup Wizard, Documentation ==== */
function ewc_school_id() {
	global $wpdb;
	$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->prefix}wlsm_schools ORDER BY ID ASC LIMIT 1" );
	return $id ? $id : 1;
}
function ewc_page_catalog() {
	$s = ewc_school_id();
	return array(
		'portal'       => array( 'Student & Parent Portal', '[school_management_account]', 'Login for pupils and parents: attendance, results, fees, timetable, ID card.', 'Student and Parent Portal', true ),
		'registration' => array( 'Online Registration', '[school_management_registration school_id="' . $s . '"]', 'Parents register a child online; the office approves it.', 'Admissions', true ),
		'inquiry'      => array( 'Admission Inquiry', '[school_management_inquiry school_id="' . $s . '"]', 'Short enquiry form for families.', 'Admissions', true ),
		'fees'         => array( 'Pay School Fees', '[school_management_fees school_id="' . $s . '"]', 'Find a pupil and pay or upload a bank receipt.', 'Fees', true ),
		'notices'      => array( 'Noticeboard', '[school_management_noticeboard school_id="' . $s . '"]', 'Latest school notices.', 'News', true ),
		'exam-timetable' => array( 'Exam Timetable', '[school_management_exam_time_table]', 'Public exam timetable search.', 'Exams', false ),
		'admit-card'   => array( 'Admit Card', '[school_management_exam_admit_card]', 'Download an exam admit card.', 'Exams', false ),
		'results'      => array( 'Exam Results', '[school_management_exam_result]', 'Search published exam results.', 'Exams', false ),
		'certificate'  => array( 'Certificates', '[school_management_certificate]', 'Find and print a certificate.', 'Certificates', false ),
		'invoice-history' => array( 'Invoice History', '[school_management_invoice_history]', 'Look up a pupil\'s invoices and payments.', 'Fees', false ),
		'staff-registration' => array( 'Staff Registration', '[school_management_staff_registration]', 'Job / staff application form.', 'Careers', false ),
		'lessons'      => array( 'Lessons', '[school_management_lesson]', 'Lesson and study materials list.', 'Learning', false ),
	);
}
function ewc_page_content( $item ) {
	return '<!-- wp:html --><div class="ewc-wrap"><section class="ewc-hero"><span class="ewc-eyebrow">' . esc_html( $item[3] ) . '</span><h1>' . esc_html( $item[0] ) . '</h1><p>' . esc_html( $item[2] ) . '</p></section><div class="ewc-card">' . $item[1] . '</div></div><!-- /wp:html -->';
}
function ewc_find_page_with( $shortcode_tag ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','private') AND ( post_content LIKE %s OR ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s ) ) ORDER BY ( post_status = 'publish' ) DESC, ID ASC LIMIT 1", '%[' . $wpdb->esc_like( $shortcode_tag ) . '%', '%[' . $wpdb->esc_like( $shortcode_tag ) . '%' ) );
}

/* Activation: open the wizard once. */
register_activation_hook( __FILE__, function () { set_transient( 'ewc_open_wizard', 1, 300 ); } );
add_action( 'admin_init', function () {
	if ( get_transient( 'ewc_open_wizard' ) && current_user_can( 'manage_options' ) && ! wp_doing_ajax() && empty( $_GET['activate-multi'] ) ) {
		delete_transient( 'ewc_open_wizard' );
		wp_safe_redirect( admin_url( 'admin.php?page=ewc-setup' ) );
		exit;
	}
} );
add_action( 'admin_notices', function () {
	if ( get_option( 'ewc_setup_done' ) || ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) && 'ewc-setup' === $_GET['page'] ) ) {
		return;
	}
	echo '<div class="notice notice-error ewc-setup-nag" style="border-left-color:#F5B82E;border-radius:14px;padding:14px 18px"><p style="font-size:14px"><b>🎓 eWebCity Education:</b> choose which school pages to create (portal, registration, fees…). <a class="button button-primary" style="margin-left:8px" href="' . esc_url( admin_url( 'admin.php?page=ewc-setup' ) ) . '">Open Setup Wizard</a></p></div>';
} );
add_action( 'admin_menu', function () {
	add_menu_page( 'eWebCity Education', 'eWebCity', 'manage_options', 'ewc-setup', 'ewc_page_setup', 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAyIDEgNi41IDEwIDExbDctMy41VjEzaDEuNVY2LjV6Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik00LjUgOS4zdjMuMmMwIDEuNiAyLjUgMyA1LjUgM3M1LjUtMS40IDUuNS0zVjkuM0wxMCAxMi4xeiIvPjwvc3ZnPg==', 3 );
	add_submenu_page( 'ewc-setup', 'Setup Wizard', 'Setup Wizard', 'manage_options', 'ewc-setup', 'ewc_page_setup' );
	add_submenu_page( 'ewc-setup', 'Documentation', 'Documentation', 'manage_options', 'ewc-docs', 'ewc_page_docs' );
}, 20 );

add_action( 'admin_post_ewc_create_pages', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No access' ); }
	check_admin_referer( 'ewc_create_pages' );
	$cat     = ewc_page_catalog();
	$want    = isset( $_POST['pages'] ) ? array_map( 'sanitize_key', (array) $_POST['pages'] ) : array();
	$titles  = isset( $_POST['title'] ) ? (array) $_POST['title'] : array();
	$made    = array();
	$map     = (array) get_option( 'ewc_pages', array() );
	foreach ( $want as $key ) {
		if ( ! isset( $cat[ $key ] ) ) { continue; }
		$item = $cat[ $key ];
		if ( ! empty( $titles[ $key ] ) ) { $item[0] = sanitize_text_field( wp_unslash( $titles[ $key ] ) ); }
		preg_match( '/\[([a-z_]+)/', $item[1], $m );
		$existing = ewc_find_page_with( $m[1] );
		if ( $existing ) { $map[ $key ] = $existing; continue; }
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $item[0], 'post_content' => ewc_page_content( $item ) ) );
		if ( $id && ! is_wp_error( $id ) ) { $map[ $key ] = $id; $made[] = $id; }
	}
	update_option( 'ewc_pages', $map );
	if ( ! empty( $_POST['menu'] ) && $map ) {
		$menu_id = wp_get_nav_menu_object( 'eWebCity School' ) ? wp_get_nav_menu_object( 'eWebCity School' )->term_id : wp_create_nav_menu( 'eWebCity School' );
		if ( ! is_wp_error( $menu_id ) ) {
			$have = wp_list_pluck( (array) wp_get_nav_menu_items( $menu_id ), 'object_id' );
			foreach ( $map as $pid ) {
				if ( ! in_array( (string) $pid, array_map( 'strval', $have ), true ) ) {
					wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-object-id' => $pid, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
				}
			}
		}
	}
	update_option( 'ewc_setup_done', 1 );
	if ( function_exists( 'tms_log_activity' ) ) { tms_log_activity( 'ewc_setup', 'Ran eWebCity Setup Wizard', array( 'pages_created' => count( $made ) ) ); }
	wp_safe_redirect( admin_url( 'admin.php?page=ewc-setup&done=' . count( $made ) ) );
	exit;
} );

add_action( 'admin_post_ewc_export_css', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No access' ); }
	check_admin_referer( 'ewc_export_css' );
	$kit = (int) get_option( 'elementor_active_kit' );
	$set = $kit ? get_post_meta( $kit, '_elementor_page_settings', true ) : array();
	$css = is_array( $set ) && ! empty( $set['custom_css'] ) ? $set['custom_css'] : '';
	$out = "/* eWebCity Education – portable portal design (exported " . current_time( 'Y-m-d H:i' ) . ") */\n";
	foreach ( array( 'TMS PLAYFUL – PLUGIN UI', 'TMS PLAYFUL – PORTAL', 'TMS PLAYFUL – PARENT' ) as $marker ) {
		$q = preg_quote( $marker, '/' );
		if ( preg_match( '/\/\* ===== ' . $q . ' ===== \*\/.*?\/\* ===== \/' . $q . ' ===== \*\//s', $css, $m ) ) { $out .= $m[0] . "\n"; }
	}
	$out = str_replace( '.elementor-shortcode', ':is(.elementor-shortcode,.ewc-wrap)', $out );
	if ( ! is_dir( __DIR__ . '/assets' ) ) { wp_mkdir_p( __DIR__ . '/assets' ); }
	$ok = file_put_contents( __DIR__ . '/assets/ewc-frontend.css', $out );
	wp_safe_redirect( admin_url( 'admin.php?page=ewc-docs&css=' . ( $ok ? strlen( $out ) : 'fail' ) ) );
	exit;
} );

/* Front-end design shipped with the plugin. */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap', array(), null );
	$f = __DIR__ . '/assets/ewc-frontend.css';
	if ( file_exists( $f ) ) {
		wp_enqueue_style( 'ewc-frontend', plugins_url( 'assets/ewc-frontend.css', __FILE__ ), array(), filemtime( $f ) );
	}
	wp_register_style( 'ewc-base', false );
	wp_enqueue_style( 'ewc-base' );
	wp_add_inline_style( 'ewc-base', '.ewc-wrap{max-width:1180px;margin:0 auto;padding:0 16px 60px;font-family:Nunito,sans-serif}.ewc-hero{background:linear-gradient(120deg,#043353,#0B5C8E 60%,#1E7FB8);color:#fff;border-radius:0 0 40px 40px;padding:56px 28px 70px;text-align:center;margin:0 -16px 28px;position:relative;overflow:hidden}.ewc-hero:after{content:"★";position:absolute;right:8%;top:30%;color:#F5B82E;font-size:30px}.ewc-hero h1{font-family:Fredoka,sans-serif;color:#fff;font-size:clamp(30px,5vw,48px);margin:10px 0 8px}.ewc-hero p{color:#D6E6F5;margin:0;font-size:17px}.ewc-eyebrow{display:inline-block;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:7px 16px;color:#FDE68A;font-weight:800;font-size:13px;letter-spacing:2px;text-transform:uppercase}.ewc-card{background:#fff;border-radius:28px;padding:28px;box-shadow:0 20px 50px rgba(4,51,83,.10);border:2px solid #EEF2FF;margin-top:-40px;position:relative}@media(max-width:767px){.ewc-card{padding:16px;border-radius:20px}}' );
} );

function ewc_page_setup() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$cat = ewc_page_catalog();
	$map = (array) get_option( 'ewc_pages', array() );
	echo '<div class="tdb"><section class="tdb-hero"><div><p class="tdb-eyebrow">eWebCity Education · Setup Wizard</p><h1>Which school pages do you need?</h1><p class="tdb-sub">Tick the pages, rename them if you like, and we will create them with the eWebCity design. Pages that already exist are reused.</p></div></section>';
	if ( isset( $_GET['done'] ) ) { echo '<div class="tdb-card" style="margin-top:16px;background:#ECFDF5;border-color:#A7F3D0"><b>✅ Done.</b> ' . (int) $_GET['done'] . ' new page(s) created. Existing pages were linked.</div>'; }
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tdb-card" style="margin-top:16px">';
	wp_nonce_field( 'ewc_create_pages' );
	echo '<input type="hidden" name="action" value="ewc_create_pages"><div class="ewc-wiz">';
	foreach ( $cat as $key => $it ) {
		preg_match( '/\[([a-z_]+)/', $it[1], $m );
		$pid = ! empty( $map[ $key ] ) && get_post( $map[ $key ] ) ? (int) $map[ $key ] : ewc_find_page_with( $m[1] );
		echo '<label class="ewc-opt' . ( $pid ? ' has' : '' ) . '"><input type="checkbox" name="pages[]" value="' . esc_attr( $key ) . '"' . checked( $it[4] || $pid, true, false ) . '><span><input type="text" name="title[' . esc_attr( $key ) . ']" value="' . esc_attr( $pid ? get_the_title( $pid ) : $it[0] ) . '"><small>' . esc_html( $it[2] ) . '</small><code>' . esc_html( $it[1] ) . '</code>' . ( $pid ? '<em>✓ Exists: <a href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank">view</a></em>' : '' ) . '</span></label>';
	}
	echo '</div><p><label style="font-weight:800"><input type="checkbox" name="menu" value="1" checked> Add these pages to a menu called “eWebCity School” (assign it under Appearance → Menus)</label></p><p><button class="tdb-save" type="submit">✨ Create my pages</button></p></form></div>';
	echo '<style>.ewc-wiz{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-bottom:16px}.ewc-opt{display:flex;gap:12px;align-items:flex-start;border:2px solid #E2E8F0;border-radius:18px;padding:14px;cursor:pointer;background:#fff}.ewc-opt:has(input[type=checkbox]:checked){border-color:#F5B82E;background:#FFFBEB}.ewc-opt.has{border-style:dashed}.ewc-opt span{flex:1;display:flex;flex-direction:column;gap:4px}.ewc-opt input[type=text]{font-weight:800;color:#043353;border-radius:10px;border:1px solid #E2E8F0}.ewc-opt small{color:#64748B}.ewc-opt code{font-size:11px;background:#F1F5F9;border-radius:6px;padding:2px 6px;align-self:flex-start}.ewc-opt em{font-style:normal;color:#047857;font-weight:800;font-size:12px}.tdb-save{background:#E44546;color:#fff;border:0;border-radius:999px;padding:12px 28px;font-weight:900;font-size:15px;cursor:pointer;box-shadow:0 4px 0 #B92F31}</style>';
}

function ewc_page_docs() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$css_file = __DIR__ . '/assets/ewc-frontend.css';
	echo '<div class="tdb"><section class="tdb-hero"><div><p class="tdb-eyebrow">eWebCity Education · by Mahim</p><h1>Documentation</h1><p class="tdb-sub">Everything the school system can do, every shortcode, and how to set it up on a new site.</p></div><div class="tdb-actions"><a class="tdb-btn tdb-btn-gold" href="' . esc_url( admin_url( 'admin.php?page=ewc-setup' ) ) . '">✨ Setup Wizard</a><a class="tdb-btn" target="_blank" href="https://www.facebook.com/eWebcity">💬 Support</a></div></section>';
	if ( isset( $_GET['css'] ) ) { echo '<div class="tdb-card" style="margin-top:16px">' . ( 'fail' === $_GET['css'] ? '⚠️ Could not write the design file (folder not writable).' : '✅ Design exported into the plugin (' . (int) $_GET['css'] . ' bytes).' ) . '</div>'; }
	echo '<section class="tdb-grid" style="margin-top:16px">';
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Quick start on a new website</h3></div><ol class="ewc-steps"><li>Upload and activate <b>eWebCity Education</b> (Plugins → Add New → Upload).</li><li>The <b>Setup Wizard</b> opens: tick the pages you need and press <b>Create my pages</b>.</li><li>Go to <b>eWebCity Setup → Schools / Sessions / Classes</b> and add your school, session and classes.</li><li>Add staff in <b>Staff &amp; Admin → Staff</b>: give teachers the <b>Teacher</b> role and a class.</li><li>Create committee accounts in Users with role <b>Committee</b>, then link them as staff with the Committee role.</li><li>Put the “eWebCity School” menu in your header (Appearance → Menus).</li></ol></div>';
	echo '<div class="tdb-card tdb-span2"><div class="tdb-card-h"><h3>Who sees what</h3></div><ul class="tdb-mini"><li><b>Admin</b><span>Everything, plus white-label dashboard, approvals, activity log.</span></li><li><b>Committee</b><span>Committee Panel: school figures, approvals, activity log, staff &amp; teachers.</span></li><li><b>Teacher</b><span>Teacher Panel: Teacher Insights, Student 360°, Gradebook, attendance, homework, exams — only their class.</span></li><li><b>Parent / Student</b><span>Website portal: attendance, results, fees, timetable, ID card, notices.</span></li></ul></div>';
	echo '<div class="tdb-card tdb-span2" style="grid-column:1/-1"><div class="tdb-card-h"><h3>Shortcodes</h3><span>Click a code to copy</span></div><table class="s360-table"><thead><tr><th>Page</th><th>Shortcode</th><th>What it shows</th></tr></thead><tbody>';
	foreach ( ewc_page_catalog() as $it ) { echo '<tr><td><b>' . esc_html( $it[0] ) . '</b></td><td><code class="ewc-copy" title="Copy">' . esc_html( $it[1] ) . '</code></td><td>' . esc_html( $it[2] ) . '</td></tr>'; }
	echo '<tr><td><b>School register (multi-school)</b></td><td><code class="ewc-copy">[school_register]</code></td><td>Lets a new school sign up (multi-school setups only).</td></tr></tbody></table></div>';
	echo '<div class="tdb-card tdb-span2" style="grid-column:1/-1"><div class="tdb-card-h"><h3>Features</h3></div><div class="ewc-feat">';
	foreach ( array( '🏫 Schools, sessions, classes, sections, subjects', '🎒 Admissions, registration with approval, inquiries', '👧 Students, ID cards, promotion, transfer, certificates', '✅ Attendance (daily & by subject), leave requests', '📚 Timetables, study materials, homework, noticeboard, events', '🏆 Exams, papers, admit cards, results, assessments, academic reports', '💳 Fees, invoices, payments, bank transfer & cash, income & expenses', '📖 Library, transport, hostel', '🔔 Email/SMS notifications', '📊 Teacher Insights, Student 360°, Gradebook', '🏛️ Committee Panel with approvals', '🛡️ Activity log, live activity, money alerts', '🎨 Portable design, white-label admin, branded login' ) as $f ) { echo '<span>' . esc_html( $f ) . '</span>'; }
	echo '</div></div>';
	echo '<div class="tdb-card tdb-span2" style="grid-column:1/-1"><div class="tdb-card-h"><h3>Design</h3><span>' . ( file_exists( $css_file ) ? 'Design file present ✓' : 'Design file missing' ) . '</span></div><p>The portal, fees, registration and parent pages use the eWebCity design stored inside the plugin, so the look is the same on every site. After changing the design in the site customizer, press the button to copy it into the plugin.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'ewc_export_css' );
	echo '<input type="hidden" name="action" value="ewc_export_css"><button class="tdb-save">🎨 Copy current design into plugin</button></form></div></section></div>';
	echo '<style>.ewc-steps li{margin:8px 0;font-weight:600}.ewc-copy{cursor:pointer;background:#F1F5F9;border-radius:8px;padding:4px 8px}.ewc-copy:hover{background:#FFF8E6}.ewc-feat{display:flex;flex-wrap:wrap;gap:10px}.ewc-feat span{background:#F8FAFC;border:1px solid #E7ECF5;border-radius:999px;padding:8px 14px;font-weight:700;color:#043353}.tdb-save{background:#E44546;color:#fff;border:0;border-radius:999px;padding:12px 28px;font-weight:900;cursor:pointer;box-shadow:0 4px 0 #B92F31}</style><script>document.querySelectorAll(".ewc-copy").forEach(function(c){c.addEventListener("click",function(){navigator.clipboard&&navigator.clipboard.writeText(c.textContent);var o=c.textContent;c.textContent="Copied ✓";setTimeout(function(){c.textContent=o;},1200);});});</script>';
}
add_action( 'admin_head', function () {
	if ( empty( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'ewc-setup', 'ewc-docs' ), true ) ) { return; }
	echo '<style>.tdb{font-family:Nunito,sans-serif;margin:18px 20px 8px 0}.tdb-hero{display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;background:linear-gradient(120deg,#043353,#0B5C8E 60%,#1E7FB8);color:#fff;border-radius:26px;padding:28px 30px;box-shadow:0 18px 40px rgba(4,51,83,.25)}.tdb-hero h1{font-family:Fredoka,sans-serif;font-size:30px;color:#fff;margin:4px 0;padding:0}.tdb-eyebrow{margin:0;text-transform:uppercase;letter-spacing:2px;font-size:12px;font-weight:800;color:#FDE68A}.tdb-sub{margin:4px 0 0;color:#CFE3F3;font-weight:600}.tdb-actions{display:flex;gap:10px;flex-wrap:wrap}.tdb-btn{display:inline-flex;gap:6px;padding:11px 18px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);color:#fff!important;font-weight:800;text-decoration:none}.tdb-btn-gold{background:#F5B82E;border-color:#F5B82E;color:#043353!important}.tdb-card{background:#fff;border-radius:22px;padding:20px;border:1px solid #E7ECF5;box-shadow:0 6px 18px rgba(4,51,83,.06)}.tdb-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.tdb-span2{grid-column:span 2}.tdb-card-h{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}.tdb-card-h h3{font-family:Fredoka,sans-serif;font-size:18px;margin:0;color:#043353}.tdb-card-h span{font-size:12px;color:#64748B;font-weight:700}.tdb-mini{list-style:none;margin:0;padding:0}.tdb-mini li{padding:9px 12px;border-radius:12px;background:#F8FAFC;margin-bottom:8px;border-left:4px solid #F5B82E}.tdb-mini li b{display:block;color:#043353}.tdb-mini li span{font-size:12.5px;color:#64748B}.s360-table{width:100%;border-collapse:separate;border-spacing:0}.s360-table th{background:#043353;color:#fff;text-align:left;padding:10px 12px}.s360-table td{padding:10px 12px;border-bottom:1px solid #EEF2F7}@media(max-width:1100px){.tdb-grid{grid-template-columns:1fr}.tdb-span2{grid-column:auto}}</style>';
} );

/* ==== Hide School Engine vendor branding on screen (file notices are kept for GPL) ==== */
function ewc_debrand( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, 'weblizar' ) ) {
		return $html;
	}
	$html = preg_replace( '#(href\s*=\s*["\'])https?://(?:www\.)?weblizar\.com[^"\']*#i', '$1https://www.facebook.com/eWebcity', $html );
	$html = preg_replace( '#(>[^<]*?)Weblizar#i', '$1eWebCity', $html );
	$html = preg_replace( '#(["\'])Weblizar(["\'])#', '$1eWebCity$2', $html );
	return $html;
}
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() ) {
		$a = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 0 === strpos( $a, 'wlsm' ) ) { ob_start( 'ewc_debrand' ); }
		return;
	}
	ob_start( 'ewc_debrand' );
}, 1 );
add_action( 'template_redirect', function () {
	if ( ! is_admin() ) { ob_start( 'ewc_debrand' ); }
}, 1 );
add_action( 'admin_menu', function () {
	global $submenu;
	foreach ( array( 'school-management' ) as $parent ) {
		if ( empty( $submenu[ $parent ] ) ) { continue; }
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( isset( $item[2] ) && false !== stripos( $item[2], 'license' ) ) { unset( $submenu[ $parent ][ $i ] ); }
		}
	}
}, 10003 );
add_action( 'admin_head', function () {
	echo '<style>a[href*="weblizar.com"]:not([href*="facebook"]),img[src*="weblizar"],.wlsm-weblizar,.wlsm-pro-banner,.wlsm-rate-us{display:none!important}</style>';
} );
