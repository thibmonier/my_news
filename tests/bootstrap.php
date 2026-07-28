<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Charge .env + .env.{APP_ENV} + .env.local + .env.{APP_ENV}.local
// APP_ENV=test est défini dans phpunit.dist.xml
if (file_exists(dirname(__DIR__) . '/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

$_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] = '0';
