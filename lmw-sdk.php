<?php
/**
 * License Manager Client SDK
 *
 * Standalone PHP library — drop this folder into your plugin's vendor/
 * directory to add license activation support with a premium SaaS UI.
 *
 * ===================================================================
 * HOW TO ADD THIS TO YOUR PLUGIN
 * ===================================================================
 *
 * 1. Copy this entire folder into:
 *      your-plugin/vendor/lmw-client-sdk/
 *
 * 2. Add to your plugin's main .php file:
 *
 *      if ( ! function_exists( 'my_plugin_lmw' ) ) {
 *          function my_plugin_lmw() {
 *              global $my_plugin_lmw; // Use global variable to store the SDK instance
 *              if ( ! isset( $my_plugin_lmw ) ) {
 *                  // Load the SDK entry file
 *                  require_once plugin_dir_path( __FILE__ ) . 'vendor/lmw-client-sdk/lmw-sdk.php';
 *
 *                  // Initialize the SDK with your configuration
 *                  $my_plugin_lmw = lmw_sdk_init( array(
 *                      'public_key'             => 'lm_live_xxxxxxxx',      // Application Public Key (from Store backend)
 *                      'application_id'         => 1,                       // Application ID (from Store backend)
 *                      'rest_api_url'           => 'https://your-store.com',// Your License Manager Pro URL
 *                      'slug'                   => 'your-plugin-slug',      // Unique slug for storage configuration
 *                      'plugin_name'            => 'My Pro Plugin',         // Plugin name for the dashboard
 *                      'block_after_expiration' => true,                    // Lock features upon expiry (true/false)
 *                      'menu'                   => array(
 *                          'parent_slug' => 'your-plugin-slug',             // Parent menu where page will appear
 *                          'page_title'  => 'Activate License',             // Title for the menu item
 *                      ),
 *                  ) );
 *              }
 *              return $my_plugin_lmw;
 *          }
 *          // Initialize immediately to register hooks and menus
 *          my_plugin_lmw();
 *      }
 *
 * 3. To activate a license entered by the user:
 *      my_plugin_lmw()->activate( $license_key );
 *
 * 4. To check if the license is active (local, no HTTP):
 *      my_plugin_lmw()->isActiveLicense(); // returns bool
 *
 * ===================================================================
 *
 * @package  LmwClientSDK
 * @version  1.0.0
 * @requires WordPress 5.0+, PHP 7.2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// SDK version constant.
if ( ! defined( 'LMW_SDK_VERSION' ) ) {
    define( 'LMW_SDK_VERSION', '1.0.0' );
}

error_log( '[LMW SDK] Entry point lmw-sdk.php LOADED' );

// Load all SDK classes.
require_once __DIR__ . '/src/autoload.php';

use LmwClientSDK\LmwClient;
use LmwClientSDK\LmwException;

if ( ! function_exists( 'lmw_sdk_init' ) ) {

    /**
     * Create and return an LmwClient instance.
     *
     * @param array $config
     * @return LmwClient|null
     */
    function lmw_sdk_init( array $config ) {
        error_log( '[LMW SDK] lmw_sdk_init called.' );
        try {
            return new LmwClient( $config );
        } catch ( \InvalidArgumentException $e ) {
            error_log( '[LMW SDK] Config error: ' . $e->getMessage() );
            return null;
        } catch ( LmwException $e ) {
            error_log( '[LMW SDK] Init error: ' . $e->getMessage() );
            return null;
        } catch ( \Throwable $e ) {
            error_log( '[LMW SDK] Critical Init error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return null;
        }
    }
}
