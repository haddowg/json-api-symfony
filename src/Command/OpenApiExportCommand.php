<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Command;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports a server's OpenAPI 3.1 document to JSON or YAML — to a file, or to stdout.
 * The CLI export is **always available** (independent of the
 * HTTP expose gate), so a CI pipeline can spec-diff or publish the document with no
 * web exposure.
 *
 * `--format=yaml` requires `symfony/yaml` (a suggested dependency); without it the
 * command fails with a clear message rather than emitting broken output.
 *
 * `--compact` writes the JSON on one line, with the same encoding as the pretty default.
 * YAML has no compact form, so `--compact` with `--format=yaml` is rejected.
 *
 * Each operation example the projector could not build is printed as a
 * warning on stderr, so stdout stays a clean document. An output with no separate stderr
 * gets them only when the document goes to a file. `--strict` turns any of them into a
 * failure and writes no document.
 */
#[AsCommand(
    name: 'json-api:openapi:export',
    description: 'Export a server\'s OpenAPI 3.1 document (JSON or YAML) to a file or stdout.',
)]
final class OpenApiExportCommand extends Command
{
    public function __construct(private readonly DocumentFactory $documents)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'The JSON:API server name to export.', ServerProvider::DEFAULT_SERVER)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json or yaml.', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout.')
            ->addOption('compact', null, InputOption::VALUE_NONE, 'Write the JSON on one line instead of pretty-printing it (JSON only).')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail, writing no document, when any operation is missing its generated example or needs a scope the document does not declare.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverOption = $input->getOption('server');
        $server = \is_string($serverOption) ? $serverOption : ServerProvider::DEFAULT_SERVER;
        $formatOption = $input->getOption('format');
        $format = \strtolower(\is_string($formatOption) ? $formatOption : 'json');
        $outputFile = $input->getOption('output');
        $outputFile = \is_string($outputFile) ? $outputFile : null;

        if (!\in_array($format, ['json', 'yaml', 'yml'], true)) {
            $io->error(\sprintf('Unsupported format "%s" (use json or yaml).', $format));

            return Command::INVALID;
        }

        $isYaml = $format === 'yaml' || $format === 'yml';
        $compact = $input->getOption('compact') === true;
        if ($isYaml && $compact) {
            $io->error('The --compact option only applies to JSON output; drop it or export with --format=json.');

            return Command::INVALID;
        }

        if ($isYaml && !\class_exists(Yaml::class)) {
            $io->error('YAML export requires symfony/yaml; install it (composer require symfony/yaml) or export JSON.');

            return Command::FAILURE;
        }

        try {
            $projection = $this->documents->projectionForServer($server);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not build the OpenAPI document for server "%s": %s', $server, $e->getMessage()));

            return Command::FAILURE;
        }

        $document = $projection->document;
        $missing = $projection->missingExamples;
        $strict = $input->getOption('strict') === true;
        // Without a separate stderr a warning would land in a document piped to stdout,
        // so it is only printed there when the document goes to a file or none is written.
        $warnings = $output instanceof ConsoleOutputInterface || $outputFile !== null || $strict
            ? $io->getErrorStyle()
            : null;
        if ($missing !== [] && $warnings !== null) {
            $warnings->warning(\sprintf(
                '%d value(s) have no declared example, so the operations that need them have no generated example:',
                \count($missing),
            ));
            $warnings->listing(\array_map(static fn(\Stringable $miss): string => (string) $miss, $missing));
        }

        $undeclared = $projection->undeclaredScopes;
        if ($undeclared !== [] && $warnings !== null) {
            $warnings->warning(\sprintf(
                '%d scope(s) are required but declared by neither the scope catalogue nor the scope scheme\'s flows:',
                \count($undeclared),
            ));
            $warnings->listing(\array_map(static fn(\Stringable $scope): string => (string) $scope, $undeclared));
        }

        if ($projection->scopeScheme !== null) {
            $warnings?->warning((string) $projection->scopeScheme);
        }

        if ($missing !== [] && $strict) {
            $io->getErrorStyle()->error('Wrote no document: --strict fails on a missing example. Declare the examples listed above, or switch the operation\'s example off.');

            return Command::FAILURE;
        }

        if ($undeclared !== [] && $strict) {
            $io->getErrorStyle()->error('Wrote no document: --strict fails on an undeclared scope. Describe each scope listed above in your ScopeCatalogInterface service or the scope scheme\'s flows.');

            return Command::FAILURE;
        }

        $rendered = $isYaml
            ? Yaml::dump($document->toArray(), 16, 2, Yaml::DUMP_OBJECT_AS_MAP)
            : $document->toJsonString(!$compact) . "\n";

        if ($outputFile === null) {
            // Write straight to the output stream (no SymfonyStyle decoration) so a
            // piped/redirected document is byte-clean.
            $output->write($rendered);

            return Command::SUCCESS;
        }

        if (\file_put_contents($outputFile, $rendered) === false) {
            $io->error(\sprintf('Could not write to "%s".', $outputFile));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote the OpenAPI document for server "%s" to %s.', $server, $outputFile));

        return Command::SUCCESS;
    }
}
