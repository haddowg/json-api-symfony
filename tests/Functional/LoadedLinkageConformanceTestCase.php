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
