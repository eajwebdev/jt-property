<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        if ($database !== ':memory:' && ! preg_match('/_test(?:_\d+)?$/', $database)) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]. Clear cached config and select a test database.");
        }

        return $app;
    }
}
