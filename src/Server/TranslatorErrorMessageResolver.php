<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's {@see ErrorMessageResolverInterface}: resolves an error's title/detail
 * template from the Symfony translator, keyed by the stable error `code`, so core's
 * error catalogue is localizable and overridable through ordinary Symfony translation
 * files — with nothing to register per error.
 *
 * Keys live in the `jsonapi_errors` domain as `<CODE>.title` / `<CODE>.detail`
 * (e.g. `translations/jsonapi_errors.fr.yaml`):
 *
 *     RESOURCE_NOT_FOUND:
 *         title: Ressource introuvable
 *     MEDIA_TYPE_UNSUPPORTED:
 *         detail: "Le type de média '{mediaType}' n'est pas supporté."
 *
 * A missing key falls back to the error's own default: the translator returns the id
 * unchanged, which maps to `null`, so a partial translation degrades gracefully per
 * slot. The returned string is a **template** — core interpolates the error's
 * `{placeholder}` context into it *after* translation, so the messages carry `{name}`
 * tokens, not Symfony `%name%` parameters (no parameters are passed to the translator,
 * which therefore leaves the tokens intact). The locale is the translator's current
 * request locale, so `Accept-Language` negotiation stays the framework's job.
 */
final readonly class TranslatorErrorMessageResolver implements ErrorMessageResolverInterface
{
    public const DOMAIN = 'jsonapi_errors';

    public function __construct(private TranslatorInterface $translator) {}

    public function title(string $code): ?string
    {
        return $this->resolve($code . '.title');
    }

    public function detail(string $code): ?string
    {
        return $this->resolve($code . '.detail');
    }

    private function resolve(string $id): ?string
    {
        // No parameters: core fills the `{placeholder}` tokens after translation, so
        // the translator must return the template untouched.
        $translated = $this->translator->trans($id, [], self::DOMAIN);

        // Symfony returns the id unchanged when the key is absent from the catalogue
        // (in every fallback locale) — that means "no override", so keep the default.
        return $translated === $id ? null : $translated;
    }
}
