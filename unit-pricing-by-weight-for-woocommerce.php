<?php
/**
 * Plugin Name: Unit Pricing by Weight for WooCommerce
 * Description: Displays catalog prices per unit of weight and filters classic WooCommerce product archives by unit price.
 * Version: 4.1.3
 * Author: biduk
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: biduk-price-per-weight
 */

namespace Biduk\PricePerWeight;

defined('ABSPATH') || exit;

const REINDEX_HOOK = 'biduk_price_per_weight_reindex';
const BATCH_SIZE = 100;
const SETTINGS_TAB = 'biduk_price_per_weight';
const SETTINGS_MENU = 'biduk-price-per-weight';
const DISPLAY_UNITS = ['kg' => 'kg', 'g' => 'g', 'lbs' => 'lb', 'oz' => 'oz'];

function settings_url(): string {
    return admin_url('admin.php?page=wc-settings&tab=' . SETTINGS_TAB);
}

function register_settings_menu(): void {
    $label = __('Price per Weight', 'biduk-price-per-weight');
    $redirect = static function (): void {
        wp_safe_redirect(settings_url());
        exit;
    };
    $page = add_submenu_page('woocommerce', $label, $label, 'manage_woocommerce', SETTINGS_MENU, $redirect, 4);
    if ($page) {
        add_action('load-' . $page, $redirect);
    }
}

function display_unit(): string {
    $unit = get_option('biduk_ppw_display_unit', 'kg');
    return isset(DISPLAY_UNITS[$unit]) ? $unit : 'kg';
}

function category_scope(): array {
    $mode = get_option('biduk_ppw_category_mode', 'all');
    $ids = array_values(array_filter(array_map('absint', (array) get_option('biduk_ppw_categories', []))));
    return [$mode, $ids];
}

function shows_unit_price(\WC_Product $product): bool {
    [$mode, $selected] = category_scope();
    if ($mode === 'all') {
        return true;
    }
    $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $categories = get_the_terms($product_id, 'product_cat');
    $matches = false;
    if (is_array($categories)) {
        foreach ($categories as $term) {
            $branch = array_merge([(int) $term->term_id], get_ancestors($term->term_id, 'product_cat', 'taxonomy'));
            if (array_intersect($selected, $branch)) {
                $matches = true;
                break;
            }
        }
    }
    return $mode === 'include' ? $matches : !$matches;
}

function settings_fields(): array {
    $options = [];
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $options[$term->term_id] = get_term_parents_list($term->term_id, 'product_cat', [
                'link' => false, 'separator' => ' / ', 'inclusive' => false,
            ]) . $term->name;
        }
    }
    return [
        [
            'title' => __('Price per Weight', 'biduk-price-per-weight'),
            'type' => 'title',
            'id' => SETTINGS_TAB,
        ],
        [
            'title' => __('Price display unit', 'biduk-price-per-weight'),
            'id' => 'biduk_ppw_display_unit',
            'type' => 'select',
            'default' => 'kg',
            'options' => DISPLAY_UNITS,
            'desc' => __('Unit used for the displayed price and the price filter.', 'biduk-price-per-weight'),
        ],
        [
            'title' => __('Category visibility', 'biduk-price-per-weight'),
            'id' => 'biduk_ppw_category_mode',
            'type' => 'select',
            'default' => 'all',
            'options' => [
                'all' => __('Show in all categories', 'biduk-price-per-weight'),
                'include' => __('Show only in selected categories', 'biduk-price-per-weight'),
                'exclude' => __('Hide in selected categories', 'biduk-price-per-weight'),
            ],
        ],
        [
            'title' => __('Product categories', 'biduk-price-per-weight'),
            'id' => 'biduk_ppw_categories',
            'type' => 'multiselect',
            'class' => 'wc-enhanced-select',
            'css' => 'min-width: 300px;',
            'default' => [],
            'options' => $options,
            'desc' => __('Selections include child categories. A match in any assigned category counts.', 'biduk-price-per-weight'),
        ],
        ['type' => 'sectionend', 'id' => SETTINGS_TAB],
    ];
}

function render_settings(): void {
    $currency_url = admin_url('admin.php?page=wc-settings&tab=general#woocommerce_currency');
    $weight_url = admin_url('admin.php?page=wc-settings&tab=products#woocommerce_weight_unit');
    ?>
    <p>
        <?php esc_html_e('WooCommerce controls the store currency and the unit used to enter product weights. Choose the unit used to display prices below.', 'biduk-price-per-weight'); ?>
    </p>
    <ul>
        <li><a href="<?php echo esc_url($currency_url); ?>"><?php esc_html_e('Change store currency', 'biduk-price-per-weight'); ?></a>
            (<?php echo esc_html(get_option('woocommerce_currency')); ?>)</li>
        <li><a href="<?php echo esc_url($weight_url); ?>"><?php esc_html_e('Change product weight unit', 'biduk-price-per-weight'); ?></a>
            (<?php echo esc_html(get_option('woocommerce_weight_unit', 'kg')); ?>)</li>
    </ul>
    <?php
    \WC_Admin_Settings::output_fields(settings_fields());
}

function index_key(): string {
    $key = '_biduk_price_per_kg_' . sanitize_key(get_option('woocommerce_weight_unit', 'kg'));
    return display_unit() === 'kg' ? $key : $key . '_to_' . display_unit();
}

function unit_price(\WC_Product $product): ?float {
    if ($product->is_type('variable')) {
        $lowest = null;
        foreach ($product->get_available_variations('objects') as $variation) {
            $value = unit_price($variation);
            if ($value !== null && ($lowest === null || $value < $lowest)) {
                $lowest = $value;
            }
        }
        return $lowest;
    }

    $price = $product->get_price('edit');
    $weight = (float) wc_get_weight((float) $product->get_weight(), display_unit());
    if (!is_numeric($price) || (float) $price < 0 || $weight <= 0) {
        return null;
    }
    return round((float) $price / $weight, wc_get_price_decimals());
}

function format_price(float $price): string {
    return wc_price($price, ['currency' => get_option('woocommerce_currency')]);
}

function append_unit_price(string $html, \WC_Product $product): string {
    if (is_admin() || $html === '' || !shows_unit_price($product)) {
        return $html;
    }
    $price = unit_price($product);
    if ($price === null) {
        return $html;
    }
    $label = $product->is_type('variable') ? __('From %1$s / %2$s', 'biduk-price-per-weight') : __('%1$s / %2$s', 'biduk-price-per-weight');
    return $html . '<small class="biduk-price-per-weight">' . sprintf(esc_html($label), format_price($price), esc_html(DISPLAY_UNITS[display_unit()])) . '</small>';
}

function index_product(\WC_Product $product): void {
    $price = unit_price($product);
    if ($price === null) {
        delete_post_meta($product->get_id(), index_key());
        return;
    }
    update_post_meta($product->get_id(), index_key(), $price);
}

function refresh_index(int $product_id): void {
    $product = wc_get_product($product_id);
    if ($product && $product->is_type('variation')) {
        $product = wc_get_product($product->get_parent_id());
    }
    if ($product) {
        index_product($product);
    }
}

function schedule_reindex(): void {
    if (!wp_next_scheduled(REINDEX_HOOK, [0])) {
        wp_schedule_single_event(time(), REINDEX_HOOK, [0]);
    }
}

function reindex_batch(int $cursor): void {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND ID > %d ORDER BY ID LIMIT %d",
        $cursor,
        BATCH_SIZE
    ));
    foreach ($ids as $id) {
        refresh_index((int) $id);
    }
    if (count($ids) === BATCH_SIZE) {
        wp_schedule_single_event(time(), REINDEX_HOOK, [(int) end($ids)]);
    }
}

function filter_bounds(): ?array {
    foreach ([['biduk_ppk_min', 'biduk_ppk_max'], ['bb_ppk_min', 'bb_ppk_max']] as [$min_key, $max_key]) {
        $min = $_GET[$min_key] ?? null;
        $max = $_GET[$max_key] ?? null;
        if (is_numeric($min) && is_numeric($max) && is_finite((float) $min) && is_finite((float) $max)) {
            $factor = $min_key === 'bb_ppk_min' ? wc_get_weight(1, display_unit(), 'kg') : 1;
            return [
                max(0, min((float) $min, (float) $max)) / $factor,
                max(0, max((float) $min, (float) $max)) / $factor,
            ];
        }
    }
    return null;
}

function filter_product_query(\WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || ($bounds = filter_bounds()) === null) {
        return;
    }
    $existing = $query->get('meta_query');
    $meta_query = ['relation' => 'AND'];
    if (!empty($existing)) {
        $meta_query[] = $existing;
    }
    $meta_query[] = ['key' => index_key(), 'value' => $bounds, 'compare' => 'BETWEEN', 'type' => 'DECIMAL(30,12)'];
    $query->set('meta_query', $meta_query);
    [$mode, $categories] = category_scope();
    if ($mode === 'all' || ($mode === 'exclude' && !$categories)) {
        return;
    }
    if (!$categories) {
        $query->set('post__in', [0]);
        return;
    }
    $tax_query = ['relation' => 'AND'];
    $existing_tax = $query->get('tax_query');
    if (!empty($existing_tax)) {
        $tax_query[] = $existing_tax;
    }
    $tax_query[] = [
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => $categories,
        'include_children' => true,
        'operator' => $mode === 'include' ? 'IN' : 'NOT IN',
    ];
    $query->set('tax_query', $tax_query);
}

function render_filter(string $url): void {
    [$min, $max] = filter_bounds() ?? ['', ''];
    $currency_symbol = get_woocommerce_currency_symbol(get_option('woocommerce_currency'));
    $unit_suffix = html_entity_decode($currency_symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '/' . DISPLAY_UNITS[display_unit()];
    $query = wp_parse_url($url, PHP_URL_QUERY);
    $url = strtok($url, '?');
    $route = [];
    if (is_string($query)) {
        parse_str($query, $route);
    }
    ?>
    <form method="get" action="<?php echo esc_url($url); ?>" class="biduk-price-per-weight-filter">
        <p>
            <label><?php printf(esc_html__('Minimum %s', 'biduk-price-per-weight'), esc_html($unit_suffix)); ?>
                <br>
                <input type="number" min="0" step="any" name="biduk_ppk_min" value="<?php echo esc_attr($min); ?>" required>
            </label>
        </p>
        <p>
            <label><?php printf(esc_html__('Maximum %s', 'biduk-price-per-weight'), esc_html($unit_suffix)); ?>
                <br>
                <input type="number" min="0" step="any" name="biduk_ppk_max" value="<?php echo esc_attr($max); ?>" required>
            </label>
        </p>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'biduk-price-per-weight'); ?></button>
        <?php
        wc_query_string_form_fields(
            $route + wc_clean(wp_unslash($_GET)),
            ['biduk_ppk_min', 'biduk_ppk_max', 'bb_ppk_min', 'bb_ppk_max', 'paged', 'product-page']
        );
        ?>
    </form>
    <?php
}

final class Price_Filter_Widget extends \WP_Widget {
    public function __construct() {
        parent::__construct('biduk_price_per_weight', __('Biduk Price per Weight Filter', 'biduk-price-per-weight'), [
            'description' => __('Filters WooCommerce product archives by unit price.', 'biduk-price-per-weight'),
        ]);
    }

    public function widget($args, $instance) {
        if (!(is_shop() || is_product_taxonomy())) {
            return;
        }
        $url = is_shop() ? wc_get_page_permalink('shop') : get_term_link(get_queried_object());
        if (!is_string($url) || $url === '') {
            return;
        }
        $title = sprintf(__('Filter by price per %s', 'biduk-price-per-weight'), DISPLAY_UNITS[display_unit()]);
        echo $args['before_widget'];
        echo $args['before_title'] . esc_html($title) . $args['after_title'];
        render_filter($url);
        echo $args['after_widget'];
    }
}

function place_beside_price_widget(array $widget): void {
    if (
        !preg_match('/^woocommerce_price_filter-\d+$/', $widget['id'] ?? '')
        || is_active_widget(false, false, 'biduk_price_per_weight', true)
    ) {
        return;
    }
    the_widget(Price_Filter_Widget::class, [], [
        'before_widget' => '<section class="widget widget_biduk_price_per_weight">',
        'after_widget' => '</section>',
        'before_title' => '<h2 class="widget-title">',
        'after_title' => '</h2>',
    ]);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\schedule_reindex');
register_deactivation_hook(__FILE__, static function (): void {
    wp_unschedule_hook(REINDEX_HOOK);
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    add_filter('woocommerce_settings_tabs_array', static function (array $tabs): array {
        $tabs[SETTINGS_TAB] = __('Price per Weight', 'biduk-price-per-weight');
        return $tabs;
    });
    add_action('woocommerce_settings_tabs_' . SETTINGS_TAB, __NAMESPACE__ . '\render_settings');
    add_action('woocommerce_update_options_' . SETTINGS_TAB, static function (): void {
        \WC_Admin_Settings::save_fields(settings_fields());
    });
    add_action('admin_menu', __NAMESPACE__ . '\register_settings_menu', 60);
    add_filter('submenu_file', static function ($submenu) {
        if (($GLOBALS['plugin_page'] ?? '') === 'wc-settings' && ($_GET['tab'] ?? '') === SETTINGS_TAB) {
            return SETTINGS_MENU;
        }
        return $submenu;
    });
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
        array_unshift($links, '<a href="' . esc_url(settings_url()) . '">' . esc_html__('Settings', 'biduk-price-per-weight') . '</a>');
        return $links;
    });
    add_filter('woocommerce_get_price_html', __NAMESPACE__ . '\append_unit_price', 20, 2);
    add_action('woocommerce_after_product_object_save', static function (\WC_Product $product): void {
        refresh_index($product->get_id());
    });
    add_action('woocommerce_after_product_variation_object_save', static function (\WC_Product $variation): void {
        refresh_index($variation->get_id());
    });
    add_action('woocommerce_variable_product_sync_data', __NAMESPACE__ . '\index_product');
    add_action('update_option_woocommerce_weight_unit', __NAMESPACE__ . '\schedule_reindex');
    add_action('update_option_biduk_ppw_display_unit', __NAMESPACE__ . '\schedule_reindex');
    add_action('add_option_biduk_ppw_display_unit', __NAMESPACE__ . '\schedule_reindex');
    add_action('update_option_woocommerce_price_num_decimals', __NAMESPACE__ . '\schedule_reindex');
    add_action(REINDEX_HOOK, __NAMESPACE__ . '\reindex_batch');
    add_action('woocommerce_product_query', __NAMESPACE__ . '\filter_product_query', 20);
    add_action('widgets_init', static function (): void {
        register_widget(Price_Filter_Widget::class);
    });
    add_action('dynamic_sidebar', __NAMESPACE__ . '\place_beside_price_widget');
});
