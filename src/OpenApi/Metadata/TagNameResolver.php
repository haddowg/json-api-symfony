<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use Symfony\Component\String\Inflector\EnglishInflector;

use function Symfony\Component\String\u;

/**
 * Resolves the **default** OpenAPI tag name for a JSON:API type when none is
 * declared (design §4.7, D15): a humanized, title-cased, pluralized form of the
 * type — `blog-post` → `'Blog Posts'`, `genre` → `'Genres'`.
 *
 * It is a heuristic, always overridable by an explicit `tags` ref on the resource /
 * serializer / action. The type is split on `-`/`_`/camelCase boundaries into words,
 * each word title-cased; the **last** word is pluralized via Symfony's
 * {@see EnglishInflector} (a noun phrase pluralizes its head noun) — `pluralize()`
 * returns one or more candidates, the first is used.
 */
final class TagNameResolver
{
    private EnglishInflector $inflector;

    public function __construct()
    {
        $this->inflector = new EnglishInflector();
    }

    /**
     * The humanized-title-case, pluralized tag name for `$type`.
     */
    public function defaultFor(string $type): string
    {
        // Normalize separators to spaces, split camelCase, collapse runs, then split
        // into words. u()->snake() turns `blogPost`/`blog-post` into `blog_post`.
        $words = \preg_split('/[\s_]+/', u($type)->snake()->replace('-', '_')->toString()) ?: [];
        $words = \array_values(\array_filter($words, static fn(string $word): bool => $word !== ''));

        if ($words === []) {
            return u($type)->title(true)->toString();
        }

        $last = \array_key_last($words);
        $words[$last] = $this->pluralize($words[$last]);

        return \implode(' ', \array_map(
            static fn(string $word): string => u($word)->title(true)->toString(),
            $words,
        ));
    }

    /**
     * The first pluralization candidate the inflector offers for `$word` (it may
     * return several when the singular is ambiguous), or the word unchanged when it
     * already appears plural — `singularize()` returning a different word signals the
     * input is already a plural (e.g. `people`, `articles`), so re-pluralizing it
     * (`peoples`, `articless`) is suppressed. The word itself when the inflector
     * offers no candidate.
     */
    private function pluralize(string $word): string
    {
        if (!\in_array($word, $this->inflector->singularize($word), true)) {
            return $word;
        }

        $candidates = $this->inflector->pluralize($word);

        return $candidates[0] ?? $word;
    }
}
