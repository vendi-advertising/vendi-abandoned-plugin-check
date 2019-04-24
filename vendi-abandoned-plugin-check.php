<?php
/*
Plugin Name: Vendi Abandoned Plugin Check
Description: Provides information about abandoned plugins
Version: 3.4.0
License: GPLv2
Author: Vendi Advertising (Chris Haas)
*/

require_once __DIR__ . '/includes/autoload.php';

//Init the plugin
$plugin = Vendi\Plugin\HealthCheck\Checker::create_with_default_file_logger();
$plugin->register_all_hooks();

