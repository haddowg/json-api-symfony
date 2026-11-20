<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\StaticAnalysis;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\ActionLinkBuilder;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\WhereHasMatching;
use haddowg\JsonApiBundle\DataProvider\Doctrine\KeysetExpression;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Validation\Constraint\NativeConstraints;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as an
 * application would, so a hook declaration that rejects or narrows a closure typed on a
 * Doctrine entity, or a bundle seam that rejects an application's closure, fails the
 * `composer phpstan` run.
 */
final class HookClosureTypes
{
    /**
     * @return list<FieldBuilderInterface|DeclaredLinkBuilder|ActionLinkBuilder>
     */
    public static function declarations(): array
    {
        return [
            Str::make('title')
                ->extractUsing(static fn(HookAlbum $album): string => $album->title)
                ->fillUsing(static fn(HookAlbum $album, mixed $value): HookAlbum => $album)
                ->hidden(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => $album->locked)
                ->readOnly(static fn(JsonApiRequestInterface $request): bool => $request->getMethod() === 'POST'),
            Str::make('label')->computedUsing(
                static fn(HookAlbum $album, JsonApiRequestInterface $request, string $name): string => $name . $album->id,
            ),
            Str::make('nullable')->extractUsing(static fn(?HookAlbum $album): ?string => $album?->title),
            Obj::make('credits')
                ->additionalProperties(Str::make('*'))
                ->extractUsing(static fn(HookAlbum $album): ?array => $album->credits),
            Map::make('totals')->fields(Integer::make('views'))
                ->extractUsing(static fn(HookAlbum $album, JsonApiRequestInterface $request, string $name): array => ['views' => $album->id]),
            HasMany::make('children', 'albums')
                ->extractUsing(static fn(HookAlbum $album): mixed => $album->children)
                ->cannotAdd(static fn(HookAlbum $album): bool => $album->locked)
                ->cannotRemove(static fn(?HookAlbum $album): bool => $album?->locked === true)
                ->cannotReplace(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => $album->locked)
                ->cannotBeIncluded(static fn(HookAlbum $album): bool => $album->locked)
                ->identifierMeta(static fn(HookAlbum $parent, HookAlbum $related): array => ['parent' => $parent->id, 'at' => $related->id]),
            BelongsTo::make('parent', 'albums')->extractUsing(static fn(HookAlbum $album): ?HookAlbum => $album->parent),
            DeclaredLink::make('store')
                ->hrefUsing(static fn(HookAlbum $album): string => '/albums/' . $album->id)
                ->metaUsing(static fn(?HookAlbum $album): array => ['title' => $album?->title]),
            ActionLink::for('publish')
                ->when(static fn(HookAlbum $album, JsonApiRequestInterface $request): bool => !$album->locked),
        ];
    }

    /**
     * The bundle's own seams an application passes closures to.
     *
     * @return list<object>
     */
    public static function seams(ActionContext $context): array
    {
        $context->afterCommit(static function (): void {});

        $provider = new InMemoryDataProvider(
            'albums',
            [],
            static fn(HookAlbum $item): string => (string) $item->id,
            static function (HookAlbum $item, string $id): void {
                $item->id = (int) $id;
            },
        );
        $seeded = new InMemoryDataProvider('albums', ['1' => new HookAlbum()], static fn(HookAlbum $item): string => (string) $item->id);

        return [
            $seeded,
            self::persisterOver(self::albums()),
            new InMemoryDataPersister(
                'albums',
                $provider->store(),
                static fn(): HookAlbum => new HookAlbum(),
                static fn(string $type, string $id): ?object => $provider->store()->find($id),
            ),
            WhereHasMatching::using('tagged', 'tags', static function (QueryBuilder $query, string $alias, mixed $value): void {
                $query->andWhere($alias . '.name = :tag')->setParameter('tag', $value);
            }),
            KeysetExpression::repeatable(static fn(string $suffix): string => 'COALESCE(a' . $suffix . '.rank, 0)'),
            NativeConstraints::make(new NotBlank())->schema(static fn(Schema $schema): Schema => $schema),
        ];
    }

    /**
     * @return InMemoryDataProvider<HookAlbum>
     */
    private static function albums(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('albums', [], static fn(HookAlbum $item): string => (string) $item->id);
    }

    /**
     * @param InMemoryDataProvider<HookAlbum> $provider
     *
     * @return InMemoryDataPersister<HookAlbum>
     */
    private static function persisterOver(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('albums', $provider->store(), static fn(): HookAlbum => new HookAlbum());
    }
}
