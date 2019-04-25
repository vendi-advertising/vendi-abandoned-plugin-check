<?php

namespace Vendi\Plugin\HealthCheck;

use Psr\Log\AbstractLogger;
use Webmozart\PathUtil\Path;

/*
Parts of this code are from the Monologo library
*/
class Logger extends AbstractLogger
{
    private $log_file;

    public function __construct($log_file)
    {
        $this->log_file = $log_file;
    }

    public static function create_with_plugin_relative_file_path($file_path)
    {
        $abs_path = Path::join(VENDI_APC_DIR, $file_path);
        return new self($abs_path);
    }

    public function log($level, $message, array $context = array())
    {
        if( ! $this->try_init_logger() ) {
            return;
        }

        $date_as_string = date('Y-m-d\TH:i:s.uP');
        $context_as_string = $this->stringify($context);
        $data = "${date_as_string}: [${level}] - ${message} ${context_as_string}" . PHP_EOL;

        try{
            file_put_contents( $this->log_file, $data, FILE_APPEND );
        }catch(\Exception $ex){
            return;
        }
    }

    public function try_make_log_dir()
    {
        $log_dir = $this->get_log_folder();
        if(is_dir($log_dir)){
            return true;
        }

        try{
            mkdir($log_dir, 0777, true);
        }catch(\Exception $ex){
            return false;
        }

        return is_dir($log_dir);
    }

    public function try_make_log_file()
    {
        //Try making the file
        if( ! file_exists( $this->log_file ) ){
            try{
                touch( $this->log_file );
            }catch(\Exception $e){

                //If we can't fail, we don't want to throw an exception,
                //just fail silently
                return false;
            }
        }

        //Can we write to it?
        if(is_writable( $this->log_file )){
            return true;
        }

        //Special case version for Windows
        if($this->win_is_writable( $this->log_file )){
            return true;
        }

        //The above didn't work
        return false;
    }

    public function get_log_folder()
    {
        return Path::getDirectory($this->log_file);;
    }

    public function is_wordpress_debug_mode()
    {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }

    private function try_init_logger()
    {
        //Check if previous attempts to log failed and if so, don't bother again.
        if( false === $this->log_file ) {
            return false;
        }

        if( null === $this->log_file ) {

            if(!$this->try_make_log_dir()){
                return false;
            }

            if(!$this->try_make_log_file()){
                return false;
            }
        }

        return true;
    }

    /**
     * [win_is_writable description]
     *
     * @see  http://core.trac.wordpress.org/browser/tags/3.3/wp-admin/includes/misc.php#L537
     * @param  [type] $path [description]
     * @return [type]       [description]
     */
    private function win_is_writable( $path )
    {
        /* will work in despite of Windows ACLs bug
         * NOTE: use a trailing slash for folders!!!
         * see http://bugs.php.net/bug.php?id=27609
         * see http://bugs.php.net/bug.php?id=30931
         */

         // recursively return a temporary file path
        if ( $path[strlen( $path ) - 1] == '/' ){
            return $this->win_is_writable( $path . uniqid( mt_rand() ) . '.tmp');
        }

        if ( is_dir( $path ) ){
            return $this->win_is_writable( $path . '/' . uniqid( mt_rand() ) . '.tmp' );
        }

        // check tmp file for read/write capabilities
        $should_delete_tmp_file = !file_exists( $path );
        $f = @fopen( $path, 'a' );
        if ( $f === false ){
            return false;
        }

        fclose( $f );
        if ( $should_delete_tmp_file ){
            unlink( $path );
        }

        return true;
    }

    //https://github.com/Seldaek/monolog/blob/ebb804e432e8fe0fe96828f30d89c45581d36d07/src/Monolog/Formatter/NormalizerFormatter.php#L264
    private function jsonEncode($data)
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    //https://github.com/Seldaek/monolog/blob/ebb804e432e8fe0fe96828f30d89c45581d36d07/src/Monolog/Formatter/NormalizerFormatter.php#L244
    protected function toJson($data, $ignoreErrors = false)
    {
        // suppress json_encode errors since it's twitchy with some inputs
        return @$this->jsonEncode($data);
    }

    //https://github.com/Seldaek/monolog/blob/ebb804e432e8fe0fe96828f30d89c45581d36d07/src/Monolog/Formatter/LineFormatter.php#L124
    public function stringify($value)
    {
        return $this->replaceNewlines($this->convertToString($value));
    }

    //https://github.com/Seldaek/monolog/blob/ebb804e432e8fe0fe96828f30d89c45581d36d07/src/Monolog/Formatter/LineFormatter.php#L145
    protected function convertToString($data)
    {
        if (null === $data || is_bool($data)) {
            return var_export($data, true);
        }
        if (is_scalar($data)) {
            return (string) $data;
        }
        return (string) $this->toJson($data, true);
    }

    //https://github.com/Seldaek/monolog/blob/ebb804e432e8fe0fe96828f30d89c45581d36d07/src/Monolog/Formatter/LineFormatter.php#L158
    protected function replaceNewlines($str)
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $str);
    }
}
