<?php
/**
 * Plugin Name:       NeonDigital WorkDesk
 * Description:       Work management for NeonDigital: tasks, follow-ups, daily worksheet with carry-forward, teams with per-team permissions, clients, leave, login-based attendance, notifications, Excel/CSV/PDF reports, Accounts (invoices, payments, income, expenses, passbook) and client service packages with usage tracking.
 * Version:           1.21.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NeonDigital
 * License:           GPL-2.0-or-later
 * Text Domain:       ndwd
 */

defined( 'ABSPATH' ) || exit;

define( 'NDWD_VERSION', '1.21.0' );
define( 'NDWD_DB_VERSION', 20 );
define( 'NDWD_FILE', __FILE__ );
define( 'NDWD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NDWD_URL', plugin_dir_url( __FILE__ ) );
define( 'NDWD_TZ', 'Asia/Kolkata' );

require_once NDWD_DIR . 'includes/class-helpers.php';
require_once NDWD_DIR . 'includes/class-installer.php';
require_once NDWD_DIR . 'includes/class-permissions.php';
require_once NDWD_DIR . 'includes/class-activity.php';
require_once NDWD_DIR . 'includes/class-tasks.php';
require_once NDWD_DIR . 'includes/class-clients.php';
require_once NDWD_DIR . 'includes/class-leaves.php';
require_once NDWD_DIR . 'includes/class-attendance.php';
require_once NDWD_DIR . 'includes/class-notifications.php';
require_once NDWD_DIR . 'includes/class-push.php';
require_once NDWD_DIR . 'includes/class-stats.php';
require_once NDWD_DIR . 'includes/lib/class-xlsx-writer.php';
require_once NDWD_DIR . 'includes/lib/class-pdf-writer.php';
require_once NDWD_DIR . 'includes/lib/class-webpush.php';
require_once NDWD_DIR . 'includes/class-reports.php';
require_once NDWD_DIR . 'includes/class-files.php';
require_once NDWD_DIR . 'includes/class-exports.php';
require_once NDWD_DIR . 'includes/class-jobs.php';
require_once NDWD_DIR . 'includes/class-invoice-pdf.php';
require_once NDWD_DIR . 'includes/class-accounts.php';
require_once NDWD_DIR . 'includes/class-accounts-rest.php';
require_once NDWD_DIR . 'includes/class-packages.php';
require_once NDWD_DIR . 'includes/class-import.php';
require_once NDWD_DIR . 'includes/class-profile.php';
require_once NDWD_DIR . 'includes/class-workflow.php';
require_once NDWD_DIR . 'includes/class-cron.php';
require_once NDWD_DIR . 'includes/class-rest.php';
require_once NDWD_DIR . 'includes/class-app.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-schema.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-core.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-webhooks.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-requests.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-api.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-admin.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-enquiries.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-tasklink.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-manage.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-projects.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-hub.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-btype.php';
require_once NDWD_DIR . 'includes/workspace/class-ws-attachments.php';

register_activation_hook( __FILE__, array( 'NDWD_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NDWD_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	NDWD_Installer::maybe_upgrade();
	NDWD_Attendance::init();
	NDWD_Notifications::init();
	NDWD_Push::init();
	NDWD_Cron::init();
	NDWD_REST::init();
	NDWD_App::init();
	NDWD_Reports::init();
	NDWD_Files::init();
	NDWD_WS_Webhooks::init();
	NDWD_WS_API::init();
	NDWD_WS_Admin::init();
	NDWD_Enquiries::init();
	NDWD_WS_TaskLink::init();
	NDWD_WS_Manage::init();
	NDWD_WS_Projects::init();
	NDWD_WS_Hub::init();
	NDWD_WS_BType::init();
	NDWD_WS_Attachments::init();
	NDWD_Helpers::password_hooks();
} );
