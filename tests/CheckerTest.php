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
}
