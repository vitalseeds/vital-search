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

define('VITAL_SEARCH_VERSION', filemtime(__DIR__ . '/js/vital-search.js'));
define('VITAL_SEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VITAL_SEARCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VITAL_SEARCH_CRON_HOOK', 'vital_search_export');
define('VITAL_SEEDS_CATEGORY_SLUG', 'seeds');
/**
 * Schedule the daily cron job if not already scheduled
 */
function vital_search_schedule_cron() {
    if (!wp_next_scheduled(VITAL_SEARCH_CRON_HOOK)) {
        wp_schedule_event(strtotime('today 3:00am'), 'daily', VITAL_SEARCH_CRON_HOOK);
    }
}

/**
 * Schedule cron on plugin activation
 */
function vital_search_activate() {
    vital_search_rewrite_rule();
    flush_rewrite_rules();
    vital_search_schedule_cron();
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

// Ensure cron is scheduled on init (in case missed or after re-activation)
add_action('init', 'vital_search_schedule_cron');

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
 * Get thumbnail URL for an attachment ID, with placeholder fallback
 *
 * @param int $thumbnail_id Attachment ID (can be 0 or empty)
 * @return string Thumbnail URL
 */
function vital_search_get_thumbnail_url($thumbnail_id) {
    if ($thumbnail_id) {
        return wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail');
    }
    return wc_placeholder_img_src('woocommerce_thumbnail');
}

/**
 * Export products, categories and tags to JSON file
 *
 * @param bool $return_details Whether to return detailed result array
 * @return int|array Version timestamp, or array with details if $return_details is true
 */
function vital_search_export_json($return_details = false) {
    $upload_dir = wp_upload_dir();
    $json_path = $upload_dir['basedir'] . '/search-index.json';

    $version = time();

    $products = vital_search_get_products();
    $categories = vital_search_get_categories();
    $tags = vital_search_get_tags();

    $items = array_merge($products, $categories, $tags);

    $data = [
        'version' => $version,
        'generated' => date('c'),
        'items' => $items
    ];

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $bytes_written = file_put_contents($json_path, $json);

    if ($bytes_written === false) {
        if ($return_details) {
            return [
                'success' => false,
                'error' => 'Failed to write JSON file. Check directory permissions.',
                'path' => $json_path,
            ];
        }
        return 0;
    }

    update_option('vital_search_version', $version);

    if ($return_details) {
        return [
            'success' => true,
            'version' => $version,
            'product_count' => count($products),
            'category_count' => count($categories),
            'tag_count' => count($tags),
            'file_size' => $bytes_written,
        ];
    }

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
    $heading_categories = vital_search_load_heading_categories();

    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'has_password' => false,
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];

    $product_ids = get_posts($args);

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        if ($product->get_catalog_visibility() === 'hidden') continue;

        $thumbnail_url = vital_search_get_thumbnail_url($product->get_image_id());
        $terms = get_the_terms($product_id, 'product_cat');
        $categories = [];
        $top_category = null;

        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }

            // Find the best heading category for this product
            $top_category = vital_search_get_heading_category($terms, $heading_categories);
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
            'top_category' => $top_category,
            'sku' => $product->get_sku(),
        ];
    }

    return $products;
}

/**
 * Find the best heading category for a product based on its terms
 *
 * Checks which selected heading categories the product belongs to
 * (either directly or as a descendant) and returns the one highest in the hierarchy.
 *
 * @param array $product_terms Product's category terms
 * @param array $heading_categories Selected heading categories with depth info
 * @return string|null Category name or null if none found
 */
function vital_search_get_heading_category($product_terms, $heading_categories) {
    if (empty($heading_categories)) {
        return vital_search_get_top_level_category($product_terms);
    }

    // Build a list of all category IDs the product belongs to (including ancestors)
    $product_category_ids = vital_search_collect_category_ids_with_ancestors($product_terms);

    // Find matching heading categories and pick the one with lowest depth (highest in hierarchy)
    $best_match = null;
    $best_depth = PHP_INT_MAX;

    foreach ($heading_categories as $cat_id => $cat_info) {
        if (in_array($cat_id, $product_category_ids) && $cat_info['depth'] < $best_depth) {
            $best_depth = $cat_info['depth'];
            // Use the category name as the results section heading, but
            // substitute 'Varieties' for category name 'Seeds'
            $best_match = ($cat_info['term']->slug === VITAL_SEEDS_CATEGORY_SLUG) ? 'Varieties' : $cat_info['term']->name;
        }
    }

    return $best_match;
}

/**
 * Get the top-level category name for a product
 *
 * @param array $product_terms Product's category terms
 * @return string Category name
 */
function vital_search_get_top_level_category($product_terms) {
    // Check for direct top-level category
    foreach ($product_terms as $term) {
        if ($term->parent == 0) {
            return $term->name;
        }
    }

    // Traverse up to find top-level ancestor
    $first_term = reset($product_terms);
    $ancestors = get_ancestors($first_term->term_id, 'product_cat', 'taxonomy');

    if (!empty($ancestors)) {
        $top_term = get_term(end($ancestors), 'product_cat');
        if ($top_term && !is_wp_error($top_term)) {
            return $top_term->name;
        }
    }

    return $first_term->name;
}

/**
 * Collect all category IDs for terms including their ancestors
 *
 * @param array $terms Category terms
 * @return array Unique category IDs
 */
function vital_search_collect_category_ids_with_ancestors($terms) {
    $category_ids = [];

    foreach ($terms as $term) {
        $category_ids[] = $term->term_id;
        $ancestors = get_ancestors($term->term_id, 'product_cat', 'taxonomy');
        $category_ids = array_merge($category_ids, $ancestors);
    }

    return array_unique($category_ids);
}

/**
 * Load heading categories with depth information from settings
 *
 * @return array Heading categories keyed by term ID with 'term' and 'depth' values
 */
function vital_search_load_heading_categories() {
    $heading_category_ids = get_option('vital_search_heading_categories', []);

    if (empty($heading_category_ids)) {
        return [];
    }

    $heading_categories = [];
    foreach ($heading_category_ids as $cat_id) {
        $term = get_term($cat_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        $ancestors = get_ancestors($cat_id, 'product_cat', 'taxonomy');
        $heading_categories[$cat_id] = [
            'term' => $term,
            'depth' => count($ancestors),
        ];
    }

    return $heading_categories;
}

/**
 * Get seed categories formatted for search
 *
 * @return array
 */
function vital_search_get_categories() {
    $categories = [];

    $seeds_parent = get_term_by('slug', VITAL_SEEDS_CATEGORY_SLUG, 'product_cat');
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
        $thumbnail_url = vital_search_get_thumbnail_url($thumbnail_id);

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
 * Get product tags formatted for search
 *
 * @return array
 */
function vital_search_get_tags() {
    $tags = [];

    $terms = get_terms([
        'taxonomy' => 'product_tag',
        'hide_empty' => true,
    ]);

    if (is_wp_error($terms)) {
        return $tags;
    }

    foreach ($terms as $term) {
        $tags[] = [
            'id' => 'tag-' . $term->term_id,
            'type' => 'tag',
            'title' => $term->name,
            'url' => get_term_link($term),
            'top_category' => 'Tags',
            'count' => $term->count,
        ];
    }

    return $tags;
}

/**
 * Render a search trigger button/link
 *
 * @param array $args {
 *     @type string $button_text  Screen reader text for the button
 *     @type string $button_class CSS class(es) for the link
 *     @type string $placeholder  Placeholder text for search input
 * }
 * @return string HTML for the search trigger link
 */
function vital_search_render_trigger($args = []) {
    $args = wp_parse_args($args, [
        'button_text' => __('Search', 'vital-search'),
        'button_class' => 'vital-search-button',
        'placeholder' => __('Search', 'vital-search'),
    ]);

    $version = get_option('vital_search_version', time());
    $search_url = home_url('/search');

    return sprintf(
        '<a href="%s" class="%s" data-vital-search-trigger data-vital-search-version="%s" data-vital-search-placeholder="%s">' .
        '<span class="screen-reader-text">%s</span>' .
        '<svg width="20" height="20" class="search-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' .
        '<path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>' .
        '</svg></a>',
        esc_url($search_url),
        esc_attr($args['button_class']),
        esc_attr($version),
        esc_attr($args['placeholder']),
        esc_html($args['button_text'])
    );
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

    return vital_search_render_trigger($atts);
}
add_shortcode('vital_search', 'vital_search_shortcode');

/**
 * Add search link as last item in primary navigation menu
 *
 * Progressive enhancement: renders as a link to /search that works without JS.
 * JavaScript enhances the link to open the search popup.
 */
function vital_search_add_to_nav_menu($items, $args) {
    if ($args->theme_location !== 'primary') {
        return $items;
    }

    vital_search_enqueue_assets();

    $trigger = vital_search_render_trigger([
        'button_class' => 'vital-search-button vital-search-button--header',
    ]);

    return $items . '<li class="menu-item vital-search-menu-item">' . $trigger . '</li>';
}
add_filter('wp_nav_menu_items', 'vital_search_add_to_nav_menu', 10, 2);

/**
 * Enqueue assets for Storefront handheld footer bar search integration
 *
 * The Storefront theme includes a handheld footer bar on mobile with a search link.
 * We enqueue our assets to replace that search with vital-search.
 */
function vital_search_storefront_handheld_footer() {
    if (!is_admin() && function_exists('storefront_handheld_footer_bar')) {
        vital_search_enqueue_assets();
    }
}
add_action('wp_enqueue_scripts', 'vital_search_storefront_handheld_footer');

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

    wp_localize_script('vital-search', 'vitalSearch', [
        'jsonUrl' => '/wp-content/uploads/search-index.json',
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

    // Handle settings save
    if (isset($_POST['vital_search_save_settings']) && check_admin_referer('vital_search_settings')) {
        $heading_categories = isset($_POST['vital_search_heading_categories']) ? array_map('intval', $_POST['vital_search_heading_categories']) : [];
        update_option('vital_search_heading_categories', $heading_categories);
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    if (isset($_POST['vital_search_regenerate']) && check_admin_referer('vital_search_regenerate_index')) {
        $result = vital_search_export_json(true);
        if ($result['success']) {
            $version = $result['version'];
            $json_exists = true;
            printf(
                '<div class="notice notice-success"><p>Search index regenerated! %d products, %d categories, %d tags. File size: %s</p></div>',
                $result['product_count'],
                $result['category_count'],
                $result['tag_count'],
                size_format($result['file_size'])
            );
        } else {
            echo '<div class="notice notice-error"><p>Error: ' . esc_html($result['error']) . '</p><p>Path: <code>' . esc_html($result['path']) . '</code></p></div>';
        }
    }

    $heading_categories = get_option('vital_search_heading_categories', []);

    // Get all product categories for the multiselect
    $all_categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
    ]);

    ?>
    <div class="wrap">
        <h1>Vital Search Index</h1>

        <h2>Settings</h2>
        <form method="post">
            <?php wp_nonce_field('vital_search_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="vital_search_heading_categories">Search result headings</label>
                    </th>
                    <td>
                        <select name="vital_search_heading_categories[]" id="vital_search_heading_categories" multiple="multiple" style="min-width: 300px; min-height: 200px;">
                            <?php
                            if (!is_wp_error($all_categories)) {
                                echo vital_search_render_category_options($all_categories, 0, $heading_categories);
                            }
                            ?>
                        </select>
                        <p class="description">
                            Select product categories to use as search result headings.
                            <br>Products will be grouped under the highest selected category in their hierarchy.
                            <br>For example, if both <em>Seed</em> and <em>Vegetable Seed</em> are selected, vegetable products will be displayed under Seed.
                            <br>Hold Ctrl/Cmd to select multiple categories.
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="vital_search_save_settings" class="button button-secondary">
                    Save Settings
                </button>
            </p>
        </form>

        <hr>

        <h2>Index Status</h2>
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

        <hr>

        <h2>Shortcode Usage</h2>
        <p><code>[vital_search]</code> - Adds a search button</p>
        <p>Optional attributes: <code>button_text="Search"</code>, <code>placeholder="Search"</code></p>
    </div>
    <?php
}

/**
 * Render hierarchical category options for the multiselect
 *
 * @param array $categories All categories
 * @param int $parent Parent term ID
 * @param array $selected Selected category IDs
 * @param int $depth Current depth for indentation
 * @return string HTML options
 */
function vital_search_render_category_options($categories, $parent = 0, $selected = [], $depth = 0) {
    $html = '';
    foreach ($categories as $category) {
        if ($category->parent != $parent) {
            continue;
        }
        $indent = str_repeat('— ', $depth);
        $is_selected = in_array($category->term_id, $selected) ? 'selected' : '';
        $html .= sprintf(
            '<option value="%d" %s>%s%s</option>',
            $category->term_id,
            $is_selected,
            $indent,
            esc_html($category->name)
        );
        // Recursively add children
        $html .= vital_search_render_category_options($categories, $category->term_id, $selected, $depth + 1);
    }
    return $html;
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

/**
 * Register WP-CLI command to rebuild search index
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('vital-search', 'Vital_Search_CLI');
}

/**
 * WP-CLI commands for Vital Search
 */
class Vital_Search_CLI {

    /**
     * Rebuild the search index
     *
     * ## EXAMPLES
     *
     *     wp vital-search rebuild
     *
     * @when after_wp_load
     */
    public function rebuild($args, $assoc_args) {
        WP_CLI::log('Rebuilding search index...');

        $result = vital_search_export_json(true);

        if ($result['success']) {
            WP_CLI::success(sprintf(
                'Search index rebuilt: %d products, %d categories, %d tags. File size: %s',
                $result['product_count'],
                $result['category_count'],
                $result['tag_count'],
                size_format($result['file_size'])
            ));
        } else {
            WP_CLI::error($result['error']);
        }
    }
}
