<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (! defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

if (! defined('OBJECT')) {
	define('OBJECT', 'OBJECT');
}

if (! defined('PHP_INT_MAX')) {
	define('PHP_INT_MAX', 9223372036854775807);
}

if (! class_exists('wpdb')) {
	/**
	 * Minimal stand-in for the WordPress `$wpdb` class so the queue can be unit
	 * tested (and mocked with Mockery) without loading WordPress.
	 */
	class wpdb
	{
		public string $prefix = 'wp_';

		public int $insert_id = 0;

		public function prepare($query, ...$args)
		{
			return $query;
		}

		public function query($query)
		{
			return 0;
		}

		public function get_var($query)
		{
			return null;
		}

		public function get_row($query, $output = null)
		{
			return null;
		}

		public function get_results($query, $output = null)
		{
			return [];
		}

		public function get_col($query)
		{
			return [];
		}

		public function insert($table, $data, $format = null)
		{
			return 1;
		}

		public function update($table, $data, $where, $format = null, $where_format = null)
		{
			return 1;
		}

		public function delete($table, $where, $where_format = null)
		{
			return 1;
		}

		public function esc_like($text)
		{
			return $text;
		}

		public function get_charset_collate()
		{
			return '';
		}
	}
}
