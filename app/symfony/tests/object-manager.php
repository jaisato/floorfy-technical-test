<?php

declare(strict_types=1);

// Entity manager loader for phpstan-doctrine (see phpstan.dist.neon). Booting the
// test kernel only reads the mapping metadata; no database connection is opened.

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

if (!$entityManager instanceof EntityManagerInterface) {
    throw new LogicException('The default entity manager is missing from the test container.');
}

return $entityManager;
