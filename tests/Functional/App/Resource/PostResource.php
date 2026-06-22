<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `posts` resource: the shared {@see BasePostResource}
 * declaration whose `author` relation targets the curated `public-members` type,
 * served (and written) by the in-memory provider/persister pair.
 */
final class PostResource extends BasePostResource {}
