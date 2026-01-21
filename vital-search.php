<?php
/**
 * Plugin Name: Vital Search
 * Plugin URI: https://vitalseeds.co.uk
 * Description: Fast client-side product search using FlexSearch with IndexedDB caching.
 * Version: 1.0.0
 * Author: Vital Seeds
 * Author URI: https://vitalseeds.co.uk
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: vital-search
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VITAL_SEARCH_VERSION', '1.0.0');
define('VITAL_SEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VITAL_SEARCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VITAL_SEARCH_CRON_HOOK', 'vital_search_export');

/**
 * Schedule cron on plugin activation
 */
function vital_search_activate() {
    vital_search_rewrite_rule();
    flush_rewrite_rules();

    if (!wp_next_scheduled(VITAL_SEARCH_CRON_HOOK)) {
        wp_schedule_event(strtotime('today 3:00am'), 'daily', VITAL_SEARCH_CRON_HOOK);
    }
}
register_activation_hook(__FILE__, 'vital_search_activate');

/**
 * Clear cron on plugin deactivation
 */
function vital_search_deactivate() {
    wp_clear_scheduled_hook(VITAL_SEARCH_CRON_HOOK);
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'vital_search_deactivate');

/**
 * Schedule cron if not already scheduled (in case missed)
 */
function vital_search_init_cron() {
    if (!wp_next_scheduled(VITAL_SEARCH_CRON_HOOK)) {
        wp_schedule_event(strtotime('today 3:00am'), 'daily', VITAL_SEARCH_CRON_HOOK);
    }
}
add_action('init', 'vital_search_init_cron');

/**
 * Register /search rewrite rule
 */
function vital_search_rewrite_rule() {
    add_rewrite_rule('^search/?$', 'index.php?vital_search_page=1', 'top');
}
add_action('init', 'vital_search_rewrite_rule');

/**
 * Register query var for search page
 */
function vital_search_query_vars($vars) {
    $vars[] = 'vital_search_page';
    return $vars;
}
add_filter('query_vars', 'vital_search_query_vars');

/**
 * Load search page template for /search URL
 */
function vital_search_template($template) {
    if (get_query_var('vital_search_page')) {
        $plugin_template = VITAL_SEARCH_PLUGIN_DIR . 'templates/page-search.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    return $template;
}
add_filter('template_include', 'vital_search_template');

/**
 * Export products and categories to JSON file
 *
 * @return int Version timestamp
 */
function vital_search_export_json() {
    $upload_dir = wp_upload_dir();
    $base_path = $upload_dir['basedir'];

    $version = time();

    $products = vital_search_get_products();
    $categories = vital_search_get_categories();

    $items = array_merge($products, $categories);

    $data = [
        'version' => $version,
        'generated' => date('c'),
        'items' => $items
    ];

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($base_path . '/search-index.json', $json);

    update_option('vital_search_version', $version);

    return $version;
}
add_action(VITAL_SEARCH_CRON_HOOK, 'vital_search_export_json');

/**
 * Get all published products formatted for search
 *
 * @return array
 */
function vital_search_get_products() {
    $products = [];

    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];

    $product_ids = get_posts($args);

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        if ($product->get_catalog_visibility() === 'hidden') continue;

        $thumbnail_id = $product->get_image_id();
        $thumbnail_url = $thumbnail_id
            ? wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail')
            : wc_placeholder_img_src('woocommerce_thumbnail');

        $terms = get_the_terms($product_id, 'product_cat');
        $categories = [];
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }
        }

        $latin_name = function_exists('get_field') ? get_field('latin_name', $product_id) : null;

        $products[] = [
            'id' => 'product-' . $product_id,
            'type' => 'product',
            'title' => $product->get_name(),
            'latin_name' => $latin_name ?: null,
            'url' => get_permalink($product_id),
            'thumbnail' => $thumbnail_url,
            'category' => $categories,
            'sku' => $product->get_sku(),
        ];
    }

    return $products;
}

/**
 * Get seed categories formatted for search
 *
 * @return array
 */
function vital_search_get_categories() {
    $categories = [];

    $seeds_parent = get_term_by('slug', 'seeds', 'product_cat');
    if (!$seeds_parent) {
        return $categories;
    }

    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'child_of' => $seeds_parent->term_id,
        'hide_empty' => true,
    ]);

    if (is_wp_error($terms)) {
        return $categories;
    }

    foreach ($terms as $term) {
        $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
        $thumbnail_url = $thumbnail_id
            ? wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail')
            : wc_placeholder_img_src('woocommerce_thumbnail');

        $categories[] = [
            'id' => 'category-' . $term->term_id,
            'type' => 'category',
            'title' => $term->name,
            'url' => get_term_link($term),
            'thumbnail' => $thumbnail_url,
            'count' => $term->count,
        ];
    }

    return $categories;
}

/**
 * Register the [vital_search] shortcode
 *
 * Progressive enhancement: renders as a link to /search that works without JS.
 * JavaScript enhances the link to open the search popup.
 */
function vital_search_shortcode($atts) {
    $atts = shortcode_atts([
        'button_text' => __('Search', 'vital-search'),
        'button_class' => 'vital-search-button',
        'placeholder' => __('Search', 'vital-search'),
    ], $atts);

    vital_search_enqueue_assets();

    $version = get_option('vital_search_version', time());
    $search_url = home_url('/search');

    ob_start();
    ?>
    <a href="<?php echo esc_url($search_url); ?>"
       class="<?php echo esc_attr($atts['button_class']); ?>"
       data-vital-search-trigger
       data-vital-search-version="<?php echo esc_attr($version); ?>"
       data-vital-search-placeholder="<?php echo esc_attr($atts['placeholder']); ?>">
        <span class="screen-reader-text"><?php echo esc_html($atts['button_text']); ?></span>
        <svg width="20" height="20" class="search-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
    </a>
    <?php
    return ob_get_clean();
}
add_shortcode('vital_search', 'vital_search_shortcode');

// Keep backward compatibility with old shortcode
add_shortcode('vs_product_search', 'vital_search_shortcode');

/**
 * Add search link as last item in primary navigation menu
 *
 * Progressive enhancement: renders as a link to /search that works without JS.
 * JavaScript enhances the link to open the search popup.
 */
function vital_search_add_to_nav_menu($items, $args) {
    // Only add to primary menu
    if ($args->theme_location !== 'primary') {
        return $items;
    }

    vital_search_enqueue_assets();
    $version = get_option('vital_search_version', time());
    $search_url = home_url('/search');
    $placeholder = esc_attr__('Search', 'vital-search');

    $search_item = '<li class="menu-item vital-search-menu-item">';
    $search_item .= '<a href="' . esc_url($search_url) . '" class="vital-search-button vital-search-button--header" data-vital-search-trigger data-vital-search-version="' . esc_attr($version) . '" data-vital-search-placeholder="' . $placeholder . '">';
    $search_item .= '<span class="screen-reader-text">' . esc_html__('Search', 'vital-search') . '</span>';
    $search_item .= '<svg width="20" height="20" class="search-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>';
    $search_item .= '</a>';
    $search_item .= '</li>';

    return $items . $search_item;
}
add_filter('wp_nav_menu_items', 'vital_search_add_to_nav_menu', 10, 2);

/**
 * Enqueue search assets (called when shortcode or header search is used)
 */
function vital_search_enqueue_assets() {
    static $enqueued = false;
    if ($enqueued) return;
    $enqueued = true;

    wp_enqueue_script(
        'flexsearch',
        'https://cdn.jsdelivr.net/npm/flexsearch@0.7.31/dist/flexsearch.bundle.min.js',
        [],
        '0.7.31',
        true
    );

    wp_enqueue_script(
        'idb',
        'https://cdn.jsdelivr.net/npm/idb@7/build/umd.js',
        [],
        '7.1.1',
        true
    );

    wp_enqueue_script(
        'vital-search',
        VITAL_SEARCH_PLUGIN_URL . 'js/vital-search.js',
        ['flexsearch', 'idb'],
        VITAL_SEARCH_VERSION,
        true
    );

    $upload_dir = wp_upload_dir();
    wp_localize_script('vital-search', 'vitalSearch', [
        'jsonUrl' => $upload_dir['baseurl'] . '/search-index.json',
        'version' => get_option('vital_search_version', 0),
    ]);

    wp_enqueue_style(
        'vital-search',
        VITAL_SEARCH_PLUGIN_URL . 'css/vital-search.css',
        [],
        VITAL_SEARCH_VERSION
    );
}

/**
 * Add admin menu for search index management
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Vital Search Index',
        'Search Index',
        'manage_options',
        'vital-search-index',
        'vital_search_admin_page'
    );
});

/**
 * Admin page to manage search index
 */
function vital_search_admin_page() {
    $upload_dir = wp_upload_dir();
    $json_path = $upload_dir['basedir'] . '/search-index.json';
    $json_url = $upload_dir['baseurl'] . '/search-index.json';
    $json_exists = file_exists($json_path);
    $version = get_option('vital_search_version', 0);

    if (isset($_POST['vital_search_regenerate']) && check_admin_referer('vital_search_regenerate_index')) {
        $version = vital_search_export_json();
        echo '<div class="notice notice-success"><p>Search index regenerated! Version: ' . esc_html($version) . '</p></div>';
        $json_exists = true;
    }

    ?>
    <div class="wrap">
        <h1>Vital Search Index</h1>

        <table class="form-table">
            <tr>
                <th>JSON File</th>
                <td>
                    <?php if ($json_exists): ?>
                        <span style="color: green;">&#10003; Exists</span>
                        (<a href="<?php echo esc_url($json_url); ?>" target="_blank">View JSON</a>)
                    <?php else: ?>
                        <span style="color: red;">&#10007; Not found</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Current Version</th>
                <td><?php echo $version ? date('Y-m-d H:i:s', $version) : 'Not set'; ?></td>
            </tr>
            <tr>
                <th>File Path</th>
                <td><code><?php echo esc_html($json_path); ?></code></td>
            </tr>
        </table>

        <form method="post">
            <?php wp_nonce_field('vital_search_regenerate_index'); ?>
            <p>
                <button type="submit" name="vital_search_regenerate" class="button button-primary">
                    Regenerate Search Index
                </button>
            </p>
        </form>

        <h2>Shortcode Usage</h2>
        <p><code>[vital_search]</code> - Adds a search button</p>
        <p>Optional attributes: <code>button_text="Search"</code>, <code>placeholder="Search"</code></p>
        <p><em>Note: The old <code>[vs_product_search]</code> shortcode still works for backward compatibility.</em></p>
    </div>
    <?php
}

/**
 * Admin action to manually trigger export (legacy endpoint)
 */
add_action('admin_post_vital_search_export', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('vital_search_export');

    $version = vital_search_export_json();

    wp_redirect(add_query_arg([
        'vital_search_export' => 'success',
        'version' => $version
    ], admin_url()));
    exit;
});
