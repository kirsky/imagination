<?php
/**
 * Plugin settings page.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Admin;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Error\Log;
use Indigit\Imagination\{Options, Options_Data};
use Indigit\Imagination\Vendor\Jcupitt\Vips\Kernel;

/**
 * Registers and renders the Settings > Indigit Imagination admin page.
 */
final class Settings {

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'imagination_options_group';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'imagination';

	/**
	 * Values of the "Strip metadata" select mapped to the stored value. The
	 * empty value ("WordPress default") stores null.
	 *
	 * @var array<string, bool>
	 */
	private const STRIP_META_CHOICES = [
		'strip' => true,
		'keep'  => false,
	];

	/**
	 * Options this page reads and writes.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options this page reads and writes.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Registers the settings page.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );

		add_action( 'admin_init', [ $this, 'register_settings' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_filter(
			'imagination_resampling_kernel',
			[ $this, 'filter_resampling_kernel' ],
			9
		);

		add_filter(
			'big_image_size_threshold',
			[ $this, 'filter_big_image_size_threshold' ]
		);

		add_filter( 'image_strip_meta', [ $this, 'filter_strip_meta' ] );

		add_filter(
			'imagination_keep_icc_only',
			[ $this, 'filter_keep_icc_only' ],
			9
		);

		add_filter(
			'wp_client_side_media_processing_enabled',
			[ $this, 'filter_client_side_processing' ]
		);

		add_filter(
			'imagination_log_errors',
			[ $this, 'filter_log_errors' ],
			9
		);

		add_action(
			'update_option_' . Options::OPTION_NAME,
			[ $this, 'clear_error_log_when_disabled' ],
			10,
			2
		);
	}

	/**
	 * Registers the settings page under Settings.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Indigit Imagination', 'indigit-imagination' ),
			__( 'Indigit Imagination', 'indigit-imagination' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Registers the setting and its fields with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Options::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);

		add_settings_section(
			'imagination_general',
			__( 'General', 'indigit-imagination' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'imagination_resampling_kernel',
			__( 'Resampling kernel', 'indigit-imagination' ),
			[ $this, 'render_resampling_kernel_field' ],
			self::PAGE_SLUG,
			'imagination_general',
			[ 'label_for' => Options::field_name( 'resampling_kernel' ) ]
		);

		add_settings_field(
			'imagination_big_image_size_threshold',
			__( 'Big image threshold', 'indigit-imagination' ),
			[ $this, 'render_big_image_size_threshold_field' ],
			self::PAGE_SLUG,
			'imagination_general',
			[ 'label_for' => Options::field_name( 'big_image_size_threshold' ) ]
		);

		add_settings_field(
			'imagination_strip_meta',
			__( 'Strip metadata', 'indigit-imagination' ),
			[ $this, 'render_strip_meta_field' ],
			self::PAGE_SLUG,
			'imagination_general',
			[ 'label_for' => Options::field_name( 'strip_meta' ) ]
		);

		add_settings_field(
			'imagination_keep_icc_only',
			__( 'Keep only ICC metadata', 'indigit-imagination' ),
			[ $this, 'render_keep_icc_only_field' ],
			self::PAGE_SLUG,
			'imagination_general',
			[ 'label_for' => Options::field_name( 'keep_icc_only' ) ]
		);

		if ( function_exists( 'wp_is_client_side_media_processing_enabled' ) ) {
			add_settings_field(
				'imagination_disable_client_side_processing',
				__( 'Client-side processing', 'indigit-imagination' ),
				[ $this, 'render_client_side_processing_field' ],
				self::PAGE_SLUG,
				'imagination_general',
				[
					'label_for' => Options::field_name(
						'disable_client_side_processing'
					),
				]
			);
		}

		add_settings_section(
			'imagination_troubleshooting',
			__( 'Troubleshooting', 'indigit-imagination' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'imagination_log_errors',
			__( 'Error log', 'indigit-imagination' ),
			[ $this, 'render_log_errors_field' ],
			self::PAGE_SLUG,
			'imagination_troubleshooting',
			[ 'label_for' => Options::field_name( 'log_errors' ) ]
		);
	}

	/**
	 * Enqueues the settings page's inline style and script: a width cap on
	 * field descriptions, and "Strip metadata" disabled while "Keep only ICC
	 * metadata" is checked.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_register_style(
			'imagination-settings',
			false,
			[],
			IMAGINATION_VERSION
		);
		wp_enqueue_style( 'imagination-settings' );

		wp_add_inline_style(
			'imagination-settings',
			'.form-table td p.description { max-width: 640px; }'
		);

		wp_register_script(
			'imagination-settings',
			false,
			[],
			IMAGINATION_VERSION,
			true
		);
		wp_enqueue_script( 'imagination-settings' );

		wp_add_inline_script(
			'imagination-settings',
			sprintf(
				'(function(){'
				. 'var iccOnly=document.getElementById(%1$s),stripMeta=document.getElementById(%2$s);'
				. 'if(!iccOnly||!stripMeta){return;}'
				. 'function sync(){stripMeta.disabled=iccOnly.checked;}'
				. 'iccOnly.addEventListener("change",sync);'
				. 'sync();'
				. '})();',
				wp_json_encode( Options::field_name( 'keep_icc_only' ) ),
				wp_json_encode( Options::field_name( 'strip_meta' ) )
			)
		);
	}

	/**
	 * Sanitizes options before they are stored.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return $this->options->get()->to_array();
		}

		$resampling_kernel = $value['resampling_kernel'] ?? null;
		$threshold         = $value['big_image_size_threshold'] ?? null;
		$strip_meta        = $this->options->get()->strip_meta;

		if ( is_string( $threshold ) && ctype_digit( $threshold ) ) {
			$threshold = (int) $threshold;
		}

		// A disabled select is not submitted: the stored choice stays.
		if ( array_key_exists( 'strip_meta', $value ) ) {
			$submitted  = $value['strip_meta'];
			$strip_meta = null;

			if ( is_bool( $submitted ) ) {
				$strip_meta = $submitted;
			} elseif ( is_string( $submitted ) ) {
				$strip_meta = self::STRIP_META_CHOICES[ $submitted ] ?? null;
			}
		}

		return [
			'resampling_kernel'              => in_array(
				$resampling_kernel,
				Options::RESAMPLING_KERNELS,
				true
			)
				? $resampling_kernel
				: null,

			'big_image_size_threshold'       => is_int(
				$threshold
			) && $threshold >= 0
				? $threshold
				: null,

			'strip_meta'                     => $strip_meta,
			'keep_icc_only'                  => ! empty( $value['keep_icc_only'] ),

			'disable_client_side_processing' => ! empty(
				$value['disable_client_side_processing']
			),

			'log_errors'                     => ! empty( $value['log_errors'] ),
		];
	}

	/**
	 * Renders the settings page form.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the resampling kernel select field.
	 */
	public function render_resampling_kernel_field(): void {
		$resampling_kernel = $this->options->get()->resampling_kernel ?? Kernel::LINEAR;
		$field_name        = Options::field_name( 'resampling_kernel' );

		?>
		<select
			id="<?php echo esc_attr( $field_name ); ?>"
			name="<?php echo esc_attr( $field_name ); ?>"
		>
			<option
				value="<?php echo esc_attr( Kernel::LINEAR ); ?>"
				<?php selected( $resampling_kernel, Kernel::LINEAR ); ?>
			>
				<?php esc_html_e( 'Linear', 'indigit-imagination' ); ?>
			</option>

			<option
				value="<?php echo esc_attr( Kernel::LANCZOS3 ); ?>"
				<?php selected( $resampling_kernel, Kernel::LANCZOS3 ); ?>
			>
				<?php esc_html_e( 'Lanczos3', 'indigit-imagination' ); ?>
			</option>
		</select>
		<p class="description">
			<?php
			esc_html_e(
				'Linear matches the default "Triangle" filter in WordPress’s Imagick editor. It balances speed and small file sizes, making it ideal for logos, screenshots, and flat graphics. Lanczos3 offers superior sharpness and detail for photos, though it processes slightly slower and produces larger files.',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the big image threshold number field.
	 */
	public function render_big_image_size_threshold_field(): void {
		$threshold  = $this->options->get()->big_image_size_threshold;
		$field_name = Options::field_name( 'big_image_size_threshold' );

		printf(
			'<input type="number" class="small-text" id="%1$s" name="%1$s" value="%2$s" min="0" step="1" placeholder="%3$s" />',
			esc_attr( $field_name ),
			esc_attr( null === $threshold ? '' : (string) $threshold ),
			esc_attr( (string) Options::DEFAULT_BIG_IMAGE_SIZE_THRESHOLD )
		);
		?>
		<p class="description">
			<?php
			esc_html_e(
				'Images exceeding this pixel limit in width or height are scaled down to serve as the full-size version on your site. The original upload remains stored on disk but is not used directly. This corresponds to WordPress’s `big_image_size_threshold`. Set to 0 to keep full-resolution uploads, or leave empty for the default limit (2560 px).',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
		$chosen    = $threshold ?? Options::DEFAULT_BIG_IMAGE_SIZE_THRESHOLD;
		$in_effect = (int) apply_filters(
			'big_image_size_threshold', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			Options::DEFAULT_BIG_IMAGE_SIZE_THRESHOLD,
			[ 0, 0 ],
			'',
			0
		);

		if ( $chosen !== $in_effect ) {
			$this->render_in_effect(
				0 === $in_effect
					? __( '0 (no scaling)', 'indigit-imagination' )
					: number_format_i18n( $in_effect )
			);
		}
	}

	/**
	 * Renders the strip metadata select field.
	 */
	public function render_strip_meta_field(): void {
		$strip_meta = $this->options->get()->strip_meta;
		$field_name = Options::field_name( 'strip_meta' );
		$selected   = null === $strip_meta
			? ''
			: (string) array_search(
				$strip_meta,
				self::STRIP_META_CHOICES,
				true
			);
		$choices    = [
			''      => __( 'WordPress default', 'indigit-imagination' ),
			'strip' => __(
				'Strip, keeping ICC, EXIF, XMP and IPTC',
				'indigit-imagination'
			),
			'keep'  => __(
				'Don\'t strip, keep all metadata',
				'indigit-imagination'
			),
		];

		printf(
			'<select id="%1$s" name="%1$s">',
			esc_attr( $field_name )
		);

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		?>
		<p class="description">
			<?php
			esc_html_e(
				'Controls image metadata removal via WordPress’s `image_strip_meta`. Selecting WordPress default performs basic metadata stripping, but it does not remove sensitive data like GPS coordinates, camera details, or copyright info. Use "Keep only ICC metadata" below for a stricter, privacy-focused clean-up.',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
		$chosen    = $strip_meta ?? true;
		$in_effect = (bool) apply_filters(
			'image_strip_meta', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			true
		);

		if ( $chosen !== $in_effect ) {
			$this->render_in_effect(
				$in_effect
					? $choices['strip']
					: $choices['keep']
			);
		}
	}

	/**
	 * Renders the keep-ICC-only checkbox field.
	 */
	public function render_keep_icc_only_field(): void {
		$keep_icc_only = $this->options->get()->keep_icc_only;
		$field_name    = Options::field_name( 'keep_icc_only' );

		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $field_name ),
			checked( $keep_icc_only, true, false ),
			esc_html__(
				'Drop EXIF, XMP and IPTC even when metadata is not stripped above',
				'indigit-imagination'
			)
		);
		?>
		<p class="description">
			<?php
			esc_html_e(
				'Overrides "Strip metadata": always keeps only the ICC colour profile.',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the checkbox that turns off client-side processing.
	 */
	public function render_client_side_processing_field(): void {
		$disabled   = $this->options->get()->disable_client_side_processing;
		$field_name = Options::field_name( 'disable_client_side_processing' );

		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $field_name ),
			checked( $disabled, true, false ),
			esc_html__(
				'Process all uploads on the server',
				'indigit-imagination'
			)
		);
		?>
		<p class="description">
			<?php
			esc_html_e(
				'The WordPress block editor resizes images directly in the browser, bypassing server-side processing. Check this box to force Indigit Imagination to process all uploads on the server instead.',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
		if ( $disabled && wp_is_client_side_media_processing_enabled() ) {
			$this->render_in_effect(
				__(
					'images are processed in the browser',
					'indigit-imagination'
				)
			);
		}
	}

	/**
	 * Renders the checkbox that turns on error logging.
	 */
	public function render_log_errors_field(): void {
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( Options::field_name( 'log_errors' ) ),
			checked( $this->options->get()->log_errors, true, false ),
			esc_html__( 'Log image processing errors', 'indigit-imagination' )
		);
		?>
		<p class="description">
			<?php
			esc_html_e(
				'Keeps the last 10 errors, without file names, and shows them in Tools > Site Health > Info > Indigit Imagination. Turning this off deletes them.',
				'indigit-imagination'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders a note with the value in effect when a plugin or the theme
	 * changes the setting.
	 *
	 * @param string $value Value in effect, ready for display.
	 */
	private function render_in_effect( string $value ): void {
		printf(
			'<p class="description"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: Value in effect. */
					__(
						'A plugin or the theme changes this setting. In effect: %s.',
						'indigit-imagination'
					),
					$value
				)
			)
		);
	}

	/**
	 * Returns the stored resampling kernel, or the given one when none is stored.
	 *
	 * @param mixed $kernel Kernel from earlier callbacks.
	 * @return mixed
	 */
	public function filter_resampling_kernel( $kernel ) {
		return $this->options->get()->resampling_kernel ?? $kernel;
	}

	/**
	 * Returns the stored big-image threshold, or the given one when none is stored.
	 *
	 * @param mixed $threshold Threshold from earlier callbacks.
	 * @return mixed
	 */
	public function filter_big_image_size_threshold( $threshold ) {
		return $this->options->get()->big_image_size_threshold ?? $threshold;
	}

	/**
	 * Returns the stored metadata stripping choice, or the given one when none is stored.
	 *
	 * @param mixed $strip_meta Value from earlier callbacks.
	 * @return mixed
	 */
	public function filter_strip_meta( $strip_meta ) {
		return $this->options->get()->strip_meta ?? $strip_meta;
	}

	/**
	 * Returns true when keep-ICC-only is stored as on, else the given value.
	 *
	 * @param mixed $keep_icc_only Value from earlier callbacks.
	 * @return mixed
	 */
	public function filter_keep_icc_only( $keep_icc_only ) {
		return $this->options->get()->keep_icc_only ? true : $keep_icc_only;
	}

	/**
	 * Returns false when client-side processing is stored as off, else the
	 * given value.
	 *
	 * @param mixed $enabled Value from earlier callbacks.
	 * @return mixed
	 */
	public function filter_client_side_processing( $enabled ) {
		return $this->options->get()->disable_client_side_processing
			? false
			: $enabled;
	}

	/**
	 * Returns true when error logging is stored as on, else the given value.
	 *
	 * @param mixed $log_errors Value from earlier callbacks.
	 * @return mixed
	 */
	public function filter_log_errors( $log_errors ) {
		return $this->options->get()->log_errors ? true : $log_errors;
	}

	/**
	 * Deletes the logged errors when error logging is turned off.
	 *
	 * @param mixed $old_value Previous value of the option.
	 * @param mixed $value     New value of the option.
	 */
	public function clear_error_log_when_disabled( $old_value, $value ): void {
		$was_on = Options_Data::from_stored(
			is_array( $old_value ) ? $old_value : []
		)->log_errors;
		$is_on  = Options_Data::from_stored(
			is_array( $value ) ? $value : []
		)->log_errors;

		if ( $was_on && ! $is_on ) {
			Log::clear();
		}
	}
}
