<?php
/*
Plugin Name: Distributor Discount & PV System
Description: Custom plugin to assign PV to products and apply discount based on total PV for distributor role.
Version: 1.0
Author: Aakash Dave
*/

// Add custom PV field to product admin
add_action('woocommerce_product_options_general_product_data', 'add_custom_pv_field');
function add_custom_pv_field() {
    woocommerce_wp_text_input([
        'id' => 'custom_pv',
        'label' => __('Point Value (PV)', 'woocommerce'),
        'desc_tip' => 'true',
        'description' => __('Enter the custom point value for this product.', 'woocommerce'),
        'type' => 'number',
        'custom_attributes' => [
            'step' => 'any',
            'min' => '0'
        ]
    ]);
}

// Save custom PV field
add_action('woocommerce_process_product_meta', 'save_custom_pv_field');
function save_custom_pv_field($post_id) {
    if (isset($_POST['custom_pv'])) {
        update_post_meta($post_id, 'custom_pv', wc_clean($_POST['custom_pv']));
    }
}

// Apply discount based on total PV in cart for distributor role
add_action('woocommerce_cart_calculate_fees', 'apply_distributor_discount');
function apply_distributor_discount($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();

    if (!in_array('distributor', (array) $user->roles)) return;

    $total_pv = 0;
    foreach ($cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];
        $pv = get_post_meta($product_id, 'custom_pv', true);
        if ($pv) {
            $total_pv += floatval($pv) * $cart_item['quantity'];
        }
    }

    $discount_percent = 0;
    if ($total_pv > 0 && $total_pv <= 74.5) {
        $discount_percent = 20;
    } elseif ($total_pv > 74.5 && $total_pv <= 224.5) {
        $discount_percent = 30;
    } elseif ($total_pv > 224.5 && $total_pv <= 562) {
        $discount_percent = 40;
    } elseif ($total_pv > 562.5) {
        $discount_percent = 50;
    }

    if ($discount_percent > 0) {
        $discount = ($discount_percent / 100) * $cart->cart_contents_total;
        $cart->add_fee("Distributor Discount ({$discount_percent}%)", -$discount);
    }
}

// Display PV info on product page
add_action('woocommerce_single_product_summary', 'show_pv_on_product_page', 20);
function show_pv_on_product_page() {
    global $product;
    $pv = get_post_meta($product->get_id(), 'custom_pv', true);
    if ($pv) {
        echo '<p><strong>Point Value (PV):</strong> ' . esc_html($pv) . '</p>';
    }
}

// Show PV and discount in cart/checkout page
add_action('woocommerce_review_order_before_order_total', 'show_pv_cart_checkout');
add_action('woocommerce_cart_totals_before_order_total', 'show_pv_cart_checkout');
function show_pv_cart_checkout() {
    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();
    if (!in_array('distributor', (array) $user->roles)) return;

    $total_pv = 0;
    foreach (WC()->cart->get_cart() as $cart_item) {
        $pv = get_post_meta($cart_item['product_id'], 'custom_pv', true);
        if ($pv) {
            $total_pv += floatval($pv) * $cart_item['quantity'];
        }
    }
    echo '<tr><th>Total PV</th><td>' . esc_html($total_pv) . '</td></tr>';
}
