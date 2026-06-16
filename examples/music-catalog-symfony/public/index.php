<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\Seed;
use haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel;
use Symfony\Component\HttpFoundation\Request;

// The example runs on the bundle's own Composer install (its autoload-dev maps this
// app), so the front controller boots from the repository-root vendor.
require \dirname(__DIR__, 3) . '/vendor/autoload.php';

// The example uses an in-memory SQLite database that lives and dies with the kernel,
// so each request creates the schema and loads the deterministic seed before
// handling — the same setup the test suite performs. Writes therefore do not
// persist across requests: this is a live demo of the bundle, not a database app.
$kernel = new MusicCatalogKernel('test', true);
$kernel->boot();

$container = $kernel->getContainer();
$services = $container->has('test.service_container')
    ? $container->get('test.service_container')
    : $container;

$entityManager = $services->get('doctrine.orm.entity_manager');
\assert($entityManager instanceof EntityManagerInterface);

(new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
Seed::into($entityManager);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
