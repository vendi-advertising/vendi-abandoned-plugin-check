<?php
/*
Plugin Name: Vendi Abandoned Plugin Check
Description: Provides information about abandoned plugins
Version: 3.4.0
Requires PHP: 5.6
License: GPLv2
Author: Vendi Advertising (Chris Haas)
*/

if(!defined('VENDI_APC_DIR')){
    define('VENDI_APC_DIR', dirname(__FILE__));
}

if(!defined('VENDI_APC_FILE')){
    define('VENDI_APC_FILE', __FILE__);
}

require_once VENDI_APC_DIR . '/includes/autoload.php';

//Init the plugin
$plugin = Vendi\Plugin\HealthCheck\Checker::create_with_default_file_logger();
$plugin->register_all_hooks();

