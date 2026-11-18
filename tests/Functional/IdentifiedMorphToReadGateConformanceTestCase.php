<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A read-gated identified MorphTo, run identically against the in-memory provider
 * ({@see InMemoryIdentifiedMorphToReadGateTest}) and the Doctrine provider
 * ({@see DoctrineIdentifiedMorphToReadGateTest}) over
 * {@see App\IdentifiedMorphGate\IdentifiedMorphGateFixtures}.
 *
 * A post's `subject` is declared with `identifiedByColumns()` over articles and videos,
 * and neither resource overrides getType(), so the kernel booting at all is the first
 * check. Each type gates its own rows: the article gate refuses `restricted`, the video
 * gate refuses `classified`. A subject must be checked against its stored type's gate,
 * so video 3, named `restricted`, shows and video 2, named `classified`, doesn't.
 * An included video loads its own relations, so the scope hides its channel.
 */
abstract class IdentifiedMorphToReadGateConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:resource-linkage')]
    public function theLinkageRendersEachPairAndHidesWhatItsOwnTypesGateRefuses(): void
    {
        self::assertSame([
            '1' => ['type' => 'gatedArticles', 'id' => '1'],
            '2' => null,
            '3' => ['type' => 'gatedVideos', 'id' => '1'],
            '4' => null,
            '5' => ['type' => 'gatedVideos', 'id' => '3'],
            '6' => null,
        ], $this->linkageByPost($this->document('/gatedPosts')));

        self::assertSame(['5' => ['type' => 'gatedVideos', 'id' => '3']], $this->linkageByPost($this->document('/gatedPosts/5')));
        self::assertSame(['4' => null], $this->linkageByPost($this->document('/gatedPosts/4')));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeRendersEachTargetAsItsStoredType(): void
    {
        $document = $this->document('/gatedPosts?include=subject');

        self::assertSame(['gatedArticles:1', 'gatedVideos:1', 'gatedVideos:3'], $this->included($document));
        // Each video's relations load as a video's, so the scope hides its channel.
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        foreach ($included as $resource) {
            if ($this->at($resource, 'type') === 'gatedVideos') {
                $channel = $this->at($resource, 'relationships', 'channel');
                self::assertIsArray($channel);
                self::assertArrayHasKey('data', $channel);
                self::assertNull($channel['data'], 'video ' . \json_encode($this->at($resource, 'id')));
            }
        }
        self::assertSame([
            '1' => ['type' => 'gatedArticles', 'id' => '1'],
            '2' => null,
            '3' => ['type' => 'gatedVideos', 'id' => '1'],
            '4' => null,
            '5' => ['type' => 'gatedVideos', 'id' => '3'],
            '6' => null,
        ], $this->linkageByPost($document));
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function theRelatedEndpointGatesTheTargetAsItsStoredType(): void
    {
        $readable = $this->document('/gatedPosts/5/subject');
        self::assertSame('gatedVideos', $this->at($readable, 'data', 'type'));
        self::assertSame('3', $this->at($readable, 'data', 'id'));
        self::assertSame('restricted', $this->at($readable, 'data', 'attributes', 'name'));

        self::assertNull($this->document('/gatedPosts/4/subject')['data'] ?? null);
        self::assertNull($this->document('/gatedPosts/2/subject')['data'] ?? null);
        self::assertSame('gatedArticles', $this->at($this->document('/gatedPosts/1/subject'), 'data', 'type'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointGatesTheTargetAsItsStoredType(): void
    {
        self::assertSame(['type' => 'gatedVideos', 'id' => '3'], $this->document('/gatedPosts/5/relationships/subject')['data'] ?? null);
        self::assertNull($this->document('/gatedPosts/4/relationships/subject')['data'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * Each post's `subject` linkage, keyed by post id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function linkageByPost(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $linkage = [];
        foreach (\array_is_list($data) ? $data : [$data] as $resource) {
            $id = $this->at($resource, 'id');
            self::assertIsString($id);
            $relationship = $this->at($resource, 'relationships', 'subject');
            self::assertIsArray($relationship, 'post ' . $id);
            self::assertArrayHasKey('data', $relationship, 'post ' . $id);
            $linkage[$id] = $relationship['data'];
        }

        return $linkage;
    }

    /**
     * The `type:id` of every included resource, sorted.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function included(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $members = [];
        foreach ($included as $resource) {
            $type = $this->at($resource, 'type');
            $id = $this->at($resource, 'id');
            self::assertIsString($type);
            self::assertIsString($id);
            $members[] = $type . ':' . $id;
        }
        \sort($members);

        return $members;
    }

    private function at(mixed $node, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($node)) {
                return null;
            }
            $node = $node[$key] ?? null;
        }

        return $node;
    }
}
