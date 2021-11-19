<?php
/**
 * Plugin Name:	WooCommerce Edinar Payment Gateway
 * Description:	Un portail de paiement WooCommerce pour le système de paiement par carte Edinar
 * Version:		0.1
 * Author:		Amira
 * Author URI:	https://github.com/Amira1502
 * License:		GPL v3
 * License URI:	https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

add_filter( 'woocommerce_payment_gateways', 'wc_edinar_add' );
function wc_edinar_add( $gateways ) {
    $gateways[] = 'WC_Gateway_Edinar'; 
    return $gateways;
}

add_action( 'plugins_loaded', 'wc_edinar_init' );
function wc_edinar_init() {
	
    class WC_Gateway_Edinar extends WC_Payment_Gateway {
		
		public function __construct() {
			
			$this->id = 'edinar';
			$this->has_fields = false;
			$this->method_title = 'Paiement CB edinar';
			$this->method_description = 'Redirige le client vers le portail de paiement Edinar';
			
			$this->init_form_fields();
			$this->init_settings();
			
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
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
					'type'			=> 'text',
					'description'	=> 'Clé sercète fournie par Edinar',
					'default'		=> 'S9i8qClCnb2CZU3y3Vn0toIOgz3z_aBi79akR30vM9o',
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
					'default'		=> '211000021310001',
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
		
		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$woocommerce->cart->empty_cart();
			
			$montant = $order->get_total() * 100;
				
			if( $montant > 0 ) {
				$order->update_status('on-hold', 'Attente du paiement' );
				
				$data = '';
				$data.= 'amount='.$order->get_total() * 100;
				$data.= '|merchantId='.$this->get_option('marchantid');
				$data.= '|normalReturnUrl='.$this->get_return_url( $order );
				$data.= '|automaticResponseUrl='.site_url().'/?wc-api=edinar_api&order='.$order_id;
				$data.= '|transactionReference='.$this->get_option('transPrefix').$order_id;
				$data.= '|keyVersion='.$this->get_option('keyversion');
				$data = utf8_encode( $data );
				
				$order->add_meta_data( 'edinar_data', $data, true );
				$order->save();
				
				return array(
					'result' => 'success',
					'redirect' => $this->get_return_url( $order )
				);
			}
		}
		
    }
}

add_action( 'woocommerce_thankyou', 'edinar_thankyou' );
function edinar_thankyou( $order_id ) {
	
	global $woocommerce;
	$order = wc_get_order( $order_id );
	
	if( $order->has_status( 'on-hold' ) && $order->get_payment_method() == 'edinar' ) {
		
		$gateway = new WC_Gateway_Edinar();
		
		$post_url		= $gateway->get_option('urlsip');
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

add_action( 'woocommerce_edinar_api', 'edinar_callback' );
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
			}
		} else {
			
			// Données de transaction corrompue
			$order->update_status( 'failed', 'Données de transaction corrompues' );
		}
	}
}
