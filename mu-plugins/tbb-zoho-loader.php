<?php
/**
 * Loader for the Zoho Inventory integration.
 *
 * WordPress only auto-loads PHP files sitting directly in mu-plugins, not those
 * in subdirectories, so this requires the real plugin file. Mirrors the
 * 10up-plugin-loader.php convention already used here.
 *
 * @package TBB\Zoho
 */

require_once __DIR__ . '/tbb-zoho/plugin.php';
