<?php
/**
 * Plugin Name: MarketPress Remote Inventory
 * Plugin URI:  https://premium.wpmudev.org/
 * Description: Manage products invemtory remotely
 * Version:     1.0.0
 * Author:      Panos Lyrakis | WPMUDEV
 * Author URI:  https://premium.wpmudev.org/profile/panoskatws
 * License: 	GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */
 

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

if( ! class_exists( 'WPMUDEV_MP_Remote_Inventory' ) ) {
	class WPMUDEV_MP_Remote_Inventory {
	 
	    protected static $instance = null;
	    /**
         * Use username and key for simple verification
         * Plugin provides only product quantity access
         */
	    private static $username;
	    private static $key;
	     /**
         * The array of templates that this plugin tracks.
         */
        protected $templates;
	 


	    private function __construct() {

	    	self::$username = 'wpmudev';
	    	self::$key 		= '!rand0mP@5s';

	    	// Add a filter to the attributes metabox to inject template into the cache.
			if ( version_compare( floatval( get_bloginfo( 'version' ) ), '4.7', '<' ) ) {

				// 4.6 and older
				add_filter(
					'page_attributes_dropdown_pages_args',
					array( $this, 'register_project_templates' )
				);

			} else {

				// Add a filter to the wp 4.7 version attributes metabox
				add_filter(
					'theme_page_templates', array( $this, 'add_new_template' )
				);

			}

			// Add a filter to the save post to inject out template into the page cache
			add_filter(
				'wp_insert_post_data', 
				array( $this, 'register_project_templates' ) 
			);

			// Add a filter to the template include to determine if the page has our 
			// template assigned and return it's path
			add_filter(
				'template_include', 
				array( $this, 'view_project_template') 
			);

			// Add your templates to this array.
			$this->templates = array(
				'wpmudev-mp-inventory-template.php' => 'MP Remote Inventory',
			);

	 		add_shortcode( 'wpmudev_mp_remote_inventory', array( $this, 'shortcode' ) );
	    }



	    public static function get_instance() {
	 
	        if ( null == self::$instance ) {
	            self::$instance = new self;
	        }
	 
	        return self::$instance;
	 
	    }



		/**
		 * Adds our template to the page dropdown for v4.7+
		 *
		*/
		public function add_new_template( $posts_templates ) {
			$posts_templates = array_merge( $posts_templates, $this->templates );
			return $posts_templates;
		}

		/**
		 * Adds our template to the pages cache in order to trick WordPress
		 * into thinking the template file exists where it doens't really exist.
		*/
		public function register_project_templates( $atts ) {

			// Create the key used for the themes cache
			$cache_key = 'page_templates-' . md5( get_theme_root() . '/' . get_stylesheet() );

			// Retrieve the cache list. 
			// If it doesn't exist, or it's empty prepare an array
			$templates = wp_get_theme()->get_page_templates();
			if ( empty( $templates ) ) {
				$templates = array();
			} 

			// New cache, therefore remove the old one
			wp_cache_delete( $cache_key , 'themes');

			// Now add our template to the list of templates by merging our templates
			// with the existing templates array from the cache.
			$templates = array_merge( $templates, $this->templates );

			// Add the modified cache to allow WordPress to pick it up for listing
			// available templates
			wp_cache_add( $cache_key, $templates, 'themes', 1800 );

			return $atts;

		} 

		/**
		 * Checks if the template is assigned to the page
		 */
		public function view_project_template( $template ) {
			
			// Get global post
			global $post;

			// Return template if post is empty
			if ( ! $post ) {
				return $template;
			}

			// Return default template if we don't have a custom one defined
			if ( ! isset( $this->templates[get_post_meta( 
				$post->ID, '_wp_page_template', true 
			)] ) ) {
				return $template;
			} 

			$file = plugin_dir_path( __FILE__ ). get_post_meta( 
				$post->ID, '_wp_page_template', true
			);

			// Just to be safe, we check if the file exist first
			if ( file_exists( $file ) ) {
				return $file;
			} else {
				echo $file;
			}

			// Return template
			return $template;

		}



	    public function shortcode( $atts ){

	    	$this->remote_inventory();

	    }



	    public function remote_inventory(){

	    	$products_quantities 	= isset( $_REQUEST[ 'products_quantities' ] ) ? $_REQUEST[ 'products_quantities' ] : false;

	    	if( ! $this->_verified_user() || ! $products_quantities ){
	    		return new WP_Error( 'broke', "I've fallen and can't get up" );
	    	}


	    	$products_quantities_json = json_decode( stripcslashes( $products_quantities ) );

	    	if( empty( $products_quantities_json ) ){
	    		return;
	    	}

	    	foreach( $products_quantities_json as $product_id => $quantity ){

	    		$product = new MP_Product( $product_id );

				if ( $product->get_meta( 'inventory_tracking' ) ) {
					$stock = $product->get_stock();

					// Update inventory
					$new_stock = ( $stock - $quantity );

					$product->update_meta( 'inventory', $new_stock );
					$product->update_meta( 'inv_inventory', $new_stock );

					// Send low-stock notification if needed
					if ( $new_stock <= mp_get_setting( 'inventory_threshhold' ) ) {
						$product->low_stock_notification();
					}

					if ( mp_get_setting( 'inventory_remove' ) && $new_stock <= 0 ) {
						// Flag product as out of stock - @version 2.9.5.8
						wp_update_post( array(
							'ID'          => $product->ID,
							'post_status' => 'draft'
						) );
					}
				}

				// Update sales count
				$sales = $product->get_meta( 'mp_sales_count', 0 );
				$sales += $quantity;

				update_post_meta( $product->ID, 'mp_sales_count', $sales );

	    	}

	    	$return = array(
				'message'	=> 'Quantity updated succesfully'
			);

			wp_send_json_success( $return );
			exit;

	    }



	    private function _verified_user(){

	    	$username 				= isset( $_REQUEST[ 'username' ] ) ? $_REQUEST[ 'username' ] : false;
	    	$key 					= isset( $_REQUEST[ 'key' ] ) ? $_REQUEST[ 'key' ] : false;

	    	if( ! $username || $username != self::$username ||
	    		! $key || $key != self::$key ){

	    		return false;
	    	}

	    	return true;

	    }

	}

	$wpmudev_mp_remote_inventory = WPMUDEV_MP_Remote_Inventory::get_instance();

}
