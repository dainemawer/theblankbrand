<?php
/**
 * Assets module.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme;

use TenupFramework\Assets\GetAssetInfo;
use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Assets module.
 *
 * @package BlankBrandTheme
 */
class Assets implements ModuleInterface {

	use Module;
	use GetAssetInfo;

	/**
	 * Google Fonts stylesheet URL (Asap + Libre Baskerville).
	 *
	 * @var string
	 */
	const GOOGLE_FONTS_URL = 'https://fonts.googleapis.com/css2?family=Asap:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap';

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register() {
		return true;
	}

	/**
	 * Register any hooks and filters.
	 *
	 * @return void
	 */
	public function register() {
		$this->setup_asset_vars(
			dist_path: BLANK_BRAND_THEME_DIST_PATH,
			fallback_version: BLANK_BRAND_THEME_VERSION
		);
		add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_scripts' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'styles' ] );
		add_action( 'enqueue_block_assets', [ $this, 'editor_fonts' ] );
	}


	/**
	 * Enqueue scripts for front-end.
	 *
	 * @return void
	 */
	public function scripts() {
		/**
		 * Enqueuing frontend.js is required to get css hot reloading working in the frontend
		 * If you're not shipping any front-end js wrap this enqueue in a SCRIPT_DEBUG check.
		 */
		wp_enqueue_script(
			'frontend',
			BLANK_BRAND_THEME_TEMPLATE_URL . '/dist/js/frontend.js',
			$this->get_asset_info( 'frontend', 'dependencies' ),
			$this->get_asset_info( 'frontend', 'version' ),
			true
		);
	}

	/**
	 * Enqueue core block filters, styles and variations.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_scripts() {
		wp_enqueue_script(
			'block-editor-script',
			BLANK_BRAND_THEME_DIST_URL . 'js/block-editor-script.js',
			$this->get_asset_info( 'block-editor-script', 'dependencies' ),
			$this->get_asset_info( 'block-editor-script', 'version' ),
			true
		);
	}

	/**
	 * Enqueue styles for front-end.
	 *
	 * @return void
	 */
	public function styles() {
		wp_enqueue_style(
			'blank-brand-fonts',
			self::GOOGLE_FONTS_URL,
			[],
			null
		);

		wp_enqueue_style(
			'styles',
			BLANK_BRAND_THEME_TEMPLATE_URL . '/dist/css/frontend.css',
			[ 'blank-brand-fonts' ],
			$this->get_asset_info( 'frontend', 'version' )
		);
	}

	/**
	 * Enqueue the web fonts inside the block editor.
	 *
	 * On the front end the fonts load via {@see styles()}. In the editor the
	 * font-family declarations come from theme.json, but the font files
	 * themselves are never loaded, so text falls back to system fonts.
	 * `enqueue_block_assets` runs in both contexts and reaches the editor
	 * iframe; gate to admin so the front end isn't double-loaded.
	 *
	 * @return void
	 */
	public function editor_fonts() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'blank-brand-fonts',
			self::GOOGLE_FONTS_URL,
			[],
			null
		);
	}
}
