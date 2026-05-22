<?php

namespace Smoxy\WP;

use Smoxy\WP\Api\Client;
use Smoxy\WP\Setup\Audit;
use Smoxy\WP\Setup\Bootstrap;
use Smoxy\WP\Setup\RuleDefinitions;

defined( 'ABSPATH' ) || exit;

class Settings {


	public const OPTION_GROUP            = 'smoxy_settings';
	public const OPTION_NAME             = 'smoxy_settings';
	public const MENU_SLUG               = 'smoxy';
	public const SETTINGS_SLUG           = 'smoxy-settings';
	public const PURGE_ACTION            = 'smoxy_purge_all';
	public const PURGE_URL_ACTION        = 'smoxy_purge_url';
	public const PURGE_TAG_ACTION        = 'smoxy_purge_tag';
	public const SAVE_TOKEN_ACTION       = 'smoxy_save_token';
	public const CONNECT_ACTION          = 'smoxy_connect';
	public const DISCONNECT_ACTION       = 'smoxy_disconnect';
	public const RECREATE_RULE_ACTION    = 'smoxy_recreate_rule';
	public const MOVE_HOSTNAME_ACTION    = 'smoxy_move_hostname';
	public const DISMISS_HOSTNAME_ACTION = 'smoxy_dismiss_hostname_move';
	public const NOTICE_KEY              = 'smoxy_purge_notice';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
		add_action( 'admin_post_' . self::PURGE_URL_ACTION, array( $this, 'handle_purge_url' ) );
		add_action( 'admin_post_' . self::PURGE_TAG_ACTION, array( $this, 'handle_purge_tag' ) );
		add_action( 'admin_post_' . self::SAVE_TOKEN_ACTION, array( $this, 'handle_save_token' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::RECREATE_RULE_ACTION, array( $this, 'handle_recreate_rule' ) );
		add_action( 'admin_post_' . self::MOVE_HOSTNAME_ACTION, array( $this, 'handle_move_hostname' ) );
		add_action( 'admin_post_' . self::DISMISS_HOSTNAME_ACTION, array( $this, 'handle_dismiss_hostname_move' ) );
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
				'title' => '<span class="ab-icon dashicons dashicons-cloud" style="margin-top:2px"></span> ' . esc_html__( 'Purge smoxy cache', 'smoxy' ),
				'href'  => $url,
				'meta'  => array(
					'title'   => __( 'Purge all pages from smoxy', 'smoxy' ),
					'onclick' => "return confirm('" . esc_js( __( 'Purge all cached pages from smoxy?', 'smoxy' ) ) . "');",
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Option accessors
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	public static function get_options(): array {
		$options = get_option( self::OPTION_NAME, array() );
		return is_array( $options ) ? $options : array();
	}

	public static function get_api_token(): string {
		$options = self::get_options();
		return isset( $options['api_token'] ) ? (string) $options['api_token'] : '';
	}

	public static function get_organization_id(): int {
		$options = self::get_options();
		return isset( $options['organization_id'] ) ? (int) $options['organization_id'] : 0;
	}

	public static function get_zone_id(): int {
		$options = self::get_options();
		return isset( $options['zone_id'] ) ? (int) $options['zone_id'] : 0;
	}

	/**
	 * BAN secret used by Purger to call ingress.smoxy.eu. Prefers the new
	 * `zone_secret` populated by the API setup; falls back to the legacy
	 * `secret_key` so installs that pasted the token by hand keep working
	 * until they re-run setup.
	 */
	public static function get_secret_key(): string {
		$options = self::get_options();
		if ( isset( $options['zone_secret'] ) && is_string( $options['zone_secret'] ) && '' !== $options['zone_secret'] ) {
			return $options['zone_secret'];
		}
		return isset( $options['secret_key'] ) ? (string) $options['secret_key'] : '';
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public static function update_options( array $patch ): void {
		$current = self::get_options();
		update_option( self::OPTION_NAME, array_merge( $current, $patch ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'smoxy', 'smoxy' ),
			__( 'smoxy', 'smoxy' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cloud',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'smoxy Settings', 'smoxy' ),
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
				'default'           => array(),
			)
		);
	}

	/**
	 * register_setting() runs this on every update_option() call — not just
	 * options.php form submits — so it must be a true pass-through. The
	 * admin-post handlers already validated whatever they wrote; dropping any
	 * field here would silently lose state (e.g. api_token after token save).
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_options();
		}
		if ( isset( $input['secret_key'] ) ) {
			$input['secret_key'] = trim( (string) $input['secret_key'] );
		}
		return $input;
	}

	/* ------------------------------------------------------------------
	 * Page rendering
	 * ------------------------------------------------------------------ */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'smoxy', 'smoxy' ) . '</h1>';
		$this->render_intro();

		$token = self::get_api_token();
		if ( '' === $token ) {
			$this->render_step_token();
		} else {
			$this->render_step_connected_or_picker( $token );
		}

		echo '</div>';
	}

	private function render_intro(): void {
		?>
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
				<?php echo esc_html__( 'This plugin connects WordPress to smoxy, keeps the edge cache in sync, and installs the bypass rules needed to keep logged-in users, the admin area and WooCommerce flows out of cache.', 'smoxy' ); ?>
			</p>
		</div>
		<?php
	}

	private function render_step_token(): void {
		?>
		<h2><?php echo esc_html__( '1. Connect to smoxy', 'smoxy' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: link to the smoxy hub */
				esc_html__( 'Create an API token in your %s and paste it below. The plugin will use it to set up the zone, register this site\'s hostname and install the cache bypass rules.', 'smoxy' ),
				'<a href="https://hub.smoxy.eu" target="_blank" rel="noopener noreferrer">' . esc_html__( 'smoxy hub', 'smoxy' ) . '</a>'
			);
			?>
		</p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_TOKEN_ACTION ); ?>"/>
			<?php wp_nonce_field( self::SAVE_TOKEN_ACTION ); ?>
			<table class="form-table" role="presentation"><tbody><tr>
				<th scope="row"><label for="smoxy_api_token"><?php echo esc_html__( 'API token', 'smoxy' ); ?></label></th>
				<td>
					<input type="password" id="smoxy_api_token" name="api_token" value="" class="regular-text" autocomplete="new-password" required/>
					<p class="description"><?php echo esc_html__( 'The token is sent only to hub.smoxy.eu over HTTPS and stored as a WordPress option.', 'smoxy' ); ?></p>
				</td>
			</tr></tbody></table>
			<?php submit_button( __( 'Validate & continue', 'smoxy' ) ); ?>
		</form>
		<?php
	}

	private function render_step_connected_or_picker( string $token ): void {
		$zone_id = self::get_zone_id();
		if ( $zone_id > 0 ) {
			$this->render_step_connected( $token, $zone_id );
			return;
		}
		$this->render_step_picker( $token );
	}

	private function render_step_picker( string $token ): void {
		$client = new Client( $token );

		$orgs_response = $client->list_organizations();
		if ( ! $orgs_response['ok'] ) {
			$this->render_api_error( __( 'Could not load your organizations.', 'smoxy' ), $orgs_response['error'] ?? '' );
			$this->render_disconnect_button( __( 'Use a different token', 'smoxy' ) );
			return;
		}
		$organizations = $this->extract_members( $orgs_response['body'] );
		if ( empty( $organizations ) ) {
			echo '<p>' . esc_html__( 'Your account has no organizations yet. Create one in the smoxy hub, then come back.', 'smoxy' ) . '</p>';
			$this->render_disconnect_button( __( 'Use a different token', 'smoxy' ) );
			return;
		}

		$selected_org = self::get_organization_id();
		if ( 0 === $selected_org ) {
			$first = $organizations[0];
			if ( is_array( $first ) && isset( $first['id'] ) ) {
				$selected_org = (int) $first['id'];
			}
		}

		$zones_response = $client->list_zones( $selected_org );
		if ( ! $zones_response['ok'] ) {
			$this->render_api_error( __( 'Could not load zones for this organization.', 'smoxy' ), $zones_response['error'] ?? '' );
			$this->render_disconnect_button( __( 'Use a different token', 'smoxy' ) );
			return;
		}
		$zones = $this->extract_members( $zones_response['body'] );

		$origins_response = $client->list_origins( $selected_org );
		$origins          = $origins_response['ok'] ? $this->extract_members( $origins_response['body'] ) : array();
		$has_origins      = ! empty( $origins );

		$site_host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_scheme    = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$server_addr    = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		$default_origin = array(
			'name'         => '' !== $site_host ? $site_host : 'wordpress',
			'protocol'     => 'https' === $site_scheme ? 'https' : 'http',
			'address'      => $server_addr,
			'port'         => 'https' === $site_scheme ? 443 : 80,
			'request_host' => $site_host,
		);

		?>
		<h2><?php echo esc_html__( '2. Pick a zone', 'smoxy' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="smoxy-connect-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>"/>
			<?php wp_nonce_field( self::CONNECT_ACTION ); ?>

			<table class="form-table" role="presentation"><tbody>
				<?php if ( count( $organizations ) > 1 ) : ?>
				<tr>
					<th scope="row"><label for="smoxy_org"><?php echo esc_html__( 'Organization', 'smoxy' ); ?></label></th>
					<td>
						<select name="organization_id" id="smoxy_org" onchange="this.form.submit()">
							<?php foreach ( $organizations as $org ) : ?>
								<?php
								if ( ! is_array( $org ) || ! isset( $org['id'], $org['name'] ) ) {
									continue; }
								?>
								<option value="<?php echo esc_attr( (string) $org['id'] ); ?>" <?php selected( $selected_org, (int) $org['id'] ); ?>>
									<?php echo esc_html( (string) $org['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Changing the organization reloads zones and origins.', 'smoxy' ); ?></p>
					</td>
				</tr>
				<?php else : ?>
				<input type="hidden" name="organization_id" value="<?php echo esc_attr( (string) $selected_org ); ?>"/>
				<?php endif; ?>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Zone', 'smoxy' ); ?></th>
					<td>
						<label>
							<input type="radio" name="zone_choice" value="existing" data-smoxy-zone-choice checked/>
							<?php echo esc_html__( 'Use existing zone', 'smoxy' ); ?>
						</label>
						<div id="smoxy-zone-existing" style="margin-left:24px;margin-top:8px;">
							<select name="zone_id">
								<option value=""><?php echo esc_html__( '— pick a zone —', 'smoxy' ); ?></option>
								<?php foreach ( $zones as $zone ) : ?>
									<?php
									if ( ! is_array( $zone ) || ! isset( $zone['id'], $zone['name'] ) ) {
										continue; }
									?>
									<option value="<?php echo esc_attr( (string) $zone['id'] ); ?>">
										<?php echo esc_html( (string) $zone['name'] . ' (' . ( $zone['tag'] ?? '' ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Existing zones already have an origin attached — the plugin reuses it.', 'smoxy' ); ?></p>
						</div>

						<br/>

						<label>
							<input type="radio" name="zone_choice" value="new" data-smoxy-zone-choice/>
							<?php echo esc_html__( 'Create a new zone', 'smoxy' ); ?>
						</label>
						<div id="smoxy-zone-new" style="margin-left:24px;margin-top:8px;display:none;">
							<p>
								<label><?php echo esc_html__( 'Name', 'smoxy' ); ?>
									<input type="text" name="new_zone_name" value="<?php echo esc_attr( $site_host ); ?>" class="regular-text"/>
								</label>
							</p>
							<p>
								<label><?php echo esc_html__( 'Tag', 'smoxy' ); ?>
									<select name="new_zone_tag">
										<option value="Prod" selected>Prod</option>
										<option value="Dev">Dev</option>
									</select>
								</label>
							</p>

							<h4 style="margin-top:16px;"><?php echo esc_html__( 'Origin', 'smoxy' ); ?></h4>
							<?php if ( $has_origins ) : ?>
							<label>
								<input type="radio" name="origin_choice" value="existing" data-smoxy-origin-choice checked/>
								<?php echo esc_html__( 'Use existing origin', 'smoxy' ); ?>
							</label>
							<div id="smoxy-origin-existing" style="margin-left:24px;margin-top:4px;">
								<select name="origin_id">
									<option value=""><?php echo esc_html__( '— pick an origin —', 'smoxy' ); ?></option>
									<?php foreach ( $origins as $origin ) : ?>
										<?php
										if ( ! is_array( $origin ) || ! isset( $origin['id'], $origin['name'] ) ) {
											continue; }
										?>
										<option value="<?php echo esc_attr( (string) $origin['id'] ); ?>">
											<?php echo esc_html( sprintf( '%s — %s://%s:%s', (string) $origin['name'], (string) ( $origin['protocol'] ?? '' ), (string) ( $origin['address'] ?? '' ), (string) ( $origin['port'] ?? '' ) ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<br/>

							<label>
								<input type="radio" name="origin_choice" value="new" data-smoxy-origin-choice/>
								<?php echo esc_html__( 'Create a new origin from this WordPress site', 'smoxy' ); ?>
							</label>
							<?php else : ?>
							<input type="hidden" name="origin_choice" value="new"/>
							<p class="description" style="margin-top:0;"><?php echo esc_html__( 'No origins exist in this organization yet — the plugin will create one from this WordPress site.', 'smoxy' ); ?></p>
							<?php endif; ?>

							<div id="smoxy-origin-new" style="margin-left:<?php echo $has_origins ? '24' : '0'; ?>px;margin-top:4px;display:<?php echo $has_origins ? 'none' : 'block'; ?>;">
								<p>
									<label><?php echo esc_html__( 'Name', 'smoxy' ); ?>
										<input type="text" name="new_origin_name" value="<?php echo esc_attr( $default_origin['name'] ); ?>" class="regular-text"/>
									</label>
								</p>
								<p>
									<label><?php echo esc_html__( 'Protocol', 'smoxy' ); ?>
										<select name="new_origin_protocol">
											<option value="https" <?php selected( $default_origin['protocol'], 'https' ); ?>>https</option>
											<option value="http"  <?php selected( $default_origin['protocol'], 'http' ); ?>>http</option>
										</select>
									</label>
									&nbsp;
									<label><?php echo esc_html__( 'Server IP', 'smoxy' ); ?>
										<input type="text" name="new_origin_address" value="<?php echo esc_attr( $default_origin['address'] ); ?>" class="regular-text" placeholder="203.0.113.10"/>
									</label>
									&nbsp;
									<label><?php echo esc_html__( 'Port', 'smoxy' ); ?>
										<input type="number" name="new_origin_port" value="<?php echo esc_attr( (string) $default_origin['port'] ); ?>" min="1" max="65535"/>
									</label>
								</p>
								<p class="description" style="margin-top:-4px;">
									<?php echo esc_html__( 'Server IP is the address smoxy will connect to. We prefilled it from $_SERVER[SERVER_ADDR]; correct it if your origin is reached via a different IP.', 'smoxy' ); ?>
								</p>
								<p>
									<label><?php echo esc_html__( 'Request hostname (Host header)', 'smoxy' ); ?>
										<input type="text" name="new_origin_request_host" value="<?php echo esc_attr( $default_origin['request_host'] ); ?>" class="regular-text"/>
									</label>
								</p>
							</div>
						</div>
					</td>
				</tr>
			</tbody></table>

			<?php submit_button( __( 'Connect this site to smoxy', 'smoxy' ) ); ?>
		</form>

		<script>
		(function () {
			var form = document.getElementById('smoxy-connect-form');
			if (!form) return;
			var zoneExisting = form.querySelector('#smoxy-zone-existing');
			var zoneNew      = form.querySelector('#smoxy-zone-new');
			var originExisting = form.querySelector('#smoxy-origin-existing');
			var originNew      = form.querySelector('#smoxy-origin-new');

			function toggleZone() {
				var choice = (form.querySelector('input[name="zone_choice"]:checked') || {}).value;
				if (zoneExisting) zoneExisting.style.display = (choice === 'existing') ? '' : 'none';
				if (zoneNew)      zoneNew.style.display      = (choice === 'new')      ? '' : 'none';
			}
			function toggleOrigin() {
				var picked = form.querySelector('input[name="origin_choice"]:checked');
				if (!picked) return;
				if (originExisting) originExisting.style.display = (picked.value === 'existing') ? '' : 'none';
				if (originNew)      originNew.style.display      = (picked.value === 'new')      ? '' : 'none';
			}
			form.querySelectorAll('input[data-smoxy-zone-choice]').forEach(function (r) { r.addEventListener('change', toggleZone); });
			form.querySelectorAll('input[data-smoxy-origin-choice]').forEach(function (r) { r.addEventListener('change', toggleOrigin); });
			toggleZone();
			toggleOrigin();
		})();
		</script>

		<p>
			<?php $this->render_disconnect_button( __( 'Use a different token', 'smoxy' ) ); ?>
		</p>
		<?php
	}

	private function render_step_connected( string $token, int $zone_id ): void {
		$client = new Client( $token );
		$audit  = new Audit( $client );
		$report = $audit->audit_zone( $zone_id );

		$zone_response = $client->get_zone( $zone_id );
		$zone_name     = '';
		$zone_tag      = '';
		if ( $zone_response['ok'] ) {
			$zone_name = isset( $zone_response['body']['name'] ) ? (string) $zone_response['body']['name'] : '';
			$zone_tag  = isset( $zone_response['body']['tag'] ) ? (string) $zone_response['body']['tag'] : '';
		}

		$this->render_hostname_move_warning( $zone_id, $zone_name );
		?>
		<h2><?php echo esc_html__( 'Connected', 'smoxy' ); ?></h2>
		<table class="widefat striped" style="max-width:720px;">
			<tbody>
				<tr><th><?php echo esc_html__( 'Zone', 'smoxy' ); ?></th><td><?php echo esc_html( $zone_name ); ?> <?php echo $zone_tag ? '<code>' . esc_html( $zone_tag ) . '</code>' : ''; ?> <span class="description">#<?php echo esc_html( (string) $zone_id ); ?></span></td></tr>
				<tr><th><?php echo esc_html__( 'WordPress hostname', 'smoxy' ); ?></th><td><code><?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code></td></tr>
				<tr><th><?php echo esc_html__( 'Logged-in cookie', 'smoxy' ); ?></th><td><code><?php echo esc_html( RuleDefinitions::logged_in_cookie_name() ); ?></code> <span class="description"><?php echo esc_html__( 'Used by the bypass rule below.', 'smoxy' ); ?></span></td></tr>
			</tbody>
		</table>

		<h2 style="margin-top:24px;"><?php echo esc_html__( 'Cache bypass rules', 'smoxy' ); ?></h2>
		<?php if ( ! $report['ok'] ) : ?>
			<?php $this->render_api_error( __( 'Could not audit the bypass rules.', 'smoxy' ), $report['error'] ?? '' ); ?>
		<?php else : ?>
			<table class="widefat striped" style="max-width:960px;">
				<thead><tr>
					<th><?php echo esc_html__( 'Rule', 'smoxy' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'smoxy' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'smoxy' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( RuleDefinitions::all() as $key => $expected ) : ?>
						<?php
						$row    = $report['rules'][ $key ] ?? array(
							'status' => Audit::STATUS_MISSING,
							'diff'   => null,
						);
						$status = (string) ( $row['status'] ?? Audit::STATUS_MISSING );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $expected['name'] ); ?></strong><br/>
								<span class="description"><?php echo esc_html( $expected['description'] ); ?></span>
							</td>
							<td>
								<?php $this->render_status_badge( $status, isset( $row['diff'] ) ? (string) $row['diff'] : '' ); ?>
							</td>
							<td>
								<?php if ( Audit::STATUS_OK !== $status ) : ?>
									<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::RECREATE_RULE_ACTION ); ?>"/>
										<input type="hidden" name="rule_key" value="<?php echo esc_attr( $key ); ?>"/>
										<?php wp_nonce_field( self::RECREATE_RULE_ACTION ); ?>
										<?php submit_button( Audit::STATUS_MISSING === $status ? __( 'Create', 'smoxy' ) : __( 'Fix', 'smoxy' ), 'secondary small', 'submit', false ); ?>
									</form>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p style="margin-top:16px;">
			<?php $this->render_disconnect_button( __( 'Disconnect from smoxy', 'smoxy' ) ); ?>
		</p>

		<hr/>

		<h2><?php echo esc_html__( 'Cache', 'smoxy' ); ?></h2>
		<?php $this->render_purge_controls(); ?>
		<?php
	}

	private function render_status_badge( string $status, string $detail ): void {
		switch ( $status ) {
			case Audit::STATUS_OK:
				echo '<span style="color:#1a7f37;font-weight:600;">● ' . esc_html__( 'OK', 'smoxy' ) . '</span>';
				return;
			case Audit::STATUS_DRIFTED:
				echo '<span style="color:#9a6700;font-weight:600;">● ' . esc_html__( 'Drifted', 'smoxy' ) . '</span>';
				if ( '' !== $detail ) {
					echo '<br/><span class="description">' . esc_html( $detail ) . '</span>';
				}
				return;
			default:
				echo '<span style="color:#cf222e;font-weight:600;">● ' . esc_html__( 'Missing', 'smoxy' ) . '</span>';
		}
	}

	private function render_purge_controls(): void {
		?>
		<h3><?php echo esc_html__( 'Purge all', 'smoxy' ); ?></h3>
		<p><?php echo esc_html__( 'Remove all cached pages from smoxy. The next request for each page will be regenerated.', 'smoxy' ); ?></p>
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
		<?php
	}

	private function render_disconnect_button( string $label ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>"/>
			<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
			<?php submit_button( $label, 'link-delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_hostname_move_warning( int $zone_id, string $zone_name ): void {
		$options = self::get_options();
		$pending = $options['pending_hostname_move'] ?? null;
		if ( ! is_array( $pending ) || empty( $pending['hostname_id'] ) ) {
			return;
		}

		$hostname         = isset( $pending['hostname'] ) ? (string) $pending['hostname'] : '';
		$existing_zone_id = isset( $pending['existing_zone_id'] ) ? (int) $pending['existing_zone_id'] : 0;
		$zone_label       = '' !== $zone_name ? sprintf( '%s (#%d)', $zone_name, $zone_id ) : '#' . $zone_id;
		?>
		<div class="notice notice-warning inline" style="padding:12px 16px;margin:15px 0;">
			<p style="margin-top:0;">
				<strong><?php echo esc_html__( 'Hostname already attached to another zone', 'smoxy' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: hostname, 2: existing zone id, 3: newly created zone label */
					esc_html__( 'The hostname %1$s is currently attached to zone #%2$s on smoxy. To route this WordPress site through the newly created zone (%3$s), move the hostname to it.', 'smoxy' ),
					'<code>' . esc_html( $hostname ) . '</code>',
					esc_html( (string) $existing_zone_id ),
					'<code>' . esc_html( $zone_label ) . '</code>'
				);
				?>
			</p>
			<p style="margin-bottom:0;">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::MOVE_HOSTNAME_ACTION ); ?>"/>
					<?php wp_nonce_field( self::MOVE_HOSTNAME_ACTION ); ?>
					<?php submit_button( __( 'Move hostname to this zone', 'smoxy' ), 'primary small', 'submit', false ); ?>
				</form>
				&nbsp;
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DISMISS_HOSTNAME_ACTION ); ?>"/>
					<?php wp_nonce_field( self::DISMISS_HOSTNAME_ACTION ); ?>
					<?php submit_button( __( 'Dismiss', 'smoxy' ), 'link', 'submit', false ); ?>
				</form>
			</p>
		</div>
		<?php
	}

	private function render_api_error( string $headline, string $detail ): void {
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html( $headline ) . '</strong></p>';
		if ( '' !== $detail ) {
			echo '<p>' . esc_html( $detail ) . '</p>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * admin-post handlers
	 * ------------------------------------------------------------------ */

	public function handle_save_token(): void {
		// Nonce + capability verified in require_caps().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->require_caps( self::SAVE_TOKEN_ACTION );
		$token = isset( $_POST['api_token'] ) ? trim( (string) wp_unslash( $_POST['api_token'] ) ) : '';
		if ( '' === $token ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'API token cannot be empty.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		// /api/internal/me requires JWT auth and 401s on X-API-TOKEN. Use
		// list-organizations as the validation probe — it accepts X-API-TOKEN
		// and we need the result on the next step anyway.
		$client = new Client( $token );
		$probe  = $client->list_organizations();
		if ( ! $probe['ok'] ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'Token rejected by smoxy hub.', 'smoxy' ) . ' ' . ( $probe['error'] ?? '' ),
				)
			);
			$this->redirect_back();
		}

		self::update_options(
			array(
				'api_token'       => $token,
				'organization_id' => 0,
				'zone_id'         => 0,
				'zone_secret'     => '',
			)
		);

		$this->flash(
			array(
				'ok'      => true,
				'message' => __( 'API token validated. Pick a zone to finish setup.', 'smoxy' ),
			)
		);
		$this->redirect_back();
	}

	public function handle_connect(): void {
		// Nonce + capability verified in require_caps().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->require_caps( self::CONNECT_ACTION );

		$org_id      = isset( $_POST['organization_id'] ) ? (int) $_POST['organization_id'] : 0;
		$zone_choice = isset( $_POST['zone_choice'] ) ? (string) wp_unslash( $_POST['zone_choice'] ) : '';

		if ( $org_id <= 0 ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'Please pick an organization.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		self::update_options( array( 'organization_id' => $org_id ) );

		// "Apply organization" pseudo-submit (the org select onchange fires the form before the user can pick a zone).
		// Detect by absence of zone_choice — just save the org and reload.
		if ( '' === $zone_choice ) {
			$this->redirect_back();
		}

		$input = array(
			'organization_id' => $org_id,
			'zone_id'         => null,
			'new_zone_name'   => null,
			'new_zone_tag'    => null,
			'origin_id'       => null,
			'new_origin'      => null,
		);

		if ( 'existing' === $zone_choice ) {
			$zone_id = isset( $_POST['zone_id'] ) ? (int) $_POST['zone_id'] : 0;
			if ( $zone_id <= 0 ) {
				$this->flash(
					array(
						'ok'      => false,
						'message' => __( 'Please pick a zone or choose "Create a new zone".', 'smoxy' ),
					)
				);
				$this->redirect_back();
			}
			$input['zone_id'] = $zone_id;
		} else {
			$input['new_zone_name'] = isset( $_POST['new_zone_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_zone_name'] ) ) : '';
			$input['new_zone_tag']  = isset( $_POST['new_zone_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['new_zone_tag'] ) ) : 'Prod';

			$origin_choice = isset( $_POST['origin_choice'] ) ? (string) wp_unslash( $_POST['origin_choice'] ) : '';
			if ( 'existing' === $origin_choice ) {
				$input['origin_id'] = isset( $_POST['origin_id'] ) ? (int) $_POST['origin_id'] : 0;
				if ( ! $input['origin_id'] ) {
					$this->flash(
						array(
							'ok'      => false,
							'message' => __( 'Please pick an origin or choose "Create a new origin".', 'smoxy' ),
						)
					);
					$this->redirect_back();
				}
			} else {
				$input['new_origin'] = array(
					'name'            => isset( $_POST['new_origin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_origin_name'] ) ) : '',
					'protocol'        => isset( $_POST['new_origin_protocol'] ) && 'http' === $_POST['new_origin_protocol'] ? 'http' : 'https',
					'address'         => isset( $_POST['new_origin_address'] ) ? sanitize_text_field( wp_unslash( $_POST['new_origin_address'] ) ) : '',
					'port'            => isset( $_POST['new_origin_port'] ) ? (int) $_POST['new_origin_port'] : 443,
					'requestHostname' => isset( $_POST['new_origin_request_host'] ) ? sanitize_text_field( wp_unslash( $_POST['new_origin_request_host'] ) ) : null,
				);
				if ( null !== $input['new_origin']['requestHostname'] && '' === $input['new_origin']['requestHostname'] ) {
					$input['new_origin']['requestHostname'] = null;
				}
			}
		}

		$client    = new Client( self::get_api_token() );
		$bootstrap = new Bootstrap( $client );
		$result    = $bootstrap->run( $input );

		if ( ! $result['ok'] ) {
			$message = $result['message'];
			if ( ! empty( $result['steps'] ) ) {
				$message .= "\n" . implode( "\n", $result['steps'] );
			}
			$this->flash(
				array(
					'ok'      => false,
					'message' => $message,
				)
			);
			$this->redirect_back();
		}

		self::update_options(
			array(
				'zone_id'               => $result['zone_id'],
				'zone_secret'           => $result['secret_token'],
				'pending_hostname_move' => $result['hostname_conflict'] ?? null,
			)
		);

		$summary = $result['message'] . "\n" . implode( "\n", $result['steps'] );
		$this->flash(
			array(
				'ok'      => true,
				'message' => $summary,
			)
		);
		$this->redirect_back();
	}

	public function handle_disconnect(): void {
		$this->require_caps( self::DISCONNECT_ACTION );
		self::update_options(
			array(
				'api_token'       => '',
				'organization_id' => 0,
				'zone_id'         => 0,
				'zone_secret'     => '',
			)
		);
		$this->flash(
			array(
				'ok'      => true,
				'message' => __( 'Disconnected from smoxy.', 'smoxy' ),
			)
		);
		$this->redirect_back();
	}

	public function handle_recreate_rule(): void {
		// Nonce + capability verified in require_caps().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->require_caps( self::RECREATE_RULE_ACTION );
		$key = isset( $_POST['rule_key'] ) ? sanitize_key( wp_unslash( $_POST['rule_key'] ) ) : '';
		$all = RuleDefinitions::all();
		if ( ! isset( $all[ $key ] ) ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'Unknown rule.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		$zone_id = self::get_zone_id();
		if ( $zone_id <= 0 ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'No zone is connected.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		$client          = new Client( self::get_api_token() );
		$audit           = new Audit( $client );
		$report          = $audit->audit_zone( $zone_id );
		$status          = $report['ok'] ? ( $report['rules'][ $key ]['status'] ?? Audit::STATUS_MISSING ) : Audit::STATUS_MISSING;
		$remote_id       = $report['ok'] ? ( $report['rules'][ $key ]['remote_id'] ?? null ) : null;
		$remote_position = $report['ok'] ? ( $report['rules'][ $key ]['remote_position'] ?? null ) : null;

		if ( Audit::STATUS_DRIFTED === $status && null !== $remote_id ) {
			$result = $client->patch_conditional_rule( $zone_id, (int) $remote_id, $all[ $key ]['payload'] );
		} else {
			$result          = $client->create_conditional_rule( $zone_id, $all[ $key ]['payload'] );
			$remote_id       = isset( $result['body']['id'] ) && is_numeric( $result['body']['id'] ) ? (int) $result['body']['id'] : $remote_id;
			$remote_position = isset( $result['body']['position'] ) && is_numeric( $result['body']['position'] ) ? (int) $result['body']['position'] : $remote_position;
		}

		if ( ! $result['ok'] ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => $result['error'] ?? __( 'Could not update the rule.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		$expected_position = $all[ $key ]['expected_position'] ?? null;
		if ( null !== $expected_position && null !== $remote_id && $expected_position !== $remote_position ) {
			$move = $client->patch_conditional_rule_position( $zone_id, (int) $remote_id, (int) $expected_position );
			if ( ! $move['ok'] ) {
				$this->flash(
					array(
						'ok'      => false,
						'message' => $move['error'] ?? __( 'Could not reorder the rule.', 'smoxy' ),
					)
				);
				$this->redirect_back();
			}
		}

		$this->flash(
			array(
				'ok'      => true,
				'message' => sprintf(
				/* translators: %s: rule name */
					__( 'Rule "%s" is in sync.', 'smoxy' ),
					$all[ $key ]['name']
				),
			)
		);
		$this->redirect_back();
	}

	public function handle_move_hostname(): void {
		$this->require_caps( self::MOVE_HOSTNAME_ACTION );

		$options = self::get_options();
		$pending = $options['pending_hostname_move'] ?? null;
		if ( ! is_array( $pending ) || empty( $pending['hostname_id'] ) ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'No pending hostname move.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		$zone_id = self::get_zone_id();
		if ( $zone_id <= 0 ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'No zone is connected.', 'smoxy' ),
				)
			);
			$this->redirect_back();
		}

		$client = new Client( self::get_api_token() );
		$result = $client->patch_hostname(
			(int) $pending['hostname_id'],
			array( 'zone' => $zone_id )
		);
		if ( ! $result['ok'] ) {
			$this->flash(
				array(
					'ok'      => false,
					'message' => __( 'Could not move the hostname.', 'smoxy' ) . ' ' . ( $result['error'] ?? '' ),
				)
			);
			$this->redirect_back();
		}

		self::update_options( array( 'pending_hostname_move' => null ) );
		$this->flash(
			array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: hostname */
					__( 'Moved hostname %s to this zone.', 'smoxy' ),
					(string) ( $pending['hostname'] ?? '' )
				),
			)
		);
		$this->redirect_back();
	}

	public function handle_dismiss_hostname_move(): void {
		$this->require_caps( self::DISMISS_HOSTNAME_ACTION );
		self::update_options( array( 'pending_hostname_move' => null ) );
		$this->redirect_back();
	}

	public function handle_purge_url(): void {
		// Nonce + capability verified in require_caps().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->require_caps( self::PURGE_URL_ACTION );
		$url    = isset( $_POST['smoxy_purge_url'] ) ? sanitize_text_field( wp_unslash( $_POST['smoxy_purge_url'] ) ) : '';
		$result = ( new Purger() )->purge_url( $url );
		$this->flash( $result );
		$this->redirect_back();
	}

	public function handle_purge_tag(): void {
		// Nonce + capability verified in require_caps().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->require_caps( self::PURGE_TAG_ACTION );

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

		$this->flash( $result );
		$this->redirect_back();
	}

	public function handle_purge(): void {
		$this->require_caps( self::PURGE_ACTION );
		$result = ( new Purger() )->purge_all();
		$this->flash( $result );
		$this->redirect_back();
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
			'<div class="notice %s is-dismissible"><pre style="white-space:pre-wrap;margin:0;">%s</pre></div>',
			esc_attr( $class ),
			esc_html( (string) ( $notice['message'] ?? '' ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function require_caps( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'smoxy' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array{ok:bool|null, message:string} $notice
	 */
	private function flash( array $notice ): void {
		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $notice, 60 );
	}

	private function redirect_back(): void {
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @param array<int|string,mixed> $body
	 * @return list<mixed>
	 */
	private function extract_members( array $body ): array {
		foreach ( array( 'member', 'hydra:member' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return array_values( $body[ $key ] );
			}
		}
		if ( ! empty( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			return array_values( $body );
		}
		return array();
	}
}
