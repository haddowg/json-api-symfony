<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Assembles the {@see ErrorCatalogSourceInterface}s the {@see MetadataSource} hands
 * every server's {@see \haddowg\JsonApiBundle\OpenApi\Metadata\ServerMetadata}, so an
 * application's own described errors are catalogued in the OpenAPI document beside
 * core's (core ADR 0136).
 *
 * Two ways in, because an exception is not a service and cannot be autoconfigured:
 *
 *  - **`json_api.error_codes.paths`.** Each configured directory is walked for classes
 *    implementing core's {@see DescribedErrorInterface} and the findings become one
 *    {@see ClassListErrorSource}. The walk happens here, at container build time, so a
 *    request never touches the filesystem and the compiled container carries the
 *    resolved list; a {@see DirectoryResource} on each path rebuilds the container when
 *    a file under it is added, changed or removed.
 *  - **{@see JsonApiBundle::ERROR_SOURCE_TAG}.** Any service implementing
 *    {@see ErrorCatalogSourceInterface} is autoconfigured onto this tag. That is the
 *    escape hatch for a class no scan reaches — one outside the configured paths, or
 *    generated into a cache directory — via core's
 *    `ClassListErrorSource(Foo::class, Bar::class)` or a source of your own.
 *
 * **The scan is scoped to the configured paths and nothing else**, and the default is
 * no paths at all. That is what makes discovery safe: a `DescribedErrorInterface` in a
 * test fixture, a vendored package or anywhere else under the project reaches a
 * published contract only when someone configures the directory it lives in, or names
 * the class outright.
 *
 * Order is fixed so the projected component order is deterministic (it decides the emit
 * order of the error components, which byte-parity with the Laravel package depends on):
 * the scanned source first with its classes in case-insensitive class-name order — the
 * rule {@see \haddowg\JsonApi\Exception\CoreErrorSource} sorts by — then the tagged
 * services in tag-priority order.
 */
final class ErrorCatalogPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    /**
     * The service id the scanned {@see ClassListErrorSource} is registered under.
     */
    public const string SCANNED_SOURCE_ID = 'haddowg.json_api.error_source.scanned';

    /**
     * The container parameter carrying `json_api.error_codes.paths`.
     */
    public const string PATHS_PARAMETER = 'haddowg_json_api.error_code_paths';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MetadataSource::class)) {
            return;
        }

        $sources = [];

        $scanned = $this->scan($container);
        if ($scanned !== []) {
            $container->setDefinition(
                self::SCANNED_SOURCE_ID,
                (new Definition(ClassListErrorSource::class, $scanned))->setPublic(false),
            );
            $sources[] = new Reference(self::SCANNED_SOURCE_ID);
        }

        foreach ($this->findAndSortTaggedServices(JsonApiBundle::ERROR_SOURCE_TAG, $container) as $reference) {
            $id = (string) $reference;
            $class = $container->findDefinition($id)->getClass();
            if ($class !== null && !\is_a($class, ErrorCatalogSourceInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    'The service "%s" tagged "%s" must implement %s.',
                    $id,
                    JsonApiBundle::ERROR_SOURCE_TAG,
                    ErrorCatalogSourceInterface::class,
                ));
            }

            $sources[] = $reference;
        }

        $container->getDefinition(MetadataSource::class)->setArgument('$errorSources', $sources);
    }

    /**
     * Every concrete {@see DescribedErrorInterface} declared under the configured paths,
     * in case-insensitive class-name order.
     *
     * @return list<class-string<DescribedErrorInterface>>
     */
    private function scan(ContainerBuilder $container): array
    {
        $classes = [];
        foreach ($this->paths($container) as $path) {
            if (!\is_dir($path)) {
                continue;
            }

            $container->addResource(new DirectoryResource($path, '/\.php$/'));

            foreach ($this->classesIn($path) as $class) {
                if (!\is_subclass_of($class, DescribedErrorInterface::class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                /** @var class-string<DescribedErrorInterface> $class */
                $classes[$class] = true;
            }
        }

        $found = \array_keys($classes);
        \usort($found, static fn(string $a, string $b): int => \strcasecmp($a, $b));

        return $found;
    }

    /**
     * The configured scan roots.
     *
     * @return list<string>
     */
    private function paths(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::PATHS_PARAMETER)) {
            return [];
        }

        $paths = $container->getParameter(self::PATHS_PARAMETER);
        if (!\is_array($paths)) {
            return [];
        }

        $roots = [];
        foreach ($paths as $path) {
            if (\is_string($path) && $path !== '') {
                $roots[] = \rtrim($path, '/');
            }
        }

        return $roots;
    }

    /**
     * The class declared by each PHP file under `$path`, recursively.
     *
     * @return list<class-string>
     */
    private function classesIn(string $path): array
    {
        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classInFile($file->getPathname());
            if ($class !== null && \class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The fully-qualified name of the class declared in `$file`, read by tokenising the
     * source so the file is never executed to discover it, or `null` when it declares no
     * top-level class.
     *
     * @return class-string|null
     */
    private function classInFile(string $file): ?string
    {
        $contents = @\file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($contents),
            static fn(\PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
        ));

        $namespace = '';
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->is(\T_NAMESPACE)) {
                $namespace = $this->readNamespace($tokens, $i);

                continue;
            }

            if (!$tokens[$i]->is(\T_CLASS)) {
                continue;
            }

            // `Foo::class` and `new class {…}` declare nothing nameable.
            if ($i > 0 && $tokens[$i - 1]->is([\T_DOUBLE_COLON, \T_NEW])) {
                continue;
            }

            if (!isset($tokens[$i + 1]) || !$tokens[$i + 1]->is(\T_STRING)) {
                return null;
            }

            /** @var class-string $fqcn */
            $fqcn = $namespace === '' ? $tokens[$i + 1]->text : $namespace . '\\' . $tokens[$i + 1]->text;

            return $fqcn;
        }

        return null;
    }

    /**
     * The namespace name following a `T_NAMESPACE` token.
     *
     * @param list<\PhpToken> $tokens
     */
    private function readNamespace(array $tokens, int $start): string
    {
        $namespace = '';
        $count = \count($tokens);

        for ($i = $start + 1; $i < $count; $i++) {
            if ($tokens[$i]->text === ';' || $tokens[$i]->text === '{') {
                break;
            }

            if ($tokens[$i]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NS_SEPARATOR])) {
                $namespace .= $tokens[$i]->text;
            }
        }

        return $namespace;
    }
}
