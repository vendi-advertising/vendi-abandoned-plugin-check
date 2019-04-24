<?php
/*
Plugin Name: Vendi Abandoned Plugin Check
Description: Provides information about abandoned plugins
Version: 3.3.1
License: GPLv2
Author: Vendi Advertising (Chris Haas)
*/

require_once __DIR__ . '/includes/autoload.php';

if( ! defined( 'VENDI_APC_LOG_LEVEL' ) ) {
    define( 'VENDI_APC_LOG_LEVEL', Vendi_Plugin_Health_Check::LOG_LEVEL_NONE );
}

if( ! defined( 'VENDI_APC_LOG_PATH' ) ) {
    define( 'VENDI_APC_LOG_PATH', dirname( __FILE__ ) . '/__debug/' );
}

//Init the plugin
new Vendi\Plugin\HealthCheck();

