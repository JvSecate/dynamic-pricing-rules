<?php
/**
 * Plugin Name: Store Dynamic Pricing
 * Description: Adds WooCommerce quantity tiers, product/category bulk discounts, and buy X get Y offers.
 * Version: 0.1.1
 * Author: Jv Secate
 * Text Domain: dynamic-pricing-rules
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 9.9
 */

defined('ABSPATH') || exit;

final class Store_Dynamic_Pricing {
    private const OPTION_RULES = 'store_dynamic_pricing_rules';
    private const BASE_PRICE = '_store_dynamic_pricing_base_price';
    private const PRICE_WAS_CHANGED = '_store_dynamic_pricing_price_was_changed';
    private const APPLIED_LABEL = '_store_dynamic_pricing_applied_label';
    private const APPLIED_DISPLAY_TITLE = '_store_dynamic_pricing_applied_display_title';

    public static function activate(): void {
        self::load_textdomain();

        if (false === get_option(self::OPTION_RULES, false)) {
            add_option(self::OPTION_RULES, self::default_rules());
        }
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'dynamic-pricing-rules',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    public static function init(): void {
        $plugin = new self();
        $plugin->hooks();
    }

    private function hooks(): void {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'settings_link']);

        if (!$this->woocommerce_available()) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        add_action('woocommerce_before_calculate_totals', [$this, 'apply_quantity_pricing'], 20);
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_buy_x_get_y_offers'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_discount'], 10, 2);
    }

    public function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            __('Dynamic Pricing', 'dynamic-pricing-rules'),
            __('Dynamic Pricing', 'dynamic-pricing-rules'),
            'manage_woocommerce',
            'dynamic-pricing-rules',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting(
            'store_dynamic_pricing',
            self::OPTION_RULES,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_rules'],
                'default' => self::default_rules(),
            ]
        );
    }

    public function settings_link(array $links): array {
        $settings_url = admin_url('admin.php?page=dynamic-pricing-rules');
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url($settings_url),
                esc_html__('Settings', 'dynamic-pricing-rules')
            )
        );

        return $links;
    }

    public function enqueue_admin_assets(string $hook_suffix): void {
        if ('woocommerce_page_dynamic-pricing-rules' !== $hook_suffix) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('wc-enhanced-select');

        wp_add_inline_style(
            'woocommerce_admin_styles',
            '.dpr-settings-grid{display:grid;gap:18px;max-width:1120px}.dpr-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:18px 20px}.dpr-card h2{margin:0 0 12px}.dpr-card table.form-table{margin-top:0}.dpr-card .form-table th{width:210px}.dpr-card input.regular-text{width:420px;max-width:100%}.dpr-tiers{border-collapse:collapse;width:100%;max-width:780px;margin-top:8px}.dpr-tiers th,.dpr-tiers td{border:1px solid #dcdcde;padding:8px;text-align:left;vertical-align:middle}.dpr-tiers th{background:#f6f7f7}.dpr-tiers input.small-text{width:88px}.dpr-field-note{color:#646970;margin-top:6px}.dpr-rule-toggle{margin:0 0 12px}.dpr-product-select,.dpr-card .select2-container,.dpr-card .selectWoo-container{width:420px!important;max-width:100%!important}.dpr-scope-row.is-hidden{display:none}.dpr-inline-number{width:74px!important}'
        );

        $admin_js = <<<'JS'
jQuery(function($) {
    $(document.body).trigger('wc-enhanced-select-init');

    function updateDprScopes() {
        var pairs = [
            ['#dpr_quantity_scope', 'quantity'],
            ['#dpr_buy_get_scope', 'buy-get']
        ];

        pairs.forEach(function(pair) {
            var scope = $(pair[0]).val();
            $('.dpr-scope-row[data-rule="' + pair[1] + '"]').addClass('is-hidden');
            $('.dpr-scope-row[data-rule="' + pair[1] + '"][data-scope="' + scope + '"]').removeClass('is-hidden');
        });
    }

    $(document).on('change', '#dpr_quantity_scope,#dpr_buy_get_scope', updateDprScopes);
    updateDprScopes();
});
JS;

        wp_add_inline_script('wc-enhanced-select', $admin_js);
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage dynamic pricing.', 'dynamic-pricing-rules'));
        }

        $rules = $this->get_rules(false);
        $quantity_rule = $this->first_rule_by_type($rules, 'quantity_tier') ?: $this->normalize_rule(self::default_rules()[0]);
        $buy_get_rule = $this->first_rule_by_type($rules, 'buy_x_get_y') ?: $this->normalize_rule(self::default_rules()[1]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Dynamic Pricing', 'dynamic-pricing-rules'); ?></h1>
            <?php settings_errors('store_dynamic_pricing'); ?>
            <p>
                <?php esc_html_e('Create quantity discounts, target specific products or categories, and optionally run buy X get Y offers.', 'dynamic-pricing-rules'); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('store_dynamic_pricing'); ?>

                <div class="dpr-settings-grid">
                    <div class="dpr-card">
                        <h2><?php esc_html_e('Quantity Discount', 'dynamic-pricing-rules'); ?></h2>
                        <p class="dpr-rule-toggle">
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][enabled]" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][enabled]" value="1" <?php checked(!empty($quantity_rule['enabled'])); ?>>
                                <?php esc_html_e('Enable quantity discount', 'dynamic-pricing-rules'); ?>
                            </label>
                        </p>
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][id]" value="<?php echo esc_attr($quantity_rule['id']); ?>">
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][type]" value="quantity_tier">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="dpr_quantity_label"><?php esc_html_e('Discount label', 'dynamic-pricing-rules'); ?></label></th>
                                <td><input type="text" class="regular-text" id="dpr_quantity_label" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][label]" value="<?php echo esc_attr($quantity_rule['label']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_quantity_display_title"><?php esc_html_e('Checkout label', 'dynamic-pricing-rules'); ?></label></th>
                                <td>
                                    <input type="text" class="regular-text" id="dpr_quantity_display_title" name="<?php echo esc_attr(self::OPTION_RULES); ?>[0][display_title]" value="<?php echo esc_attr($quantity_rule['display_title']); ?>">
                                    <p class="dpr-field-note"><?php esc_html_e('This is the text before the colon at cart and checkout, for example Discount.', 'dynamic-pricing-rules'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_quantity_scope"><?php esc_html_e('Apply to', 'dynamic-pricing-rules'); ?></label></th>
                                <td>
                                    <?php $this->render_scope_select('dpr_quantity_scope', self::OPTION_RULES . '[0][scope]', $quantity_rule['scope']); ?>
                                    <p class="dpr-field-note"><?php esc_html_e('Choose Specific products to set a discount for one product or selected products.', 'dynamic-pricing-rules'); ?></p>
                                </td>
                            </tr>
                            <tr class="dpr-scope-row" data-rule="quantity" data-scope="product">
                                <th scope="row"><label for="dpr_quantity_products"><?php esc_html_e('Specific products', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_product_search('dpr_quantity_products', self::OPTION_RULES . '[0][product_ids][]', $quantity_rule['product_ids']); ?></td>
                            </tr>
                            <tr class="dpr-scope-row" data-rule="quantity" data-scope="category">
                                <th scope="row"><label for="dpr_quantity_categories"><?php esc_html_e('Specific categories', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_category_select('dpr_quantity_categories', self::OPTION_RULES . '[0][category_ids][]', $quantity_rule['category_ids']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_quantity_mode"><?php esc_html_e('Count quantity by', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_quantity_mode_select('dpr_quantity_mode', self::OPTION_RULES . '[0][quantity_mode]', $quantity_rule['quantity_mode']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Discount tiers', 'dynamic-pricing-rules'); ?></th>
                                <td>
                                    <?php $this->render_tiers_table(self::OPTION_RULES . '[0][tiers]', $quantity_rule['tiers']); ?>
                                    <p class="dpr-field-note"><?php esc_html_e('Leave Max empty for open-ended tiers like 6+ items.', 'dynamic-pricing-rules'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dpr-card">
                        <h2><?php esc_html_e('Buy X, Get Y Free', 'dynamic-pricing-rules'); ?></h2>
                        <p class="dpr-rule-toggle">
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][enabled]" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][enabled]" value="1" <?php checked(!empty($buy_get_rule['enabled'])); ?>>
                                <?php esc_html_e('Enable buy X, get Y free', 'dynamic-pricing-rules'); ?>
                            </label>
                        </p>
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][id]" value="<?php echo esc_attr($buy_get_rule['id']); ?>">
                        <input type="hidden" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][type]" value="buy_x_get_y">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="dpr_buy_get_label"><?php esc_html_e('Offer label', 'dynamic-pricing-rules'); ?></label></th>
                                <td><input type="text" class="regular-text" id="dpr_buy_get_label" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][label]" value="<?php echo esc_attr($buy_get_rule['label']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_buy_get_display_title"><?php esc_html_e('Checkout label', 'dynamic-pricing-rules'); ?></label></th>
                                <td>
                                    <input type="text" class="regular-text" id="dpr_buy_get_display_title" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][display_title]" value="<?php echo esc_attr($buy_get_rule['display_title']); ?>">
                                    <p class="dpr-field-note"><?php esc_html_e('Reserved for cart item display if this offer is shown beside products.', 'dynamic-pricing-rules'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_buy_quantity"><?php esc_html_e('Offer', 'dynamic-pricing-rules'); ?></label></th>
                                <td>
                                    <?php esc_html_e('Buy', 'dynamic-pricing-rules'); ?>
                                    <input type="number" min="1" step="1" class="small-text dpr-inline-number" id="dpr_buy_quantity" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][buy_quantity]" value="<?php echo esc_attr($buy_get_rule['buy_quantity']); ?>">
                                    <?php esc_html_e('get', 'dynamic-pricing-rules'); ?>
                                    <input type="number" min="1" step="1" class="small-text dpr-inline-number" name="<?php echo esc_attr(self::OPTION_RULES); ?>[1][free_quantity]" value="<?php echo esc_attr($buy_get_rule['free_quantity']); ?>">
                                    <?php esc_html_e('free', 'dynamic-pricing-rules'); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_buy_get_scope"><?php esc_html_e('Apply to', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_scope_select('dpr_buy_get_scope', self::OPTION_RULES . '[1][scope]', $buy_get_rule['scope']); ?></td>
                            </tr>
                            <tr class="dpr-scope-row" data-rule="buy-get" data-scope="product">
                                <th scope="row"><label for="dpr_buy_get_products"><?php esc_html_e('Specific products', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_product_search('dpr_buy_get_products', self::OPTION_RULES . '[1][product_ids][]', $buy_get_rule['product_ids']); ?></td>
                            </tr>
                            <tr class="dpr-scope-row" data-rule="buy-get" data-scope="category">
                                <th scope="row"><label for="dpr_buy_get_categories"><?php esc_html_e('Specific categories', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_category_select('dpr_buy_get_categories', self::OPTION_RULES . '[1][category_ids][]', $buy_get_rule['category_ids']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dpr_buy_get_mode"><?php esc_html_e('Count quantity by', 'dynamic-pricing-rules'); ?></label></th>
                                <td><?php $this->render_quantity_mode_select('dpr_buy_get_mode', self::OPTION_RULES . '[1][quantity_mode]', $buy_get_rule['quantity_mode']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button(__('Save pricing rules', 'dynamic-pricing-rules')); ?>
            </form>
        </div>
        <?php
    }

    private function first_rule_by_type(array $rules, string $type): ?array {
        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') === $type) {
                return $rule;
            }
        }

        return null;
    }

    private function render_scope_select(string $id, string $name, string $selected): void {
        $options = [
            'all' => __('All products', 'dynamic-pricing-rules'),
            'product' => __('Specific products', 'dynamic-pricing-rules'),
            'category' => __('Specific categories', 'dynamic-pricing-rules'),
        ];

        printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    private function render_quantity_mode_select(string $id, string $name, string $selected): void {
        $options = [
            'line' => __('Each cart line', 'dynamic-pricing-rules'),
            'product' => __('Same product together', 'dynamic-pricing-rules'),
            'category' => __('Selected category together', 'dynamic-pricing-rules'),
            'cart' => __('Whole cart', 'dynamic-pricing-rules'),
        ];

        printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    private function render_product_search(string $id, string $name, array $product_ids): void {
        printf(
            '<select id="%s" class="wc-product-search dpr-product-select" multiple="multiple" name="%s" data-placeholder="%s" data-action="woocommerce_json_search_products_and_variations" style="width:420px;max-width:100%%">',
            esc_attr($id),
            esc_attr($name),
            esc_attr__('Search for a product...', 'dynamic-pricing-rules')
        );

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            printf(
                '<option value="%d" selected="selected">%s</option>',
                (int) $product_id,
                esc_html(sprintf('%s (#%d)', $product->get_name(), $product_id))
            );
        }

        echo '</select>';
        echo '<p class="dpr-field-note">' . esc_html__('Use this when Apply to is set to Specific products.', 'dynamic-pricing-rules') . '</p>';
    }

    private function render_category_select(string $id, string $name, array $category_ids): void {
        $terms = get_terms(
            [
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
            ]
        );

        printf(
            '<select id="%s" class="wc-enhanced-select dpr-product-select" multiple="multiple" name="%s" data-placeholder="%s" style="width:420px;max-width:100%%">',
            esc_attr($id),
            esc_attr($name),
            esc_attr__('Choose categories...', 'dynamic-pricing-rules')
        );

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    (int) $term->term_id,
                    selected(in_array((int) $term->term_id, $category_ids, true), true, false),
                    esc_html($term->name)
                );
            }
        }

        echo '</select>';
        echo '<p class="dpr-field-note">' . esc_html__('Use this when Apply to is set to Specific categories.', 'dynamic-pricing-rules') . '</p>';
    }

    private function render_tiers_table(string $name, array $tiers): void {
        $rows = array_values($tiers);

        while (count($rows) < 5) {
            $rows[] = [
                'min' => '',
                'max' => '',
                'discount_type' => 'percent',
                'amount' => '',
            ];
        }

        echo '<table class="dpr-tiers">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Min qty', 'dynamic-pricing-rules') . '</th>';
        echo '<th>' . esc_html__('Max qty', 'dynamic-pricing-rules') . '</th>';
        echo '<th>' . esc_html__('Discount type', 'dynamic-pricing-rules') . '</th>';
        echo '<th>' . esc_html__('Amount', 'dynamic-pricing-rules') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $index => $tier) {
            printf('<tr><td><input type="number" min="1" step="1" class="small-text" name="%s[%d][min]" value="%s"></td>', esc_attr($name), (int) $index, esc_attr($tier['min']));
            printf('<td><input type="number" min="0" step="1" class="small-text" name="%s[%d][max]" value="%s"></td>', esc_attr($name), (int) $index, esc_attr((int) ($tier['max'] ?? 0) > 0 ? $tier['max'] : ''));
            printf('<td><select name="%s[%d][discount_type]">', esc_attr($name), (int) $index);

            foreach (['percent' => __('Percent off', 'dynamic-pricing-rules'), 'fixed' => __('Fixed amount off', 'dynamic-pricing-rules'), 'fixed_price' => __('Fixed item price', 'dynamic-pricing-rules')] as $value => $label) {
                printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($tier['discount_type'] ?? 'percent', $value, false), esc_html($label));
            }

            echo '</select></td>';
            printf('<td><input type="number" min="0" step="0.01" class="small-text" name="%s[%d][amount]" value="%s"></td></tr>', esc_attr($name), (int) $index, esc_attr($tier['amount']));
        }

        echo '</tbody></table>';
    }

    public function sanitize_rules($value): array {
        $decoded = $value;

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);

            if (JSON_ERROR_NONE !== json_last_error()) {
                add_settings_error(
                    'store_dynamic_pricing',
                    'invalid_json',
                    __('Pricing rules were not saved because the JSON is invalid.', 'dynamic-pricing-rules'),
                    'error'
                );

                $current = get_option(self::OPTION_RULES, self::default_rules());
                return is_array($current) ? $current : self::default_rules();
            }
        }

        if (isset($decoded['type'])) {
            $decoded = [$decoded];
        }

        if (!is_array($decoded)) {
            add_settings_error(
                'store_dynamic_pricing',
                'invalid_rules',
                __('Pricing rules must be a JSON array.', 'dynamic-pricing-rules'),
                'error'
            );

            $current = get_option(self::OPTION_RULES, self::default_rules());
            return is_array($current) ? $current : self::default_rules();
        }

        $rules = [];

        foreach ($decoded as $rule) {
            $normalized = $this->normalize_rule($rule);

            if ($normalized) {
                $rules[] = $normalized;
            }
        }

        add_settings_error(
            'store_dynamic_pricing',
            'rules_saved',
            __('Pricing rules saved.', 'dynamic-pricing-rules'),
            'updated'
        );

        return $rules;
    }

    public function apply_quantity_pricing($cart): void {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }

        $rules = $this->get_enabled_rules('quantity_tier');

        if (!$rules) {
            $this->restore_cart_base_prices($cart);
            return;
        }

        $this->restore_cart_base_prices($cart);

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['data']) || !is_object($cart_item['data'])) {
                continue;
            }

            $base_price = $this->get_cart_item_base_price($cart, $cart_item_key, $cart_item);
            $best_price = $base_price;
            $best_label = '';

            foreach ($rules as $rule) {
                if (!$this->rule_matches_cart_item($rule, $cart_item)) {
                    continue;
                }

                $quantity = $this->get_rule_quantity($rule, $cart, $cart_item_key, $cart_item);
                $tier = $this->find_matching_tier($rule, $quantity);

                if (!$tier) {
                    continue;
                }

                $candidate_price = $this->calculate_discounted_price($base_price, $tier);

                if ($candidate_price < $best_price) {
                    $best_price = $candidate_price;
                    $best_label = $rule['label'];
                }
            }

            if ($best_price < $base_price) {
                $cart->cart_contents[$cart_item_key]['data']->set_price($best_price);
                $cart->cart_contents[$cart_item_key][self::PRICE_WAS_CHANGED] = true;
                $cart->cart_contents[$cart_item_key][self::APPLIED_LABEL] = $best_label;
                $cart->cart_contents[$cart_item_key][self::APPLIED_DISPLAY_TITLE] = $this->rule_display_title_by_label($rules, $best_label);
            } else {
                unset($cart->cart_contents[$cart_item_key][self::APPLIED_LABEL]);
                unset($cart->cart_contents[$cart_item_key][self::APPLIED_DISPLAY_TITLE]);
                $cart->cart_contents[$cart_item_key][self::PRICE_WAS_CHANGED] = false;
            }
        }
    }

    public function apply_buy_x_get_y_offers($cart): void {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }

        foreach ($this->get_enabled_rules('buy_x_get_y') as $rule) {
            $grouped_unit_prices = [];

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (!$this->rule_matches_cart_item($rule, $cart_item)) {
                    continue;
                }

                $quantity = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 0;
                $product = $cart_item['data'] ?? null;

                if (!$quantity || !$product || !is_object($product)) {
                    continue;
                }

                $unit_price = (float) $product->get_price();
                $group_key = $this->buy_x_get_y_group_key($rule, $cart_item_key, $cart_item);

                if (!isset($grouped_unit_prices[$group_key])) {
                    $grouped_unit_prices[$group_key] = [];
                }

                for ($i = 0; $i < $quantity; $i++) {
                    $grouped_unit_prices[$group_key][] = $unit_price;
                }
            }

            $group_size = $rule['buy_quantity'] + $rule['free_quantity'];
            $discount = 0;

            foreach ($grouped_unit_prices as $unit_prices) {
                $eligible_quantity = count($unit_prices);

                if ($eligible_quantity < $group_size) {
                    continue;
                }

                $free_quantity = (int) floor($eligible_quantity / $group_size) * $rule['free_quantity'];

                if ($free_quantity <= 0) {
                    continue;
                }

                sort($unit_prices, SORT_NUMERIC);
                $discount += array_sum(array_slice($unit_prices, 0, $free_quantity));
            }

            if ($discount > 0) {
                $cart->add_fee($rule['label'], -1 * $discount, false);
            }
        }
    }

    public function display_cart_item_discount(array $item_data, array $cart_item): array {
        if (!empty($cart_item[self::APPLIED_LABEL])) {
            $display_title = !empty($cart_item[self::APPLIED_DISPLAY_TITLE])
                ? $cart_item[self::APPLIED_DISPLAY_TITLE]
                : __('Discount', 'dynamic-pricing-rules');

            $item_data[] = [
                'key' => wc_clean($display_title),
                'value' => wc_clean($cart_item[self::APPLIED_LABEL]),
                'display' => wc_clean($cart_item[self::APPLIED_LABEL]),
            ];
        }

        return $item_data;
    }

    private function rule_display_title_by_label(array $rules, string $label): string {
        foreach ($rules as $rule) {
            if (($rule['label'] ?? '') === $label) {
                return $rule['display_title'] ?? __('Discount', 'dynamic-pricing-rules');
            }
        }

        return __('Discount', 'dynamic-pricing-rules');
    }

    public function woocommerce_missing_notice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <?php esc_html_e('Store Dynamic Pricing is active, but WooCommerce is not available. Activate WooCommerce to apply pricing rules.', 'dynamic-pricing-rules'); ?>
            </p>
        </div>
        <?php
    }

    private function woocommerce_available(): bool {
        return class_exists('WooCommerce') && function_exists('WC');
    }

    private function restore_cart_base_prices($cart): void {
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (
                empty($cart_item[self::PRICE_WAS_CHANGED])
                || !isset($cart_item[self::BASE_PRICE])
                || empty($cart_item['data'])
                || !is_object($cart_item['data'])
            ) {
                continue;
            }

            $cart->cart_contents[$cart_item_key]['data']->set_price((float) $cart_item[self::BASE_PRICE]);
            $cart->cart_contents[$cart_item_key][self::PRICE_WAS_CHANGED] = false;
        }
    }

    private function get_cart_item_base_price($cart, string $cart_item_key, array $cart_item): float {
        if (!empty($cart_item[self::PRICE_WAS_CHANGED]) && isset($cart_item[self::BASE_PRICE])) {
            return (float) $cart_item[self::BASE_PRICE];
        }

        $product = $cart_item['data'];
        $base_price = (float) $product->get_price('edit');
        $cart->cart_contents[$cart_item_key][self::BASE_PRICE] = max(0, $base_price);

        return max(0, $base_price);
    }

    private function get_enabled_rules(string $type): array {
        return array_values(
            array_filter(
                $this->get_rules(),
                static function (array $rule) use ($type): bool {
                    return !empty($rule['enabled']) && $rule['type'] === $type;
                }
            )
        );
    }

    private function get_rules(bool $apply_filter = true): array {
        $rules = get_option(self::OPTION_RULES, self::default_rules());

        if (!is_array($rules)) {
            $rules = self::default_rules();
        }

        $normalized = [];

        foreach ($rules as $rule) {
            $normalized_rule = $this->normalize_rule($rule);

            if ($normalized_rule) {
                $normalized[] = $normalized_rule;
            }
        }

        if (!$apply_filter) {
            return $normalized;
        }

        return apply_filters('store_dynamic_pricing_rules', $normalized);
    }

    private function normalize_rule($rule): ?array {
        if (!is_array($rule)) {
            return null;
        }

        $type = sanitize_key($rule['type'] ?? 'quantity_tier');
        $allowed_types = ['quantity_tier', 'buy_x_get_y'];

        if (!in_array($type, $allowed_types, true)) {
            return null;
        }

        $scope = sanitize_key($rule['scope'] ?? 'all');
        $allowed_scopes = ['all', 'product', 'category'];

        if (!in_array($scope, $allowed_scopes, true)) {
            $scope = 'all';
        }

        $quantity_mode = sanitize_key($rule['quantity_mode'] ?? 'line');
        $allowed_quantity_modes = ['line', 'product', 'category', 'cart'];

        if (!in_array($quantity_mode, $allowed_quantity_modes, true)) {
            $quantity_mode = 'line';
        }

        $normalized = [
            'id' => sanitize_key($rule['id'] ?? uniqid('rule-', false)),
            'enabled' => $this->truthy($rule['enabled'] ?? true),
            'type' => $type,
            'label' => sanitize_text_field($rule['label'] ?? __('Dynamic pricing discount', 'dynamic-pricing-rules')),
            'display_title' => sanitize_text_field($rule['display_title'] ?? __('Discount', 'dynamic-pricing-rules')),
            'scope' => $scope,
            'product_ids' => $this->absint_list($rule['product_ids'] ?? []),
            'category_ids' => $this->absint_list($rule['category_ids'] ?? []),
            'quantity_mode' => $quantity_mode,
        ];

        if ('' === trim($normalized['label'])) {
            $normalized['label'] = __('Dynamic pricing discount', 'dynamic-pricing-rules');
        }

        if ('' === trim($normalized['display_title'])) {
            $normalized['display_title'] = __('Discount', 'dynamic-pricing-rules');
        }

        if ('quantity_tier' === $type) {
            $normalized['tiers'] = $this->normalize_tiers($rule['tiers'] ?? []);
        }

        if ('buy_x_get_y' === $type) {
            $normalized['buy_quantity'] = max(1, absint($rule['buy_quantity'] ?? 2));
            $normalized['free_quantity'] = max(1, absint($rule['free_quantity'] ?? 1));
        }

        return $normalized;
    }

    private function normalize_tiers($tiers): array {
        if (!is_array($tiers)) {
            return [];
        }

        $normalized = [];

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            if (!isset($tier['amount']) || '' === trim((string) $tier['amount'])) {
                continue;
            }

            $discount_type = sanitize_key($tier['discount_type'] ?? 'percent');
            $allowed_discount_types = ['percent', 'fixed', 'fixed_price'];

            if (!in_array($discount_type, $allowed_discount_types, true)) {
                $discount_type = 'percent';
            }

            $normalized[] = [
                'min' => max(1, absint($tier['min'] ?? 1)),
                'max' => absint($tier['max'] ?? 0),
                'discount_type' => $discount_type,
                'amount' => max(0, (float) ($tier['amount'] ?? 0)),
            ];
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                return $a['min'] <=> $b['min'];
            }
        );

        return $normalized;
    }

    private function find_matching_tier(array $rule, int $quantity): ?array {
        foreach ($rule['tiers'] ?? [] as $tier) {
            $max = (int) $tier['max'];

            if ($quantity >= (int) $tier['min'] && (0 === $max || $quantity <= $max)) {
                return $tier;
            }
        }

        return null;
    }

    private function calculate_discounted_price(float $base_price, array $tier): float {
        $amount = (float) $tier['amount'];

        if ('fixed_price' === $tier['discount_type']) {
            return max(0, $amount);
        }

        if ('fixed' === $tier['discount_type']) {
            return max(0, $base_price - $amount);
        }

        return max(0, $base_price * (1 - min(100, $amount) / 100));
    }

    private function get_rule_quantity(array $rule, $cart, string $cart_item_key, array $cart_item): int {
        $mode = $rule['quantity_mode'] ?? 'line';

        if ('line' === $mode) {
            return isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 0;
        }

        $quantity = 0;
        $target_product_id = $this->cart_item_product_group_id($cart_item);

        foreach ($cart->get_cart() as $other_item) {
            if (!$this->rule_matches_cart_item($rule, $other_item)) {
                continue;
            }

            if ('product' === $mode && $this->cart_item_product_group_id($other_item) !== $target_product_id) {
                continue;
            }

            $quantity += isset($other_item['quantity']) ? (int) $other_item['quantity'] : 0;
        }

        return $quantity;
    }

    private function buy_x_get_y_group_key(array $rule, string $cart_item_key, array $cart_item): string {
        $mode = $rule['quantity_mode'] ?? 'cart';

        if ('line' === $mode) {
            return 'line:' . $cart_item_key;
        }

        if ('product' === $mode) {
            return 'product:' . $this->cart_item_product_group_id($cart_item);
        }

        if ('category' === $mode) {
            return 'category:' . implode('-', $rule['category_ids'] ?? []);
        }

        return 'cart';
    }

    private function rule_matches_cart_item(array $rule, array $cart_item): bool {
        $scope = $rule['scope'] ?? 'all';

        if ('all' === $scope) {
            return true;
        }

        $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
        $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
        $group_id = $this->cart_item_product_group_id($cart_item);

        if ('product' === $scope) {
            $product_ids = $rule['product_ids'] ?? [];

            return in_array($product_id, $product_ids, true)
                || in_array($variation_id, $product_ids, true)
                || in_array($group_id, $product_ids, true);
        }

        if ('category' === $scope) {
            $category_ids = $rule['category_ids'] ?? [];

            if (!$category_ids || !$group_id) {
                return false;
            }

            return has_term($category_ids, 'product_cat', $group_id);
        }

        return false;
    }

    private function cart_item_product_group_id(array $cart_item): int {
        if (!empty($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'get_parent_id')) {
            $parent_id = (int) $cart_item['data']->get_parent_id();

            if ($parent_id > 0) {
                return $parent_id;
            }
        }

        return isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
    }

    private function absint_list($values): array {
        if (!is_array($values)) {
            $values = [$values];
        }

        $values = array_map('absint', $values);
        $values = array_filter(
            $values,
            static function (int $value): bool {
                return $value > 0;
            }
        );

        return array_values(array_unique($values));
    }

    private function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    public static function default_rules(): array {
        return [
            [
                'id' => 'default-quantity-tiers',
                'enabled' => true,
                'type' => 'quantity_tier',
                'label' => __('Quantity discount', 'dynamic-pricing-rules'),
                'display_title' => __('Discount', 'dynamic-pricing-rules'),
                'scope' => 'all',
                'product_ids' => [],
                'category_ids' => [],
                'quantity_mode' => 'line',
                'tiers' => [
                    [
                        'min' => 2,
                        'max' => 3,
                        'discount_type' => 'percent',
                        'amount' => 5,
                    ],
                    [
                        'min' => 4,
                        'max' => 5,
                        'discount_type' => 'percent',
                        'amount' => 10,
                    ],
                    [
                        'min' => 6,
                        'max' => 0,
                        'discount_type' => 'percent',
                        'amount' => 15,
                    ],
                ],
            ],
            [
                'id' => 'default-buy-2-get-1',
                'enabled' => false,
                'type' => 'buy_x_get_y',
                'label' => __('Buy 2, get 1 free', 'dynamic-pricing-rules'),
                'display_title' => __('Discount', 'dynamic-pricing-rules'),
                'scope' => 'all',
                'product_ids' => [],
                'category_ids' => [],
                'quantity_mode' => 'cart',
                'buy_quantity' => 2,
                'free_quantity' => 1,
            ],
        ];
    }
}

register_activation_hook(__FILE__, ['Store_Dynamic_Pricing', 'activate']);
add_action('plugins_loaded', ['Store_Dynamic_Pricing', 'load_textdomain'], 0);
add_action('plugins_loaded', ['Store_Dynamic_Pricing', 'init'], 20);
