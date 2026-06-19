<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Testing;

use haddowg\JsonApi\OpenApi\ComponentNaming;

/**
 * The JSON:API document envelope a response carries — the discriminator
 * {@see SchemaConformanceTrait::assertResponseMatchesGeneratedSchema()} uses to pick
 * which generated component schema validates a real response.
 *
 * Each case maps a response shape to the component-name suffix the
 * {@see \haddowg\JsonApi\OpenApi\OpenApiProjector} emits for it, so the trait names the
 * exact component the projection produced (the naming convention is shared, via core's
 * {@see ComponentNaming}):
 *
 * - {@see Single} — `GET /{type}/{id}` → `<Base>Document` (`{data: <Resource>}`).
 * - {@see Collection} — `GET /{type}` → `<Base>Collection` (`{data: [<Resource>]}`).
 * - {@see Relationship} — `GET /{type}/{id}/relationships/{rel}` →
 *   `<Base><Rel>RelationshipDocument` (linkage only).
 * - {@see Related} — `GET /{type}/{id}/{rel}` for a **to-one** relation →
 *   `<Base><Rel>RelatedDocument` (the related resource as primary data). A to-many
 *   related endpoint serves the related type's plain {@see Collection}, so validate it
 *   as a {@see Collection} of the *related* type, not with this case.
 */
enum SchemaDocumentKind
{
    case Single;
    case Collection;
    case Relationship;
    case Related;

    /**
     * The generated component name for `$type` (and, for the relationship/related
     * kinds, `$relationship`), derived through the same {@see ComponentNaming} the
     * projector uses — so the name always matches an emitted component.
     *
     * @param string      $type         the JSON:API primary type (the URI/type name, e.g. `articles`)
     * @param string|null $relationship the relation name — required for {@see Relationship} / {@see Related}
     *
     * @throws \InvalidArgumentException when a relationship kind is used without a relation name
     */
    public function componentName(string $type, ?string $relationship = null): string
    {
        $base = ComponentNaming::base($type);

        return match ($this) {
            self::Single => $base . 'Document',
            self::Collection => $base . 'Collection',
            self::Relationship => $base . $this->relationBase($relationship) . 'RelationshipDocument',
            self::Related => $base . $this->relationBase($relationship) . 'RelatedDocument',
        };
    }

    private function relationBase(?string $relationship): string
    {
        if ($relationship === null || $relationship === '') {
            throw new \InvalidArgumentException(\sprintf('The "%s" document kind requires a relationship name.', $this->name));
        }

        return ComponentNaming::base($relationship);
    }
}
