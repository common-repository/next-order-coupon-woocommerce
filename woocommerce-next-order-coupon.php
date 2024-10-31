<?php
/**
 * Plugin Name: WooCommerce Next Order Coupon
 * Text Domain: wc_noc
 * Domain Path: /languages
 * Plugin URI: http://kenanfallon.com
 * Description: Email customers a coupon code when they complete an order to encourage them to return to your WooCommerce store.
 * Version: 0.4.0
 * Author: Kenan Fallon
 * Author URI: http://kenanfallon.com
 * License: GPLv2 or later
 * WC requires at least: 3.0.0
 * WC tested up to: 3.4.5
 */

/**
 * Prevent direct access
 **/
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Freemius Activation
 **/
function noc_fs() {
    global $noc_fs;

    if ( ! isset( $noc_fs ) ) {
        // Include Freemius SDK.
        require_once dirname(__FILE__) . '/freemius/start.php';

        $noc_fs = fs_dynamic_init( array(
            'id'                  => '757',
            'slug'                => 'next-order-coupon-woocommerce',
            'type'                => 'plugin',
            'public_key'          => 'pk_1a398c52466d5d18e646f88bea67c',
            'is_premium'          => false,
            'has_premium_version' => false,
            'has_addons'          => false,
            'has_paid_plans'      => false,
            'menu'                => array(
                'slug'           => 'next-order-coupon-woocommerce',
                'first-path'     => 'plugins.php',
                'account'        => false,
                'contact'        => false,
                'support'        => false,
            ),
        ) );
    }

    return $noc_fs;
}

// Init Freemius.
noc_fs();

class WC_Next_Order_Coupon {

    /**
     * Init required actions & filters.
     *
     */
    public static function init() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );

        add_action( 'woocommerce_settings_tabs_next_order_coupon_settings', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_next_order_coupon_settings', __CLASS__ . '::update_settings' );
        add_action( 'admin_notices', array( __CLASS__, 'show_warning' ) );

        add_action( 'woocommerce_email_before_order_table', array(__CLASS__, 'add_order_email_instructions'), 10, 2 );
        add_action( 'woocommerce_payment_complete', array(__CLASS__, 'create_coupon'), 10, 1 );
//      add_action( 'woocommerce_order_status_completed', array(__CLASS__, 'create_coupon'), 10, 1 );

        //Activation Notices
        register_activation_hook( __FILE__, array( __CLASS__, 'activation_hook' ) );
        add_action( 'admin_notices', array( __CLASS__, 'show_activation_notice' ) );

        add_action( 'plugins_loaded', array(__CLASS__, '_load_textdomain' ) );

    }

    public static function _load_textdomain(){
        load_plugin_textdomain(
                'wc_noc',
                FALSE,
                basename( dirname( __FILE__ ) ) . '/languages/'
        );
    }

    protected static function check_wc() {
        if ( ! function_exists( 'WC' ) ) {
            return false;
        } else {
            return true;
        }
    }

    protected static function check_wc_memberships() {
        if ( function_exists( 'WC' ) && get_option('woocommerce_enable_signup_and_login_from_checkout') == 'no' && get_option('woocommerce_enable_myaccount_registration' == 'no')) {
            return false;
        } else {
            return true;
        }
    }

    static function activation_hook() {
        set_transient( 'noc-admin-notice-activation', true, 5 );
    }

    public static function show_activation_notice() {
        if( get_transient( 'noc-admin-notice-activation' ) ){
            $settings_url = admin_url( 'admin.php?page=wc-settings&tab=next_order_coupon_settings' );
            ?>
            <div class="updated notice is-dismissible">
                <p><?php printf( __( 'Please enable and configure <strong>WooCommerce Next Order Coupon</strong> settings on the <a href="%s">settings page</a>.', 'wc_noc' ), esc_url( $settings_url ) ); ?></p>
            </div>

            <?php
            delete_transient( 'noc-admin-notice-activation' );
        }
    }

    public static function show_warning() {
        if (!self::check_wc()){
            ?>
            <div class="notice notice-warning is-dismissible fade">
                <p>
                    <strong><?php _e( 'WooCommerce Next Order Coupon requires WooCommerce to be activated', 'wc_noc' ); ?></strong>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Show action links on the plugin screen.
     *
     * @param	mixed $links Plugin Action links
     * @return	array
     */
    public static function plugin_action_links( $links ) {
        $action_links = array(
            'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=next_order_coupon_settings' ) . '">' . __( 'Settings', 'wc_noc' ) . '</a>',
        );
        return array_merge( $action_links, $links );
    }

    /**
     * Add a new settings tab to the WooCommerce settings tabs array.
     *
     * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
     * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
     */
    public static function add_settings_tab( $settings_tabs ) {
        $settings_tabs['next_order_coupon_settings'] = __( 'Next Order Coupon', 'wc_noc' );
        return $settings_tabs;
    }
    /**
     * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
     *
     * @uses woocommerce_admin_fields()
     * @uses self::get_settings()
     */
    public static function settings_tab() {
        woocommerce_admin_fields( self::get_settings() );
    }
    /**
     * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
     *
     * @uses woocommerce_update_options()
     * @uses self::get_settings()
     */
    public static function update_settings() {
        woocommerce_update_options( self::get_settings() );
    }
    /**
     * Get all the settings @see woocommerce_admin_fields() function.
     *
     * @return array Array of settings for @see woocommerce_admin_fields() function.
     */
    public static function get_settings() {
        $settings = array(
            'section_title' => array(
                'name'     => __( 'Next Order Coupon', 'wc_noc' ),
                'type'     => 'title',
                //'desc'     => 'Email customers a coupon code to encourage them to return to your store.',
                'id'       => 'wc_noc_title'
            ),
            'select' => array(
                'name' => __( 'Enable', 'wc_noc' ),
                'type' => 'checkbox',
                'desc' => 'Enable WooCommerce Next Order Coupon',
                'id'   => 'wc_noc_enable'
            ),
            array(
                'name' => __( 'Coupon Code', 'wc_noc' ),
                'type' => 'text',
                'placeholder' => 'WELCOMEBACK',
                'desc_tip' =>  true,
                'desc' => 'The coupon code that is emailed to the customer',
                'id'   => 'wc_noc_coupon_code'
            ),
            array(
                'title'    => __( 'Coupon Amount', 'wc_noc' ),
                'desc'     => __( 'The coupon value that is emailed to the customer', 'wc_noc' ),
                'id'       => 'wc_noc_coupon_amount',
                'default'  => 'all',
                'type'     => 'select',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width: 350px;',
                'desc_tip' =>  true,
                'options'  => array(
                    '5'        => __( '5% Off Entire Cart', 'wc_noc' ),
                    '10' => __( '10% Off Entire Cart', 'wc_noc' ),
                )
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_noc_section_end'
            )
        );
        return apply_filters( 'wc_noc_settings_tab', $settings );
    }

    /**
     * Create the coupon upon successful order
     *
     */
    public static function create_coupon ($order_id) {

        $order = new WC_Order( $order_id );
        $customer_id = (int)$order->get_user_id();
        $customer_info = get_userdata($customer_id);

        if (get_option('wc_noc_enable') == 'yes' && $customer_id):

            $coupon_code = ( get_option('wc_noc_coupon_code') ? get_option('wc_noc_coupon_code') : 'WELCOMEBACK');
            $amount = get_option('wc_noc_coupon_amount','5'); // Amount
            $discount_type = 'percent'; // Type: fixed_cart, percent, fixed_product, percent_product

            $coupon = array(
                'post_title' => $coupon_code,
                'post_content' => '',
                'post_status' => 'publish',
                'post_author' => 1,
                'post_type'		=> 'shop_coupon'
            );

            $new_coupon_id = wp_insert_post( $coupon );

            // Add meta
            update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
            update_post_meta( $new_coupon_id, 'coupon_amount', $amount );
            update_post_meta( $new_coupon_id, 'individual_use', 'yes' );
            update_post_meta( $new_coupon_id, 'product_ids', '' );
            update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
            update_post_meta( $new_coupon_id, 'usage_limit', '1' );
            update_post_meta( $new_coupon_id, 'expiry_date', '' );
            update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );
            update_post_meta( $new_coupon_id, 'free_shipping', 'no' );
            update_post_meta( $new_coupon_id, 'customer_email', $customer_info->user_email);

        endif;

    }

    /**
     * Emails the coupon to the customer
     * TODO: Check if the coupon exists (and it attached to the user) before inserting it into the email.
     *
     */
    public static function add_order_email_instructions( $order, $sent_to_admin ) {

        $coupon_amount = get_option('wc_noc_coupon_amount', '5');
        $coupon_code = ( get_option('wc_noc_coupon_code') ? get_option('wc_noc_coupon_code') : 'WELCOMEBACK');

        if ( ! $sent_to_admin && get_option('wc_noc_enable') == 'yes' && $order->get_user_id() && 'processing' == $order->get_status()) {

//            echo '<h2>Get '.$coupon_amount.'% off</h2><p id="noc_thanks">Thanks for your purchase! Come back and use the code "<strong>'.$coupon_code.'</strong>" to receive a '.$coupon_amount.'% discount on your next purchase!</p>';

            echo '<h2>';
            /* translators: this is inserted into the email and 1 is the coupon amount */
            printf( __( 'Get %1$d%% off', 'wc_noc' ),
                $coupon_amount
            );
            echo '</h2>';

            echo '<p id="noc_thanks">';
            /* translators: this is inserted into the email. 1 is the coupon amount and 2 is the coupon code */
            printf(
                __( 'Thanks for your purchase! Come back and use the code <strong>%2$s</strong> to receive a %1$d%% discount on your next purchase!', 'wc_noc' ),
                $coupon_amount,
                $coupon_code
            );
            echo '</p>';

        }
    }
}

WC_Next_Order_Coupon::init();


