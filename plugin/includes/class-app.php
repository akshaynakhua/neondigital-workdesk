<?php
/**
 * Application shell: renders the WorkDesk single-page app (or the sign-in
 * screen), keeps members out of wp-admin, and blocks deactivated accounts.
 *
 * The app is served at /workdesk/ by the plugin. When the NeonDigital
 * WorkDesk theme is active it is also the site's front page.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_App {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', function ( $v ) {
			$v[] = 'ndwd_app';
			return $v;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_filter( 'authenticate', array( __CLASS__, 'block_inactive' ), 99 );
		add_action( 'wp_login', function ( $login, $user ) {
			update_user_meta( $user->ID, 'ndwd_last_login', NDWD_Helpers::now_mysql() );
		}, 5, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		// Failed sign-in from the app screen: return to it with a message instead of wp-login.php.
		add_action( 'wp_login_failed', function () {
			$ref = wp_get_referer();
			if ( $ref && 0 === strpos( $ref, home_url() ) && false === strpos( $ref, 'wp-login.php' ) ) {
				wp_safe_redirect( add_query_arg( 'login', 'failed', remove_query_arg( 'login', $ref ) ) );
				exit;
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', function ( $show ) {
			return current_user_can( 'manage_options' ) ? $show : false;
		} );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NDWD_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Open WorkDesk</a>' );
			return $links;
		} );
	}

	public static function rewrite() {
		add_rewrite_rule( '^workdesk/?$', 'index.php?ndwd_app=1', 'top' );
		if ( get_option( 'ndwd_rewrite_version' ) !== NDWD_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'ndwd_rewrite_version', NDWD_VERSION );
		}
	}

	/** Canonical app URL. The theme filters this to the home page. */
	public static function url() {
		return apply_filters( 'ndwd_app_url', home_url( '/workdesk/' ) );
	}

	public static function maybe_render() {
		if ( get_query_var( 'ndwd_app' ) || isset( $_GET['ndwd_app'] ) ) { // phpcs:ignore
			self::render();
			exit;
		}
	}

	public static function block_inactive( $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, 'ndwd_inactive', true ) ) {
			return new WP_Error( 'ndwd_inactive', '<strong>Account deactivated.</strong> Please contact your WorkDesk administrator.' );
		}
		return $user;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'ndwd_access' ) && ! user_can( $user, 'manage_options' ) ) {
			$app = self::url();
			// Honour an explicit redirect back into the app, otherwise go to the app.
			if ( $requested && 0 === strpos( $requested, home_url() ) && false === strpos( $requested, '/wp-admin' ) ) {
				return $requested;
			}
			return $app;
		}
		return $redirect_to;
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'admin-post.php' === $script ) {
			return;
		}
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) && current_user_can( 'ndwd_access' ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function admin_menu() {
		add_menu_page( 'WorkDesk', 'WorkDesk', 'ndwd_access', 'ndwd', array( __CLASS__, 'admin_page' ), 'dashicons-yes-alt', 3 );
	}

	public static function admin_page() {
		echo '<div class="wrap"><h1>NeonDigital WorkDesk</h1>';
		echo '<p>WorkDesk runs as its own application outside wp-admin.</p>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( self::url() ) . '">Open WorkDesk</a></p>';
		echo '<h2>Quick setup</h2><ol>';
		echo '<li>Open WorkDesk → <em>Admin → Members</em> to add members (Admin or Member).</li>';
		echo '<li><em>Admin → Teams</em> to create teams, choose team managers and set what each team may do.</li>';
		echo '<li><em>Admin → Settings</em> to configure email (SMTP) and review the scheduler status.</li>';
		echo '<li>For reliable scheduling, add a PHP CLI server cron job every minute (see Settings → Scheduler health).</li>';
		echo '</ol></div>';
	}

	/* ------------------------------------------------------------------ */

	public static function asset( $rel ) {
		$file = NDWD_DIR . 'assets/' . $rel;
		$ver  = file_exists( $file ) ? filemtime( $file ) : NDWD_VERSION;
		return add_query_arg( 'v', $ver, NDWD_URL . 'assets/' . $rel );
	}

	public static function logos() {
		$theme_dir = get_template_directory() . '/assets/img/';
		$theme_url = get_template_directory_uri() . '/assets/img/';
		$pick      = function ( $name ) use ( $theme_dir, $theme_url ) {
			return file_exists( $theme_dir . $name ) ? $theme_url . $name : NDWD_URL . 'assets/img/' . $name;
		};
		return apply_filters( 'ndwd_logos', array(
			'mark'     => $pick( 'logo-mark.png' ),
			'wordmark' => $pick( 'logo-wordmark.png' ),
			'full'     => $pick( 'logo-full.png' ),
			'favicon'  => $pick( 'favicon.png' ),
		) );
	}

	/** Prints the full HTML document for the app or the sign-in screen. */
	public static function render() {
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );

		$user  = wp_get_current_user();
		$logos = self::logos();
		$state = 'app';
		if ( ! $user->exists() ) {
			$state = 'login';
		} elseif ( get_user_meta( $user->ID, 'ndwd_inactive', true ) ) {
			wp_logout();
			$state = 'login';
		} elseif ( ! user_can( $user, 'ndwd_access' ) ) {
			$state = 'noaccess';
		} else {
			NDWD_Attendance::touch_session( $user->ID ); // Opening WorkDesk on a new IST date while still signed in.
		}
		$theme = $user->exists() ? get_user_meta( $user->ID, 'ndwd_theme', true ) : '';
		$theme = in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : 'system';
		?><!doctype html>
<html lang="en" data-theme-pref="<?php echo esc_attr( $theme ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#111214">
<title>NeonDigital WorkDesk</title>
<link rel="icon" href="<?php echo esc_url( $logos['favicon'] ); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( self::asset( 'css/app.css' ) ); ?>">
<?php do_action( 'ndwd_head' ); ?>
<script>
(function(){var p=document.documentElement.getAttribute('data-theme-pref');try{var l=localStorage.getItem('ndwd-theme');if(l&&p==='system')p=l;}catch(e){}
var d=p==='dark'||(p!=='light'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');})();
</script>
</head>
<body class="ndwd ndwd-<?php echo esc_attr( $state ); ?>">
		<?php
		if ( 'login' === $state ) {
			self::login_screen( $logos );
		} elseif ( 'noaccess' === $state ) {
			echo '<main class="auth"><div class="auth-card"><img class="auth-logo" src="' . esc_url( $logos['full'] ) . '" alt="NeonDigital"><h1>No WorkDesk access</h1><p class="muted">Your account is not a WorkDesk member yet. Ask an administrator to add you.</p><a class="btn btn-primary" href="' . esc_url( wp_logout_url( self::url() ) ) . '">Sign out</a></div></main>';
		} else {
			$config = array(
				'root'    => esc_url_raw( rest_url( NDWD_REST::NS . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'appUrl'  => self::url(),
				'logos'   => $logos,
				'version' => NDWD_VERSION,
				'push'    => array( 'swUrl' => NDWD_Push::sw_url(), 'scope' => NDWD_Push::scope() ),
			);
			echo '<div id="ndwd-app"><div class="boot"><img src="' . esc_url( $logos['mark'] ) . '" alt="" width="56" height="56"><div class="boot-bar"></div></div></div>';
			echo '<script>window.NDWD=' . wp_json_encode( $config ) . ';</script>';
			echo '<script src="' . esc_url( self::asset( 'js/core.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/views.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/accounts.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/workflow.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/push.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/workspace-admin.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/workspace-extra.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/workspace-connect.js' ) ) . '"></script>';
			echo '<script src="' . esc_url( self::asset( 'js/projects-hub.js' ) ) . '"></script>';
		}
		?>
</body>
</html>
		<?php
	}

	private static function login_screen( $logos ) {
		$error = '';
		if ( isset( $_GET['login'] ) && 'failed' === $_GET['login'] ) { // phpcs:ignore
			$error = 'Incorrect username or password.';
		}
		?>
<main class="auth">
	<div class="auth-card">
		<img class="auth-logo" src="<?php echo esc_url( $logos['full'] ); ?>" alt="NeonDigital">
		<h1>Sign in to WorkDesk</h1>
		<p class="muted">Your daily work, follow-ups and team in one place.</p>
		<?php if ( $error ) : ?><div class="alert alert-error" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
		<?php
		wp_login_form( array(
			'redirect'       => self::url(),
			'label_username' => 'Email or username',
			'label_log_in'   => 'Sign in',
			'remember'       => true,
			'form_id'        => 'ndwd-login',
		) );
		?>
		<p class="auth-foot"><a href="<?php echo esc_url( wp_lostpassword_url( self::url() ) ); ?>">Forgot password?</a></p>
		<script>
		// Shared links such as …/#/tasks/12 keep their destination through sign-in. The hash never
		// reaches the server, so it is carried in redirect_to and, as a fallback, sessionStorage.
		(function () {
			var form = document.getElementById('ndwd-login');
			function keep() {
				var h = location.hash;
				if (!/^#\/[\w\/-]*$/.test(h)) { return; }
				try { sessionStorage.setItem('ndwd-after-login', h); } catch (e) {}
				var r = form && form.querySelector('input[name="redirect_to"]');
				if (r) { r.value = r.value.split('#')[0] + h; }
			}
			keep();
			window.addEventListener('hashchange', keep);
			if (form) { form.addEventListener('submit', keep); }
		})();
		</script>
	</div>
	<p class="auth-note">Attendance is recorded when you sign in. Times are shown in India Standard Time.</p>
</main>
		<?php
	}
}
