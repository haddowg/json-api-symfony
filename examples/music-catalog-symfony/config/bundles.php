<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;

/*
 * The bundle registration a real Symfony app uses (vs the imperative
 * `registerBundles()` the bundle's own MicroKernel test kernels use). The
 * music-catalog example app needs four bundles: FrameworkBundle (the services the
 * bundle relies on), DoctrineBundle (the reference data layer), JsonApiBundle
 * itself, and SecurityBundle — the firewall behind the declarative-authorization
 * witness (`config/packages/security.yaml`), which activates the bundle's optional
 * security layer (a resource's `#[AsJsonApiResource(security: …)]` expressions).
 * `symfony/validator`'s services are discovered automatically by FrameworkBundle
 * when the package is installed.
 */

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    JsonApiBundle::class => ['all' => true],
];
