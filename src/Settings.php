<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

class Settings {


	public const OPTION_GROUP     = 'smoxy_settings';
	public const OPTION_NAME      = 'smoxy_settings';
	public const MENU_SLUG        = 'smoxy';
	public const SETTINGS_SLUG    = 'smoxy-settings';
	public const PURGE_ACTION     = 'smoxy_purge_all';
	public const PURGE_URL_ACTION = 'smoxy_purge_url';
	public const PURGE_TAG_ACTION = 'smoxy_purge_tag';
	public const NOTICE_KEY       = 'smoxy_purge_notice';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
		add_action( 'admin_post_' . self::PURGE_URL_ACTION, array( $this, 'handle_purge_url' ) );
		add_action( 'admin_post_' . self::PURGE_TAG_ACTION, array( $this, 'handle_purge_tag' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 100 );
	}

	public function add_admin_bar_node( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( 'action', self::PURGE_ACTION, admin_url( 'admin-post.php' ) ),
			self::PURGE_ACTION
		);

		$bar->add_node(
			array(
				'id'    => 'smoxy-purge',
				'title' => '<span class="ab-icon dashicons dashicons-cloud" style="margin-top:2px"></span> ' . esc_html__( 'Purge smoxy Proxy cache', 'smoxy' ),
				'href'  => $url,
				'meta'  => array(
					'title'   => __( 'Purge all pages from smoxy Proxy', 'smoxy' ),
					'onclick' => "return confirm('" . esc_js( __( 'Purge all cached pages from smoxy Proxy?', 'smoxy' ) ) . "');",
				),
			)
		);
	}

	public static function get_secret_key(): string {
		$options = get_option( self::OPTION_NAME, array() );
		return isset( $options['secret_key'] ) ? (string) $options['secret_key'] : '';
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'smoxy Proxy', 'smoxy' ),
			__( 'smoxy Proxy', 'smoxy' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cloud',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'smoxy Proxy Settings', 'smoxy' ),
			__( 'Settings', 'smoxy' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_page' )
		);

		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array( 'secret_key' => '' ),
			)
		);

		add_settings_section(
			'smoxy_main',
			__( 'API credentials', 'smoxy' ),
			function () {
				echo '<p>' . esc_html__( 'Credentials used when calling the smoxy Proxy cache service.', 'smoxy' ) . '</p>';
				printf(
					'<p>%s</p>',
					sprintf(
						/* translators: 1: link to the smoxy hub, 2: bold "free account" text. */
						esc_html__( 'Log in to the %1$s or create a %2$s.', 'smoxy' ),
						'<a href="' . esc_url( 'https://hub.smoxy.eu' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'smoxy hub', 'smoxy' ) . '</a>',
						'<strong>' . esc_html__( 'free account', 'smoxy' ) . '</strong>'
					) . ' ' . sprintf(
						/* translators: 1: code-wrapped "Basic configuration" menu label. */
						esc_html__( 'Your token is available under your Zone\'s %1$s menu.', 'smoxy' ),
						'<code>' . esc_html__( 'Basic configuration', 'smoxy' ) . '</code>'
					)
				);
			},
			self::SETTINGS_SLUG
		);

		add_settings_field(
			'secret_key',
			__( 'Secret token', 'smoxy' ),
			array( $this, 'render_secret_key_field' ),
			self::SETTINGS_SLUG,
			'smoxy_main',
			array( 'label_for' => 'smoxy_secret_key' )
		);
	}

	/**
	 * @param mixed $input
	 * @return array{secret_key: string}
	 */
	public function sanitize( $input ): array {
		$current   = get_option( self::OPTION_NAME, array() );
		$current   = is_array( $current ) ? $current : array();
		$output    = array();
		$submitted = isset( $input['secret_key'] ) ? trim( (string) $input['secret_key'] ) : '';
		if ( '' !== $submitted ) {
			$output['secret_key'] = $submitted;
		} else {
			$output['secret_key'] = isset( $current['secret_key'] ) ? (string) $current['secret_key'] : '';
		}
		return $output;
	}

	public function render_secret_key_field(): void {
		$has_value = '' !== self::get_secret_key();
		if ( $has_value ) {
			$placeholder = '••••••••';
			$description = esc_html__( 'A secret token is saved. Leave this field blank to keep it; paste a new token to replace it.', 'smoxy' );
		} else {
			$placeholder = esc_attr__( 'Paste your smoxy secret token', 'smoxy' );
			$description = esc_html__( 'Paste the secret token from your smoxy hub Zone to authenticate purge requests.', 'smoxy' );
		}
		printf(
			'<input type="password" id="smoxy_secret_key" name="%1$s[secret_key]" value="" placeholder="%2$s" class="regular-text" autocomplete="new-password" aria-label="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $placeholder ),
			esc_attr__( 'smoxy secret token', 'smoxy' )
		);
		echo '<p class="description">' . $description . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped via esc_html__() above.
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'smoxy Proxy Settings', 'smoxy' ); ?></h1>

			<div class="notice notice-info inline" style="padding:12px 16px;margin:15px 0;">
				<p style="margin-top:0;">
					<strong><?php echo esc_html__( 'About smoxy', 'smoxy' ); ?></strong> —
					<?php
					printf(
					/* translators: %s: link to smoxy.eu */
						esc_html__( '%s is a performance and security platform built for e-commerce shops and brand websites. It combines full-page HTML caching, automatic image optimization and edge-level protection to deliver pages up to 50–800%% faster — improving user experience, SEO rankings and conversion rates.', 'smoxy' ),
						'<a href="https://www.smoxy.eu" target="_blank" rel="noopener noreferrer">smoxy</a>'
					);
					?>
				</p>
				<p style="margin-bottom:0;">
					<?php echo esc_html__( 'This plugin connects your WordPress site to smoxy and keeps the edge cache in sync — automatically purging pages when content changes and giving you one-click control from the admin bar.', 'smoxy' ); ?>
				</p>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SETTINGS_SLUG );
				submit_button();
				?>
			</form>

			<hr/>

			<h2><?php echo esc_html__( 'Cache', 'smoxy' ); ?></h2>

			<h3><?php echo esc_html__( 'Purge all', 'smoxy' ); ?></h3>
			<p><?php echo esc_html__( 'Remove all cached pages from smoxy Proxy. The next request for each page will be regenerated.', 'smoxy' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_ACTION ); ?>"/>
				<?php wp_nonce_field( self::PURGE_ACTION ); ?>
				<?php submit_button( __( 'Purge all cache', 'smoxy' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3 style="margin-top:24px;"><?php echo esc_html__( 'Purge by URL', 'smoxy' ); ?></h3>
			<p><?php echo esc_html__( 'Remove a single cached resource by its URL — works for any cacheable asset: pages, images, JS, CSS, fonts, and other static files. Accepts a full URL, a site-relative path (e.g. /sample-page/), or leave blank for the home page.', 'smoxy' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_URL_ACTION ); ?>"/>
				<?php wp_nonce_field( self::PURGE_URL_ACTION ); ?>
				<p>
					<label for="smoxy_purge_url"
							class="screen-reader-text"><?php echo esc_html__( 'URL to purge', 'smoxy' ); ?></label>
					<input type="text" id="smoxy_purge_url" name="smoxy_purge_url" class="regular-text"
							placeholder="/sample-page/"/>
				</p>
				<?php submit_button( __( 'Purge URL', 'smoxy' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3 style="margin-top:24px;"><?php echo esc_html__( 'Purge by tag', 'smoxy' ); ?></h3>
			<p><?php echo esc_html__( 'Purge every page carrying one or more cache tags. Enter a single tag or a comma-separated list (e.g. p-42, t-7, home).', 'smoxy' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_TAG_ACTION ); ?>"/>
				<?php wp_nonce_field( self::PURGE_TAG_ACTION ); ?>
				<p>
					<label for="smoxy_purge_tag"
							class="screen-reader-text"><?php echo esc_html__( 'Tags to purge', 'smoxy' ); ?></label>
					<input type="text" id="smoxy_purge_tag" name="smoxy_purge_tag" class="regular-text"
							placeholder="p-42, t-7, home" required/>
				</p>
				<?php submit_button( __( 'Purge tags', 'smoxy' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_purge_url(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'smoxy' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PURGE_URL_ACTION );

		$url    = isset( $_POST['smoxy_purge_url'] ) ? sanitize_text_field( wp_unslash( $_POST['smoxy_purge_url'] ) ) : '';
		$result = ( new Purger() )->purge_url( $url );

		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $result, 60 );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_purge_tag(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'smoxy' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PURGE_TAG_ACTION );

		$raw  = isset( $_POST['smoxy_purge_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['smoxy_purge_tag'] ) ) : '';
		$tags = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) ),
				static fn( string $t ): bool => '' !== $t
			)
		);

		if ( empty( $tags ) ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'Please enter at least one tag.', 'smoxy' ),
			);
		} else {
			$result = ( new Purger() )->purge_tags( $tags );
		}

		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $result, 60 );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'smoxy' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PURGE_ACTION );

		$result = ( new Purger() )->purge_all();
		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $result, 60 );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_notice(): void {
		$key    = self::NOTICE_KEY . '_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$ok = array_key_exists( 'ok', $notice ) ? $notice['ok'] : false;
		if ( true === $ok ) {
			$class = 'notice-success';
		} elseif ( null === $ok ) {
			$class = 'notice-warning';
		} else {
			$class = 'notice-error';
		}
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( (string) ( $notice['message'] ?? '' ) )
		);
	}
}
