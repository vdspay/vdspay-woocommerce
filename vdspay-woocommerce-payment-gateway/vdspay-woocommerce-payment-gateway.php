<?php
/*
	Plugin Name: VdsPay WooCommerce Payment Gateway
	Description: VdsPay Woocommerce Payment Gateway allows you to accept payment on your Woocommerce store via Credit Cards & P2P.
	Version: 1.0
	Author: VdsPay Developers
	Author Email: devteam@vdspay.net
	License:           GPL-2.0+
 	License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 	Github: https://github.com/vdspay/vdspay-woocommerce

*/
if ( ! defined( 'ABSPATH' ) )
	exit;

add_action('plugins_loaded', 'wc_vdspay_init', 0);

function wc_vdspay_init() {
	
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
	
	/**
 	 * Gateway class
 	 */
	class WC_Vdspay_Gateway extends WC_Payment_Gateway {
		
		public function __construct(){

			$this->id 					= 'vdspay_gateway';
    		$this->icon 				= apply_filters('woocommerce_vdspay_icon', plugins_url( 'assets/vdspay.jpg' , __FILE__ ) );
			$this->has_fields 			= false;
			$this->order_button_text 	= 'Make Payment';
        	$this->payment_url 			= 'https://acs.vdspay.net/transaction/auth';
			$this->notify_url        	= WC()->api_request_url( 'WC_Vdspay_Gateway' );
        	$this->method_title     	= 'VdsPay';
        	$this->method_description  	= 'Pay via Credit Cards, PayPal or BitCoin';
			
			// Load the form fields.
			$this->init_form_fields();
			
			// Load the settings.
			$this->init_settings();
			
			// Define user set variables
			$this->title 					= $this->get_option( 'title' );
			$this->description 				= $this->get_option( 'description' );
			$this->vdsPayAccountNo 		    = $this->get_option( 'vdsPayAccountNo' );
			$this->vdsPayUsername 		    = $this->get_option( 'vdsPayUsername' );
			$this->vdsPayApiKey 		    = $this->get_option( 'vdsPayApiKey' );
			$this->vdsPayApiPass 		    = $this->get_option( 'vdsPayApiPass' );
			$this->storeId 					= $this->get_option( 'storeId' );
			
			//Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_vdspay_gateway', array( $this, 'check_vdspay_response' ) );

			// Check if the gateway can be used
			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = false;
			}
		}
		
		public function is_valid_for_use() {

			if( ! in_array( get_woocommerce_currency(), array( 'NGN', 'USD' ) ) ) {

				$this->msg = 'VdsPay doesn\'t support your store currency. Contact VdsPay. &#36 ';

				return false;

			}

			return true;
		}
		
		/**
		 * Check if this gateway is enabled
		 */
		public function is_available() {

			if ( $this->enabled == "yes" ) {

				if ( ! $this->vdsPayAccountNo ) {
					return false;
				}
				return true;
			}

			return false;
		}
		
		/**
         * Admin Panel Options
         **/
        public function admin_options() {
            echo '<h3>VdsPay</h3>';
            
			if ( $this->is_valid_for_use() ){
	            echo '<table class="form-table">';
	            $this->generate_settings_html();
	            echo '</table>';
            }
			else{	 ?>
			<div class="inline error"><p><strong>VdsPay Payment Gateway Disabled</strong>: <?php echo $this->msg ?></p></div>

			<?php }
        }
		
		/**
	     * Initialise Gateway Settings Form Fields
	    **/
		function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title' 		=> 'Enable/Disable',
					'type' 			=> 'checkbox',
					'label' 		=> 'Enable VdsPay Payment Gateway',
					'description' 	=> 'Enable or disable the gateway.',
            		'desc_tip'      => true,
					'default' 		=> 'yes'
				),
				'title' => array(
					'title' 		=> 'Title',
					'type' 			=> 'text',
					'description' 	=> 'This controls the title which the user sees during checkout.',
        			'desc_tip'      => false,
					'default' 		=> 'VdsPay'
				),
				'description' => array(
					'title' 		=> 'Description',
					'type' 			=> 'textarea',
					'description' 	=> 'This controls the description which the user sees during checkout.',
					'default' 		=> 'Pay via Credit Cards, PayPal or BitCoin'
				),
				'vdsPayAccountNo' => array(
					'title' 		=> 'VdsPay Account Number',
					'type' 			=> 'text',
					'description' 	=> 'Enter Your VdsPay Account Number, this can be gotten on your account page when you login on VdsPay' ,
					'default' 		=> '',
        			'desc_tip'      => true
				),
				'vdsPayUsername' => array(
					'title' 		=> 'VdsPay Username',
					'type' 			=> 'text',
					'description' 	=> 'Enter Your VdsPay Merchant Username, this is not UserID. It looks like mXXXXXX' ,
					'default' 		=> '',
        			'desc_tip'      => true
				),
				'vdsPayApiKey' => array(
					'title' 		=> 'VdsPay API Key',
					'type' 			=> 'text',
					'description' 	=> 'Enter Your VdsPay API Key' ,
					'default' 		=> '',
        			'desc_tip'      => true
				),
				'vdsPayApiPass' => array(
					'title' 		=> 'VdsPay API Password',
					'type' 			=> 'text',
					'description' 	=> 'Enter Your VdsPay API Password' ,
					'default' 		=> '',
        			'desc_tip'      => true
				),
			);
		}
		
		/**
		 * Get vdspay args
		**/
		function get_vdspay_args( $order ) {

			$order_id 		= $order->id;

			$order_total	= $order->get_total();
			$accountNo 	= $this->vdsPayAccountNo;
			$apikey 	= $this->vdsPayApiKey;
			$memo        	= "Payment for Order ID: $order_id on ". get_bloginfo('name');
			$type           = "sale";
            $notify_url  	= $this->notify_url;

			$success_url  	= $this->get_return_url( $order );

			$fail_url	  	= $this->get_return_url( $order );

			$store_id 		= $this->storeId  ? $this->storeId : '';
			
			$fn = $order->billing_first_name;
			$ln = $order->billing_last_name;
			
			// vdspay Args
			$vdspay_args = array(
			
			    "transaction"  => array(
				
				'accountNo' 		    => $this->vdsPayAccountNo,
				'currency' 				=> get_woocommerce_currency(),
				'memo'					=> $memo,
				'type'					=> $type,
				'amount' 				=> $order_total,
				'reference'	     		=> $order_id,
				'notify_url'			=> $notify_url,
				'return_url'			=> $success_url,
				
				"customer"     => array(
				
				'name'                  => "$fn $ln",
				'email'                 => $order->billing_email,
				'phone'                 => $order->billing_phone
				)
			)
			);

			$vdspay_args = apply_filters( 'woocommerce_vdspay_args', $vdspay_args );
			return $vdspay_args;
		}
		
		/**
	     * Process the payment and return the result
	    **/
		function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );
			
			$data = $this->get_vdspay_args( $order );
			$post_data = json_encode($data, true);
			
			$hash = hash("sha512", $this->vdsPayAccountNo.$order->id.$order->get_total().$this->vdsPayApiKey);
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $this->payment_url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(					
    			'Content-Type: application/json',                                                                                
    			'Content-Length: ' . strlen($post_data),
	  			'Authorization: Merchant '.$hash.'')                                                                     
    		); 
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			$c = curl_exec($ch);
			$http_status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			

			if($http_status_code == 200) {
				$res = json_decode($c, true);
				if($res["message"] == "Authorization URL created") {
					$url = $res["data"]["authorization_url"];
					return array(
		        	'result' 	=> 'success',
					'redirect'	=> $url
		        );
				} else {
					wc_add_notice($res["message"], 'error' );
					return array(
		        	'result' 	=> 'fail',
					'redirect'	=> ''
		        );
				}
			}
			else {
				wc_add_notice( 'Unable to connect to VdsPay, please try again or contact our customer service team.', 'error' );

		        return array(
		        	'result' 	=> 'fail',
					'redirect'	=> ''
		        );
			}
		}
		
		
		/**
		 * Verify a successful Payment!
		**/
		function check_vdspay_response( $posted ) {
			if( isset( $_POST['transid'] ) ) {
				$sdk = plugins_url( 'assets/vdspay_sdk/Service/emp.class.php' , __FILE__ );
				require_once($sdk);
				$transaction_id = $_POST['transid'];
				$args = array( 'timeout' => 60 );
				
				define("username", $this->vdsPayUsername);
				define("acct_number", $this->vdsPayAccountNo);
				define("api_key", $this->vdsPayApiKey);
				define("api_pass", $this->vdsPayApiPass);
				
				$service = new emp_service();
				
				$transaction = $service->query_transaction($transaction_id);
				
				$order_id = $transaction['reference'];
				$order_id = (int) $order_id;
				
				do_action( 'wc_vdspay_after_payment', $transaction );
				
				if($transaction['status'] == 'Approved' ) {
					$order->payment_complete( $transaction_id );
					$order->add_order_note( 'Payment Via VdsPay.<br />Transaction ID: '.$transaction_id );
					$message = 'Payment was successful.';
					$message_type = 'success';
					// Empty cart
					wc_empty_cart();
			        $vdspay_message = array(
	                	'message'	=> $message,
	                	'message_type' => $message_type
	                );

					update_post_meta( $order_id, '_vdspay_message', $vdspay_message );

                    die( 'IPN Processed OK. Payment Successfully' );
				} else {
					$message = $transaction["response"];
					$message_type = 'error';
					
					$transaction_id = $transaction['transid'];
					$order->add_order_note($message.'<br />Vdspay Transaction ID: '.$transaction_id);
					$order->update_status( 'failed', '' );
					
					$vdspay_message = array(
	                	'message'	=> $message,
	                	'message_type' => $message_type
	                );
					
					update_post_meta( $order_id, '_vdspay_message', $vdspay_message );
					
					add_post_meta( $order_id, '_transaction_id', $transaction_id, true );
					
					die( 'IPN Processed OK. Payment Failed' );
				}
			} else {
				$message = 	'Thank you for shopping with us. <br /> Payment Failed.';
				$message_type = 'error';
				
				$vdspay_message = array(
                	'message'	=> $message,
                	'message_type' => $message_type
                );

				update_post_meta( $order_id, '_vdspay_message', $vdspay_message );

                die( 'IPN Processed OK' );
				
			}
			
		}
		
	}
	
	function wc_vdspay_message() {
		
		if( get_query_var( 'order-received' ) ){
			
			$order_id 		= absint( get_query_var( 'order-received' ) );
			$order 			= wc_get_order( $order_id );
			$payment_method = $order->payment_method;

			if( is_order_received_page() &&  ( 'vdspay_gateway' == $payment_method ) ){

				$vdspay_message 	= get_post_meta( $order_id, '_vdspay_message', true );

				if( ! empty( $vdspay_message ) ){

					$message 			= $vdspay_message['message'];
					$message_type 		= $vdspay_message['message_type'];

					delete_post_meta( $order_id, '_vdspay_message' );

					wc_add_notice( $message, $message_type );
				}
			}

		}

	}
	
	add_action( 'wp', 'wc_vdspay_message' );
	
	/**
 	* Add Vdspay Gateway to WC
 	**/
	function wc_add_vdspay_gateway($methods) {
		$methods[] = 'WC_Vdspay_Gateway';
		return $methods;
	}
	
	add_filter( 'woocommerce_payment_gateways', 'wc_add_vdspay_gateway' );
	
	add_filter('plugin_action_links', 'vdspay_plugin_action_links', 10, 2);

		function vdspay_plugin_action_links($links, $file) {
		    static $this_plugin;

		    if (!$this_plugin) {
		        $this_plugin = plugin_basename(__FILE__);
		    }

		    if ($file == $this_plugin) {
		        $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_vdspay_gateway">Settings</a>';
		        array_unshift($links, $settings_link);
		    }
		    return $links;
		}
		
}
			
			
					
		              
			
		
		
		
