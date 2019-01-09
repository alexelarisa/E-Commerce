<?php
/*
Plugin Name: TrustAlexe
Description: Collect and display product and company reviews through TrustSpot
*/
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

add_action('after_setup_theme', 'trustspot_init');
if (!function_exists('trustspot_init')):

    function trustspot_init() {
        if( is_admin() )
            $my_settings_page = new trustspotSettingsPage();
    
        global $trustspot_options;
        $trustspot_options = get_option('trustspot_options');
        $order_status = $trustspot_options['trustspot_order_status'];
        
        if($order_status){
            $order_status = str_replace("wc-","",$order_status);
            add_action( 'woocommerce_order_status_' . $order_status,'trustspot_process_order_api' );
        }
        
    }

endif; // end
function trustspot_process_order_api( $orderId ) {
    
    $order = new WC_Order( $orderId );
    $orderBilling = $order->get_address();
    $_pf = new WC_Product_Factory(); 
    
    global $trustspot_options;
    
    $key        = $trustspot_options['trustspot_api_key']; // from user settings
    $secretKey  = $trustspot_options['trustspot_api_secret']; // from user settings
    $merchantId = $trustspot_options['trustspot_trustspot_client_id']; // from user settings

    $customerEmail = $orderBilling['email']; // from order
    $dataForHmac = $merchantId . $orderId . $customerEmail;
    $calculatedHmac = base64_encode(hash_hmac('sha256', $dataForHmac, $secretKey, true));

    foreach($order->get_items() as $item_id => $item)
    {
        $_product = $_pf->get_product($item['product_id']);
        $name = $_product->post->post_title;
        $value[] = array(
            'product_sku'    => $item['product_id'],
            'product_name'   => $name,
            'product_desc'   => $_product->post->post_excerpt,
            'product_price'  => $_product->get_display_price(),
            'product_url'    => get_permalink( $_product->id ),
            'product_image'  => wp_get_attachment_url( get_post_thumbnail_id($_product->id)),
        );
    }

    $apiData = array(
        "merchant_id"       => $merchantId,
        "order_id"          => $orderId,
        "customer_name"     => $order->get_formatted_billing_full_name(),
        "customer_email"    => $customerEmail,
        "purchase_date"     => $order->order_date,
        "key"               => $key,
        "hmac"              => $calculatedHmac,
        "products"          => $value
    );

    $result = trustspot_call($apiData, "https://trustspot.io/api/merchant/add_product_review");

}

/**
* Api call.
*/
function trustspot_call($data, $url){
    $dataString = json_encode($data);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($dataString))
    );
    curl_setopt($curl, CURLOPT_POSTFIELDS, $dataString);  // Insert the data
    ob_start();
    $result = curl_exec($curl);
    $result = ob_get_clean();
    ob_flush();
    curl_close($curl);
    if($result)
        return new SimpleXMLElement($result);
    else
        return 0;
}

add_action('wp_head', 'trustspot_wp_head');
function trustspot_wp_head()
{
    global $trustspot_options;
    $key  = $trustspot_options['trustspot_api_key']; // from user settings
    ?>
   <script>trustspot_key='<?php print $key ?>';</script>
    <?php
}


global $trustspot_options;
$trustspot_options = get_option('trustspot_options');

if($trustspot_options['trustspot_widget_location'] == 'review-tab'){
    add_filter('woocommerce_product_tabs', 'trustpot_product_tab',98);
}
else if($trustspot_options['trustspot_widget_location'] == 'bottom'){
    add_action('get_footer', 'trustpot_product_tab_content', 10);
    add_filter('woocommerce_product_tabs', 'trustspot_remove_review_tab',98);
}
else if($trustspot_options['trustspot_widget_location'] == 'bottom_woo'){
    add_action('woocommerce_after_single_product', 'trustpot_product_tab_content', 10);
    add_filter('woocommerce_product_tabs', 'trustspot_remove_review_tab',98);
}
else{
    add_filter('woocommerce_product_tabs', 'trustspot_remove_review_tab',98);
}

if($trustspot_options['trustspot_category_stars'] == '1'){
    add_action('woocommerce_before_shop_loop_item_title', 'trustspot_inline_ratings',15);
}
if($trustspot_options['trustspot_product_stars'] == '1'){
    add_action('woocommerce_single_product_summary' , 'trustspot_inline_ratings',7);
}


function trustspot_inline_ratings()
{
    global $product;
    ?>
    <div class="trustspot-inline-simple" data-product-sku="<?php echo $product->id ?>"  data-name="<?php echo $product->post->post_title ?>"></div>
    <?php
}

function trustspot_remove_review_tab($tabs){
    unset($tabs['reviews']);
    return $tabs;
}

function trustpot_product_tab( $tabs ) {

    unset($tabs['reviews']); 
    /* Adds the new tab */
    $tabs['trustpot_tab'] = array(
        'title'     => __( 'Reviews', 'woocommerce' ),
        'priority'  => 50,  
        'callback'  => 'trustpot_product_tab_content'
    );
    return $tabs;
}

function trustpot_product_tab_content() 
{
    global $product;
    ?>
    <div class="trustspot trustspot-main-widget" data-product-sku="<?php print $product->id ?>" data-name="<?php print $product->post->post_title ?>"></div>
    <?php
}

class trustspotSettingsPage{
    /**
     * Holds the values to be used in the fields callbacks
     */
    public $options;

    /**
     * Start up
     */
    public function __construct(){
        add_action( 'admin_menu', array( $this, 'trustspot_menu' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Add options page
     */
    function trustspot_menu(){
        add_menu_page( 'TrustSpot Api', 'TrustSpot', 'manage_options', 'trustspot-menu', array( $this, 'create_admin_page' ), plugins_url( 'img/icon.png', __FILE__ ) );
    }

    /**
     * Options page callback
     */
    public function create_admin_page(){
        // Set class property
        $this->options = get_option( 'trustspot_options' );
        ?>
        <div class="wrap">
            <img style="max-height: 100px;" src="<?php echo plugins_url( 'img/TS_Logo_Alternative_ForWhiteBG.png', __FILE__ );?>" />           
            <p class="description" style="padding-bottom: 10px;"><?php _e("Don't have a TrustSpot account? Sign up Free in seconds: ","woocommerce-trustspot");?><button class="button-secondary" type='button' onclick="location.href='https://trustspot.io/index.php/Login/merchant'" style="top: 18px;margin-left: 10px;"><?php _e("Create a Free Account","woocommerce-trustspot");?></button></p>
            <hr style="border-bottom: 1px black solid;"/>
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'my_option_group' );   
                do_settings_sections( 'trustspot-settings' );
                submit_button(); 
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init(){        
        register_setting(
            'my_option_group', // Option group
            'trustspot_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'setting_section_id', // ID
            __('Api Settings','woocommerce-trustspot'), // Title
            array( $this, 'print_section_info' ), // Callback
            'trustspot-settings' // Page
        );  

        add_settings_field(
            'trustspot_account_email', // ID
            'Account email', // Title 
            array( $this, 'trustspot_account_email' ), // Callback
            'trustspot-settings', // Page
            'setting_section_id' // Section           
        );      

        add_settings_field(
            'trustspot_api_key', 
            'API Key', 
            array( $this, 'trustspot_api_key' ), 
            'trustspot-settings', 
            'setting_section_id'
        );

        add_settings_field(
            'trustspot_api_secret', 
            'API Secret', 
            array( $this, 'trustspot_api_secret' ), 
            'trustspot-settings', 
            'setting_section_id'
        );
        
        add_settings_section(
            'setting_section_two', // ID
            __('Order Settings','woocommerce-trustspot'), // Title
            array( $this, 'print_section_info_two' ), // Callback
            'trustspot-settings' // Page
        );
        
        add_settings_field(
            'trustspot_order_status', 
            __('Order Status:','woocommerce-trustspot'), 
            array( $this, 'trustspot_order_status' ), 
            'trustspot-settings', 
            'setting_section_two'
        );

        add_settings_section(
            'setting_section_three', // ID
            __('Widget and Star Settings (Product Reviews Only)','woocommerce-trustspot'), // Title
            array( $this, 'print_section_info_three' ), // Callback
            'trustspot-settings' // Page
        );

        add_settings_field(
            'trustspot_category_stars', 
            'Show Mini Stars On Category Pages', 
            array( $this, 'trustspot_category_stars' ), 
            'trustspot-settings', 
            'setting_section_three'
        ); 

        add_settings_field(
            'trustspot_product_stars', 
            'Show Mini Stars On Product Pages', 
            array( $this, 'trustspot_product_stars' ), 
            'trustspot-settings', 
            'setting_section_three'
        ); 

        add_settings_field(
            'trustspot_widget_location', 
            'Widget Location', 
            array( $this, 'trustspot_widget_location' ), 
            'trustspot-settings', 
            'setting_section_three'
        ); 
        
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input ){
        $new_input = array();
        if( isset( $input['trustspot_account_email'] ) )
            $new_input['trustspot_account_email'] = sanitize_text_field( $input['trustspot_account_email'] );

        if( isset( $input['trustspot_api_key'] ) )
            $new_input['trustspot_api_key'] = sanitize_text_field( $input['trustspot_api_key'] );
        
        if( isset( $input['trustspot_api_secret'] ) )
            $new_input['trustspot_api_secret'] = sanitize_text_field( $input['trustspot_api_secret'] );
        
        if( isset( $input['trustspot_trustspot_options'] ) )
            $new_input['trustspot_trustspot_options'] = $input['trustspot_trustspot_options'];
        
        if( isset( $input['trustspot_trustspot_client_id'] ) )
            $new_input['trustspot_trustspot_client_id'] = sanitize_text_field( $input['trustspot_trustspot_client_id'] );
        
        if( isset( $input['trustspot_order_status'] ) )
            $new_input['trustspot_order_status'] = sanitize_text_field( $input['trustspot_order_status'] );

        if( isset( $input['trustspot_widget_location'] ) )
            $new_input['trustspot_widget_location'] = sanitize_text_field( $input['trustspot_widget_location'] );

        if( isset( $input['trustspot_category_stars'] ) )
            $new_input['trustspot_category_stars'] = sanitize_text_field( $input['trustspot_category_stars'] );

        if( isset( $input['trustspot_product_stars'] ) )
            $new_input['trustspot_product_stars'] = sanitize_text_field( $input['trustspot_product_stars'] );
        

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info(){
        print '<p>' . __('Enter in your Account Information and than click "Verify Settings" to connect your WooCommerce Site to your TrustSpot account. Be sure to click save at the bottom after successfully verifying','woocommerce-trustspot') . '</p>';
    }
    
    public function print_section_info_two(){
        print '<p>' . __('Select the order status below when you would like WooCommerce orders to be sent to TrustSpot for review collection.','woocommerce-trustspot') . '</p>';
    }

    public function print_section_info_three(){
        print '<p>' . __('Select the settings for showing product review stars and widgets','woocommerce-trustspot') . '</p>';
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function trustspot_account_email(){
        printf(
            '<input type="text" id="trustspot_account_email" name="trustspot_options[trustspot_account_email]" value="%s" style="min-width:450px;" />',
            isset( $this->options['trustspot_account_email'] ) ? esc_attr( $this->options['trustspot_account_email']) : ''
        );
        print '<div class="description">' . __('This is the email address used to register your Trustspot Account.','woocommerce-trustspot') . '</div>';
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function trustspot_api_key(){
        printf(
            '<input type="text" id="trustspot_api_key" name="trustspot_options[trustspot_api_key]" value="%s" style="min-width:450px;" />',
            isset( $this->options['trustspot_api_key'] ) ? esc_attr( $this->options['trustspot_api_key']) : ''
        );
        print '<div class="description">' . __( 'Your API Key can be found within your ','woocommerce-trustspot') . '<a href="https://trustspot.io/index.php/Login/merchant" target="_blank">' . __('TrustSpot Profile.', 'woocommerce-trustspot' ) . '</a></div>';
    }
    
    public function trustspot_api_secret(){
        printf(
            '<input type="text" id="trustspot_api_secret" name="trustspot_options[trustspot_api_secret]" value="%s" style="min-width:450px;" />',
            isset( $this->options['trustspot_api_secret'] ) ? esc_attr( $this->options['trustspot_api_secret']) : ''
        );
        print '<div class="description">' . __( 'Your API Secret can be found below the API Key within your ','woocommerce-trustspot') . '<a href="https://trustspot.io/index.php/Login/merchant" target="_blank">' . __('TrustSpot Profile.', 'woocommerce-trustspot' ) . '</a></div>';
        $this->print_verify_button();
    }

    public function trustspot_widget_location(){
        $tab = ( $this->options['trustspot_widget_location'] == "review-tab" ) ? 'selected' : '';
        $bottom = ( $this->options['trustspot_widget_location'] == "bottom" ) ? 'selected' : '';
        $bottom_woo = ( $this->options['trustspot_widget_location'] == "bottom_woo" ) ? 'selected' : '';
        $off = ( $this->options['trustspot_widget_location'] == "off" ) ? 'selected' : '';
        $select = '<select name="trustspot_options[trustspot_widget_location]" id="trustspot_widget_location" style="min-width:450px;" >';
        $select .= '<option value="review-tab" ' . $tab . ' >Product Page Review Tab</option>';
        $select .= '<option value="bottom_woo" ' . $bottom_woo . '>Bottom Of The Product Page</option>';
        $select .= '<option value="bottom" ' . $bottom . '>Bottom Of The Site</option>';
        $select .= '<option value="off" ' . $off . '>Disabled</option>';
        $select .= '</select>';
        print $select;
        print '<div class="description">' . __('Select where you want the review widget to appear on the product page','woocommerce-trustspot') . '</div>';
        print '<div class="description">' . '<br/><strong>Options:</strong><br/>
            Product Page Review Tab - Widget will appear in the Woocommerce reviews tab, replacing the native product review tab content<br/>
            Bottom Of The Product Page - Widget will appear at the bottom of the product page<br/>
            Bottom Of The Site - Widget will appear above the footer<br/>
            Disabled - Widget will not appear on product pages<br/>
            <br/>';
    }

    public function trustspot_category_stars(){
        $yes = ( $this->options['trustspot_category_stars'] == "1" ) ? 'selected' : '';
        $no = ( $this->options['trustspot_category_stars'] == "0" ) ? 'selected' : '';
        $select = '<select name="trustspot_options[trustspot_category_stars]" id="trustspot_category_stars" style="min-width:100px;" >';
        $select .= '<option value="1" ' . $yes . ' >Yes</option>';
        $select .= '<option value="0" ' . $no . '>No</option>';
        $select .= '</select>';
        print $select;
        print '<div class="description">' . __('Select if you want mini stars to appear under products on category pages','woocommerce-trustspot') . '</div>';
    }

    public function trustspot_product_stars(){
        $yes = ( $this->options['trustspot_product_stars'] == "1" ) ? 'selected' : '';
        $no = ( $this->options['trustspot_product_stars'] == "0" ) ? 'selected' : '';
        $select = '<select name="trustspot_options[trustspot_product_stars]" id="trustspot_product_stars" style="min-width:100px;" >';
        $select .= '<option value="1" ' . $yes . ' >Yes</option>';
        $select .= '<option value="0" ' . $no . '>No</option>';
        $select .= '</select>';
        print $select;
        print '<div class="description">' . __('Select if you want mini stars to appear under the product name on product pages','woocommerce-trustspot') . '</div>';
    }
    
    public function trustspot_order_status(){
        $completed = ( $this->options['trustspot_order_status'] == "wc-completed" ) ? 'selected' : '';
        $processing = ( $this->options['trustspot_order_status'] == "wc-processing" ) ? 'selected' : '';
        $select = '<select name="trustspot_options[trustspot_order_status]" id="trustspot_order_status" style="min-width:450px;" >';
        $select .= '<option value="wc-completed" ' . $completed . ' >' . __('Completed','woocommerce-trustspot') . '</option>';
        $select .= '<option value="wc-processing" ' . $processing . '>' . __('Processing','woocommerce-trustspot') . '</option>';
        $select .= '</select>';
        print $select;
        print '<div class="description">' . __('When an order status is updated to this, the information will be sent to TrustSpot and a review request will be sent to your customer.','woocommerce-trustspot') . '</div>';
        print '<div class="description">' . '<br/><strong>Options:</strong><br/>
            Processing - when an order is processing, a review request will be sent<br/>
            Completed - when an order is completed, a review request will be sent<br/>
            <br/>
            Note: Only 1 review request can be sent per unique order.<br/>
            <br/>
            <strong>Questions?</strong><br/>
            Please see the <a href="https://trustspot.io/index.php/Login/merchant" target="_blank">Wordpress Integration</a> section on your TrustSpot account</div>';
    }
    
    public function print_verify_button(){
        $trustspot_trustspot_options = isset( $this->options['trustspot_trustspot_options'] ) ? esc_attr( $this->options['trustspot_trustspot_options']) : '';
        $trustspot_trustspot_client_id = isset( $this->options['trustspot_trustspot_client_id'] ) ? esc_attr( $this->options['trustspot_trustspot_client_id']) : '';
        $api_status = ($this->options['trustspot_trustspot_options']) ? __('Connected to TrustSpot','woocommerce-trustspot') : __('Disconnected to TrustSpot','woocommerce-trustspot');
        print "<script>
            jQuery(document).ready(function(){
                jQuery('#trustspot_trustspot_verify_button').click(function(e){
                    e.preventDefault();
                    var user_email      = jQuery('#trustspot_account_email').val();
                    var user_api        = jQuery('#trustspot_api_key').val();
                    var user_api_secret = jQuery('#trustspot_api_secret').val();
                    if( !user_email || !user_api || !user_api_secret ){
                        alert('" . __('Please enter all fields','woocommerce-trustspot') . "');
                        return false;
                    }
                    
                    jQuery.ajax({
                        data : {
                            'action'            : 'trustspot_verify_account',
                            'user_email'        : user_email,
                            'user_api'          : user_api,
                            'user_api_secret'   : user_api_secret
                        },
                        dataType : 'json',
                        url : ajaxurl,
                        type : 'post',
                        success : function(response) {
                            if(response){
                                jQuery('#verify_estatus').html('" . __("Connected to TrustSpot","woocommerce-trustspot") . "');
                                jQuery('#trustspot_trustspot_options').val('1');
                                jQuery('#trustspot_trustspot_client_id').val(response);
                            }else{
                                jQuery('#verify_estatus').html('" . __("Failed, please recheck your entry and try again","woocommerce-trustspot
") . "');
                                jQuery('#trustspot_trustspot_options').val('0');
                                jQuery('#trustspot_trustspot_client_id').val(0);
                            }
                        }
                    }); 
                    
                });
            });
        </script>
        <tr>
            <th scope='row'>
                
            </th>
            <td>
                <input type='hidden' id='trustspot_trustspot_options' name='trustspot_options[trustspot_trustspot_options]' value='" . $trustspot_trustspot_options . "' />
                <input type='hidden' id='trustspot_trustspot_client_id' name='trustspot_options[trustspot_trustspot_client_id]' value='" . $trustspot_trustspot_client_id . "' />
                <button class='button-secondary' type='button' name='trustspot_trustspot_verify_button' id='trustspot_trustspot_verify_button'>" . __( 'Verify API Settings', 'woocommerce-trustspot' ) . "</button>
                <div class='description'>" . __( 'Connection Status: ','woocommerce-trustspot') . '<span id="verify_estatus">' . $api_status . "</span>.</div>
            </td>
        </tr>";
    }
    
}