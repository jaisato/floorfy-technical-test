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

// The schema is built from the mapping rather than by running the migrations:
// those are MySQL DDL, and the suite is meant to run on SQLite on a clean clone
// with no services at all. CI applies the migrations against MySQL separately,
// which is where they get proven.
$kernel = new Kernel('test', true);
$kernel->boot();

$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

if (!$entityManager instanceof EntityManagerInterface) {
    throw new LogicException('The default entity manager is missing from the test container.');
}

$metadata = $entityManager->getMetadataFactory()->getAllMetadata();
$schemaTool = new SchemaTool($entityManager);
$schemaTool->dropSchema($metadata);
$schemaTool->createSchema($metadata);

$kernel->shutdown();
