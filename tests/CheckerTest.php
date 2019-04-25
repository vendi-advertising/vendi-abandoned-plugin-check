<?php

namespace Vendi\Plugin\Tests\HealthCheck;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Vendi\Plugin\HealthCheck\Checker;


class CheckerTest extends \WP_UnitTestCase
{
    private function _get_default_object()
    {
        return Checker::create_with_null_logger();
    }

    /**
     * @covers Vendi\Plugin\HealthCheck\Checker::__construct
     * @covers Vendi\Plugin\HealthCheck\Checker::get_logger
     * @covers Vendi\Plugin\HealthCheck\Checker::create_with_null_logger
     * @covers Vendi\Plugin\HealthCheck\Checker::create_with_default_file_logger
     */
    public function test__static_constructors()
    {
        $this->assertInstanceOf(NullLogger::class, Checker::create_with_null_logger()->get_logger());
        $this->assertInstanceOf(AbstractLogger::class, Checker::create_with_default_file_logger()->get_logger());
    }

    /**
     * @dataProvider provider_for__check_for_upgrade
     * @covers Vendi\Plugin\HealthCheck\Checker::cleanup_basic
     */
    public function test__check_for_upgrade($value, $expected)
    {
        \update_option('vendi_abandoned_plugin_version', $value);
        $obj = $this->_get_default_object();
        $obj->check_for_upgrade();
        $this->assertSame(\get_option('vendi_abandoned_plugin_version'), $expected);
    }

    public function assertActionDoesNotExist($tag, $function_to_check = false)
    {
        $this->assertFalse(\has_filter($tag, $function_to_check));
    }

    public function assertActionDoesExists($tag, $function_to_check = false)
    {
        $this->assertTrue(\has_filter($tag, $function_to_check));
    }

    /**
     * @covers Vendi\Plugin\HealthCheck\Checker::register_all_hooks
     */
    public function test__register_all_hooks()
    {
        $base_name = \plugin_basename(VENDI_APC_FILE);
        $this->assertActionDoesNotExist('activate_' . $base_name);
        $this->assertActionDoesNotExist('vendi_cron_plugin_health_check_batching');
        $this->assertActionDoesNotExist('vendi_cron_plugin_health_check_daily');
        $this->assertActionDoesNotExist('vendi_cron_plugin_health_check_watcher');
        $this->assertActionDoesNotExist('plugin_row_meta');
        $this->assertActionDoesNotExist('plugin_install_action_links');
        $this->assertActionDoesNotExist('plugins_api_args');
        $this->assertActionDoesNotExist('deactivate_' . $base_name);

        $obj = $this->_get_default_object();
        $obj->register_all_hooks();

        $this->assertActionDoesExists('activate_' . $base_name);
        $this->assertActionDoesExists('vendi_cron_plugin_health_check_batching');
        $this->assertActionDoesExists('vendi_cron_plugin_health_check_daily');
        $this->assertActionDoesExists('vendi_cron_plugin_health_check_watcher');
        $this->assertActionDoesExists('plugin_row_meta');
        $this->assertActionDoesExists('plugin_install_action_links');
        $this->assertActionDoesExists('plugins_api_args');
        $this->assertActionDoesExists('deactivate_' . $base_name);
    }

    /**
     * @covers Vendi\Plugin\HealthCheck\Checker::modify_plugin_api_search_query
     */
    public function test__modify_plugin_api_search_query()
    {
        $obj = $this->_get_default_object();

        //This version will pass through untouched
        $args = ['cheese' => 'yes'];
        $this->assertSame($args, $obj->modify_plugin_api_search_query($args, ''));

        //$args is expected to be an object. If the code detects that it isn't
        //an empty object should be created with these properties.
        $expected = new \stdClass();
        $expected->fields = ['last_updated' => true];
        $this->assertEquals($expected, $obj->modify_plugin_api_search_query($args, 'query_plugins'));

        //If given an object, it will still add the correct fields
        $args = new \stdClass();
        $args->fields = [];
        $this->assertEquals($expected, $obj->modify_plugin_api_search_query($args, 'query_plugins'));

        //Extra properties should not be affected
        $args = new \stdClass();
        $args->fields = ['last_updated' => true];
        $args->cheese = true;
        $expected->cheese = true;
        $this->assertEquals($expected, $obj->modify_plugin_api_search_query($args, 'query_plugins'));
    }

    /**
     * @covers Vendi\Plugin\HealthCheck\Checker::generate_random_string
     */
    public function test__generate_random_string()
    {
        $obj = $this->_get_default_object();
        $this->assertSame(10, mb_strlen($obj->generate_random_string(10)));
        $this->assertSame(99, mb_strlen($obj->generate_random_string(99)));
        $this->assertRegExp('/[a-zA-Z0-9]{99}/', $obj->generate_random_string(99));
    }

    /**
     * @dataProvider provider_for__get_number_of_days_between_two_dates
     * @covers Vendi\Plugin\HealthCheck\Checker::get_number_of_days_between_two_dates
     */
    public function test__get_number_of_days_between_two_dates($expected, $a, $b)
    {
        //Run in both directions because the order shouldn't matter
        $this->assertSame($expected, $this->_get_default_object()->get_number_of_days_between_two_dates(new \DateTime($a), new \DateTime($b)));
        $this->assertSame($expected, $this->_get_default_object()->get_number_of_days_between_two_dates(new \DateTime($b), new \DateTime($a)));
    }

    /**
     * @covers Vendi\Plugin\HealthCheck\Checker::highlight_old_plugins_on_install
     */
    public function test__highlight_old_plugins_on_install()
    {
        $obj = $this->_get_default_object();

        //Pass invalid arg directly through
        $this->assertSame('cheese', $obj->highlight_old_plugins_on_install('cheese', ''));

        $result = $obj->highlight_old_plugins_on_install([], ['last_updated' => '2015-05-21 7:21pm GMT']);
        $this->assertTrue(is_array($result));
        $found = false;
        foreach($result as $item){
            if(is_string($item) && 0 === mb_strpos($item, '<strong id="ab_') ){
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Could not find string marking plugin as old');
    }

    /**
     * @dataProvider provider_for__test__cleanup_basic__stuff
     * @covers Vendi\Plugin\HealthCheck\Checker::cleanup_basic
     * @covers Vendi\Plugin\HealthCheck\Checker::cleanup_deactivation
     */
    public function test__cleanup_deactivation($stuff)
    {
        foreach($stuff as $key => $values){
            if(0 === count($values)){
                continue;
            }

            $func = null;
            switch($key){
                case 'events':
                    $func = 'perform_single_event_test';
                    break;
                case 'transients':
                    $func = 'perform_single_transient_test';
                    break;
                case 'options':
                    $func = 'perform_single_option_test';
                    break;
                default:
                    throw new \Exception('Unknown cleanup test key: ' . $key);
            }

            if($func){
                foreach($values as $value){
                    $this->$func($value);
                }
            }
        }
    }

    public function perform_single_transient_test($transient_name)
    {
        //Shouldn't exist by default
        $this->assertFalse(get_transient($transient_name));

        //Schedule the event
        set_transient($transient_name, 'cheese');

        //Should exist now
        $this->assertSame('cheese', get_transient($transient_name));

        //Create our object and call for cleanup
        $obj = $this->_get_default_object();
        $obj->cleanup_deactivation();

        //Shouldn't exist now
        $this->assertFalse(wp_get_schedule($transient_name));
    }

    public function perform_single_event_test($event_name)
    {
        //Shouldn't exist by default
        $this->assertFalse(wp_get_schedule($event_name));

        //Schedule the event
        wp_schedule_event(time(), 'hourly', $event_name);

        //Should exist now
        $this->assertSame('hourly', wp_get_schedule($event_name));

        //Create our object and call for cleanup
        $obj = $this->_get_default_object();
        $obj->cleanup_deactivation();

        //Shouldn't exist now
        $this->assertFalse(wp_get_schedule($event_name));
    }

    public function perform_single_option_test($name)
    {
        //Shouldn't exist by default
        $this->assertFalse(get_option($name));

        //Schedule the event
        update_option($name, 'cheese');

        //Should exist now
        $this->assertSame('cheese', get_option($name));

        //Create our object and call for cleanup
        $obj = $this->_get_default_object();
        $obj->cleanup_deactivation();

        //Shouldn't exist now
        $this->assertFalse(get_option($name));
    }

    public function provider_for__test__cleanup_basic__stuff()
    {
        return array(
                    array(
                        array(
                            'events' => array('vendi_plugin_health_check', 'vendi_plugin_health_check_batch', 'vendi_cron_plugin_health_check_daily', 'vendi_cron_plugin_health_check_batching'),
                            'transients' => array('vendi_plugin_health_check', 'vendi_tran_name_plugins_to_batch'),
                            'options' => array(),
                        )
                    ),
                    array(
                        array(
                            'events' => array('vendi_plugin_health_watcher', 'vendi_cron_plugin_health_check_watcher'),
                            'transients' => array('vendi_tran_name_plugin_timestamps'),
                            'options' => array('vendi_option_name_last_daily_run'),
                        )
                    ),
        );
    }

    public function provider_for__check_for_upgrade()
    {
        return array(
            array(0, 1),
            array('0', 1),
            array(2, 1),
        );
    }

    public function provider_for__get_number_of_days_between_two_dates()
    {
        return [
            [1, '2018-01-01', '2018-01-02'],
            [5, '2018-01-01', '2018-01-06'],
            [34, '2018-03-01', '2018-04-04'],
        ];
    }
}
