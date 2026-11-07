<?php

declare(strict_types=1);

/*
 * Writes the music-catalog example's documents into a directory, by default build/specs:
 * the OpenAPI document and the standalone JSON-Schema document of each of its two servers,
 * plus versions.json naming the haddowg/json-api and haddowg/json-api-symfony versions that
 * projected them. CI exports them to diff a pull request's output against its base, and to
 * publish `@haddowg/json-api-symfony-specs`.
 *
 * Each document is what the bundle's own console commands write for the example kernel:
 * `json-api:openapi:export` and `json-api:json-schema:export` (every type, as one object
 * keyed by type).
 *
 * Usage:  php bin/export-specs.php [<directory>]
 */

use Composer\InstalledVersions;
use haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

require \dirname(__DIR__) . '/vendor/autoload.php';

$directory = $argv[1] ?? \dirname(__DIR__) . '/build/specs';
$servers = ['default' => 'music-catalog', 'admin' => 'music-catalog-admin'];

if (!\is_dir($directory) && !\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
    \fwrite(\STDERR, "Could not create {$directory}.\n");
    exit(1);
}

// A document is written to the buffer verbatim: the console formatter would otherwise read
// any `<info>`-like text in a description as a style tag and strip it.
$verbatim = new class extends OutputFormatter {
    public function format(?string $message): ?string
    {
        return $message;
    }
};

// The console reads its verbosity from SHELL_VERBOSITY, and a quiet one would write empty
// documents, so a caller's setting (PHPUnit pins it to -1) is dropped.
\putenv('SHELL_VERBOSITY');
unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);

$run = static function (Application $application, array $parameters) use ($verbatim): string {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false, $verbatim);
    $code = $application->run(new ArrayInput($parameters), $output);
    if ($code !== 0) {
        \fwrite(\STDERR, \sprintf("%s failed (exit %d):\n%s\n", \json_encode($parameters, \JSON_THROW_ON_ERROR), $code, $output->fetch()));
        exit($code);
    }

    $document = $output->fetch();
    if (\trim($document) === '') {
        \fwrite(\STDERR, \sprintf("%s wrote nothing.\n", \json_encode($parameters, \JSON_THROW_ON_ERROR)));
        exit(1);
    }

    return $document;
};

$kernel = new MusicCatalogKernel('test', true);
$application = new Application($kernel);
$application->setAutoExit(false);
$application->setCatchExceptions(false);

foreach ($servers as $server => $name) {
    \file_put_contents(
        "{$directory}/{$name}.openapi.json",
        $run($application, ['command' => 'json-api:openapi:export', '--server' => $server]),
    );
    \file_put_contents(
        "{$directory}/{$name}.schemas.json",
        $run($application, ['command' => 'json-api:json-schema:export', '--server' => $server]),
    );
}

$versions = [];
foreach (['haddowg/json-api', 'haddowg/json-api-symfony'] as $package) {
    $versions[$package] = InstalledVersions::getPrettyVersion($package);
}
\file_put_contents(
    "{$directory}/versions.json",
    \json_encode($versions, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
);

$kernel->shutdown();

\fwrite(\STDOUT, "Wrote the music-catalog documents to {$directory}.\n");
