<?php
/**
 * Settings → River Conditions.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions;

use RiverConditions\Cache\GaugeRepository;

/**
 * Stores plugin settings in one option and renders the settings page with
 * the Settings API.
 */
final class Settings {

	public const OPTION     = 'rivcon_settings';
	public const PAGE       = 'river-conditions';
	public const GROUP      = 'rivcon_settings_group';
	public const CLEAR_HOOK = 'rivcon_clear_cache';

	public const MIN_CACHE_MINUTES = 5;
	public const MAX_CACHE_MINUTES = 120;

	/**
	 * Defaults for every setting.
	 *
	 * @return array{api_key: string, cache_minutes: int, temp_unit: string, show_attribution: bool}
	 */
	public static function defaults(): array {
		return array(
			'api_key'          => '',
			'cache_minutes'    => 15,
			'temp_unit'        => 'F',
			'show_attribution' => true,
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array{api_key: string, cache_minutes: int, temp_unit: string, show_attribution: bool}
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::defaults();

		if ( isset( $saved['api_key'] ) && is_string( $saved['api_key'] ) ) {
			$out['api_key'] = $saved['api_key'];
		}
		if ( isset( $saved['cache_minutes'] ) ) {
			$out['cache_minutes'] = self::clamp_minutes( $saved['cache_minutes'] );
		}
		if ( isset( $saved['temp_unit'] ) && in_array( $saved['temp_unit'], array( 'F', 'C' ), true ) ) {
			$out['temp_unit'] = $saved['temp_unit'];
		}
		if ( isset( $saved['show_attribution'] ) ) {
			$out['show_attribution'] = (bool) $saved['show_attribution'];
		}
		return $out;
	}

	/**
	 * Cache lifetime in seconds.
	 */
	public static function cache_ttl(): int {
		return self::get()['cache_minutes'] * MINUTE_IN_SECONDS;
	}

	/**
	 * Clamp a cache duration into the allowed range.
	 *
	 * @param mixed $minutes Raw input.
	 */
	public static function clamp_minutes( mixed $minutes ): int {
		$minutes = is_numeric( $minutes ) ? (int) $minutes : self::defaults()['cache_minutes'];
		return max( self::MIN_CACHE_MINUTES, min( self::MAX_CACHE_MINUTES, $minutes ) );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::CLEAR_HOOK, array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the option, section and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'rivcon_main', '', '__return_null', self::PAGE );

		$fields = array(
			'api_key'          => __( 'USGS API key', 'river-conditions' ),
			'cache_minutes'    => __( 'Cache duration', 'river-conditions' ),
			'temp_unit'        => __( 'Default temperature', 'river-conditions' ),
			'show_attribution' => __( 'Attribution link', 'river-conditions' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'rivcon_' . $key,
				$label,
				array( $this, 'field_' . $key ),
				self::PAGE,
				'rivcon_main',
				array( 'label_for' => 'rivcon_' . $key )
			);
		}
	}

	/**
	 * Sanitize the submitted settings. Runs only after options.php has checked
	 * the nonce and the manage_options capability.
	 *
	 * @param mixed $input Raw form input.
	 * @return array{api_key: string, cache_minutes: int, temp_unit: string, show_attribution: bool}
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();

		// The key is never echoed back, so an empty field means "keep the saved key".
		$api_key = $current['api_key'];
		if ( ! empty( $input['remove_api_key'] ) ) {
			$api_key = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$api_key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['api_key'] ) ?? '';
		}

		$out = array(
			'api_key'          => $api_key,
			'cache_minutes'    => self::clamp_minutes( $input['cache_minutes'] ?? null ),
			'temp_unit'        => ( isset( $input['temp_unit'] ) && 'C' === $input['temp_unit'] ) ? 'C' : 'F',
			'show_attribution' => ! empty( $input['show_attribution'] ),
		);

		// A shorter TTL or new key should take effect right away.
		if ( $out['cache_minutes'] !== $current['cache_minutes'] || $out['api_key'] !== $current['api_key'] ) {
			GaugeRepository::flush();
		}
		return $out;
	}

	/**
	 * Add Settings → River Conditions.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'River Conditions', 'river-conditions' ),
			__( 'River Conditions', 'river-conditions' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the settings page script (admin only; the front end has no JS).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'rivcon-settings',
			plugins_url( 'assets/settings.js', PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-i18n' ),
			VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'rivcon-settings', 'river-conditions', plugin_dir_path( PLUGIN_FILE ) . 'languages' );
	}

	/**
	 * Render the page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Set by handle_clear_cache() after its own nonce check; display only.
		$cleared = isset( $_GET['rivcon-cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache cleared.', 'river-conditions' ); ?></p></div>
			<?php endif; ?>
			<div id="rivcon-test-result" class="notice inline hidden" role="status" aria-live="polite"><p></p></div>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				?>
				<p class="submit">
					<?php submit_button( __( 'Save changes', 'river-conditions' ), 'primary', 'submit', false ); ?>
					<button type="button" class="button" id="rivcon-test"><?php esc_html_e( 'Test connection', 'river-conditions' ); ?></button>
					<button type="submit" class="button" form="rivcon-clear-form"><?php esc_html_e( 'Clear cache', 'river-conditions' ); ?></button>
				</p>
			</form>

			<form id="rivcon-clear-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_HOOK ); ?>">
				<?php wp_nonce_field( self::CLEAR_HOOK ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * API key field. The saved key is never printed.
	 */
	public function field_api_key(): void {
		$has_key = '' !== self::get()['api_key'];
		printf(
			'<input type="password" id="rivcon_api_key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="off" placeholder="%2$s">',
			esc_attr( self::OPTION ),
			esc_attr( $has_key ? '••••••••••••' : '' )
		);
		echo '<p class="description">';
		if ( $has_key ) {
			esc_html_e( 'Key saved. Leave blank to keep it.', 'river-conditions' );
			echo ' ';
		}
		esc_html_e( 'Optional, raises rate limits.', 'river-conditions' );
		printf(
			' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( 'https://api.waterdata.usgs.gov/docs/ogcapi/keys/' ),
			esc_html__( 'Get a free key ↗', 'river-conditions' )
		);
		echo '</p>';
		if ( $has_key ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[remove_api_key]" value="1"> %2$s</label></p>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove saved key', 'river-conditions' )
			);
		}
	}

	/**
	 * Cache duration field.
	 */
	public function field_cache_minutes(): void {
		printf(
			'<input type="number" id="rivcon_cache_minutes" name="%1$s[cache_minutes]" value="%2$d" min="%3$d" max="%4$d" step="1" class="small-text"> %5$s',
			esc_attr( self::OPTION ),
			(int) self::get()['cache_minutes'],
			(int) self::MIN_CACHE_MINUTES,
			(int) self::MAX_CACHE_MINUTES,
			esc_html__( 'minutes', 'river-conditions' )
		);
		echo '<p class="description">' . esc_html__( '5–120. Last good reading is kept 48 hours as a fallback.', 'river-conditions' ) . '</p>';
	}

	/**
	 * Default temperature unit field.
	 */
	public function field_temp_unit(): void {
		$unit = self::get()['temp_unit'];
		echo '<fieldset id="rivcon_temp_unit">';
		foreach ( array(
			'F' => '°F',
			'C' => '°C',
		) as $value => $label ) {
			printf(
				'<label style="margin-right:1em"><input type="radio" name="%1$s[temp_unit]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $value ),
				checked( $unit, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Attribution toggle field.
	 */
	public function field_show_attribution(): void {
		printf(
			'<label><input type="checkbox" id="rivcon_show_attribution" name="%1$s[show_attribution]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( self::get()['show_attribution'], true, false ),
			esc_html__( 'Show “Source: USGS” link', 'river-conditions' )
		);
	}

	/**
	 * Handle the Clear cache button.
	 */
	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'river-conditions' ), 403 );
		}
		check_admin_referer( self::CLEAR_HOOK );

		GaugeRepository::flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE,
					'rivcon-cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
