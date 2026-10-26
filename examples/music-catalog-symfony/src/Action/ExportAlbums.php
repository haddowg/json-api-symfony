<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Action;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\OpenApi\AlbumCatalogueStats;

/**
 * `GET /albums/-actions/export` answers in the format the client's `Accept` asks for: a
 * JSON:API meta document, a CSV of the albums, a PDF report or plain JSON counts. The
 * CSV and PDF come back as downloads.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [
        new MetaResult(),
        new RawResult('text/csv', schema: ['type' => 'string'], headers: ['Content-Disposition' => 'Names the downloaded file.'], example: "id,title\n1,Kind of Blue\n"),
        new RawResult('application/pdf', binary: true, headers: ['Content-Disposition' => 'Names the downloaded file.']),
        new RawResult('application/json', schema: AlbumCatalogueStats::class),
    ],
    tags: ['Catalog'],
    summary: 'Export the album catalogue',
    description: 'Exports every album in the format the Accept header asks for: a JSON:API meta document with counts, a CSV of ids and titles, a PDF report, or the counts as plain JSON.',
)]
final class ExportAlbums implements ActionHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        $albums = $this->entityManager->getRepository(Album::class)->findBy([], ['id' => 'ASC']);
        $published = \count(\array_filter($albums, static fn(Album $album): bool => $album->published));
        $counts = ['published' => $published, 'unpublished' => \count($albums) - $published];

        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw(self::csv($albums), headers: ['Content-Disposition' => 'attachment; filename="albums.csv"']),
            'application/pdf' => $context->raw(self::pdf(\count($albums)), headers: ['Content-Disposition' => 'attachment; filename="albums.pdf"']),
            'application/json' => $context->raw(\json_encode($counts, \JSON_THROW_ON_ERROR)),
            default => $context->meta(['albums' => $counts]),
        };
    }

    /**
     * @param list<Album> $albums
     */
    private static function csv(array $albums): string
    {
        $lines = ['id,title'];
        foreach ($albums as $album) {
            $lines[] = $album->id . ',"' . \str_replace('"', '""', $album->title) . '"';
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * A placeholder report streamed from memory, standing in for a real PDF renderer.
     *
     * @return resource
     */
    private static function pdf(int $count)
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        \fwrite($stream, "%PDF-1.7\n% " . $count . " albums\n%%EOF\n");
        \rewind($stream);

        return $stream;
    }
}
