<?php

namespace LmwClientSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LmwException — SDK Exception Class
 *
 * @package LmwClientSDK
 */
class LmwException extends \RuntimeException {

    private $error_code;
    private $api_message;
    private $http_status;
    private $response;

    public function __construct( $message = '', $error_code = '', $api_message = null, $response = null, $http_status = 0, $previous = null ) {
        parent::__construct( $message, 0, $previous );
        $this->error_code  = $error_code;
        $this->api_message = $api_message;
        $this->http_status = $http_status;
        $this->response    = $response;
    }

    /** @return string  Short API error code e.g. lmfwc_license_expired */
    public function getErrorCode()  { return $this->error_code; }

    /** @return string|null Human readable message from the API */
    public function getApiMessage() { return $this->api_message; }

    /** @return int HTTP status code */
    public function getHttpStatus() { return $this->http_status; }

    /** @return mixed Raw decoded response */
    public function getResponse()   { return $this->response; }
}
