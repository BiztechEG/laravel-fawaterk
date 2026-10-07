<?php

/*
 * One worker process of the MySQL lock tests: php worker.php <scenario> <json args>
 *
 * It boots the package in a fresh app on the same database as the test,
 * runs the scenario and prints the result as JSON on its last line.
 */

use BiztechEG\Fawaterk\FawaterkServiceProvider;
use BiztechEG\Fawaterk\Tests\MySql\MySqlEnvironment;
use BiztechEG\Fawaterk\Tests\MySql\Scenarios;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__.'/../../vendor/autoload.php';

[, $scenario, $json] = $argv + [null, null, '{}'];

$app = Testbench::create(null, null, ['extra' => ['providers' => [FawaterkServiceProvider::class], 'dont-discover' => ['*']]]);

// Nothing has used the database, the cache or the package yet.
MySqlEnvironment::apply($app);

$result = Scenarios::run((string) $scenario, (array) json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR));

echo PHP_EOL.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
