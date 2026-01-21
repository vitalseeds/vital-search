<?php
/**
 * Template for the /search page
 *
 * Shows a search form at the top and WooCommerce product results below.
 * Form submits back to /search with query parameters.
 *
 * @package VitalSearch
 */

// Enqueue search styles (in case nav menu isn't present)
wp_enqueue_style(
    'vital-search',
    VITAL_SEARCH_PLUGIN_URL . 'css/vital-search.css',
    [],
    VITAL_SEARCH_VERSION
);

$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <header class="entry-header">
            <h1 class="entry-title"><?php esc_html_e('Search Products', 'vital-search'); ?></h1>
        </header>

        <div class="vital-search-form-wrapper">
            <form role="search" method="get" class="vital-search-form" action="<?php echo esc_url(home_url('/search')); ?>">
                <label for="vital-search-input" class="screen-reader-text"><?php esc_html_e('Search for:', 'vital-search'); ?></label>
                <input type="search"
                       id="vital-search-input"
                       class="vital-search-input"
                       placeholder="<?php esc_attr_e('Search products...', 'vital-search'); ?>"
                       value="<?php echo esc_attr($search_query); ?>"
                       name="s"
                       autofocus>
                <button type="submit" class="vital-search-submit button">
                    <?php esc_html_e('Search', 'vital-search'); ?>
                </button>
            </form>
        </div>

        <?php if ($search_query) : ?>
            <?php
            $paged = get_query_var('paged') ? get_query_var('paged') : 1;

            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                's'              => $search_query,
                'posts_per_page' => wc_get_default_products_per_row() * wc_get_default_product_rows_per_page(),
                'paged'          => $paged,
            ];

            $products = new WP_Query($args);
            ?>

            <div class="vital-search-results">
                <?php if ($products->have_posts()) : ?>
                    <p class="woocommerce-result-count">
                        <?php
                        printf(
                            _n(
                                '%d result found for "%s"',
                                '%d results found for "%s"',
                                $products->found_posts,
                                'vital-search'
                            ),
                            $products->found_posts,
                            esc_html($search_query)
                        );
                        ?>
                    </p>

                    <?php woocommerce_product_loop_start(); ?>

                    <?php while ($products->have_posts()) : $products->the_post(); ?>
                        <?php wc_get_template_part('content', 'product'); ?>
                    <?php endwhile; ?>

                    <?php woocommerce_product_loop_end(); ?>

                    <?php if ($products->max_num_pages > 1) : ?>
                        <nav class="woocommerce-pagination">
                            <?php
                            echo paginate_links([
                                'base'      => esc_url(home_url('/search')) . '?s=' . urlencode($search_query) . '%_%',
                                'format'    => '&paged=%#%',
                                'current'   => $paged,
                                'total'     => $products->max_num_pages,
                                'prev_text' => '&larr;',
                                'next_text' => '&rarr;',
                                'type'      => 'list',
                            ]);
                            ?>
                        </nav>
                    <?php endif; ?>

                <?php else : ?>
                    <p class="woocommerce-info">
                        <?php esc_html_e('No products found matching your search.', 'vital-search'); ?>
                    </p>
                <?php endif; ?>

                <?php wp_reset_postdata(); ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php
get_sidebar();
get_footer();
