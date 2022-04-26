<?php
/**
 * Plugin Name:	WooCommerce Edinar Payment Gateway
 * Description:	Un portail de paiement WooCommerce pour le système de paiement par carte Edinar
 * Author:	Amira
 * Author URI:	https://github.com/Amira1502
 * License:	GPL v3
 * License URI:	https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

   /*
    * This action hook registers our PHP class as a WooCommerce payment gateway
    */
    add_filter( 'woocommerce_payment_gateways', 'wc_edinar_add' );
    function wc_edinar_add( $gateways ) {
      if (current_user_can('administrator')) {
         $gateways[] = 'WC_Gateway_Edinar'; 
         return $gateways;
         }
    }
	/*
   * The class itself, please note that it is inside plugins_loaded action hook
   */
    add_action( 'plugins_loaded', 'wc_edinar_init' );
    function wc_edinar_init() {
	
    class WC_Gateway_Edinar extends WC_Payment_Gateway {
    
	 /**
	 * Constructor for the gateway.
	 */	
	 public function __construct() {
			
			$this->id = 'edinar';
      $this->icon = 'https://www.klap.tn/wp-content/uploads/2022/04/logo-edinar.png'; 
			$this->has_fields = false;
			$this->method_title = 'Paiement carte Edinar';
			$this->method_description = 'Redirige le client vers le portail de paiement Edinar';
			$this->supports= array(
			'products',
			//'refunds'
	    	); 
           
   	 // Method with all the options fields
			$this->init_form_fields();
      
  	 // Load the settings.
			$this->init_settings();
			
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
      
  	// This action hook saves the setting
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      
    // edinar_callback
     add_action('woocommerce_thankyou_' . $this->id, array($this, 'edinar_thankyou'));
     add_action('woocommerce_api_' .strtolower( get_class( $this ) ), array( $this, 'edinar_callback' ) );
     add_action('init', array(&$this, 'edinar_callback'));
		}
		
		public function init_form_fields() {
			
			$this->form_fields = array(
				'enabled'		=> array(
					'title'			=> 'Activer',
					'type'			=> 'checkbox',
					'label'			=> 'Activer le paiement par carte Edinar',
					'default'		=> 'no',
				),
				'title'			=> array(
					'title'			=> 'Titre',
					'type'			=> 'text',
					'description'	=> 'Titre affiché au client lors de la commande',
					'default'		=> 'Carte Bancaire',
					'desc_tip'		=> true,
				),
				'description'	=> array(
					'title'			=> 'Description',
					'type'			=> 'textarea',
					'description'	=> 'Description du mode de paiement affiché au client lors de la commande',
					'default'		=> 'Paiement par carte Edinar',
					'desc_tip'		=> true,
				),
				'urlsip'		=> array(
					'title'			=> 'URL Edinar',
					'type'			=> 'text',
					'description'	=> 'URL Edinar',
					'default'		=> 'https://www.klap.tn/cgi-bin/edinar/transact.cgi',
					'desc_tip'		=> true,
				),
				'secretkey'		=> array(
					'title'			=> 'Clé secrète',
					'type'			=> 'password',
					'description'	=> 'Clé sercète fournie par Edinar',
					'default'		=> 'ondpPROD$17*1',
					'desc_tip'		=> true,
				),
				'keyversion'	=> array(
					'title'			=> 'Version de la clé',
					'type'			=> 'text',
					'description'	=> 'Numéro de version de la clé secrète',
					'default'		=> '1',
					'desc_tip'		=> true,
				),
				'marchantid'	=> array(
					'title'			=> 'Code marchand',
					'type'			=> 'text',
					'description'	=> 'Code marchand de la boutique sur Edinar',
					'default'		=> '***0106008',
					'desc_tip'		=> true,
				),
				'transPrefix'	=> array(
					'title'			=> 'Préfixe transaction',
					'type'			=> 'text',
					'description'	=> 'Préfixe au numéro de transaction transmis à Edinar',
					'default'		=> '',
					'desc_tip'		=> true,
				),
			);
		}
   
   //add_action( 'woocommerce_thankyou', 'edinar_thankyou' );
  function edinar_thankyou( $order_id ) {
	global $woocommerce;
	$order = wc_get_order( $order_id );

  $nbChar = strlen($order_id);
        
			if( $order->get_total() > 0 ) {
				  $order->update_status('pending', 'Attente du paiement' );
				
				$data = '';
				$data.= 'montant='.number_format((float)$order->get_total(), 3, ',', '');
				$data.= '|facture='.$order_id;
				$data.= '|client='.$order->get_billing_first_name();
				$data.= '|merchantId='.$this->get_option('marchantid');
				$data.= '|normalReturnUrl='.$this->get_return_url( $order );
				$data.= '|automaticResponseUrl='.site_url().'/?wc-api=edinar_api&order='.$order_id;
				$data.= '|transactionReference='.str_repeat("0", 20 - $nbChar).$order_id;
				$data.= '|keyVersion='.$this->get_option('keyversion');
				$data = utf8_encode( $data );
				
				$order->add_meta_data( 'edinar_data', $data, true );
				$order->save();
				}
        
    if( $order->has_status( 'pending' ) && $order->get_payment_method() == 'edinar' ) {
		
		   $gateway = new WC_Gateway_Edinar();
		 
		   $post_url		  = $gateway->get_option('urlsip');
		   $post_data		= $order->get_meta('edinar_data');
		   $post_seal		= hash( 'sha256', $post_data.$gateway->get_option('secretkey') );
						
		   echo '
			 <form style="display: none;" id="edinar_form" method="POST" action="'.$post_url.'">
				<input type="hidden" name="Data" value="'.$post_data.'">
				<input type="hidden" name="Seal" value="'.$post_seal.'">
				<input type="submit" value="Payer">
			 </form>
			 <script>document.getElementById("edinar_form").submit()</script>';
      
     	}
      
   
     }
 	  // process payment
		public function process_payment( $order_id ) {
		    global $woocommerce;
		    $order = wc_get_order( $order_id );
                 
           // Return thankyou redirect
	           return array(
	             'result' => 'success',
		     'redirect' => $this->get_return_url( $order )
				); 
           // Remove cart
               $woocommerce->cart->empty_cart();
               $order->payment_complete();
    
		}
    }
}

   //add_action( 'woocommerce_edinar_api', 'edinar_callback' );
   function edinar_callback() {
	
	 global $woocommerce;
	
	$gateway = new WC_Gateway_Edinar();
	$secretkey = $gateway->get_option('secretkey');
	$prefixe = $gateway->get_option('transPrefix');
	
	if( !empty( $_POST['Data'] ) && !empty( $_POST['Seal'] ) ) {
		
		// Formatage des données reçues
		$responseData = Array();
		$postData = explode( '|' , $_POST['Data'] );
   
		foreach( $postData as $data ) {
			$data = explode( '=', $data );
			$responseData[$data[0]] = $data[1];
		}
		$order = wc_get_order( str_replace( $prefixe, '', $responseData['transactionReference'] ) );
		
		// Test d'intégrité des données reçues
		if( hash( 'sha256' , $_POST['Data'].$secretkey ) == $_POST['Seal'] ) {
       if( $responseData['responseCode'] == '00' ) {
				
				// Transaction acceptée
				$order->payment_complete( $responseData['authorisationId'] );
      
        
               } else {
				// Erreur transaction
				$order->update_status( 'failed', 'Transaction refusée par Edinar' );
		} else {
			
			// Données de transaction corrompue
			$order->update_status( 'failed', 'Données de transaction corrompues' );
		}
	}
}
