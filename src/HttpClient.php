<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * HttpClient — Sends requests to the License Manager store's public API.
 *
 * Endpoint base: {rest_api_url}/wp-json/lmfwc/v1/public/
 * Auth: public_key is injected into every request body automatically.
 *
 * @package LmwClientSDK
 */
class HttpClient {

    private $rest_api_url;
    private $public_key;
    private $application_id;
    private $namespace = 'lmfwc/v1/public';
    private $timeout;

    /**
     * @param string $rest_api_url   The REST API base URL of the store.
     * @param string $public_key Application public key (lm_live_xxx).
     * @param int    $timeout    HTTP timeout in seconds.
     */
    public function __construct( $rest_api_url, $public_key, $application_id = null, $timeout = 15 ) {
        $this->rest_api_url       = rtrim( $rest_api_url, '/' );
        $this->public_key     = $public_key;
        $this->application_id = $application_id;
        $this->timeout        = (int) $timeout;
    }

    /**
     * POST to a public SDK endpoint.
     *
     * Available endpoints: activate | deactivate | validate | installed
     *
     * @param string $endpoint  e.g. 'activate'
     * @param array  $body      Request body fields (public_key injected automatically).
     *
     * @return object  Decoded response->data object from the store.
     * @throws LmwException  On network error or non-2xx response.
     */
    public function post( $endpoint, array $body = array() ) {
        $url = sprintf(
            '%s/wp-json/%s/%s',
            $this->rest_api_url,
            $this->namespace,
            ltrim( $endpoint, '/' )
        );

        $body['public_key'] = $this->public_key;
        if ( $this->application_id ) {
            $body['application_id'] = $this->application_id;
        }

        // Append public_key and application_id to URL as well for better compatibility.
        $url = add_query_arg( 'public_key', $this->public_key, $url );
        if ( $this->application_id ) {
            $url = add_query_arg( 'application_id', $this->application_id, $url );
        }

        $response = wp_remote_post( $url, array(
            'timeout'   => $this->timeout,
            'sslverify' => false,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
            'body' => $body, // Sending as form data instead of JSON
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[LMW SDK] Request URL: " . $url );
            error_log( "[LMW SDK] Raw Response: " . wp_remote_retrieve_body( $response ) );
        }

        return $this->parse( $response );
    }

    /**
     * Fire-and-forget POST — sends the request and returns immediately.
     * The server response is never read; no exceptions are thrown.
     * Used for analytics/tracking calls (e.g. /installed) that must not
     * delay or break the current page load.
     *
     * @param string $endpoint
     * @param array  $body
     *
     * @return array|\WP_Error|null
     */
    public function postAsync( $endpoint, array $body = array() ) {
        $url = sprintf(
            '%s/wp-json/%s/%s',
            $this->rest_api_url,
            $this->namespace,
            ltrim( $endpoint, '/' )
        );

        $body['public_key'] = $this->public_key;
        if ( $this->application_id ) {
            $body['application_id'] = $this->application_id;
        }

        $url = add_query_arg( 'public_key', $this->public_key, $url );
        if ( $this->application_id ) {
            $url = add_query_arg( 'application_id', $this->application_id, $url );
        }

        // blocking=false means WP sends the request and moves on immediately.
        // We use a slightly higher timeout to allow the connection to be established.
        $response = wp_remote_post( $url, array(
            'timeout'     => 5,
            'blocking'    => false,
            'sslverify'   => false,
            'headers'     => array( 'Accept' => 'application/json' ),
            'body'        => $body,
        ) );

        if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[LMW SDK] postAsync network error: ' . $response->get_error_message() );
        }

        return $response;

    }

    /**
     * GET update info for a specific application.
     *
     * @param int    $application_id
     * @param string $license_key
     *
     * @return object  Decoded response data { version, download_url, changelog }
     * @throws LmwException
     */
    /**
     * GET update info for a specific application.
     *
     * @param string $license_key
     *
     * @return object  Decoded response data { version, download_url, changelog }
     * @throws LmwException
     */
    public function getUpdate( $license_key ) {
        $url = sprintf(
            '%s/wp-json/plugin-update/v1/check',
            $this->rest_api_url
        );

        $url = add_query_arg( array(
            'public_key'  => $this->public_key,
            'license_key' => $license_key,
        ), $url );

        if ( $this->application_id ) {
            $url = add_query_arg( 'application_id_payload', $this->application_id, $url );
        }

        $response = wp_remote_get( $url, array(
            'timeout'   => $this->timeout,
            'sslverify' => false,
            'headers'   => array( 'Accept' => 'application/json' ),
        ) );

        return $this->parse( $response );
    }

    /**
     * @param array|\WP_Error $response
     * @return object
     * @throws LmwException
     */
    private function parse( $response ) {
        if ( is_wp_error( $response ) ) {
            $error_msg  = $response->get_error_message();
            $error_code = $response->get_error_code();

            // Detect cURL timeout (error 28) and give a human-friendly message.
            if ( false !== strpos( $error_msg, 'cURL error 28' ) || false !== strpos( $error_msg, 'timed out' ) ) {
                throw new LmwException(
                    'Connection timed out.',
                    'lmw_timeout',
                    'Could not reach the license server. Please check your internet connection or try again later.'
                );
            }

            // Generic network error.
            throw new LmwException(
                'Network error: ' . $error_msg,
                $error_code,
                'Could not connect to the license server. Please verify the store URL is correct and accessible.'
            );
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body );

        if ( $code < 200 || $code >= 300 ) {
            $error_code = 'http_error';
            $error_msg  = wp_remote_retrieve_response_message( $response );

            if ( is_object( $decoded ) ) {
                if ( isset( $decoded->code ) ) {
                    $error_code = $decoded->code;
                }
                if ( isset( $decoded->message ) ) {
                    $error_msg = $decoded->message;
                }
            } elseif ( is_string( $body ) && ! empty( $body ) && ! is_object( $decoded ) ) {
                // If body is plain text (like an application error), use it.
                $error_msg = strip_tags( $body );
            }

            throw new LmwException(
                "API error ({$error_code}).",
                $error_code,
                $error_msg,
                $decoded,
                $code
            );
        }

        return is_object( $decoded ) && isset( $decoded->data ) ? $decoded->data : $decoded;
    }
}
