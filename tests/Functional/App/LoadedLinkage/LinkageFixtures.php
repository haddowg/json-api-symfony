<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\HidingProvider;

/**
 * Template 1 holds binding 1, which every read serves, binding 2, which the scope
 * hides, and binding 3, which the bindings type's default filter hides.
 */
final class LinkageFixtures
{
    /**
     * @var array{templates: list<LinkageTemplate>, bindings: list<LinkageBinding>}|null
     */
    private static ?array $shared = null;

    /**
     * @return array{templates: list<LinkageTemplate>, bindings: list<LinkageBinding>}
     */
    public static function graph(): array
    {
        $template = new LinkageTemplate(1, 'Template One');

        return [
            'templates' => [$template],
            'bindings' => [
                new LinkageBinding(1, 'Visible Binding', $template),
                new LinkageBinding(2, 'Scoped Out Binding', $template, hidden: true),
                new LinkageBinding(3, 'Detached Binding', $template, detached: true),
            ],
        ];
    }

    /**
     * @return DataProviderInterface<object>
     */
    public static function inMemoryProvider(string $type): DataProviderInterface
    {
        $byId = [];
        self::$shared ??= self::graph();
        foreach (self::$shared[$type] as $item) {
            $byId[(string) $item->id] = $item;
        }

        $provider = new InMemoryDataProvider($type, $byId);

        return $type === 'bindings' ? new HidingProvider([$type], $provider) : $provider;
    }
}
