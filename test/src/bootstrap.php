<?php

/**
 * @file
 * Bootstrap file used by phpunit.
 */

$vendor = dirname(dirname(__DIR__)) . '/vendor';

require $vendor . '/autoload.php';

require $vendor . '/phayes/geophp/geoPHP.inc';
