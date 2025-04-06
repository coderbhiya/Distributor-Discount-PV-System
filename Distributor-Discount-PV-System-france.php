<?php
/*
Plugin Name: Distributor Discount & PV System

Description: Custom plugin to assign PV to products, accumulate on first purchase, apply discount on next, and reset monthly. PV is role-specific for distributors.
Version: 1.1
Author: Aakash Dave
*/

// Add PV field to product admin
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

add_action('woocommerce_process_product_meta', 'save_custom_pv_field');
function save_custom_pv_field($post_id) {
    if (isset($_POST['custom_pv'])) {
        update_post_meta($post_id, 'custom_pv', wc_clean($_POST['custom_pv']));
    }
}

// On order complete, store PV in user meta
add_action('woocommerce_order_status_completed', 'accumulate_pv_on_order');
function accumulate_pv_on_order($order_id) {
    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();
    if (!$user_id) return;
    $user = get_user_by('id', $user_id);
    if (!in_array('distributor', (array) $user->roles)) return;

    $pv_total = get_user_meta($user_id, '_monthly_pv', true);
    $pv_total = $pv_total ? floatval($pv_total) : 0;
    $current_pv = 0;

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $pv = get_post_meta($product_id, 'custom_pv', true);
        if ($pv) {
            $current_pv += floatval($pv) * $item->get_quantity();
        }
    }

    update_user_meta($user_id, '_monthly_pv', $pv_total + $current_pv);
    update_user_meta($user_id, '_last_pv_order', current_time('mysql'));
}

// Apply discount based on stored PV
add_action('woocommerce_cart_calculate_fees', 'apply_distributor_discount_from_pv');
function apply_distributor_discount_from_pv($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('distributor', (array) $user->roles)) return;

    $pv_total = get_user_meta($user->ID, '_monthly_pv', true);
    $pv_total = $pv_total ? floatval($pv_total) : 0;
    $discount_percent = 0;

    if ($pv_total > 0 && $pv_total <= 74.5) {
        $discount_percent = 20;
    } elseif ($pv_total > 74.5 && $pv_total <= 224.5) {
        $discount_percent = 30;
    } elseif ($pv_total > 224.5 && $pv_total <= 562) {
        $discount_percent = 40;
    } elseif ($pv_total > 562.5) {
        $discount_percent = 50;
    }

    if ($discount_percent > 0) {
        $discount = ($discount_percent / 100) * $cart->cart_contents_total;
        $cart->add_fee("Distributor Discount ({$discount_percent}%)", -$discount);
    }
}

// Show PV and expiry on My Account
add_action('woocommerce_account_dashboard', 'show_pv_in_dashboard');
function show_pv_in_dashboard() {
    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();
    if (!in_array('distributor', (array) $user->roles)) return;

    $pv = get_user_meta($user->ID, '_monthly_pv', true);
    $pv = $pv ? $pv : 0;
    $expiry = date('Y-m-t');
    echo '<p><strong>Current PV:</strong> ' . esc_html($pv) . '</p>';
    echo '<p><strong>Expires on:</strong> ' . esc_html($expiry) . '</p>';
}

// Monthly CRON job to reset PV
add_action('init', 'setup_monthly_pv_reset');
function setup_monthly_pv_reset() {
    if (!wp_next_scheduled('reset_distributor_pv_monthly')) {
        wp_schedule_event(strtotime('last day of this month 23:59'), 'monthly', 'reset_distributor_pv_monthly');
    }
}

add_action('reset_distributor_pv_monthly', 'reset_pv_for_all_distributors');
function reset_pv_for_all_distributors() {
    $users = get_users(['role' => 'distributor']);
    foreach ($users as $user) {
        delete_user_meta($user->ID, '_monthly_pv');
    }
}

// Custom schedule
add_filter('cron_schedules', 'custom_cron_monthly');
function custom_cron_monthly($schedules) {
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => 2592000,
            'display' => __('Once Monthly')
        ];
    }
    return $schedules;
}
