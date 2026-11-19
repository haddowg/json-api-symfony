<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Resource\WrittenMeta;

/**
 * The typed `meta` a write sent, read off the event's request: the request document's
 * top-level `meta` and the resource object's `data.meta`, each validated against what the
 * type declares and cast as its members cast. Empty for a write that sent none.
 *
 * @property-read \haddowg\JsonApi\Request\JsonApiRequestInterface $request
 */
trait ReadsWrittenMeta
{
    public function writtenMeta(): WrittenMeta
    {
        return WrittenMeta::of($this->request);
    }
}
