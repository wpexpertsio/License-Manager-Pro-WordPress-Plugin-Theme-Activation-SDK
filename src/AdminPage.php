<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AdminPage — Renders a WordPress admin license activation page.
 *
 * Automatically registered when 'menu' key is present in lmw_sdk_init() config.
 * Handles form submission (activate / deactivate) and displays current status.
 *
 * @package LmwClientSDK
 */
class AdminPage {

    /** @var LmwClient */
    private $client;

    /** @var array */
    private $menu_config;

    /** @var string */
    private $slug;

    /**
     * @param LmwClient $client
     * @param array     $menu_config {
     *   @type string $slug        Menu slug (matches your plugin's top-level menu).
     *   @type string $page_title  Page title. Default: 'License Activation'.
     *   @type string $menu_title  Menu item label. Default: 'License'.
     *   @type string $capability  Capability. Default: 'manage_options'.
     *   @type string $position    Menu position (for top-level menus). Default: null.
     *   @type bool   $add_submenu Whether to add as submenu of an existing menu. Default: true.
     *   @type string $parent_slug Parent menu slug for submenus.
     * }
     */
    public function __construct( LmwClient $client, array $menu_config ) {
        $this->client = $client;
        $this->slug   = $client->getConfig( 'slug' );

        // All keys are optional except parent_slug (or it defaults to the plugin slug).
        // Minimum config: [ 'parent_slug' => 'my-plugin', 'page_title' => 'My License Page' ]
        $this->menu_config = array_merge( array(
            'page_title'  => 'License Activation',
            'menu_title'  => 'Activate License',
            'capability'  => 'manage_options',
            'slug'        => $this->slug . '-license',
            'parent_slug' => $this->slug,   // default: submenu under own plugin
            'position'    => null,
        ), $menu_config );

        // menu_title defaults to page_title when not set separately.
        if ( empty( $menu_config['menu_title'] ) ) {
            $this->menu_config['menu_title'] = $this->menu_config['page_title'];
        }

        // Priority 20: runs AFTER the parent plugin registers its top-level menu (priority 10).
        add_action( 'admin_menu', array( $this, 'registerMenu' ), 20 );
        add_action( 'admin_post_lmw_activate_'   . $this->slug, array( $this, 'handleActivate' ) );
        add_action( 'admin_post_lmw_deactivate_' . $this->slug, array( $this, 'handleDeactivate' ) );
        add_action( 'admin_post_lmw_sync_'       . $this->slug, array( $this, 'handleSync' ) );
    }

    // =========================================================================
    //  Menu Registration
    // =========================================================================

    public function registerMenu() {
        if ( ! empty( $this->menu_config['parent_slug'] ) ) {
            add_submenu_page(
                $this->menu_config['parent_slug'],
                $this->menu_config['page_title'],
                $this->menu_config['menu_title'],
                $this->menu_config['capability'],
                $this->menu_config['slug'],
                array( $this, 'renderPage' )
            );

            // If blocking is enabled and license is not active or is expired, hide all other submenus of this parent.
            $status = $this->client->getLicenseStatus();
            if ( $this->client->getConfig( 'block_after_expiration' ) && ( ! $this->client->isActiveLicense() || $status === 'expired' ) ) {
                $this->restrictMenus();
            }
        } else {
            add_menu_page(
                $this->menu_config['page_title'],
                $this->menu_config['menu_title'],
                $this->menu_config['capability'],
                $this->menu_config['slug'],
                array( $this, 'renderPage' ),
                'dashicons-lock',
                $this->menu_config['position']
            );
        }
    }

    /**
     * Remove all submenus of the current parent except the license page itself.
     * This is only called when block_after_expiration is true and the license is invalid.
     */
    private function restrictMenus() {
        global $submenu;
        $parent = $this->menu_config['parent_slug'];

        if ( ! isset( $submenu[ $parent ] ) ) {
            return;
        }

        // Keep a copy of the submenus to iterate.
        $items = $submenu[ $parent ];
        foreach ( $items as $item ) {
            // item[2] is the slug.
            if ( $item[2] !== $this->menu_config['slug'] ) {
                remove_submenu_page( $parent, $item[2] );
            }
        }
    }

    // =========================================================================
    //  Form Handlers
    // =========================================================================

    /** Handle the Activate form POST. */
    public function handleActivate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'lmw_activate_' . $this->slug );

        $key = isset( $_POST['lmw_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lmw_license_key'] ) ) : '';

        if ( empty( $key ) ) {
            $this->redirect( 'error', 'Please enter a license key.' );
            return;
        }

        try {
            $this->client->activate( $key );
            $this->redirect( 'activated', 'License activated successfully!' );
        } catch ( LmwException $e ) {
            // Prefer the human-friendly API message; fall back to the exception message.
            $msg = $e->getApiMessage() ?: $e->getMessage();
            // Strip raw cURL detail to avoid exposing internals to the user.
            if ( false !== strpos( $msg, 'cURL' ) || false !== strpos( $msg, 'Network error' ) ) {
                $msg = 'Could not reach the license server. Please check your connection and try again.';
            }
            $this->redirect( 'error', $msg );
        }
    }

    /** Handle the Deactivate form POST. */
    public function handleDeactivate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'lmw_deactivate_' . $this->slug );

        try {
            $this->client->deactivate();
            $this->redirect( 'deactivated', 'License deactivated.' );
        } catch ( LmwException $e ) {
            $msg = $e->getApiMessage() ?: $e->getMessage();
            if ( false !== strpos( $msg, 'cURL' ) || false !== strpos( $msg, 'Network error' ) ) {
                $msg = 'Could not reach the license server. Please check your connection and try again.';
            }
            $this->redirect( 'error', $msg );
        }
    }

    /** Handle the Sync (Validate) form POST. */
    public function handleSync() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'lmw_sync_' . $this->slug );

        try {
            $this->client->validate();
            
            // Check if the sync actually resulted in an active license.
            if ( ! $this->client->isActiveLicense() ) {
                $status = $this->client->getLicenseStatus();
                $this->redirect( 'error', sprintf( 'License sync completed, but the license is currently "%s". Please check your license status on the store.', $status ) );
            }

            $this->redirect( 'synced', 'License synced successfully!' );
        } catch ( LmwException $e ) {
            $msg = $e->getApiMessage() ?: $e->getMessage();
            if ( false !== strpos( $msg, 'cURL' ) || false !== strpos( $msg, 'Network error' ) ) {
                $msg = 'Could not reach the license server. Please check your connection and try again.';
            }
            $this->redirect( 'error', $msg );
        }
    }

    // =========================================================================
    //  Page Render
    // =========================================================================
    public function renderPage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status       = $this->client->getLicenseStatus();
        $license_key  = $this->client->getLicenseKey();
        $is_active    = $this->client->isActiveLicense();
        $expires_at   = $this->client->getStorage()->getExpiresAt();
        
        $storage = $this->client->getStorage();
        $used = $storage->getTimesActivated();
        $activation_limit = $storage->getActivationLimit();
        $activations_left = $storage->getActivationsLeft();
        $tier = $storage->getTier();

        // Enqueue the new styles
        $css_url = plugin_dir_url( dirname( __FILE__ ) ) . 'styles/admin.css';
        echo '<link rel="stylesheet" href="' . esc_url( $css_url ) . '" type="text/css" media="all" />';

        // Read transient notices
        $notice_type = get_transient( 'lmw_notice_type_' . $this->slug );
        $notice_msg  = get_transient( 'lmw_notice_msg_' . $this->slug );
        delete_transient( 'lmw_notice_type_' . $this->slug );
        delete_transient( 'lmw_notice_msg_' . $this->slug );
        ?>

        <div id="lmw-admin-page">
            <div class="lmw-container">
                
                <header class="lmw-header">
                    <h1 class="lmw-title"><?php echo esc_html( $this->menu_config['page_title'] ); ?></h1>
                    <p class="lmw-subtitle">Manage your product license and activations</p>
                </header>

                <?php if ( $notice_msg ) : ?>
                    <div class="lmw-alert lmw-alert-<?php echo $notice_type === 'error' ? 'error' : 'success'; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <?php if ( $notice_type === 'error' ) : ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/><?php else : ?><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/><?php endif; ?>
                        </svg>
                        <span><?php echo esc_html( $notice_msg ); ?></span>
                    </div>
                <?php endif; ?>

                <?php 
                $expires_at_raw = $this->client->getStorage()->getExpiresAt();
                if ( ! $is_active && $expires_at_raw && $expires_at_raw !== '0000-00-00 00:00:00' && strtotime( $expires_at_raw ) < time() ) : ?>
                    <div class="lmw-alert lmw-alert-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span>
                            <?php printf( 
                                __( 'Your license for <strong>%s</strong> has expired on %s. Please renew your license to continue receiving updates and support.', 'lmw-client-sdk' ),
                                esc_html( $this->client->getConfig( 'plugin_name' ) ),
                                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires_at_raw ) ) )
                            ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="<?php echo $is_active ? 'lmw-stats-row' : ''; ?>" style="margin-bottom: 24px;">
                    <!-- Status Card -->
                    <div class="lmw-stat-card">
                        <span class="lmw-stat-label">License Status</span>
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span class="lmw-stat-value"><?php echo esc_html( ucfirst( $status ) ); ?></span>
                            <span class="lmw-badge lmw-badge-<?php echo esc_attr( $status ); ?>">
                                <?php echo esc_html( $is_active ? 'Active' : 'Missing' ); ?>
                            </span>
                        </div>
                        <?php if ( $is_active ) : ?>
                            <div style="margin-top: 12px; font-size: 0.8125rem; color: var(--lmw-text-muted);">
                                <?php if ( $expires_at && $expires_at !== '0000-00-00 00:00:00' ) : ?>
                                    Expires: <strong style="color: var(--lmw-primary);"><?php echo date_i18n( get_option( 'date_format' ), strtotime( $expires_at ) ); ?></strong>
                                <?php else : ?>
                                    Validity: <strong style="color: var(--lmw-success);">Lifetime</strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Usage Card -->
                    <?php if ( $is_active ) : ?>
                        <div class="lmw-stat-card">
                            <span class="lmw-stat-label">Device Limit</span>
                            <div style="display: flex; align-items: baseline; gap: 4px;">
                                <span class="lmw-stat-value"><?php echo intval( $used ); ?></span>
                                <span style="color: var(--lmw-text-muted); font-size: 0.875rem;">/ <?php echo $activation_limit ? intval( $activation_limit ) : '∞'; ?></span>
                            </div>
                            <?php if ( $activation_limit ) : ?>
                                <div class="lmw-progress">
                                    <?php $pct = min( 100, ( $used / $activation_limit ) * 100 ); ?>
                                    <div class="lmw-progress-bar" style="width: <?php echo $pct; ?>%;"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Main Card -->
                <div class="lmw-card">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php if ( $is_active ) : ?>
                            <input type="hidden" name="action" value="lmw_deactivate_<?php echo esc_attr( $this->slug ); ?>">
                            <?php wp_nonce_field( 'lmw_deactivate_' . $this->slug ); ?>
                        <?php else : ?>
                            <input type="hidden" name="action" value="lmw_activate_<?php echo esc_attr( $this->slug ); ?>">
                            <?php wp_nonce_field( 'lmw_activate_' . $this->slug ); ?>
                        <?php endif; ?>

                        <div class="lmw-input-wrapper">
                            <label class="lmw-form-label" for="lmw_license_key">License Key</label>
                            <input 
                                type="text" 
                                id="lmw_license_key" 
                                name="lmw_license_key" 
                                class="lmw-input"
                                placeholder="Paste your license key here..."
                                value="<?php echo esc_attr( $license_key ); ?>"
                                <?php echo $is_active ? 'readonly' : ''; ?>
                            />
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <?php if ( $is_active && $tier ) : ?>
                                    <span style="font-size: 0.875rem; color: var(--lmw-text-muted);">
                                        Tier: <strong style="color: var(--lmw-primary);"><?php echo esc_html( $tier ); ?></strong>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <?php if ( $is_active ) : ?>
                                    <button type="submit" form="lmw-sync-form" class="lmw-btn lmw-btn-secondary">Sync License</button>
                                    <button type="submit" class="lmw-btn lmw-btn-danger">Deactivate</button>
                                <?php else : ?>
                                    <button type="submit" class="lmw-btn lmw-btn-primary">Activate License</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <?php if ( $is_active ) : ?>
                        <form id="lmw-sync-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
                            <input type="hidden" name="action" value="lmw_sync_<?php echo esc_attr( $this->slug ); ?>">
                            <?php wp_nonce_field( 'lmw_sync_' . $this->slug ); ?>
                        </form>
                    <?php endif; ?>
                </div>

                <div style="text-align: center;">
                    <a href="<?php echo esc_url( admin_url() ); ?>" style="color: var(--lmw-text-muted); font-size: 0.875rem; text-decoration: none;">
                        &larr; Return to Dashboard
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Redirect back to the license page with a transient notice.
     *
     * @param string $type  'activated' | 'deactivated' | 'error'
     * @param string $msg
     */
    private function redirect( $type, $msg ) {
        set_transient( 'lmw_notice_type_' . $this->slug, $type === 'error' ? 'error' : 'updated', 60 );
        set_transient( 'lmw_notice_msg_' . $this->slug, $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_config['slug'] ) );
        exit;
    }

    /**
     * Mask a license key for display, showing first segment + dots.
     *
     * @param string $key
     * @return string
     */
    private function maskKey( $key ) {
        $parts = explode( '-', $key, 2 );
        if ( count( $parts ) === 2 ) {
            return $parts[0] . '-' . str_repeat( '•', min( 16, strlen( $parts[1] ) ) );
        }
        $len = strlen( $key );
        return substr( $key, 0, 4 ) . str_repeat( '•', max( 4, $len - 4 ) );
    }
}
