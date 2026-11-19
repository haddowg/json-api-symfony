<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Field Selection witness behind `docs/resources.md`: `AlbumResource`'s `credits`
 * declares selectable keys, so a table showing one column per role asks for just those
 * keys with `fields[albums]=title,credits.producer`, sends no profile, and gets a
 * response that advertises the Field Selection profile. OK Computer (album 1) seeds a
 * producer, an engineer and an artwork credit.
 */
#[Group('spec:sparse-fieldsets')]
final class FieldSelectionTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aTableSelectsOneColumnPerCreditRole(): void
    {
        $response = $this->handle('/albums/1?fields[albums]=title,credits.producer,credits.artwork');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(
            ['title' => 'OK Computer', 'credits' => ['producer' => 'Nigel Godrich', 'artwork' => 'Stanley Donwood']],
            $data['attributes'] ?? null,
        );
        self::assertSame('application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function withoutAKeyMemberTheResponseIsUnchanged(): void
    {
        $response = $this->handle('/albums/1?fields[albums]=title,credits');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['attributes'] ?? null);
        self::assertSame(['producer' => 'Nigel Godrich', 'engineer' => 'Nigel Godrich', 'artwork' => 'Stanley Donwood'], $data['attributes']['credits'] ?? null);
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function aRoleOutsideThePatternIsA400(): void
    {
        $response = $this->handle('/albums/1?fields[albums]=credits.Producer');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $errors[0]['code'] ?? null);
    }
}
