<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Command;

use haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exports a server's standalone per-type JSON Schema 2020-12 documents — the resource
 * object (`type` const, `id`, `attributes`, …) for one type to a file / stdout, or
 * every type to a directory (design §7, D11/D13).
 *
 * `--type` exports one type (stdout, or `--output=FILE`); omitting it exports every
 * type to `--output=DIR` (one `<type>.json` per type). The schema is the **same**
 * core {@see \haddowg\JsonApi\OpenApi\SchemaProjector} projection the OpenAPI document
 * uses, so the standalone artifact and the in-document component agree.
 */
#[AsCommand(
    name: 'json-api:json-schema:export',
    description: 'Export a server\'s per-type JSON Schema 2020-12 documents to a file, directory, or stdout.',
)]
final class JsonSchemaExportCommand extends Command
{
    public function __construct(private readonly JsonSchemaFactory $schemas)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'The JSON:API server name to export.', ServerProvider::DEFAULT_SERVER)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Export only this JSON:API type (default: every type).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file (single type) or directory (all types) instead of stdout.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverOption = $input->getOption('server');
        $server = \is_string($serverOption) ? $serverOption : ServerProvider::DEFAULT_SERVER;
        $type = $input->getOption('type');
        $type = \is_string($type) ? $type : null;
        $outputPath = $input->getOption('output');
        $outputPath = \is_string($outputPath) ? $outputPath : null;

        try {
            return $type !== null
                ? $this->exportOne($io, $output, $server, $type, $outputPath)
                : $this->exportAll($io, $output, $server, $outputPath);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not export JSON Schema for server "%s": %s', $server, $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function exportOne(SymfonyStyle $io, OutputInterface $output, string $server, string $type, ?string $outputPath): int
    {
        $json = $this->encode($this->schemas->forType($type, $server));

        if ($outputPath === null) {
            $output->write($json . "\n");

            return Command::SUCCESS;
        }

        if (\file_put_contents($outputPath, $json) === false) {
            $io->error(\sprintf('Could not write to "%s".', $outputPath));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote the JSON Schema for "%s" to %s.', $type, $outputPath));

        return Command::SUCCESS;
    }

    private function exportAll(SymfonyStyle $io, OutputInterface $output, string $server, ?string $outputPath): int
    {
        $documents = $this->schemas->forServer($server);

        if ($outputPath === null) {
            // No directory: emit a single JSON object keyed by type so stdout stays
            // one well-formed document.
            $output->write($this->encode((object) $documents) . "\n");

            return Command::SUCCESS;
        }

        if (!\is_dir($outputPath) && !@\mkdir($outputPath, 0o777, true) && !\is_dir($outputPath)) {
            $io->error(\sprintf('Could not create the output directory "%s".', $outputPath));

            return Command::FAILURE;
        }

        foreach ($documents as $type => $document) {
            $file = \rtrim($outputPath, '/') . '/' . $type . '.json';
            if (\file_put_contents($file, $this->encode($document)) === false) {
                $io->error(\sprintf('Could not write to "%s".', $file));

                return Command::FAILURE;
            }
        }

        $io->success(\sprintf('Wrote %d JSON Schema document(s) for server "%s" to %s.', \count($documents), $server, $outputPath));

        return Command::SUCCESS;
    }

    private function encode(\stdClass $document): string
    {
        return (string) \json_encode($document, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }
}
