<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helpers — Utility functions.
 *
 * @package LmwClientSDK
 */
class Helpers {

    /**
     * Get the current site domain (normalized: no www, no protocol, no path).
     * Used as the "domain" identifier when communicating with the store.
     *
     * @return string  e.g. "example.com"
     */
    public static function getSiteDomain() {
        $home = function_exists( 'get_home_url' ) ? get_home_url() : ( defined( 'WP_HOME' ) ? WP_HOME : '' );

        if ( $home ) {
            $parsed = wp_parse_url( $home );
            $host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
            // If the host is empty or looks like localhost, fallback to HTTP_HOST
            if ( empty( $host ) || $host === 'localhost' || $host === '127.0.0.1' ) {
                $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : $host;
            }
        } else {
            $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';
        }

        // Strip port numbers if present (to match standard store behavior)
        $host = preg_replace( '#:\d+$#', '', $host );

        return strtolower( preg_replace( '#^www\.#i', '', $host ) );
    }

    /** @return string e.g. "8.2.0" */
    public static function getPhpVersion() {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    /** @return string e.g. "6.4.3" */
    public static function getWpVersion() {
        global $wp_version;
        return isset( $wp_version ) ? $wp_version : '';
    }

    /** @return string e.g. "1.1.0" */
    public static function getSdkVersion() {
        return defined( 'LMW_SDK_VERSION' ) ? LMW_SDK_VERSION : 'unknown';
    }

    /**
     * Get the site's country code.
     *
     * Priority:
     *   1. WooCommerce base country (most accurate for stores).
     *   2. WooCommerce settings option (fallback when WC() not fully loaded).
     *   3. WordPress locale — e.g. "en_US" → "US".
     *   4. Empty string as final fallback.
     *
     * @return string ISO 3166-1 alpha-2 code, e.g. "US", "GB", "PK"
     */
    public static function getCountry() {
        // 1. WooCommerce live country.
        if ( function_exists( 'WC' ) && is_object( WC()->countries ) ) {
            $base = WC()->countries->get_base_country();
            if ( ! empty( $base ) ) {
                return strtoupper( $base );
            }
        }

        // 2. WooCommerce stored option (country may include state, e.g. "US:NY").
        $wc_country = get_option( 'woocommerce_default_country', '' );
        if ( ! empty( $wc_country ) ) {
            $parts = explode( ':', $wc_country );
            return strtoupper( $parts[0] );
        }

        // 3. WordPress locale suffix e.g. "en_US" → "US".
        $locale = function_exists( 'get_locale' ) ? get_locale() : '';
        if ( preg_match( '/[_-]([A-Z]{2})$/i', $locale, $m ) ) {
            return strtoupper( $m[1] );
        }

        return '';
    }
}
