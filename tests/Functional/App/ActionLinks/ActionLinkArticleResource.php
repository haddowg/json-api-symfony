<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;

/**
 * The in-memory `articles` resource with its action links.
 */
final class ActionLinkArticleResource extends BaseArticleResource
{
    use LinksArticleActions;
}
