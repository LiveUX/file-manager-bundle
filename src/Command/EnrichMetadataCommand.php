<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use LiveUX\FileManagerBundle\Service\MessageTranslator;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'file-manager:ai:enrich',
    description: 'Batch-enriches file metadata (alt, title, caption, tags) using the configured AssetEnricherInterface / Symfony AI bridge.',
)]
final class EnrichMetadataCommand extends Command
{
    private const array SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'md', 'json', 'csv', 'yaml', 'yml'];
    private const int MAX_SOURCE_BYTES = 20_971_520; // Matches AssetEnrichmentService::MAX_SOURCE_BYTES (20 MB)

    public function __construct(
        private readonly ProfileFileScanner $scanner,
        private readonly AssetEnrichmentService $enrichmentService,
        private readonly FolderMetadataStorageInterface $metadataStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (processes all AI-enabled profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional subfolder path filter')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Override output language locale (e.g. tr, en, fr, ja)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing alt/title/caption/tags instead of only filling missing fields')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of files to enrich in one run (cost protection)', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview target files without calling the AI provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->enrichmentService->hasActiveEnricher()) {
            $io->warning('No AssetEnricherInterface service is configured. Install `symfony/ai-bundle` or register a custom AssetEnricherInterface to use this command.');

            return Command::SUCCESS;
        }

        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $pathOpt = $input->getOption('path');
        $localeOpt = $input->getOption('locale');
        $limitOpt = $input->getOption('limit');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $subPath = is_string($pathOpt) ? trim($pathOpt) : '';
        $localeOverride = is_string($localeOpt) && trim($localeOpt) !== '' ? trim($localeOpt) : null;
        if ($localeOverride !== null && !MessageTranslator::isValidLocaleString($localeOverride)) {
            $io->error(sprintf('Invalid --locale value "%s". Expected a valid BCP-47 / ICU locale tag (e.g. en, tr, fr_FR, zh-Hans-CN).', $localeOverride));

            return Command::FAILURE;
        }
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $rawLimit = is_string($limitOpt) ? trim($limitOpt) : '50';
        if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
            $io->error(sprintf('Invalid --limit value "%s". Expected a positive integer.', (string) $limitOpt));

            return Command::FAILURE;
        }
        $limit = (int) $rawLimit;

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('LiveUX FileManager — Batch AI Metadata Enrichment');

        $rows = [];
        $totalEnriched = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $processedGlobal = 0;
        $matchedVolumes = 0;

        foreach ($profiles as $profile) {
            if (($profile->metadata['enabled'] ?? true) !== true) {
                $io->note(sprintf('Skipping profile "%s" (metadata.enabled is false).', $profile->name));
                continue;
            }
            if (!$this->enrichmentService->isAvailableForProfile($profile)) {
                $io->note(sprintf('Skipping profile "%s" (ai.enabled is false).', $profile->name));
                continue;
            }

            try {
                $volumes = $this->scanner->resolveTargetVolumes($profile, $volumeFilter);
            } catch (FileManagerException $e) {
                if ($profileName === null && $volumeFilter !== null) {
                    continue;
                }
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            foreach ($volumes as $volume) {
                ++$matchedVolumes;
                if ($this->scanner->volumeUsesUserIdToken($profile, $volume->name)) {
                    $io->note(sprintf('Skipping volume "%s" on profile "%s" (uses runtime {user_id} token).', $volume->name, $profile->name));
                    continue;
                }
                if ($volume->private && ($profile->ai['block_private_volumes'] ?? true) === true) {
                    $io->note(sprintf('Skipping private volume "%s" on profile "%s" (ai.block_private_volumes is true).', $volume->name, $profile->name));
                    continue;
                }
                try {
                    $stats = $this->processVolume($io, $profile, $volume, $subPath, $localeOverride, $force, $dryRun, $limit, $processedGlobal);
                } catch (FileManagerException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
                $rows[] = [$profile->name, $volume->name, $stats['enriched'], $stats['skipped'], $stats['errors']];
                $totalEnriched += $stats['enriched'];
                $totalSkipped += $stats['skipped'];
                $totalErrors += $stats['errors'];

                if ($processedGlobal >= $limit) {
                    break 2;
                }
            }
        }

        if ($volumeFilter !== null && $matchedVolumes === 0) {
            $io->error(sprintf('Volume "%s" is not attached to any matched AI-enabled profile.', $volumeFilter));

            return Command::FAILURE;
        }

        if ($rows !== []) {
            $io->table(['Profile', 'Volume', 'Enriched', 'Skipped', 'Errors'], $rows);
        }

        $prefix = $dryRun ? '[Dry-Run] ' : '';
        $summary = sprintf('%sDone: %d enriched, %d skipped, %d error(s).', $prefix, $totalEnriched, $totalSkipped, $totalErrors);
        if ($totalErrors > 0 && $totalEnriched === 0) {
            $io->error($summary);

            return Command::FAILURE;
        }
        if ($totalErrors > 0) {
            $io->warning($summary);

            return Command::SUCCESS;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * @return array{enriched: int, skipped: int, errors: int}
     */
    private function processVolume(
        SymfonyStyle $io,
        ProfileConfig $profile,
        \LiveUX\FileManagerBundle\Config\VolumeConfig $volume,
        string $subPath,
        ?string $localeOverride,
        bool $force,
        bool $dryRun,
        int $limit,
        int &$processedGlobal,
    ): array {
        $stats = ['enriched' => 0, 'skipped' => 0, 'errors' => 0];
        $canPreload = !$force && method_exists($this->metadataStorage, 'preload');
        $batch = [];

        foreach ($this->scanner->iterateRasterImages($profile, $volume, $subPath, self::SUPPORTED_EXTENSIONS) as $item) {
            if ($processedGlobal >= $limit) {
                break;
            }

            if ($item['size'] <= 0 || $item['size'] > self::MAX_SOURCE_BYTES) {
                ++$stats['skipped'];
                continue;
            }

            $batch[] = $item;
            if (count($batch) >= 250) {
                $this->processEnrichBatch($io, $profile, $volume->name, $batch, $localeOverride, $force, $dryRun, $limit, $processedGlobal, $stats, $canPreload);
                $batch = [];
            }
        }

        if ($batch !== [] && $processedGlobal < $limit) {
            $this->processEnrichBatch($io, $profile, $volume->name, $batch, $localeOverride, $force, $dryRun, $limit, $processedGlobal, $stats, $canPreload);
        }

        return $stats;
    }

    /**
     * @param list<array{path: string, canonical: string, extension: string, size: int}> $batch
     * @param array{enriched: int, skipped: int, errors: int}                           $stats
     */
    private function processEnrichBatch(
        SymfonyStyle $io,
        ProfileConfig $profile,
        string $volumeName,
        array $batch,
        ?string $localeOverride,
        bool $force,
        bool $dryRun,
        int $limit,
        int &$processedGlobal,
        array &$stats,
        bool $canPreload,
    ): void {
        if ($canPreload && method_exists($this->metadataStorage, 'preload')) {
            $this->metadataStorage->preload(array_map(static fn (array $item): string => $item['canonical'], $batch));
        }

        foreach ($batch as $item) {
            if ($processedGlobal >= $limit) {
                break;
            }

            if (!$force && $this->hasExistingEditorialMetadata($item['canonical'])) {
                ++$stats['skipped'];
                continue;
            }

            $itemExt = PathNormalizer::extensionUtf8($item['path']);
            $candidateMimes = $itemExt !== '' ? \Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($itemExt) : [];
            if ($candidateMimes !== [] && !$this->supportsAnyCandidateMime(array_values($candidateMimes))) {
                ++$stats['skipped'];
                continue;
            }

            ++$processedGlobal;
            if ($dryRun) {
                $io->text(sprintf('  [dry-run] %s/%s (%s)', $volumeName, $item['path'], PathNormalizer::extensionUtf8($item['path'])));
                ++$stats['enriched'];
                continue;
            }

            try {
                $result = $this->enrichmentService->enrichAndSave(
                    profile: $profile,
                    path: $item['path'],
                    volumeName: $volumeName,
                    onlyMissing: !$force,
                    localeOverride: $localeOverride,
                    skipVoter: true,
                );
                if (!$result->isEmpty()) {
                    ++$stats['enriched'];
                } else {
                    ++$stats['skipped'];
                }
            } catch (Throwable $e) {
                ++$stats['errors'];
                $io->warning(sprintf('Failed "%s": %s', $item['path'], $e->getMessage()));
            }
        }
    }

    private function hasExistingEditorialMetadata(string $canonical): bool
    {
        $meta = $this->metadataStorage->get($canonical);

        return $meta->alt !== null && ($meta->tags !== [] || $meta->title !== null || $meta->caption !== null);
    }

    /**
     * @param list<string> $mimes
     */
    private function supportsAnyCandidateMime(array $mimes): bool
    {
        foreach ($mimes as $mime) {
            if ($this->enrichmentService->supportsMime($mime)) {
                return true;
            }
        }

        return false;
    }
}
