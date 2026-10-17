<?php
/**
 * Loader for the hardening plugin.
 *
 * WordPress only auto-loads PHP files sitting directly in mu-plugins, not those
 * in subdirectories, so this requires the real plugin file. Same convention as
 * tbb-zoho-loader.php.
 *
 * @package TBB\Hardening
 */

require_once __DIR__ . '/tbb-hardening/plugin.php';
