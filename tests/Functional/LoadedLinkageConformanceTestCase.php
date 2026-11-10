<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many's linkage holds what its relationship endpoint serves, even when something
 * else read the relation off the model first. Template 1's `summary` attribute iterates
 * its bindings, which on Doctrine initialises both associations before the
 * relationships render. Binding 2 is hidden by the kernel's scope and binding 3 by the
 * bindings type's default filter, so only binding 1 may appear.
 */
abstract class LoadedLinkageConformanceTestCase extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    #[Test]
    public function aLazyToManyReadByAnAttributeRendersNoUnscopedLinkage(): void
    {
        $document = $this->get('/templates/1');

        foreach (['bindings', 'sharedBindings'] as $name) {
            $relationship = $this->relationship($document, '1', $name);
            if (\array_key_exists('data', $relationship)) {
                self::assertSame(['1'], $this->ids($relationship['data']), $name);
            }
        }
        $this->assertNoHiddenBinding($document);
    }

    #[Test]
    public function alwaysEmittedLinkageIsScopedAndFiltered(): void
    {
        $document = $this->get('/templates/1');

        self::assertSame(['1'], $this->linkageIds($document, '1', 'listedBindings'));
        $this->assertNoHiddenBinding($document);
    }

    #[Test]
    public function anIncludedToManyIsScopedAndFiltered(): void
    {
        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            $document = $this->get('/templates/1?include=' . $name);

            self::assertSame(['1'], $this->linkageIds($document, '1', $name), $name);
            self::assertSame(['1'], $this->includedIds($document, 'bindings'), $name);
            $this->assertNoHiddenBinding($document);
        }
    }

    #[Test]
    public function theRelationshipEndpointAndTheCountAgreeWithTheLinkage(): void
    {
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            self::assertSame(['1'], $this->ids($this->get('/templates/1/relationships/' . $name)['data'] ?? null), $name);
        }

        $document = $this->get('/templates/1?withCount=bindings', $counts);
        self::assertSame(1, $this->value($this->relationship($document, '1', 'bindings'), 'meta', 'total'));
        $this->assertNoHiddenBinding($document);
    }

    #[Test]
    public function anActionsResourceRendersTheLinkageItsRelationshipEndpointServes(): void
    {
        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            $document = $this->action($action);

            foreach (['bindings', 'sharedBindings'] as $name) {
                $relationship = $this->relationship($document, '1', $name);
                if (\array_key_exists('data', $relationship)) {
                    self::assertSame(['1'], $this->ids($relationship['data']), $action . ' ' . $name);
                }
            }
            self::assertSame(
                $this->ids($this->get('/templates/1/relationships/listedBindings')['data'] ?? null),
                $this->linkageIds($document, '1', 'listedBindings'),
                $action,
            );
            $this->assertNoHiddenBinding($document);
        }
    }

    #[Test]
    public function anActionsIncludedToManyIsScopedAndFiltered(): void
    {
        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
                $document = $this->action($action . '?include=' . $name);

                self::assertSame(['1'], $this->linkageIds($document, '1', $name), $action . ' ' . $name);
                self::assertSame(['1'], $this->includedIds($document, 'bindings'), $action . ' ' . $name);
                $this->assertNoHiddenBinding($document);
            }
        }
    }

    #[Test]
    public function anActionsNestedIncludeIsScopedAndFiltered(): void
    {
        foreach (['bindings', 'sharedBindings', 'listedBindings'] as $name) {
            $document = $this->action('/bindings/1/-actions/show?include=template.' . $name);

            self::assertSame(['1'], $this->includedLinkageIds($document, 'templates', '1', $name), $name);
            $this->assertNoHiddenBinding($document);
        }
    }

    #[Test]
    public function anActionsCountAgreesWithTheRelationshipEndpoint(): void
    {
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        foreach (['/templates/1/-actions/show', '/templates/-actions/list'] as $action) {
            $document = $this->action($action . '?withCount=bindings', $counts);

            self::assertSame(1, $this->value($this->relationship($document, '1', 'bindings'), 'meta', 'total'), $action);
            $this->assertNoHiddenBinding($document);
        }
    }

    #[Test]
    public function aResponseAHookSwapsInIsScopedAndFiltered(): void
    {
        $document = $this->get('/bindings/1');

        self::assertSame('templates', $this->value($document, 'data', 'type'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'listedBindings'));
        $this->assertNoHiddenBinding($document);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    protected function action(string $path, array $server = []): array
    {
        $response = $this->handle($path, 'POST', extraServer: $server);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function assertNoHiddenBinding(array $document): void
    {
        $json = (string) \json_encode($document);
        self::assertStringNotContainsString('"id":"2"', $json);
        self::assertStringNotContainsString('"id":"3"', $json);
    }
}
