<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

/*
 * The bundle registration a real Symfony app uses (vs the imperative
 * `registerBundles()` the bundle's own MicroKernel test kernels use). The
 * music-catalog example app needs only three bundles: FrameworkBundle (the
 * services the bundle relies on), DoctrineBundle (the reference data layer), and
 * JsonApiBundle itself. `symfony/validator`'s services are discovered
 * automatically by FrameworkBundle when the package is installed.
 */

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    JsonApiBundle::class => ['all' => true],
];
