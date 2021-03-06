<?php

use Webmozart\PathUtil\Path;

//Define this constant to point to our root folder of our plugin
if(!defined('VENDI_APC_DIR')){
    define('VENDI_APC_DIR', dirname(dirname(__FILE__)));
}

if(!defined('VENDI_APC_FILE')){
    define('VENDI_APC_FILE', dirname(dirname(__FILE__)) . '/vendi-abandoned-plugin-check.php');
}

//Load auto-loaders
require_once VENDI_APC_DIR . '/includes/autoload.php';
require_once VENDI_APC_DIR . '/tests/utils.php';

//Load config-specific environment variables
$dotenv = new Symfony\Component\Dotenv\Dotenv();
vendi__apc__dotenv__loadEnv($dotenv, VENDI_APC_DIR . '/.env');

//We're using an ENV here because constants weren't always working, not sure why.
//This value will be used as WordPress's ABSPATH constant. Make sure that it
//ends with a slash!
putenv('TEST_ABSPATH=' . Path::join(VENDI_APC_DIR, '/vendor/wordpress/wordpress-develop/src') . '/');

//This is the absolute path to the config file. Most sources say it should live
//relative to the test folder however this constant can be used to put it anywhere.
define('WP_TESTS_CONFIG_FILE_PATH', Path::join(VENDI_APC_DIR, '/tests/wp-tests-config.php'));

if(!is_readable(WP_TESTS_CONFIG_FILE_PATH)){
	die('Run composer setup-dev to setup the config environment!');
}

//This is the location of WordPress unit tests
$tests_dir = Path::join(VENDI_APC_DIR, '/vendor/wordpress/wordpress-develop/tests/phpunit/');

// Give access to tests_add_filter() function.
// require_once Path::join($tests_dir, '/includes/functions.php');

// Start up the WP testing environment.
require_once Path::join($tests_dir, '/includes/bootstrap.php');
