<?php

/**
 * LMW Client SDK — Class Autoloader
 *
 * @package LmwClientSDK
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$lmw_sdk_map = array(
    'LmwClientSDK\\LmwException' => __DIR__ . '/LmwException.php',
    'LmwClientSDK\\HttpClient'   => __DIR__ . '/HttpClient.php',
    'LmwClientSDK\\Storage'      => __DIR__ . '/Storage.php',
    'LmwClientSDK\\Helpers'      => __DIR__ . '/Helpers.php',
    'LmwClientSDK\\AdminPage'    => __DIR__ . '/AdminPage.php',
    'LmwClientSDK\\UpdateChecker' => __DIR__ . '/UpdateChecker.php',
    'LmwClientSDK\\LmwClient'    => __DIR__ . '/LmwClient.php',
);

spl_autoload_register( function ( $class ) use ( $lmw_sdk_map ) {
    if ( isset( $lmw_sdk_map[ $class ] ) ) {
        require_once $lmw_sdk_map[ $class ];
    }
} );
