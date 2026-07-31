<?php

/**
 * Lightweight regression test for the shortcode-scoped admin bar filter.
 * Run with: php tests/test-admin-bar-scope.php
 */

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);

if (!class_exists('WP_Post')) {
	class WP_Post
	{
		public $post_content = '';
	}
}

$registered_filters = array();
$test_is_admin = false;
$test_is_singular = true;
$test_queried_object = null;

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

function is_singular()
{
	return $GLOBALS['test_is_singular'];
}

function get_queried_object()
{
	return $GLOBALS['test_queried_object'];
}

function has_shortcode($content, $shortcode)
{
	return strpos($content, '[' . $shortcode) !== false;
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

$post = new WP_Post();
$GLOBALS['test_queried_object'] = $post;

$GLOBALS['test_is_admin'] = true;
$GLOBALS['test_is_singular'] = true;
$post->post_content = '[vietqr_generator]';
expect_value('admin screen remains unchanged', call_user_func($filter, true), true);

$GLOBALS['test_is_admin'] = false;
$GLOBALS['test_is_singular'] = false;
expect_value('non-singular frontend remains unchanged', call_user_func($filter, true), true);

$GLOBALS['test_is_singular'] = true;
$post->post_content = '[contact_form]';
expect_value('frontend without shortcode remains unchanged', call_user_func($filter, true), true);

$post->post_content = '[vietqr_generator]';
expect_value('VietQR shortcode page hides admin bar', call_user_func($filter, true), false);
expect_value('already hidden admin bar stays hidden', call_user_func($filter, false), false);

fwrite(STDOUT, "PASS: admin bar scope regression tests\n");
