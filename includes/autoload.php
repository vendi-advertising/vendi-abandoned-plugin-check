<?php

if(!defined('VENDI_APC_DIR')){
    define('VENDI_APC_DIR', dirname(__FILE__));
}

if(is_readable(VENDI_APC_DIR . '/vendor/autoload.php')){
    require_once VENDI_APC_DIR . '/vendor/autoload.php';
}

class_alias('Vendi_Plugin_Health_Check', 'Vendi\Plugin\HealthCheck');
