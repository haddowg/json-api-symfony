<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\DependencyInjection\InstalledVersionsCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InstalledVersionsCheckTest extends TestCase
{
    private const string REF_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string REF_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function itRecordsBothPackagesFromComposer(): void
    {
        $installed = InstalledVersionsCheck::installed();

        self::assertIsArray($installed);
        self::assertSame(InstalledVersionsCheck::PACKAGES, \array_keys($installed));
        self::assertIsString($installed['haddowg/json-api']['version'] ?? null);
    }

    #[Test]
    public function matchingVersionsPass(): void
    {
        $this->expectNotToPerformAssertions();

        InstalledVersionsCheck::assertFresh(
            $this->versions('v1.10.0', self::REF_A),
            $this->versions('v1.10.0', self::REF_A),
            'test',
        );
    }

    #[Test]
    public function aChangedVersionThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('compiled against haddowg/json-api-symfony v1.10.0@aaaaaaaaaaaa, haddowg/json-api v1.10.0@aaaaaaaaaaaa, but haddowg/json-api-symfony v1.11.0@bbbbbbbbbbbb');

        InstalledVersionsCheck::assertFresh(
            $this->versions('v1.10.0', self::REF_A),
            $this->versions('v1.11.0', self::REF_B),
            'test',
        );
    }

    #[Test]
    public function theMessageTellsTheUserToClearThatEnvironmentsCache(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"bin/console cache:clear --env=staging" or by deleting var/cache/staging');

        InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', null), $this->versions('v1.11.0', null), 'staging');
    }

    #[Test]
    public function aChangedReferenceOnTheSameReleaseThrows(): void
    {
        $this->expectException(\LogicException::class);

        InstalledVersionsCheck::assertFresh(
            $this->versions('v1.10.0', self::REF_A),
            $this->versions('v1.10.0', self::REF_B),
            'test',
        );
    }

    #[Test]
    public function aNullReferenceOnEitherSideComparesTheVersionOnly(): void
    {
        $this->expectNotToPerformAssertions();

        InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', null), $this->versions('v1.10.0', self::REF_B), 'test');
        InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', self::REF_A), $this->versions('v1.10.0', null), 'test');
    }

    #[Test]
    public function aDevelopmentVersionWhoseReferenceMovedPasses(): void
    {
        $this->expectNotToPerformAssertions();

        InstalledVersionsCheck::assertFresh($this->versions('dev-main', self::REF_A), $this->versions('dev-main', self::REF_B), 'test');
        InstalledVersionsCheck::assertFresh($this->versions('1.x-dev', self::REF_A), $this->versions('1.x-dev', self::REF_B), 'test');
    }

    #[Test]
    public function aContainerThatRecordedNoVersionsThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('did not record them');

        InstalledVersionsCheck::assertFresh(false, $this->versions('v1.11.0', null), 'test');
    }

    #[Test]
    public function nothingIsCheckedWithoutComposersRuntimeApi(): void
    {
        $this->expectNotToPerformAssertions();

        InstalledVersionsCheck::assertFresh(null, $this->versions('v1.11.0', null), 'test');
        InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', null), null, 'test');
    }

    #[Test]
    public function aPackageEitherSideDoesNotKnowIsSkipped(): void
    {
        $this->expectNotToPerformAssertions();

        InstalledVersionsCheck::assertFresh(
            ['haddowg/json-api-symfony' => null, 'haddowg/json-api' => ['version' => 'v1.10.0', 'reference' => null]],
            ['haddowg/json-api-symfony' => ['version' => 'v1.11.0', 'reference' => null], 'haddowg/json-api' => ['version' => 'v1.10.0', 'reference' => null]],
            'test',
        );
    }

    #[Test]
    public function cacheClearMayBootAStaleContainer(): void
    {
        foreach ([['bin/console', 'cache:clear'], ['bin/console', '--env', 'prod', 'c:c'], ['bin/console', 'cache:cl', '--no-warmup']] as $argv) {
            InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', null), $this->versions('v1.11.0', null), 'prod', $argv);
        }

        $this->expectException(\LogicException::class);
        InstalledVersionsCheck::assertFresh($this->versions('v1.10.0', null), $this->versions('v1.11.0', null), 'prod', ['bin/console', 'cache:warmup']);
    }

    /**
     * @return array<string, array{version: string, reference: ?string}>
     */
    private function versions(string $bundle, ?string $reference): array
    {
        return [
            'haddowg/json-api-symfony' => ['version' => $bundle, 'reference' => $reference],
            'haddowg/json-api' => ['version' => 'v1.10.0', 'reference' => $reference !== null ? self::REF_A : null],
        ];
    }
}
