<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `tags` resource: the shared {@see BaseTagResource}
 * declaration served over the in-memory provider/persister, the witness twin of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineTagResource}.
 */
final class TagResource extends BaseTagResource {}
