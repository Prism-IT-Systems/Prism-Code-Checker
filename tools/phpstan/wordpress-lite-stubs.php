<?php

namespace {
    function __(string $text, string $domain = 'default'): string { return $text; }
    function _e(string $text, string $domain = 'default'): void {}
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string { return $number === 1 ? $single : $plural; }
    function _x(string $text, string $context, string $domain = 'default'): string { return $text; }
    function esc_html(string $text): string { return $text; }
    function esc_attr(string $text): string { return $text; }
    function esc_url(string $url): string { return $url; }
    function esc_js(string $text): string { return $text; }
    function wp_kses_post(string $data): string { return $data; }
    function sanitize_text_field(string $str): string { return $str; }
    function sanitize_email(string $email): string { return $email; }
    function sanitize_title(string $title): string { return $title; }
    /** @param mixed $value */
    function wp_unslash($value) { return $value; }
    function absint($value): int { return (int) $value; }
    /** @return mixed */
    function get_option(string $option, $default = false) { return $default; }
    function update_option(string $option, $value, $autoload = null): bool { return true; }
    function add_option(string $option, $value = '', $deprecated = '', $autoload = 'yes'): bool { return true; }
    function delete_option(string $option): bool { return true; }
    /** @return mixed */
    function get_theme_mod(string $name, $default = false) { return $default; }
    function set_theme_mod(string $name, $value): void {}
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
    function remove_action(string $hook, $callback, int $priority = 10): bool { return true; }
    function remove_filter(string $hook, $callback, int $priority = 10): bool { return true; }
    function do_action(string $hook, ...$args): void {}
    /** @return mixed */
    function apply_filters(string $hook, $value, ...$args) { return $value; }
    function add_theme_support(string $feature, ...$args): void {}
    function register_nav_menus(array $locations = []): void {}
    function register_sidebar($args): string { return 'sidebar'; }
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void {}
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = false): void {}
    function wp_register_style(string $handle, string $src, array $deps = [], $ver = false, string $media = 'all'): void {}
    function wp_register_script(string $handle, string $src, array $deps = [], $ver = false, $args = false): void {}
    function get_template_directory(): string { return ''; }
    function get_template_directory_uri(): string { return ''; }
    function get_stylesheet_directory(): string { return ''; }
    function get_stylesheet_directory_uri(): string { return ''; }
    function get_theme_file_path(string $file = ''): string { return $file; }
    function get_theme_file_uri(string $file = ''): string { return $file; }
    function home_url(string $path = '', $scheme = null): string { return $path; }
    function site_url(string $path = '', $scheme = null): string { return $path; }
    function admin_url(string $path = '', $scheme = 'admin'): string { return $path; }
    function get_permalink($post = 0, bool $leavename = false) { return ''; }
    function get_the_ID() { return 1; }
    function get_the_title($post = 0): string { return ''; }
    function get_the_content($more_link_text = null, bool $strip_teaser = false, $post = null): string { return ''; }
    function the_content($more_link_text = null, bool $strip_teaser = false): void {}
    function have_posts(): bool { return false; }
    function the_post(): void {}
    function wp_head(): void {}
    function wp_footer(): void {}
    function body_class($class = ''): void {}
    function language_attributes(string $doctype = 'html'): void {}
    function bloginfo(string $show = ''): void {}
    function get_bloginfo(string $show = '', string $filter = 'raw'): string { return ''; }
    function is_admin(): bool { return false; }
    function is_front_page(): bool { return false; }
    function is_home(): bool { return false; }
    function is_single($post = ''): bool { return false; }
    function is_page($page = ''): bool { return false; }
    function is_singular($post_types = ''): bool { return false; }
    function is_archive(): bool { return false; }
    function is_search(): bool { return false; }
    function is_404(): bool { return false; }
    function wp_die($message = '', $title = '', $args = []): void {}
    function wp_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool { return true; }
    function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool { return true; }
    function check_admin_referer($action = -1, string $query_arg = '_wpnonce') { return true; }
    function wp_verify_nonce(string $nonce, $action = -1) { return 1; }
    function wp_create_nonce($action = -1): string { return 'nonce'; }
    function current_user_can(string $capability, ...$args): bool { return true; }
    function is_user_logged_in(): bool { return false; }
    function wp_get_current_user() { return (object) ['ID' => 0]; }
    function get_userdata($user_id) { return false; }
    function get_post($post = null, string $output = 'OBJECT', string $filter = 'raw') { return null; }
    /** @return mixed */
    function get_post_meta(int $post_id, string $key = '', bool $single = false) { return $single ? '' : []; }
    function update_post_meta(int $post_id, string $meta_key, $meta_value, $prev_value = '') { return true; }
    function get_posts($args = null): array { return []; }
    function wp_insert_post(array $postarr, bool $wp_error = false, bool $fire_after_hooks = true) { return 1; }
    function register_post_type(string $post_type, array $args = []) { return true; }
    function register_taxonomy(string $taxonomy, $object_type, array $args = []) { return true; }
    function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array { return $pairs; }
    function add_shortcode(string $tag, $callback): void {}
    function do_shortcode(string $content, bool $ignore_html = false): string { return $content; }
    function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
    function plugin_dir_url(string $file): string { return ''; }
    function plugins_url(string $path = '', $plugin = ''): string { return $path; }
    function load_plugin_textdomain(string $domain, $deprecated = false, $plugin_rel_path = false): bool { return true; }
    function load_theme_textdomain(string $domain, $path = false): bool { return true; }
    function selected($selected, $current = true, bool $display = true): string { return ''; }
    function checked($checked, $current = true, bool $display = true): string { return ''; }
    function disabled($disabled, $current = true, bool $display = true): string { return ''; }
    function wp_json_encode($data, int $options = 0, int $depth = 512) { return json_encode($data, $options, $depth); }
    function wp_parse_args($args, $defaults = []): array { return is_array($args) ? array_merge($defaults, $args) : $defaults; }
}
