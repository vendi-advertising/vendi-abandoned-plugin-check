<?php /** @noinspection PhpUnused */

namespace Vendi\Plugin\HealthCheck;

use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

class Checker
{
    const LOG_LEVEL_NONE = 0;
    const LOG_LEVEL_ERROR = 1;
    const LOG_LEVEL_WARNING = 2;
    const LOG_LEVEL_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;

    /**
     * Master cron that schedules the worker cron
     *
     * @var string
     */
    private $cron_name_watcher = 'vendi_cron_plugin_health_check_watcher';

    /**
     * Cron for the actual worker
     *
     * @var string
     */
    private $cron_name_daily = 'vendi_cron_plugin_health_check_daily';

    /**
     * Cron for batching
     *
     * @var string
     */
    private $cron_name_batching = 'vendi_cron_plugin_health_check_batching';

    private $tran_name_plugin_timestamps = 'vendi_tran_name_plugin_timestamps';

    private $tran_name_plugins_to_batch = 'vendi_tran_name_plugins_to_batch';

    private $option_name_last_daily_run = 'vendi_option_name_last_daily_run';

    private $option_name_version = 'vendi_abandoned_plugin_version';

    //On plugin releases, when this is incremented our transients and options will be reset
    private $current_db_version = 1;

    private $logger;

    /** @noinspection PhpUndefinedClassInspection */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function get_logger()
    {
        return $this->logger;
    }

    /** @noinspection PhpUndefinedClassInspection */
    public static function create_with_null_logger()
    {
        return new self(new NullLogger());
    }

    public static function create_with_default_file_logger()
    {
        return new self(Logger::create_with_plugin_relative_file_path('__debug/apc-debug.log'));
    }

    public function register_all_hooks()
    {
        register_activation_hook(VENDI_APC_FILE, [$this, 'activation']);

        //Register a handler to actually run the cron if called
        add_action($this->cron_name_batching, [$this, 'run_check']);
        add_action($this->cron_name_daily, [$this, 'run_check']);

        //Register a handler to actually run the cron if called
        add_action($this->cron_name_watcher, [$this, 'perform_watchdog']);

        //Register a handler to display information on old plugins
        //Requires WP 2.8.0
        add_filter('plugin_row_meta', [$this, 'change_plugin_row_meta'], 10, 4);

        //When searching for plugins, also highlight old ones
        //Requires WP 2.7.0
        add_filter('plugin_install_action_links', [$this, 'highlight_old_plugins_on_install'], 10, 2);

        //Before WP 4.0 the last_updated field was not sent
        //Requires WP 2.7.0
        add_filter('plugins_api_args', [$this, 'modify_plugin_api_search_query'], 10, 2);

        //Cleanup when done
        register_deactivation_hook(VENDI_APC_FILE, [$this, 'deactivation']);

        add_action('admin_init', [$this, 'check_for_upgrade']);
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

            $this->get_logger()->info('Older version of plugin found');

            //Special case each version
            /** @noinspection DegradedSwitchInspection */
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

    public function db_upgrade_0_to_2()
    {
        $this->get_logger()->info('Upgrading plugin from version 0 to version 2');

        //Fully deactivate the plugin
        $this->cleanup_deactivation();

        //Full reactive the plugin
        $this->schedule_watchdog();
    }

    public function cleanup_basic()
    {
        $this->get_logger()->info('Clearing basic schedules and transients');

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

        $this->get_logger()->info('Clearing advanced schedules and transients');

        wp_clear_scheduled_hook('vendi_plugin_health_watcher');
        wp_clear_scheduled_hook($this->cron_name_watcher);

        delete_transient($this->tran_name_plugin_timestamps);

        delete_option($this->option_name_last_daily_run);
    }

    /**
     * Adds the last_updated field to the list of requested fields.
     *
     * This is called by the filter plugins_api_args.
     *
     * @param $args
     * @param $action
     *
     * @return stdClass
     */
    public function modify_plugin_api_search_query($args, $action)
    {
        //We only want to filter the search API for now
        if (isset($action) && 'query_plugins' === $action) {

            //Just in case we aren't given an object, create one
            if (!is_object($args)) {
                $args = new stdClass();
            }

            //If we don't have a fields property in the search arguments add it
            if (!property_exists($args, 'fields')) {
                $args->fields = [];
            }

            //Merge any existing fields with our newly
            $args->fields = array_merge($args->fields, ['last_updated' => true]);
        }

        return $args;
    }

    /**
     * Create a unique random string for targeting with JS
     *
     * From http://stackoverflow.com/a/4356295/231316
     *
     * @param $length
     *
     * @return string
     */
    public function generate_random_string($length)
    {
        /** @noinspection SpellCheckingInspection */
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    public function get_number_of_days_between_two_dates(DateTime $a, DateTime $b)
    {
        return abs((int)$a->diff($b)->format('%a'));
    }

    /**
     * @param $action_links
     * @param $plugin
     *
     * @return mixed
     * @throws Exception
     */
    public function highlight_old_plugins_on_install($action_links, $plugin)
    {
        // print_r( $plugin );
        if (is_array($plugin) && array_key_exists('last_updated', $plugin)) {

            //Compute days between now and plugin last updated
            $diff_in_days = $this->get_number_of_days_between_two_dates(new DateTime(), new DateTime($plugin['last_updated']));

            //Customizable number of days for tolerance
            $tolerance_in_days = apply_filters('vendi_plugin_health_check_tolerance_in_days', 365);

            //If we're older than allowed
            if ($diff_in_days > $tolerance_in_days) {
                //Generate a random unique ID for this plugin
                $id = 'ab_' . $this->generate_random_string(10);

                //Output warning text
                $text = sprintf('<strong id="%1$s" style="color: #f00; display: block; background-color: #fff; padding: 3px; border: 1px solid #f00; text-align: left;">This plugin has not been updated by the author in %2$d days!</strong>', $id, $diff_in_days);

                //Also output some JS that hopefully can find the parent "card" and style that as well
                $js = utils::get_js_for_install_card_by_id($id);

                //Combine the text and JS
                $action_links[] = $text . $js;
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
                $last_run = new DateTime('@' . $last_run);
            }

            //Get now
            $now = new DateTime();

            if (false === $last_run || (int)$now->diff($last_run)->format('%h') >= 24) {
                $this->get_logger()->info('Performing watchdog routine');

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
        $this->get_logger()->info('Deactivating plugin');
        $this->cleanup_deactivation();
    }

    public function activation()
    {
        $this->get_logger()->info('Activating plugin');
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
     * @param $plugin_meta
     * @param $plugin_file
     * @param $plugin_data
     * @param $status
     *
     * @return mixed
     * @throws Exception
     * @noinspection PhpUnusedParameterInspection
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

            //Last updated is stored as timestamp, get a real date
            $plugin_last_updated_date = new DateTime('@' . $plugin_info[$plugin_file]);

            //Compute days between now and plugin last updated
            $diff_in_days = $this->get_number_of_days_between_two_dates(new DateTime(), $plugin_last_updated_date);

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
            /** @noinspection PhpIncludeInspection */
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        //Get our previous results
        $responses = get_transient($this->tran_name_plugin_timestamps);

        //If there was no previous result then create an empty array
        if (false === $responses || !is_array($responses)) {
            $responses = [];
        }

        //Get our previous cache of plugins for batching
        $all_plugins = get_transient($this->tran_name_plugins_to_batch);

        //If there wasn't a previous cache
        if (false === $all_plugins || !is_array($all_plugins)) {
            //Get all plugins, not just those activated
            //Requires WP 1.5.0
            $all_plugins = array_keys(get_plugins());

            //Erase the result set
            $responses = [];
        }

        //Grab a small number of plugins to scan
        $plugins_to_scan = array_splice($all_plugins, 0, apply_filters('vendi_plugin_health_check_max_plugins_to_batch', 10));

        if (is_iterable($plugins_to_scan)) {
            //Loop through each known plugin
            foreach ($plugins_to_scan as $k => $v) {
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

        $fp = fsockopen(
            $parts['host'],
            isset($parts['port']) ? $parts['port'] : 80,
            $errno,
            $errstr,
            30
        );

        if (false === $fp) {
            return;
        }

        $out = "GET " . $parts['path'] . " HTTP/1.1\r\n";
        $out .= "Host: " . $parts['host'] . "\r\n";
        $out .= "Connection: Close\r\n\r\n";

        fwrite($fp, $out);
        fclose($fp);
    }

    public function get_urls_for_plugin_as_tuple($plugin)
    {
        $base = 'http://api.wordpress.org/plugins/info/1.0/';

        $fallback_url = $base . $plugin;
        $primary_url = $base . $plugin;

        //If we support SSL
        //Requires WP 3.2.0
        if ($ssl = wp_http_supports(['ssl'])) {
            //Requires WP 3.4.0
            $primary_url = set_url_scheme($primary_url, 'https');
        }

        return [$primary_url, $fallback_url];
    }

    public function try_get_response_body_imp($url)
    {
        //Get the WordPress current version to be polite in the API call
        /** @noinspection PhpIncludeInspection */
        include(ABSPATH . WPINC . '/version.php');

        // This function populates version variables.
        /** @var string $wp_version */

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        }

        //General options to be passed to wp_remote_get
        $options = [
            'timeout' => HOUR_IN_SECONDS,
            'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo('url'),
        ];

        $raw_response = wp_remote_get($url, $options);

        //If we don't have an error and we received a valid response code
        //Requires WP 2.7.0
        if (!is_wp_error($raw_response) && 200 === wp_remote_retrieve_response_code($raw_response)) {
            //Get the actual body
            //Requires WP 2.7.0
            $body = wp_remote_retrieve_body($raw_response);

            $this->get_logger()->debug('Remote body:' . "\n" . $body);

            //Make sure that it isn't empty and also not an empty serialized object
            if ('' !== $body && 'N;' !== $body) {
                //If valid, return that
                return $body;
            }
        }

        return false;
    }

    public function get_slug_from_plugin($plugin)
    {
        //The API considers the "slug" to be the plugin's folder and
        //not what WP internally calls a "slug" which is the folder plus
        //the file that actually boots the plugin.
        if (false !== strpos($plugin, '/')) {
            $plugin = substr($plugin, 0, strpos($plugin, '/'));
        }

        return $plugin;
    }

    /**
     * Makes an attempt to get valid information on a specific plugin
     *
     * @param string $plugin The plugin folder to lookup information.
     *
     * @return boolean|string       If successful, returns the response string, otherwise false.
     */
    private function try_get_response_body($plugin)
    {
        $plugin_result = $this->get_slug_from_plugin($plugin);

        if (false === $plugin_result) {
            return false;
        }

        //Some of this code is lifted from class-wp-upgrader

        //Create two URLs. The primary URL will attempt to be made a secure one
        //which should work in almost every case. The fallback URL will not be secure.
        list($primary_url, $fallback_url) = $this->get_urls_for_plugin_as_tuple($plugin_result);

        $this->get_logger()->info('Attempting to access primary URL: ' . $primary_url);

        //Try to get the response (usually the SSL version)
        $body = $this->try_get_response_body_imp($primary_url);
        if ($body) {
            return $body;
        }

        //If we tried accessing the secure URL and it failed, try accessing
        //the non-secure one.
        if ($fallback_url !== $primary_url) {

            $body = $this->try_get_response_body_imp($fallback_url);
            if ($body) {
                return $body;
            }
        }

        return false;
    }
}
