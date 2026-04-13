<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Storage — Persists license state to WordPress options.
 *
 * All keys are namespaced per plugin slug to avoid collisions when
 * multiple plugins use this SDK at the same time.
 *
 * Option keys:
 *   lmw_{slug}_license_key    — the raw license key
 *   lmw_{slug}_activation_id  — activation record ID from the store
 *   lmw_{slug}_status         — active | inactive | expired | invalid | unknown
 *   lmw_{slug}_expires_at     — MySQL datetime or null (lifetime license)
 *   lmw_{slug}_last_check     — Unix timestamp of last validation
 *
 * @package LmwClientSDK
 */
class Storage {

    private $prefix;

    /** @param string $slug  Plugin slug (same as the 'slug' config key). */
    public function __construct( $slug ) {
        $this->prefix = 'lmw_' . sanitize_key( $slug ) . '_';
    }

    // --- Getters ---

    /** @return string|null */
    public function getLicenseKey() {
        return get_option( $this->k( 'license_key' ), null ) ?: null;
    }

    /** @return int|null */
    public function getActivationId() {
        $v = get_option( $this->k( 'activation_id' ), null );
        return ( $v !== null ) ? (int) $v : null;
    }

    /** @return string  active|inactive|expired|invalid|unknown */
    public function getStatus() {
        return get_option( $this->k( 'status' ), 'unknown' );
    }

    /** @return string|null */
    public function getExpiresAt() {
        return get_option( $this->k( 'expires_at' ), null ) ?: null;
    }

    /** @return int  Unix timestamp, 0 if never */
    public function getLastCheck() {
        return (int) get_option( $this->k( 'last_check' ), 0 );
    }

    /** @return bool */
    public function isActive() {
        $status = $this->getStatus();
        $expires = $this->getExpiresAt();
        
        // Active status or 0000-00-00 date counts as active.
        return ( $status === 'active' || $expires === '0000-00-00 00:00:00' || empty( $expires ) ) && $status !== 'inactive';
    }

    /** @return string|null */
    public function getEmail() {
        return get_option( $this->k( 'email' ), null ) ?: null;
    }

    /** @return string|null */
    public function getOrderId() {
        return get_option( $this->k( 'order_id' ), null ) ?: null;
    }

    /** @return int|null */
    public function getActivationsLeft() {
        $v = get_option( $this->k( 'activations_left' ), null );
        return ( $v !== null ) ? (int) $v : null;
    }

    /** @return int|null */
    public function getActivationLimit() {
        $v = get_option( $this->k( 'activation_limit' ), null );
        return ( $v !== null ) ? (int) $v : null;
    }

    /** @return int */
    public function getTimesActivated() {
        return (int) get_option( $this->k( 'times_activated' ), 0 );
    }

    /** @return string|null */
    public function getTier() {
        return get_option( $this->k( 'tier' ), null ) ?: null;
    }

    // --- Setters ---

    public function setLicenseKey( $key ) {
        is_null( $key )
            ? delete_option( $this->k( 'license_key' ) )
            : update_option( $this->k( 'license_key' ), sanitize_text_field( $key ), false );
    }

    public function setActivationId( $id ) {
        is_null( $id )
            ? delete_option( $this->k( 'activation_id' ) )
            : update_option( $this->k( 'activation_id' ), (int) $id, false );
    }

    public function setStatus( $status ) {
        update_option( $this->k( 'status' ), sanitize_key( $status ), false );
    }

    public function setExpiresAt( $datetime ) {
        is_null( $datetime )
            ? delete_option( $this->k( 'expires_at' ) )
            : update_option( $this->k( 'expires_at' ), sanitize_text_field( $datetime ), false );
    }

    public function setEmail( $email ) {
        is_null( $email )
            ? delete_option( $this->k( 'email' ) )
            : update_option( $this->k( 'email' ), sanitize_email( $email ), false );
    }

    public function setOrderId( $order_id ) {
        is_null( $order_id )
            ? delete_option( $this->k( 'order_id' ) )
            : update_option( $this->k( 'order_id' ), sanitize_text_field( $order_id ), false );
    }

    public function setActivationsLeft( $count ) {
        is_null( $count )
            ? delete_option( $this->k( 'activations_left' ) )
            : update_option( $this->k( 'activations_left' ), (int) $count, false );
    }

    public function setActivationLimit( $limit ) {
        is_null( $limit )
            ? delete_option( $this->k( 'activation_limit' ) )
            : update_option( $this->k( 'activation_limit' ), (int) $limit, false );
    }

    public function setTimesActivated( $count ) {
        update_option( $this->k( 'times_activated' ), (int) $count, false );
    }

    public function setTier( $tier ) {
        is_null( $tier )
            ? delete_option( $this->k( 'tier' ) )
            : update_option( $this->k( 'tier' ), sanitize_text_field( $tier ), false );
    }

    /** Update the last-check timestamp to now. */
    public function touchLastCheck() {
        update_option( $this->k( 'last_check' ), time(), false );
    }

    /** Remove ALL stored data for this plugin (e.g. on plugin uninstall). */
    public function clear() {
        foreach ( array( 'license_key', 'activation_id', 'status', 'expires_at', 'last_check', 'email', 'order_id', 'activations_left', 'activation_limit', 'tier' ) as $f ) {
            delete_option( $this->k( $f ) );
        }
    }

    private function k( $field ) {
        return $this->prefix . $field;
    }
}
