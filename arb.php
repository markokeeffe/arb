#!/usr/bin/env php
<?php
require_once dirname(__FILE__) . '/vendor/autoload.php';

use Arb\Arb;

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}
set_time_limit(0);
date_default_timezone_set('Australia/Brisbane');

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// Run
$arb = new Arb;

if (isset($argv[1]) && 'test' === $argv[1]) {
    $arb->test();
} else {
    $arb->get();
}
