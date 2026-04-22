<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UpdateChecker — Hooks into the WordPress plugin update system.
 *
 * @package LmwClientSDK
 */
class UpdateChecker {

    private $client;
    private $plugin_file;
    private $current_version;
    private $package_url;
    private $transient_key;

    /**
     * UpdateChecker constructor.
     *
     * @param LmwClient $client
     */
    public function __construct( LmwClient $client ) {
        error_log( '[LMW SDK] Initializing UpdateChecker...' );

        $this->client          = $client;
        $this->plugin_file     = plugin_basename( $client->getConfig( 'plugin_file', '' ) );
        $this->current_version = $client->getConfig( 'plugin_version', '0.0.0' );
        $this->package_url     = $client->getConfig( 'update_package_url', null );
        $this->transient_key   = 'lmw_update_' . sanitize_key( $client->getConfig( 'slug' ) );

        error_log( sprintf( '[LMW SDK] Plugin File: %s, Current Version: %s, Transient Key: %s', $this->plugin_file, $this->current_version, $this->transient_key ) );

        add_filter( 'site_transient_update_plugins',         array( $this, 'injectUpdate' ) );
        add_filter( 'plugins_api',                           array( $this, 'pluginsApiInfo' ), 10, 3 );
        add_action( 'upgrader_process_complete',             array( $this, 'clearUpdateCache' ), 10, 2 );
    }

    /**
     * Hooked into 'site_transient_update_plugins'.
     *
     * @param object $transient
     * @return object
     */
    public function injectUpdate( $transient ) {
        if ( empty( $this->plugin_file ) ) {
            return $transient;
        }

        error_log( '[LMW SDK] injectUpdate triggered for ' . $this->plugin_file );

        $update_info = $this->fetchUpdateInfo();

        if ( ! $update_info || ! isset( $update_info->new_version ) ) {
            error_log( '[LMW SDK] No update info found or valid.' );
            return $transient;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[LMW SDK] Comparing versions for %s: Server=%s, Local=%s', $this->plugin_file, $update_info->new_version, $this->current_version ) );
        }

        if ( version_compare( $update_info->new_version, $this->current_version, '>' ) ) {
            error_log( '[LMW SDK] Update available! Injecting into transient.' );

            $item = (object) array(
                'slug'        => dirname( $this->plugin_file ),
                'plugin'      => $this->plugin_file,
                'new_version' => $update_info->new_version,
                'url'         => isset( $update_info->url ) ? $update_info->url : '',
                'package'     => ! empty( $update_info->package ) ? $update_info->package : ( $this->package_url ?: '' ),
                'tested'      => isset( $update_info->tested ) ? $update_info->tested : '',
                'requires_php'=> isset( $update_info->requires_php ) ? $update_info->requires_php : '',
                'icons'       => array(),
                'banners'     => array(),
                'banners_rtl' => array(),
            );

            if ( ! is_object( $transient ) ) {
                $transient = new \stdClass();
            }

            if ( ! isset( $transient->response ) ) {
                $transient->response = array();
            }

            $transient->response[ $this->plugin_file ] = $item;
        } else {
            error_log( '[LMW SDK] No update needed (versions are equal or local is newer).' );
        }

        return $transient;
    }

    /**
     * Hooked into 'plugins_api'.
     *
     * @param object|bool $result
     * @param string      $action
     * @param object      $args
     * @return object|bool
     */
    public function pluginsApiInfo( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_file ) ) {
            return $result;
        }

        error_log( '[LMW SDK] pluginsApiInfo triggered for ' . $args->slug );

        $update_info = $this->fetchUpdateInfo();
        if ( ! $update_info ) {
            return $result;
        }

        error_log( '[LMW SDK] Returning detailed plugin info for popup.' );

        return (object) array(
            'name'          => isset( $update_info->name )          ? $update_info->name          : 'Plugin',
            'slug'          => $args->slug,
            'version'       => isset( $update_info->new_version )   ? $update_info->new_version   : $this->current_version,
            'author'        => isset( $update_info->author )        ? $update_info->author        : '',
            'homepage'      => isset( $update_info->url )           ? $update_info->url           : '',
            'download_link' => ! empty( $update_info->package ) ? $update_info->package : ( $this->package_url ?: '' ),
            'sections'      => array(
                'description' => isset( $update_info->description ) ? $update_info->description : 'Plugin update available.',
                'changelog'   => isset( $update_info->changelog )   ? $update_info->changelog   : '',
            ),
        );
    }

    /**
     * Clear update cache after upgrade.
     */
    public function clearUpdateCache( $upgrader_object, $options ) {
        if ( $options['action'] === 'update' && $options['type'] === 'plugin' && isset( $options['plugins'] ) ) {
            foreach ( $options['plugins'] as $plugin ) {
                if ( $plugin === $this->plugin_file ) {
                    error_log( '[LMW SDK] Update complete. Clearing cache for ' . $this->plugin_file );
                    delete_transient( $this->transient_key );
                    break;
                }
            }
        }
    }

    /**
     * Retrieve update info from API or cache.
     *
     * @return object|null
     */
    private function fetchUpdateInfo() {
        $cached = get_transient( $this->transient_key );
        if ( $cached !== false ) {
            error_log( '[LMW SDK] Using cached update info for ' . $this->plugin_file );
            return $cached ?: null; // false = cached "no update"
        }

        error_log( '[LMW SDK] Fetching fresh update info from API...' );

        try {
            $data = $this->client->validate();
            
            // Debug the raw response if needed
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[LMW SDK] API Validation Response: ' . print_r( $data, true ) );
            }

            // Store the whole response (new_version etc.) for 12 h.
            set_transient( $this->transient_key, $data, 12 * HOUR_IN_SECONDS );
            
            error_log( '[LMW SDK] Successfully fetched and cached update info.' );
            return $data;
        } catch ( \Exception $e ) {
            // Cache the "no result" state for 1 h to prevent repeated failures.
            set_transient( $this->transient_key, 0, HOUR_IN_SECONDS );
            error_log( '[LMW SDK] Update check failed: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Return the cached new_version string, or null if no update known.
     *
     * Used by AdminPage to show an update notice on the license page.
     *
     * @return string|null
     */
    public function getAvailableVersion() {
        error_log( '[LMW SDK] getAvailableVersion called.' );
        $cached = get_transient( $this->transient_key );
        if ( $cached && isset( $cached->new_version ) &&
             version_compare( $cached->new_version, $this->current_version, '>' ) ) {
            return $cached->new_version;
        }
        return null;
    }
}
