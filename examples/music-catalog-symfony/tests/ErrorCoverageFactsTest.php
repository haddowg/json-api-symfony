<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDetachmentRefusal;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentValidation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIncludeSafeguards;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkageResolution;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMaxIncludeDepth;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationSecurity;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresStrictQueryParameters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The runtime facts the catalogue's metadata reports, which decide the error codes each
 * operation documents.
 */
#[Group('spec:openapi')]
final class ErrorCoverageFactsTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function onlyAGatedOperationCanDenyAccess(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresOperationSecurity::class, $server);

        $playlists = $this->type($server, 'playlists');
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::Update)));
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::Delete)));
        self::assertFalse($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::FetchOne)));
        // The relation's own read expression guards its relationship read.
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::relation(OperationKind::FetchRelationship, 'owner')));

        $artists = $this->type($server, 'artists');
        self::assertFalse($server->canDenyAccess($artists, OperationKey::crud(OperationKind::FetchCollection)));

        $albums = $this->type($server, 'albums');
        self::assertTrue($server->canDenyAccess($albums, OperationKey::action('reissue', ActionScope::Resource, 'POST')));
    }

    #[Test]
    public function theFirewallsAuthenticatorCanRequireAuthenticationOnEveryRoute(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresOperationSecurity::class, $server);

        // A bad bearer token is refused before the bundle runs, even on a public read.
        self::assertTrue($server->canRequireAuthentication($this->type($server, 'artists'), OperationKey::crud(OperationKind::FetchCollection)));
        self::assertTrue($server->canRequireAuthentication(null, OperationKey::atomic()));
        self::assertFalse($server->canDenyAccess(null, OperationKey::atomic()));
    }

    #[Test]
    public function anIncludeReachingARefusedReadWithholdsTheDataInsteadOfRefusing(): void
    {
        // `owner` declares security(read:) for admins only, which an anonymous caller
        // fails. The include renders the relationship's links with no data, so it
        // documents no 401 or 403.
        $response = $this->handle('/admin/playlists/' . SeedManifest::OWNED_PLAYLIST_ID . '?include=owner');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $owner = $relationships['owner'] ?? null;
        self::assertIsArray($owner);
        self::assertArrayNotHasKey('data', $owner);
        self::assertSame([], $document['included'] ?? []);
    }

    #[Test]
    public function theServerReportsItsQueryAndDocumentChecks(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresStrictQueryParameters::class, $server);
        self::assertInstanceOf(DeclaresDocumentValidation::class, $server);
        self::assertInstanceOf(DeclaresMaxIncludeDepth::class, $server);

        self::assertTrue($server->strictQueryParameters());
        self::assertFalse($server->validatesRequestDocuments());
        self::assertTrue($server->checksResourceObjectMembers());
        self::assertFalse($server->validatesResponseDocuments());
        // json_api.max_include_depth in the example config.
        self::assertSame(2, $server->maxIncludeDepth());
    }

    #[Test]
    public function aTypeReportsTheIncludeSafeguardsOfItsSerializer(): void
    {
        $albums = $this->type($this->server(), 'albums');
        self::assertInstanceOf(DeclaresIncludeSafeguards::class, $albums);

        self::assertNull($albums->maxIncludeDepth());
        self::assertSame([], $albums->excludedIncludePaths());
    }

    #[Test]
    public function aRelationReportsWhatTheDoctrineMappingRefuses(): void
    {
        $tracks = $this->relation($this->type($this->server(), 'albums'), 'tracks');
        self::assertInstanceOf(DeclaresDetachmentRefusal::class, $tracks);
        self::assertInstanceOf(DeclaresLinkageResolution::class, $tracks);

        // A track's album_id is nullable, so a track can leave its album.
        self::assertFalse($tracks->canRefuseDetachment());
        // An integer id that isn't one of the related type's is a 404.
        self::assertTrue($tracks->canMissLinkage());
    }

    private function server(): ServerMetadataInterface
    {
        $source = static::getContainer()->get(MetadataSource::class);
        self::assertInstanceOf(MetadataSource::class, $source);

        return $source->forServer();
    }

    private function type(ServerMetadataInterface $server, string $name): TypeMetadataInterface
    {
        foreach ($server->types() as $type) {
            if ($type->type() === $name) {
                return $type;
            }
        }

        self::fail(\sprintf('The server describes no "%s" type.', $name));
    }

    private function relation(TypeMetadataInterface $type, string $name): RelationMetadataInterface
    {
        foreach ($type->relations() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        self::fail(\sprintf('The "%s" type has no "%s" relation.', $type->type(), $name));
    }
}
