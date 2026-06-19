<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Config;

use haddowg\JsonApi\OpenApi\SecuritySchemeType;
use haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfigResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see OpenApiConfigResolver} security-scheme resolution (design §4.6,
 * D8): every advertised scheme type resolves into a typed VO — including **oauth2** (its
 * `flows` graph → an `OAuthFlows`/`OAuthFlow` VO) — and a `default_requirement` that names
 * a scheme which did not resolve is dropped so the document never carries a dangling
 * security reference.
 */
#[Group('spec:openapi')]
final class OpenApiConfigResolverTest extends TestCase
{
    #[Test]
    public function itResolvesAnOauth2SchemeFromItsFlowsGraph(): void
    {
        $config = $this->resolve([
            'openapi' => [
                'security' => [
                    'schemes' => [
                        'oauth' => [
                            'type' => 'oauth2',
                            'description' => 'OAuth 2.0',
                            'flows' => [
                                'authorizationCode' => [
                                    'authorizationUrl' => 'https://auth.test/authorize',
                                    'tokenUrl' => 'https://auth.test/token',
                                    'refreshUrl' => 'https://auth.test/refresh',
                                    'scopes' => [
                                        'read' => 'Read access',
                                        'write' => 'Write access',
                                    ],
                                ],
                                'clientCredentials' => [
                                    'tokenUrl' => 'https://auth.test/token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $schemes = $config->serverDocuments['default']->securitySchemes;
        self::assertArrayHasKey('oauth', $schemes);

        $scheme = $schemes['oauth'];
        self::assertSame(SecuritySchemeType::OAuth2, $scheme->type);
        self::assertSame('OAuth 2.0', $scheme->description);
        self::assertNotNull($scheme->flows);

        $authCode = $scheme->flows->authorizationCode;
        self::assertNotNull($authCode);
        self::assertSame('https://auth.test/authorize', $authCode->authorizationUrl);
        self::assertSame('https://auth.test/token', $authCode->tokenUrl);
        self::assertSame('https://auth.test/refresh', $authCode->refreshUrl);
        self::assertSame(['read' => 'Read access', 'write' => 'Write access'], $authCode->scopes);

        $clientCreds = $scheme->flows->clientCredentials;
        self::assertNotNull($clientCreds);
        self::assertSame('https://auth.test/token', $clientCreds->tokenUrl);

        // Unconfigured flows stay absent.
        self::assertNull($scheme->flows->implicit);
        self::assertNull($scheme->flows->password);
    }

    #[Test]
    public function itDropsAnOauth2SchemeWithNoUsableFlow(): void
    {
        $config = $this->resolve([
            'openapi' => [
                'security' => [
                    'schemes' => [
                        'oauth' => ['type' => 'oauth2', 'flows' => []],
                    ],
                ],
            ],
        ]);

        // An oauth2 scheme with no flow would emit an empty `flows` object that fails OAS
        // 3.1 meta-validation, so it is dropped entirely.
        self::assertArrayNotHasKey('oauth', $config->serverDocuments['default']->securitySchemes);
    }

    #[Test]
    public function itDropsADefaultRequirementNamingAnUndefinedScheme(): void
    {
        $config = $this->resolve([
            'openapi' => [
                'security' => [
                    'schemes' => [
                        // An oauth2 scheme with no flow resolves to null (dropped).
                        'oauth' => ['type' => 'oauth2', 'flows' => []],
                    ],
                    'default_requirement' => [
                        ['name' => 'oauth', 'scopes' => []],
                    ],
                ],
            ],
        ]);

        $document = $config->serverDocuments['default'];

        // The scheme was dropped, so the requirement that names it must be dropped too —
        // otherwise a secured operation would reference a scheme not in
        // components.securitySchemes (a dangling reference).
        self::assertSame([], $document->securitySchemes);
        self::assertSame([], $document->defaultSecurity);
    }

    #[Test]
    public function itKeepsADefaultRequirementNamingAResolvedScheme(): void
    {
        $config = $this->resolve([
            'openapi' => [
                'security' => [
                    'schemes' => [
                        'bearer' => ['type' => 'bearer', 'bearerFormat' => 'JWT'],
                    ],
                    'default_requirement' => [
                        ['name' => 'bearer', 'scopes' => []],
                    ],
                ],
            ],
        ]);

        $document = $config->serverDocuments['default'];
        self::assertArrayHasKey('bearer', $document->securitySchemes);
        self::assertCount(1, $document->defaultSecurity);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolve(array $config): \haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfig
    {
        return (new OpenApiConfigResolver())->resolve($config, ['default']);
    }
}
