<?php

/**
 * Lightweight regression test for the subscriber-scoped admin bar filter.
 * Run with: php tests/test-admin-bar-scope.php
 */

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);

if (!class_exists('WP_User')) {
	class WP_User
	{
		public $ID = 0;
		public $roles = array();

		public function exists()
		{
			return $this->ID > 0;
		}
	}
}

$registered_filters = array();
$test_is_admin = false;
$test_current_user = new WP_User();

function plugin_dir_path($file)
{
	return dirname($file) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url($file)
{
	return 'https://example.test/wp-content/plugins/vietqr-wp-plugin/';
}

function register_activation_hook($file, $callback)
{
}

function register_deactivation_hook($file, $callback)
{
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
}

function add_shortcode($tag, $callback)
{
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
	$GLOBALS['registered_filters'][$hook] = $callback;
}

function is_admin()
{
	return $GLOBALS['test_is_admin'];
}

function wp_get_current_user()
{
	return $GLOBALS['test_current_user'];
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vietqr-wp-plugin.php';

function expect_value($label, $actual, $expected)
{
	if ($actual !== $expected) {
		fwrite(STDERR, "FAIL: {$label}; expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
		exit(1);
	}
}

$filter = $GLOBALS['registered_filters']['show_admin_bar'] ?? null;
expect_value('show_admin_bar filter is registered', is_callable($filter), true);

$GLOBALS['test_is_admin'] = true;
$GLOBALS['test_current_user']->ID = 10;
$GLOBALS['test_current_user']->roles = array('subscriber');
expect_value('admin screen remains unchanged', call_user_func($filter, true), true);

$GLOBALS['test_is_admin'] = false;
$GLOBALS['test_current_user']->roles = array('subscriber');
expect_value('subscriber frontend hides admin bar', call_user_func($filter, true), false);
expect_value('already hidden admin bar stays hidden', call_user_func($filter, false), false);

$GLOBALS['test_current_user']->roles = array('contributor');
expect_value('contributor frontend keeps admin bar', call_user_func($filter, true), true);

$GLOBALS['test_current_user']->roles = array('editor');
expect_value('editor frontend keeps admin bar', call_user_func($filter, true), true);

$GLOBALS['test_current_user']->roles = array('subscriber', 'editor');
expect_value('multi-role user keeps admin bar', call_user_func($filter, true), true);

$GLOBALS['test_current_user']->ID = 0;
$GLOBALS['test_current_user']->roles = array();
expect_value('logged-out frontend remains unchanged', call_user_func($filter, true), true);

fwrite(STDOUT, "PASS: admin bar scope regression tests\n");
