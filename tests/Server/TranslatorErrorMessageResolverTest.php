<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApiBundle\Server\TranslatorErrorMessageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's {@see TranslatorErrorMessageResolver} maps a core error `code` onto a
 * Symfony translation lookup (`<CODE>.title` / `<CODE>.detail` in the `jsonapi_errors`
 * domain), returning the (localized) template or `null` when the key is absent. The
 * resolver↔render contract — that such a template localizes the title/detail (and the
 * `VALIDATION_FAILED` 422 title) once bound on the Server — is covered by core's own
 * ErrorMessageResolver suite; here we pin the bundle's lookup behaviour.
 */
final class TranslatorErrorMessageResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTitleAndDetailTemplatesByCodeFromTheErrorsDomain(): void
    {
        $resolver = new TranslatorErrorMessageResolver($this->translator([
            'RESOURCE_NOT_FOUND.title' => 'Ressource introuvable',
            'MEDIA_TYPE_UNSUPPORTED.detail' => "Le type de média '{mediaType}' n'est pas supporté.",
        ]));

        self::assertSame('Ressource introuvable', $resolver->title('RESOURCE_NOT_FOUND'));
        // The template is returned verbatim — {placeholder} tokens intact for core to fill.
        self::assertSame(
            "Le type de média '{mediaType}' n'est pas supporté.",
            $resolver->detail('MEDIA_TYPE_UNSUPPORTED'),
        );
    }

    #[Test]
    public function aMissingKeyResolvesToNullSoTheDefaultIsKeptPerSlot(): void
    {
        $resolver = new TranslatorErrorMessageResolver($this->translator([
            'RESOURCE_NOT_FOUND.title' => 'Ressource introuvable',
        ]));

        // Title known, detail absent → per-slot fallback: detail stays null.
        self::assertSame('Ressource introuvable', $resolver->title('RESOURCE_NOT_FOUND'));
        self::assertNull($resolver->detail('RESOURCE_NOT_FOUND'));
        // Unknown code → both null.
        self::assertNull($resolver->title('SOMETHING_ELSE'));
        self::assertNull($resolver->detail('SOMETHING_ELSE'));
    }

    #[Test]
    public function itLooksUpInTheJsonapiErrorsDomainPassingNoParameters(): void
    {
        $translator = new class implements TranslatorInterface {
            /** @var list<array{id: string, params: array<array-key, mixed>, domain: ?string}> */
            public array $calls = [];

            /**
             * @param array<array-key, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $this->calls[] = ['id' => $id, 'params' => $parameters, 'domain' => $domain];

                return $id; // simulate "missing"
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        (new TranslatorErrorMessageResolver($translator))->title('RESOURCE_NOT_FOUND');

        self::assertSame('RESOURCE_NOT_FOUND.title', $translator->calls[0]['id']);
        // No parameters — core, not the translator, fills the {placeholders}.
        self::assertSame([], $translator->calls[0]['params']);
        self::assertSame(TranslatorErrorMessageResolver::DOMAIN, $translator->calls[0]['domain']);
    }

    /**
     * @param array<string, string> $messages
     */
    private function translator(array $messages): TranslatorInterface
    {
        return new class ($messages) implements TranslatorInterface {
            /**
             * @param array<string, string> $messages
             */
            public function __construct(private readonly array $messages) {}

            /**
             * @param array<array-key, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                // Symfony returns the id unchanged when the key is absent from the catalogue.
                return $this->messages[$id] ?? $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
