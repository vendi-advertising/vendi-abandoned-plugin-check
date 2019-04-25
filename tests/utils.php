<?php

use Symfony\Component\Dotenv\Dotenv;

/**
 * Code from Symfony, back-ported to older versions of PHP
 * @see https://github.com/symfony/dotenv/blob/fad49a7b00d72bbd120255048b488da9dd963d70/Dotenv.php#L82
 */
function vendi__apc__dotenv__loadEnv(Dotenv $dotenv, $path, $varName = 'APP_ENV', $defaultEnv = 'dev', array $testEnvs = ['test'])
{
    if (file_exists($path) || !file_exists($p = "$path.dist")) {
        $dotenv->load($path);
    } else {
        $dotenv->load($p);
    }

    $env = null;
    if($_SERVER[$varName]){
        $env = $_SERVER[$varName];
    }elseif($_ENV[$varName]){
        $env = $_ENV[$varName];
    }

    if (null === $env) {
        $dotenv->populate([$varName => $env = $defaultEnv]);
    }

    if (!\in_array($env, $testEnvs, true) && file_exists($p = "$path.local")) {
        $dotenv->load($p);
        if($_SERVER[$varName]){
            $env = $_SERVER[$varName];
        }elseif($_ENV[$varName]){
            $env = $_ENV[$varName];
        }
    }

    if ('local' === $env) {
        return;
    }

    if (file_exists($p = "$path.$env")) {
        $dotenv->load($p);
    }

    if (file_exists($p = "$path.$env.local")) {
        $dotenv->load($p);
    }
}
