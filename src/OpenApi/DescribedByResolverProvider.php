<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\DescribedByResolverInterface;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\Server\ServerProvider;

/**
 * Builds the `describedby` resolver a server renders its links with: the server's
 * document URL ({@see DocumentUrlProvider}) paired with the operations its document
 * carries, so a link never points at an operation the document doesn't have.
 *
 * The operations are {@see SpecDescribedByResolver::operationsOf()} of the document, read
 * from the map the {@see DocumentWarmer} writes beside it, so a request reads one small
 * file rather than building or loading the document. When
 * the map is missing (no warmup in dev, or the optional warmer failed) the document is
 * built once and the map written, and kept in memory for the life of the process.
 */
final class DescribedByResolverProvider
{
    /** @var array<string, array<string, list<string>>> */
    private array $operations = [];

    /**
     * @param \Closure(): DocumentFactory $documents lazy, because the document metadata reads the servers this provider configures
     */
    public function __construct(
        private readonly DocumentUrlProvider $urls,
        private readonly ArtifactStore $store,
        private readonly \Closure $documents,
        private readonly bool $combined = false,
    ) {}

    public function forServer(?string $server = null): ?DescribedByResolverInterface
    {
        $server ??= ServerProvider::DEFAULT_SERVER;
        $url = $this->urls->forServer($server);
        if ($url === null) {
            return null;
        }

        $key = $this->combined ? OpenApiController::COMBINED_KEY : $server;

        // Built per request: the URL is generated against the request's base path.
        return new SpecDescribedByResolver($url, $this->operations($key));
    }

    /**
     * @return array<string, list<string>>
     */
    private function operations(string $key): array
    {
        if (isset($this->operations[$key])) {
            return $this->operations[$key];
        }

        $operations = $this->store->readOperations($key);
        if ($operations === null) {
            $documents = ($this->documents)();
            $operations = SpecDescribedByResolver::operationsOf($key === OpenApiController::COMBINED_KEY ? $documents->combined() : $documents->forServer($key));

            try {
                $this->store->writeOperations($key, $operations);
            } catch (\Throwable) {
                // A read-only cache dir only costs this process the rebuild it just did.
            }
        }

        return $this->operations[$key] = $operations;
    }
}
