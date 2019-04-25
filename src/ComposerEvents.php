<?php

namespace Vendi\Plugin\HealthCheck;

use Composer\Script\Event;
use Webmozart\PathUtil\Path;

class ComposerEvents
{
    public static function fix_wordpress_folder_case(Event $event)
    {
        self::_load_autoload($event);
        $vendor_dir = self::_get_vendor_folder($event);

        require_once $vendor_dir . '/autoload.php';

        $bad_dir = Path::join($vendor_dir, 'WordPress');
        $good_dir = Path::join($vendor_dir, 'wordpress');

        //If the folder already exists, assume it is good
        if(\is_dir($good_dir)){
            return;
        }

        if(\is_dir($bad_dir)){
            \rename($bad_dir, $good_dir);
        }
    }

    public static function setup_wordpress_config(Event $event)
    {
        self::_load_autoload($event);
        $vendor_dir = self::_get_vendor_folder($event);

        $base_file = Path::join($vendor_dir, 'wordpress/wordpress-develop/wp-tests-config-sample.php');
        $new_file = Path::join($vendor_dir, '../tests/wp-tests-config.php');

        if(\is_file($new_file)){
            \unlink($new_file);
        }

        $contents = \file_get_contents($base_file);
        $contents = str_replace("'youremptytestdbnamehere'", "getenv('TEST_DB_NAME')", $contents);
        $contents = str_replace("'yourusernamehere'", "getenv('TEST_DB_USER')", $contents);
        $contents = str_replace("'yourpasswordhere'", "getenv('TEST_DB_PASS')", $contents);

        $pattern = preg_quote("define( 'ABSPATH'", "/");
        $pattern = '/^\s+' . $pattern . '.*?$/m';
        $replacement = "\tdefine( 'ABSPATH', getenv('TEST_ABSPATH'));";
        $contents = \preg_replace($pattern, $replacement, $contents);

        \file_put_contents($new_file, $contents);

        //The above is the equivilent of the below
        /*
            "cp vendor/wordpress/wordpress-develop/wp-tests-config-sample.php tests/wp-tests-config.php",
            "sed -i \"s/'youremptytestdbnamehere'/getenv('TEST_DB_NAME')/\" tests/wp-tests-config.php",
            "sed -i \"s/'yourusernamehere'/getenv('TEST_DB_USER')/\" tests/wp-tests-config.php",
            "sed -i \"s/'yourpasswordhere'/getenv('TEST_DB_PASS')/\" tests/wp-tests-config.php",
            "sed -i \"/define( 'ABSPATH'/c\\\tdefine( 'ABSPATH', getenv('TEST_ABSPATH'));\" tests/wp-tests-config.php"
        */
    }

    public static function delTree($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            if(is_dir("$dir/$file")){
                self::delTree("$dir/$file");
            }else{
                unlink("$dir/$file");
            }
        }
        return rmdir($dir);
    }

    public static function _load_autoload(Event $event)
    {
        require_once self::_get_vendor_folder($event) . '/autoload.php';
    }

    public static function _get_vendor_folder(Event $event)
    {
        return $event->getComposer()->getConfig()->get('vendor-dir');
    }
}
