<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;

/**
 * `POST /albums/-actions/summary` — a **collection-scope** custom action (G13, bundle
 * ADR 0076, design §1): no `{id}`, so {@see ActionContext::entity()} is `null`. It
 * computes a catalogue-wide summary (the published / unpublished album counts) and
 * returns it as a meta-only JSON:API document — a non-CRUD report that has no natural
 * place in the CRUD verb set. It declares `responds: [new MetaResult()]` so the
 * generated OpenAPI document advertises a `200` meta document rather than an albums body.
 */
#[AsJsonApiAction(type: 'albums', path: 'summary', scope: ActionScope::Collection, responds: [new MetaResult()], tags: ['Catalog'])]
final class SummarizeAlbums implements ActionHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function handle(ActionContext $context): MetaResponse
    {
        $repository = $this->entityManager->getRepository(Album::class);

        $published = (int) $repository->count(['published' => true]);
        $unpublished = (int) $repository->count(['published' => false]);

        return $context->meta([
            'albums' => [
                'published' => $published,
                'unpublished' => $unpublished,
                'total' => $published + $unpublished,
            ],
        ]);
    }
}
