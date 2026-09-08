<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

// On SQLite the schema is built from the mapping: the migrations are MySQL DDL
// and the suite is meant to run on a clean clone with no services at all.
//
// Anywhere else the migrations own the schema and this must keep its hands off
// it. CI points DATABASE_URL at MySQL and applies them before calling PHPUnit,
// so building the schema here as well met tables that already existed - "Table
// 'video_tasks' already exists", and not one test ran. It is also the point of
// running against MySQL at all: proving what the migrations produce, not what
// the mapping would have produced.
$kernel = new Kernel('test', true);
$kernel->boot();

$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

if (!$entityManager instanceof EntityManagerInterface) {
    throw new LogicException('The default entity manager is missing from the test container.');
}

// getParams() reads the configured driver without opening a connection.
$driver = $entityManager->getConnection()->getParams()['driver'] ?? '';

if (is_string($driver) && str_contains($driver, 'sqlite')) {
    $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
    $schemaTool = new SchemaTool($entityManager);
    $schemaTool->dropSchema($metadata);
    $schemaTool->createSchema($metadata);
}

$kernel->shutdown();
