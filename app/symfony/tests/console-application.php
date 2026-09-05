<?php

declare(strict_types=1);

// Console application loader for phpstan-symfony (see phpstan.dist.neon).

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return new Application($kernel);
