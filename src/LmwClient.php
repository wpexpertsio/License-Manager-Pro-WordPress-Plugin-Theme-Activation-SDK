<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LmwClient — Main License Manager Client SDK class.
 *
 * Handles all communication with the License Manager store:
 *   - activate()      → Activate a license key on this domain
 *   - deactivate()    → Deactivate the stored license
 *   - validate()      → Live check: is the license valid?
 *   - reportInstall() → Tell the store about an install/update event
 *
 * License state is cached locally in wp_options (via Storage) and
 * refreshed automatically via a background WP-Cron check every
 * $check_period hours.
 *
 * @package LmwClientSDK
 * @version 1.0.0
 */
class LmwClient {

    const VERSION              = '1.0.0';
    const DEFAULT_CHECK_DAYS   = 30;

    /** @var array */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var Storage */
    private $storage;

    /** @var AdminPage|null */
    private $admin_page;

    /** @var UpdateChecker|null */
    private $update_checker;

    /**
     * Constructor.
     *
     * @param array $config {
     *   @type string $rest_api_url          Required. Base URL of the License Manager store.
     *   @type string $public_key         Required. Application public key (lm_live_xxx).
     *   @type string $slug               Required. Unique slug for this plugin/theme.
     *   @type string $plugin_version     Optional. Plugin version string.
     *   @type string $type               Optional. 'plugin' or 'theme'. Default 'plugin'.
     *   @type int    $check_period       Optional. Hours between background checks. Default 24.
     *   @type int    $timeout            Optional. HTTP timeout in seconds. Default 15.
     *   @type string $on_expire          Optional. What to do on expiry: 'block' or 'allow'. Default 'allow'.
     *   @type string $plugin_file        Optional. Plugin file relative path for update checks, e.g. 'my-plugin/my-plugin.php'.
     *   @type string $update_package_url Optional. Direct .zip URL for plugin updates.
     * }
     *
     * @throws \InvalidArgumentException  If required keys are missing.
     */
    public function __construct( $config ) {
        if ( ! is_array( $config ) ) {
            throw new \InvalidArgumentException( '[LMW SDK] Config must be an array.' );
        }

        foreach ( array( 'public_key', 'slug', 'rest_api_url' ) as $required ) {
            if ( empty( $config[ $required ] ) ) {
                throw new \InvalidArgumentException( '[LMW SDK] Missing required config key: ' . $required );
            }
        }

        $this->config = array_merge( array(
            'store_url'          => get_site_url(),   // defaults to current site
            'rest_api_url'       => '',
            'type'               => 'plugin',
            'plugin_version'     => '',
            'check_period_days'  => self::DEFAULT_CHECK_DAYS,
            'timeout'            => 15,
            'on_expire'          => 'allow',
            'plugin_file'        => '',
            'update_package_url' => '',
            'domain'                 => '',
            'application_id'         => null,
            'block_after_expiration' => false,
        ), $config );

        // Auto-detect plugin_file if missing
        if ( empty( $this->config['plugin_file'] ) ) {
            $this->config['plugin_file'] = $this->autoDetectPluginFile();
        }

        $this->http    = new HttpClient(
            $this->config['rest_api_url'],
            $this->config['public_key'],
            $this->config['application_id'],
            $this->config['timeout']
        );
        $this->storage = new Storage( $this->config['slug'] );

        $this->registerHooks();

        // Automatically register the admin license page if 'menu' config is provided.
        if ( ! empty( $this->config['menu'] ) && is_admin() ) {
            $this->admin_page = new AdminPage( $this, (array) $this->config['menu'] );
        }

        // Automatically register the update checker if plugin_file is provided or detected.
        if ( ! empty( $this->config['plugin_file'] ) ) {
            error_log( '[LMW SDK] Registering UpdateChecker with file: ' . $this->config['plugin_file'] );
            $this->update_checker = new UpdateChecker( $this );
        } else {
            error_log( '[LMW SDK] UpdateChecker NOT registered: plugin_file is empty.' );
        }
    }

    // =========================================================================
    //  WP Lifecycle Hooks
    // =========================================================================

    private function registerHooks() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_init', array( $this, '_maybeScheduleCheck' ) );
        add_action( 'lmw_sdk_check_' . $this->config['slug'], array( $this, '_runBackgroundCheck' ) );
        add_action( 'upgrader_process_complete', array( $this, '_onUpgraderComplete' ), 10, 2 );
    }

    /** @internal Called by admin_init. */
    public function _maybeScheduleCheck() {
        if ( ! $this->storage->getLicenseKey() ) {
            return; // Nothing stored yet.
        }
        
        $period_sec = (int) $this->config['check_period_days'] * DAY_IN_SECONDS;
       
        if ( ( time() - $this->storage->getLastCheck() ) < $period_sec ) {
            return; // Not due yet.
        }

        $hook = 'lmw_sdk_check_' . $this->config['slug'];
        if ( ! wp_next_scheduled( $hook ) ) {
            // Schedule for 15s in the future so background check triggers on next page load or cron run
            wp_schedule_single_event( time() + 15, $hook );
        }
    }

    /** @internal Called by WP-Cron. */
    public function _runBackgroundCheck() {
        try {
            $this->validate();
        } catch ( LmwException $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[LMW SDK] Background check failed: ' . $e->getMessage() );
            }
        }
    }

    /** @internal Called by admin_notices. */
    public function _displayExpiredNotice() {
        // Only show to admins who can manage options.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // We check the raw storage for expiration status.
        $expires_at = $this->storage->getExpiresAt();
        
        // If it's in the past, show the notice.
        if ( $expires_at && strtotime( $expires_at ) < time() ) {
            $license_page = admin_url( 'admin.php?page=' . ( $this->config['menu']['slug'] ?? $this->config['slug'] . '-license' ) );
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php printf( 
                        __( 'Your license for <strong>%s</strong> has expired on %s. Please <a href="%s">renew your license</a> to continue receiving updates and support.', 'lmw-client-sdk' ),
                        esc_html( $this->config['plugin_name'] ),
                        esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires_at ) ) ),
                        esc_url( $license_page )
                    ); ?>
                </p>
            </div>
            <?php
        }
    }

    /** @internal Called by upgrader_process_complete. */
    public function _onUpgraderComplete( $upgrader, $hook_extra ) {
        if ( isset( $hook_extra['type'] ) && $hook_extra['type'] === $this->config['type'] ) {
            $this->reportInstall( 'update' );
        }
    }

    // =========================================================================
    //  Public API
    // =========================================================================

    /**
     * Activate a license key for this site.
     *
     * On success, the license key and status are saved to wp_options so
     * isActiveLicense() will return true immediately without another HTTP call.
     *
     * @param string $license_key  The key the user entered in your settings page.
     *
     * @return object {
     *   bool   $activated
     *   string $expires_at   (null for lifetime)
     *   int    $activation_id
     * }
     * @throws LmwException  On API error (invalid key, limit reached, expired, etc.)
     */
    public function activate( $license_key ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[LMW SDK] Starting activation tool for: " . $license_key );
        }

        $params = array_merge( array(
            'license_key'    => sanitize_text_field( $license_key ),
            'domain'         => $this->getDomain(),
            'plugin_version' => $this->config['plugin_version'],
            'wp_version'     => Helpers::getWpVersion(),
            'php_version'    => Helpers::getPhpVersion(),
        ), $this->getMetadata() );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[LMW SDK] Activation Params: " . print_r( $params, true ) );
        }

        $data = $this->http->post( 'activate', $params );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[LMW SDK] Final processed data: " . print_r( $data, true ) );
        }

        // Persist on success.
        $this->storage->setLicenseKey( $license_key );
        $this->storage->setStatus( 'active' );
        
        // Always set/clear expires_at first so a lifetime license clears old data.
        $this->storage->setExpiresAt( isset( $data->expires_at ) ? $data->expires_at : null );

        if ( isset( $data->activation_id ) ) {
            $this->storage->setActivationId( $data->activation_id );
        }
        if ( isset( $data->email ) ) {
            $this->storage->setEmail( $data->email );
        }
        if ( isset( $data->order_id ) ) {
            $this->storage->setOrderId( $data->order_id );
        }
        if ( isset( $data->activations_left ) ) {
            $this->storage->setActivationsLeft( $data->activations_left );
        }
        if ( isset( $data->times_activated_max ) ) {
            $this->storage->setActivationLimit( $data->times_activated_max );
        }
        if ( isset( $data->times_activated ) ) {
            $this->storage->setTimesActivated( $data->times_activated );
        }
        if ( isset( $data->tier ) ) {
            $this->storage->setTier( $data->tier );
        }
        $this->storage->touchLastCheck();

        // Fire /installed non-blocking (no timeout wait) so the Installed On
        // table is populated immediately without slowing down the page.
        $this->reportInstall( 'install', true );

        return $data;
    }

    /**
     * Deactivate the currently stored license on this site.
     *
     * Clears local state so isActiveLicense() returns false immediately.
     *
     * @return object { bool $deactivated, string $domain }
     * @throws LmwException  If no license key is stored, or on API error.
     */
    public function deactivate() {
        $key = $this->storage->getLicenseKey();
        if ( ! $key ) {
            throw new LmwException( 'No license key stored. Nothing to deactivate.', 'lmw_no_license' );
        }

        try {
            $data = $this->http->post( 'deactivate', array_merge( array(
                'license_key' => $key,
                'domain'      => $this->getDomain(),
            ), $this->getMetadata() ) );
        } catch ( LmwException $e ) {
            // Be radical: if the user explicitly clicks 'Deactivate' and we get ANY API error
            // (not found, invalid key, app deleted, etc.), we should still allow local deactivation
            // so the user isn't 'stuck' in an active state when the server no longer knows them.
            
            // We only re-throw if it's a network/timeout error where we can't even reach the server.
            if ( in_array( $e->getErrorCode(), array( 'lmw_timeout', 'lmw_no_license' ) ) ) {
                throw $e;
            }

            $this->storage->setStatus( 'inactive' );
            $this->storage->touchLastCheck();
            return (object) array( 'deactivated' => true, 'synced' => false );
        }

        $this->storage->setStatus( 'inactive' );
        $this->storage->setExpiresAt( null ); // Clear expiry on logout
        $this->storage->touchLastCheck();

        return $data;
    }

    /**
     * Validate the stored license key against the store (live HTTP call).
     *
     * Updates local status so future isActiveLicense() calls reflect server state.
     *
     * @param string|null $license_key  Override. Uses stored key if null.
     *
     * @return object {
     *   bool   $valid
     *   string $status          e.g. "Active"
     *   bool   $is_active
     *   bool   $is_expired
     *   string $expires_at
     *   bool   $is_activated_here
     *   int    $activations_left
     * }
     * @throws LmwException  If no license key is available or on API error.
     */
    public function validate( $license_key = null ) {
        $key = $license_key ?: $this->storage->getLicenseKey();
        if ( ! $key ) {
            throw new LmwException( 'No license key available to validate.', 'lmw_no_license' );
        }

        try {
            $data = $this->http->post( 'validate', array_merge( array(
                'license_key' => $key,
                'domain'      => $this->getDomain(),
            ), $this->getMetadata() ) );
        } catch ( LmwException $e ) {
            // If it's a network/timeout error or regular API error, we keep the current local state.
            // We only deactivate if the server EXPLICITLY returns a 'not found' or 'invalid' error in the data.
            throw $e;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[LMW SDK] Validate Raw Data: ' . print_r( $data, true ) );
        }

        // Update local cache from server response.
        if ( isset( $data->is_expired ) && $data->is_expired ) {
            $this->storage->setStatus( 'inactive' );
        } elseif ( isset( $data->status ) && ! empty( $data->status ) ) {
            // Trust the explicit status from the server if provided.
            $this->storage->setStatus( $data->status );
        } elseif ( isset( $data->is_activated_here ) ) {
            $this->storage->setStatus( $data->is_activated_here ? 'active' : 'inactive' );
        } elseif ( isset( $data->is_activated ) ) {
            $this->storage->setStatus( $data->is_activated ? 'active' : 'inactive' );
        } elseif ( isset( $data->valid ) ) {
            $this->storage->setStatus( $data->valid ? 'active' : 'inactive' );
        } elseif ( isset( $data->is_active ) ) {
            $this->storage->setStatus( $data->is_active ? 'active' : 'inactive' );
        }

        // If it's active and no expires_at is sent, it's a lifetime license.
        if ( $this->storage->isActive() ) {
            if ( ! isset( $data->expires_at ) || empty( $data->expires_at ) ) {
                $this->storage->setExpiresAt( null );
            }
        }

        if ( isset( $data->expires_at ) && ! empty( $data->expires_at ) ) {
            $this->storage->setExpiresAt( $data->expires_at );
        }
        if ( isset( $data->email ) ) {
            $this->storage->setEmail( $data->email );
        }
        if ( isset( $data->order_id ) ) {
            $this->storage->setOrderId( $data->order_id );
        }
        if ( isset( $data->activations_left ) ) {
            $this->storage->setActivationsLeft( $data->activations_left );
        }
        if ( isset( $data->times_activated_max ) ) {
            $this->storage->setActivationLimit( $data->times_activated_max );
        }
        if ( isset( $data->times_activated ) ) {
            $this->storage->setTimesActivated( $data->times_activated );
        }
        if ( isset( $data->tier ) ) {
            $this->storage->setTier( $data->tier );
        }

        $this->storage->touchLastCheck();

        return $data;
    }

    /**
     * Check for updates via the dedicated update API.
     *
     * @return object|null
     * @throws LmwException
     */
    public function checkUpdates() {
        $license_key = $this->getLicenseKey();
        if ( ! $license_key ) {
            return null;
        }

        return $this->http->getUpdate( $license_key );
    }

    /**
     * Report a plugin install / update / deactivation event to the store.
     *
     * This is optional but gives the store useful deployment analytics.
     * It is non-blocking and swallows exceptions.
     *
     * @param string $action    install | update | activate | uninstall
     * @param bool   $is_active Whether the plugin is currently active.
     *
     * @return object|array|\WP_Error|null  API response, or null if no key is stored.
     */
    public function reportInstall( $action = 'install', $is_active = true ) {
        $key = $this->storage->getLicenseKey();
        if ( ! $key ) {
            return null;
        }

        // Use fire-and-forget (non-blocking) so this never delays a page load.
        return $this->http->postAsync( 'installed', array_merge( array(
            'license_key'    => $key,
            'domain'         => $this->getDomain(),
            'plugin_name'    => $this->config['plugin_name'] ?? '',
            'plugin_version' => $this->config['plugin_version'],
            'wp_version'     => Helpers::getWpVersion(),
            'php_version'    => Helpers::getPhpVersion(),
            'action'         => sanitize_key( $action ),
            'is_active'      => (bool) $is_active,
        ), $this->getMetadata() ) );
    }

    // =========================================================================
    //  State Helpers  (fast local checks — no HTTP)
    // =========================================================================

    /**
     * Whether plugin features should be allowed to run.
     *
     * Returns false only when on_expire = 'block' AND the license is expired.
     * Use this to gate premium functionality.
     *
     *   if ( my_plugin_lmw()->isFeatureAllowed() ) { // run premium code }
     *
     * @return bool
     */
    public function isFeatureAllowed() {
        $status = $this->getLicenseStatus();
        $expires = $this->storage->getExpiresAt();

        // If license is explicitly active or delivered, always allow.
        if ( $status === 'active' || $status === 'delivered' ) {
            return true;
        }

        // If license is expired AND on_expire = 'block', disallow.
        if ( $status === 'expired' && $this->config['on_expire'] === 'block' ) {
            return false;
        }

        // No license at all — if on_expire is 'block', block; if 'allow', allow.
        // This lets devs gate even 'unknown' / 'inactive' states if desired.
        // Default: allow (don't block on missing/inactive license).
        return true;
    }

    /**
     * Return the UpdateChecker instance (or null if not configured).
     *
     * @return UpdateChecker|null
     */
    public function getUpdateChecker() {
        return $this->update_checker;
    }

    /**
     * Check if the license is locally considered active.
     *
     * This reads from wp_options — no HTTP call is made.
     * Background checks keep this value fresh automatically.
     *
     * @return bool
     */
    public function isActiveLicense() {
        return $this->storage->isActive();
    }

    /**
     * Get the current stored license status string.
     *
     * @return string  active | inactive | expired | invalid | unknown
     */
    public function getLicenseStatus() {
        return $this->storage->getStatus();
    }

    /** @return string|null */
    public function getLicenseKey() { return $this->storage->getLicenseKey(); }

    /** @return string|null  MySQL datetime or null (lifetime) */
    public function getLicenseExpiry() { return $this->storage->getExpiresAt(); }

    /**
     * Clear all stored license data for this plugin.
     * Call this on plugin uninstall.
     */
    public function clearStoredData() { $this->storage->clear(); }

    /** @return Storage */
    public function getStorage() { return $this->storage; }

    /** @return HttpClient */
    public function getHttpClient() { return $this->http; }

    public function getConfig( $key, $default = null ) {
        return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
    }

    private function getDomain() {
        return ! empty( $this->config['domain'] ) ? $this->config['domain'] : Helpers::getSiteDomain();
    }

    private function getMetadata() {
        return array(
            'sdk_version' => Helpers::getSdkVersion(),
            'country'     => Helpers::getCountry(),
            'user_email'  => get_option( 'admin_email' ),
        );
    }

    /**
     * Attempt to automatically detect the main plugin file.
     * Starts from the include directory of the SDK and looks for a .php file
     * with a plugin header in the parent plugin root.
     *
     * @return string
     */
    private function autoDetectPluginFile() {
        // SDK is assumed to be in vendor/lmw-client-sdk/src/LmwClient.php
        $plugin_root = dirname( __DIR__, 3 );
        error_log( '[LMW SDK] Attempting auto-detection in: ' . $plugin_root );
        
        if ( ! is_dir( $plugin_root ) ) {
            error_log( '[LMW SDK] Plugin root directory does not exist.' );
            return '';
        }

        $files = glob( $plugin_root . '/*.php' );
        if ( $files ) {
            foreach ( $files as $file ) {
                // Read the first 2KB of the file to check for plugin headers.
                $content = @file_get_contents( $file, false, null, 0, 2048 );
                if ( $content && stripos( $content, 'Plugin Name:' ) !== false ) {
                    return $file;
                }
            }
            // Fallback to the first found PHP file if no header found.
            return $files[0];
        }

        return '';
    }
}
