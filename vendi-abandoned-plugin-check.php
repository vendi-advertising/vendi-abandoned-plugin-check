<?php
/*
Plugin Name: Vendi Abandoned Plugin Check
Description: Provides information about abandoned plugins
Version: 4.0.0
Requires PHP: 7.0
License: GPLv2
Author: Vendi Advertising (Chris Haas)
*/

class Vendi_Plugin_Health_Check
{
    const LOG_LEVEL_NONE = 0;
    const LOG_LEVEL_ERROR = 1;
    const LOG_LEVEL_WARNING = 2;
    const LOG_LEVEL_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;

    /**
     * Master cron that schedules the worker cron
     * @var string
     */
    private $cron_name_watcher = 'vendi_cron_plugin_health_check_watcher';

    /**
     * Cron for the actual worker
     * @var string
     */
    private $cron_name_daily = 'vendi_cron_plugin_health_check_daily';

    /**
     * Cron for batching
     * @var string
     */
    private $cron_name_batching = 'vendi_cron_plugin_health_check_batching';

    private $tran_name_plugin_timestamps = 'vendi_tran_name_plugin_timestamps';

    private $tran_name_plugins_to_batch = 'vendi_tran_name_plugins_to_batch';

    private $option_name_last_daily_run = 'vendi_option_name_last_daily_run';

    private $option_name_version = 'vendi_abandoned_plugin_version';

    //On plugin releases, when this is incremented, our transients and options will be reset
    private $current_db_version = 1;

    private $log_file = null;

    public function __construct()
    {
        register_activation_hook(__FILE__, array($this, 'activation'));

        //Register a handler to actually run the cron if called
        add_action($this->cron_name_batching, array($this, 'run_check'));
        add_action($this->cron_name_daily, array($this, 'run_check'));

        //Register a handler to actually run the cron if called
        add_action($this->cron_name_watcher, array($this, 'perform_watchdog'));

        //Register a handler to display information on old plugins
        //Requires WP 2.8.0
        add_filter('plugin_row_meta', array($this, 'change_plugin_row_meta'), 10, 4);

        //When searching for plugins, also highlight old ones
        //Requires WP 2.7.0
        add_filter('plugin_install_action_links', array($this, 'highlight_old_plugins_on_install'), 10, 2);

        //Before WP 4.0 the last_updated field was not sent
        //Requires WP 2.7.0
        add_filter('plugins_api_args', array($this, 'modify_plugin_api_search_query'), 10, 2);

        //Cleanup when done
        register_deactivation_hook(__FILE__, array($this, 'deactivation'));

        add_action('admin_init', array($this, 'check_for_upgrade'));
    }

    public function check_for_upgrade()
    {
        //Get the currently installed version
        $installed_version = get_option($this->option_name_version);

        //Make sure we have something and that it is digits only
        if (false === $installed_version || false === ctype_digit($installed_version)) {
            //We don't know what version is installed
            $installed_version = 0;
        } else {
            //Cast in to
            $installed_version = (int)$installed_version;
        }

        //Version compare
        if ($installed_version < $this->current_db_version) {

            $this->log_i('Older version of plugin found');

            //Special case each version
            switch ($installed_version) {

                //First edition to introduce versions
                case 0:

                    $this->db_upgrade_0_to_2();

                    //Reset our version number
                    update_option($this->option_name_version, $this->current_db_version);

                    break;
            }
        }
    }

    private function db_upgrade_0_to_2()
    {
        $this->log_i('Upgrading plugin from version 0 to version 2');

        //Fully deactivate the plugin
        $this->cleanup_deactivation();

        //Full reactive the plugin
        $this->schedule_watchdog();
    }

    private function cleanup_basic()
    {
        $this->log_i('Clearing basic schedules and transients');

        //Legacy
        wp_clear_scheduled_hook('vendi_plugin_health_check');
        wp_clear_scheduled_hook('vendi_plugin_health_check_batch');
        delete_transient('vendi_plugin_health_check');

        wp_clear_scheduled_hook($this->cron_name_daily);
        wp_clear_scheduled_hook($this->cron_name_batching);

        delete_transient($this->tran_name_plugins_to_batch);
    }

    public function cleanup_deactivation()
    {
        $this->cleanup_basic();

        $this->log_i('Clearing advanced schedules and transients');

        wp_clear_scheduled_hook('vendi_plugin_health_watcher');
        wp_clear_scheduled_hook($this->cron_name_watcher);

        delete_transient($this->tran_name_plugin_timestamps);

        delete_option($this->option_name_last_daily_run);
    }

    /**
     * Adds the last_updated field to the list of requested fields.
     *
     * This is called by the filter plugins_api_args.
     */
    public function modify_plugin_api_search_query($args, $action)
    {
        //We only want to filter the search API for now
        if (isset($action) && 'query_plugins' === $action) {

            //Just in case we aren't given an object, create one
            if (!is_object($args)) {
                $args = new stdClass();
            }

            //If we don't have a field's property in the search arguments add it
            if (!property_exists($args, 'fields')) {
                $args->fields = array();
            }

            //Merge any existing fields with our newly
            $args->fields = array_merge($args->fields, array('last_updated' => true));
        }

        return $args;
    }

    /**
     * Create a unique random string for targeting with JS
     *
     * From http://stackoverflow.com/a/4356295/231316
     */
    private function generate_random_string($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, strlen($characters) - 1)];
        }

        return $randomString;
    }

    /**
     * @throws Exception
     */
    public function highlight_old_plugins_on_install($action_links, $plugin)
    {
        // print_r( $plugin );
        if (is_array($plugin) && array_key_exists('last_updated', $plugin)) {
            //Get now
            $now = new DateTime();

            //Last updated is stored as timestamp, get a real date
            $plugin_last_updated_date = new DateTime($plugin['last_updated']);

            //Compute days between now and plugin last updated
            $diff_in_days = $now->diff($plugin_last_updated_date)->format('%a');

            //Customizable number of days for tolerance
            $tolerance_in_days = apply_filters('vendi_plugin_health_check_tolerance_in_days', 365);

            //If we're older than allowed
            if ($diff_in_days > $tolerance_in_days) {
                //Generate a random unique ID for this plugin
                $id = 'ab_'.$this->generate_random_string();

                //Output warning text
                $text = sprintf('<strong id="%1$s" style="color: #f00; display: block; background-color: #fff; padding: 3px; border: 1px solid #f00; text-align: left;">This plugin has not been updated by the author in %2$d days!</strong>', $id, $diff_in_days);

                //Also output some JS that hopefully can find the parent "card" and style that as well
                $js = <<<EOT
<script>
    //Grab our random unique ID
    var n = document.getElementById('{$id}');

    //Make sure we have something and loop through each parent
    while( n && n.parentNode )
    {
        //If the parent has the class or tag that we're looking for
        if(
            ( n.parentNode.className && n.parentNode.className.indexOf( 'plugin-card' ) >= 0 )  //WP 4.0 and greater
            ||
            ( n.parentNode.nodeName && n.parentNode.nodeName.toUpperCase() === 'TR' )           //WP 3.9 and less
          )
        {
            //Make it stand out
            n.parentNode.style.backgroundColor = '#f99';
            n.parentNode.style.borderColor = '#f00';

            //We found it, done.
            break;
        }

        //We didn't find anything, walk up one more parent
        n = n.parentNode;
    }
</script>
EOT;
                //Combine the text and JS
                $action_links[] = $text.$js;
            }
        }

        return $action_links;
    }

    /**
     * @throws Exception
     */
    public function perform_watchdog()
    {
        //If neither of our crons are scheduled
        //Requires WP 2.1.0
        if (false === wp_next_scheduled($this->cron_name_daily) && false === wp_next_scheduled($this->cron_name_batching)) {
            $last_run = get_option($this->option_name_last_daily_run);

            if (false === $last_run || !is_int($last_run)) {
                $last_run = false;
            } else {
                $last_run = new DateTime('@'.$last_run);
            }

            //Get now
            $now = new DateTime();

            if (false === $last_run || (int)$now->diff($last_run)->format('%h') >= 24) {
                $this->log_i('Performing watchdog routine');

                //Just in case, cleanup any old data
                $this->cleanup_basic();

                //Wire up our main cron
                //Requires WP 2.1.0
                wp_schedule_event(time(), 'daily', $this->cron_name_daily);

                update_option($this->option_name_last_daily_run, $now->getTimestamp());

                $this->attempt_to_spawn_next_cron();
            }
        }
    }

    public function deactivation()
    {
        $this->log_i('Deactivating plugin');
        $this->cleanup_deactivation();
    }

    public function activation()
    {
        $this->log_i('Activating plugin');
        $this->schedule_watchdog();
    }

    public function schedule_watchdog()
    {
        //Just in case a previous activation didn't deactivate correctly
        $this->cleanup_deactivation();

        //Schedule a global watching cron just in case both other crons get killed
        if (!wp_next_scheduled($this->cron_name_watcher)) {
            //Requires WP 2.1.0
            wp_schedule_event(time(), 'hourly', $this->cron_name_watcher);
        }

        $this->attempt_to_spawn_next_cron();
    }

    /**
     * @throws Exception
     */
    public function change_plugin_row_meta($plugin_meta, $plugin_file, $plugin_data, $status)
    {
        //Grab our previously stored array of known last modified dates
        //Requires WP 2.8.0
        $plugin_info = get_transient($this->tran_name_plugin_timestamps);

        //Sanity check the response
        if (false === $plugin_info || !is_array($plugin_info) || 0 === count($plugin_info)) {
            return $plugin_meta;
        }

        //See if this specific plugin is in the known list
        if (array_key_exists($plugin_file, $plugin_info)) {
            //Get now
            $now = new DateTime();

            //Last updated is stored as timestamp, get a real date
            $plugin_last_updated_date = new DateTime('@'.$plugin_info[$plugin_file]);

            //Compute days between now and plugin last updated
            $diff_in_days = $now->diff($plugin_last_updated_date)->format('%a');

            //Customizable number of days for tolerance
            $tolerance_in_days = apply_filters('vendi_plugin_health_check_tolerance_in_days', 365);

            //If we're outside the window for tolerance show a message
            if ($diff_in_days > $tolerance_in_days) {
                $plugin_meta[] = sprintf('<strong style="color: #f00;">This plugin has not been updated by the author in %1$d days!</strong>', $diff_in_days);
            } else {
                $plugin_meta[] = sprintf('<span style="color: #090;">This plugin was last updated by the author in %1$d days ago.</span>', $diff_in_days);
            }
        }

        return $plugin_meta;
    }

    public function run_check()
    {
        //Older versions of WordPress don't load this function as early so load it if needed
        if (!function_exists('get_plugins')) {
            require_once ABSPATH.'/wp-admin/includes/plugin.php';
        }

        //Get our previous results
        $responses = get_transient($this->tran_name_plugin_timestamps);

        //If there was no previous result then create an empty array
        if (false === $responses || !is_array($responses)) {
            $responses = array();
        }

        //Get our previous cache of plugins for batching
        $all_plugins = get_transient($this->tran_name_plugins_to_batch);

        //If there wasn't a previous cache
        if (false === $all_plugins || !is_array($all_plugins)) {
            //Get all plugins, not just those activated
            //Requires WP 1.5.0
            $all_plugins = array_keys(get_plugins());

            //Erase the result set
            $responses = array();
        }

        // print_r( $all_plugins, true );

        //Grab a small number of plugins to scan
        $plugins_to_scan = array_splice($all_plugins, 0, apply_filters('vendi_plugin_health_check_max_plugins_to_batch', 10));

        //Loop through each known plugin
        foreach ($plugins_to_scan as $v) {
            //Try to get the raw information for this plugin
            $body = $this->try_get_response_body($v);

            //We couldn't get any information, skip this plugin
            if (false === $body) {
                continue;
            }

            //I was having trouble with the JSON call when using the plugin along with file name so
            //I'm just using the object call

            //Deserialize the response
            $obj = unserialize($body);

            //Sanity check that deserialization worked and that our property exists
            if (false !== $obj && is_object($obj) && property_exists($obj, 'last_updated')) {
                //Store the response in our master array
                $responses[$v] = strtotime($obj->last_updated);
            }
        }

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        }

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
        }

        //Store the master response for usage in the plugin table
        set_transient($this->tran_name_plugin_timestamps, $responses, DAY_IN_SECONDS);

        if (0 === count($all_plugins)) {
            delete_transient($this->tran_name_plugins_to_batch);
            wp_schedule_single_event(time() + DAY_IN_SECONDS, $this->cron_name_daily);
        } else {
            set_transient($this->tran_name_plugins_to_batch, $all_plugins, DAY_IN_SECONDS);
            wp_schedule_single_event(time(), $this->cron_name_batching);
            $this->attempt_to_spawn_next_cron();
        }
    }

    /**
     * Attempt to manually launch the next batch
     *
     * From: http://stackoverflow.com/a/2924987/231316
     */
    private function attempt_to_spawn_next_cron()
    {
        $url = home_url('/');

        $parts = parse_url($url);

        //This call explicitly uses error suppression because it will throw an
        //E_WARNING if the hostname is not reachable which could happen in a dev
        //environment.
        //see https://secure.php.net/manual/en/function.fsockopen.php#refsect1-function.fsockopen-errors
        $fp = @fsockopen(
            $parts['host'],
            isset($parts['port']) ? $parts['port'] : 80,
            $errno,
            $errstr,
            30
        );

        if (false === $fp) {
            return;
        }

        $out = "GET ".$parts['path']." HTTP/1.1\r\n";
        $out .= "Host: ".$parts['host']."\r\n";
        $out .= "Connection: Close\r\n\r\n";

        fwrite($fp, $out);
        fclose($fp);
    }

    /**
     * Makes an attempt to get valid information on a specific plugin
     *
     * @param string $plugin The plugin folder to lookup information.
     * @return boolean|string       If successful, returns the response string, otherwise false.
     */
    private function try_get_response_body($plugin)
    {
        //The API considers the "slug" to be the plugin's folder and
        //not what WP internally calls a "slug" which is the folder plus
        //the file that actually boots the plugin.
        if (false !== strpos($plugin, '/')) {
            $plugin = substr($plugin, 0, strpos($plugin, '/'));
        }

        //Some of this code is lifted from class-wp-upgrader

        //Get the WordPress current version to be polite in the API call
        include(ABSPATH.WPINC.'/version.php');

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        }

        //General options to be passed to wp_remote_get
        /** @noinspection PhpUndefinedVariableInspection - This value comes from the include above */
        $options = array(
            'timeout' => HOUR_IN_SECONDS,
            'user-agent' => 'WordPress/'.$wp_version.'; '.get_bloginfo('url'),
        );

        //The URL for the endpoint
        $url = $http_url = 'http://api.wordpress.org/plugins/info/1.0/';

        //If we support SSL
        //Requires WP 3.2.0
        if ($ssl = wp_http_supports(array('ssl'))) {
            //Requires WP 3.4.0
            $url = set_url_scheme($url, 'https');
        }

        $this->log_i('Attempting to access URL: '.$url.$plugin);

        //Try to get the response (usually the SSL version)
        //Requires WP 2.7.0
        $raw_response = wp_remote_get($url.$plugin, $options);

        //If we don't have an error and we received a valid response code
        //Requires WP 2.7.0
        if (!is_wp_error($raw_response) && 200 == wp_remote_retrieve_response_code($raw_response)) {
            //Get the actual body
            //Requires WP 2.7.0
            $body = wp_remote_retrieve_body($raw_response);

            $this->log_d('Remote body:'."\n".$body);

            //Make sure that it isn't empty and also not an empty serialized object
            if ('' !== $body && 'N;' !== $body) {
                //If valid, return that
                return $body;
            }
        }

        //The above valid
        //If we previously tried an SSL version try without SSL
        //Code below same as above block
        if ($ssl) {
            $raw_response = wp_remote_get($http_url.$plugin, $options);
            if (!is_wp_error($raw_response) && 200 === wp_remote_retrieve_response_code($raw_response)) {
                $body = wp_remote_retrieve_body($raw_response);
                if ('' !== $body && 'N;' !== $body) {
                    return $body;
                }
            }
        }

        //Everything above failed, bail
        return false;
    }

    public function log_d($message)
    {
        if (VENDI_APC_LOG_LEVEL >= self::LOG_LEVEL_DEBUG) {
            $this->write_to_log('DEBUG', $message);
        }
    }

    public function log_e($message)
    {
        if (VENDI_APC_LOG_LEVEL >= self::LOG_LEVEL_ERROR) {
            $this->write_to_log('ERROR', $message);
        }
    }

    public function log_w($message)
    {
        if (VENDI_APC_LOG_LEVEL >= self::LOG_LEVEL_WARNING) {
            $this->write_to_log('WARNING', $message);
        }
    }

    public function log_i($message)
    {
        if (VENDI_APC_LOG_LEVEL >= self::LOG_LEVEL_INFO) {
            $this->write_to_log('INFO', $message);
        }
    }

    private function write_to_log($status, $message)
    {
        if (!$this->init_logger()) {
            return;
        }

        $date = date('[Y-m-d H:i:s]');
        $msg = "$date: [$status] - $message".PHP_EOL;
        file_put_contents($this->log_file, $msg, FILE_APPEND);
    }

    private function init_logger()
    {
        //Check if previous attempts to log failed and if so, don't bother again.
        if (false === $this->log_file) {
            return false;
        }

        if (null === $this->log_file) {
            if (!is_dir(VENDI_APC_LOG_PATH)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if (!mkdir($concurrentDirectory = VENDI_APC_LOG_PATH) && !is_dir($concurrentDirectory)) {
                        $this->log_file = false;

                        return false;
                    }
                } else {
                    if (!@mkdir($concurrentDirectory = VENDI_APC_LOG_PATH) && !is_dir($concurrentDirectory)) {
                        $this->log_file = false;

                        return false;
                    }
                }
            }

            $debug_file_name = 'apc-debug.log';

            $this->log_file = trailingslashit(VENDI_APC_LOG_PATH).$debug_file_name;

            if (!file_exists($this->log_file)) {
                touch($this->log_file);
            }

            if (!\wp_is_writable($this->log_file)) {
                //TODO
                return false;
            }
        }

        return true;
    }
}

if (!defined('VENDI_APC_LOG_LEVEL')) {
    define('VENDI_APC_LOG_LEVEL', Vendi_Plugin_Health_Check::LOG_LEVEL_NONE);
}

if (!defined('VENDI_APC_LOG_PATH')) {
    define('VENDI_APC_LOG_PATH', __DIR__.'/__debug/');
}

//Init the above
new Vendi_Plugin_Health_Check();
