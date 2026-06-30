<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\DemoSeed;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\Seed;
use haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel;
use Symfony\Component\HttpFoundation\Request;

// The example runs on the bundle's own Composer install (its autoload-dev maps this
// app), so the front controller boots from the repository-root vendor.
require \dirname(__DIR__, 3) . '/vendor/autoload.php';

// The example's SQLite database (DATABASE_URL, default in-memory) is created and
// seeded ON FIRST USE: when the schema is absent the front controller builds it and
// loads the deterministic seed — the same setup the test suite performs. With the
// default in-memory DB each request starts fresh, so this runs every request (writes
// don't survive). With the Docker image's file-backed DATABASE_URL the schema is
// present after the first request, so the seed runs once and writes PERSIST across
// requests (until the container is recreated).
$kernel = new MusicCatalogKernel('test', true);
$kernel->boot();

$container = $kernel->getContainer();
$services = $container->has('test.service_container')
    ? $container->get('test.service_container')
    : $container;

$entityManager = $services->get('doctrine.orm.entity_manager');
\assert($entityManager instanceof EntityManagerInterface);

// Probe for the schema (version-agnostic across DBAL): a failing query means the
// tables don't exist yet, so build + seed. A persistent (file) DB skips this after
// the first request, which is what lets writes persist.
$needsSetup = true;
try {
    $entityManager->getConnection()->executeQuery('SELECT 1 FROM playlist LIMIT 1');
    $needsSetup = false;
} catch (\Throwable) {
    // Schema absent — fall through to create + seed.
}
if ($needsSetup) {
    (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    Seed::into($entityManager);
    // Layer the richer DEMO-ONLY catalogue (more artists/albums/playlists) on top of
    // the minimal test seed — served only, never seen by the test suite.
    DemoSeed::into($entityManager);
}

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
