<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'file-manager:thumbs:warmup',
    description: 'Pre-generates or rebuilds cached thumbnails for all profiles or a specific profile/volume/path.',
    aliases: ['file-manager:thumbs:generate', 'file-manager:thumbs:rebuild'],
)]
final class ThumbnailWarmupCommand extends Command
{
    private const int MAX_SOURCE_BYTES = 26_214_400; // 25 MB safety cap

    public function __construct(
        private readonly VolumeRegistry $volumeRegistry,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly ProfileFileScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (processes all profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional subfolder path filter')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force rebuild existing cached thumbnails')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of images to process in this run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview how many thumbnails would be generated without writing to disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $pathOpt = $input->getOption('path');
        $limitOpt = $input->getOption('limit');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $subPath = is_string($pathOpt) ? trim($pathOpt) : '';
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = 0;
        if ($limitOpt !== null) {
            $rawLimit = is_string($limitOpt) ? trim($limitOpt) : '';
            if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
                $io->error(sprintf('Invalid --limit value "%s". Expected a positive integer.', (string) $limitOpt));

                return Command::FAILURE;
            }
            $limit = (int) $rawLimit;
        }

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title($force ? 'LiveUX FileManager — Thumbnail Rebuild' : 'LiveUX FileManager — Thumbnail Warmup');

        $rows = [];
        $totals = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $processedGlobal = 0;
        $matchedVolumes = 0;

        foreach ($profiles as $profile) {
            $driver = $this->resolveDriverSafely($profile);
            if ($driver === null) {
                $io->warning(sprintf(
                    'Skipping profile "%s": thumbnails disabled or no PHP image driver (Imagick/GD) available.',
                    $profile->name,
                ));
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
                try {
                    $stats = $this->processVolume(
                        $io,
                        $profile,
                        $volume->name,
                        $subPath,
                        $force,
                        $dryRun,
                        $limit,
                        $processedGlobal,
                    );
                } catch (FileManagerException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
                $rows[] = [$profile->name, $volume->name, $stats['generated'], $stats['skipped'], $stats['failed']];
                $totals['generated'] += $stats['generated'];
                $totals['skipped'] += $stats['skipped'];
                $totals['failed'] += $stats['failed'];

                if ($limit > 0 && $processedGlobal >= $limit) {
                    break 2;
                }
            }
        }

        if ($volumeFilter !== null && $matchedVolumes === 0) {
            $io->error(sprintf('Volume "%s" is not attached to any matched profile.', $volumeFilter));

            return Command::FAILURE;
        }

        if ($rows !== []) {
            $io->table(['Profile', 'Volume', 'Generated', 'Skipped (Cached)', 'Failed'], $rows);
        }

        $prefix = $dryRun ? '[Dry-Run] ' : '';
        $summary = sprintf(
            '%sDone: %d generated, %d skipped, %d failed.',
            $prefix,
            $totals['generated'],
            $totals['skipped'],
            $totals['failed'],
        );

        if ($totals['failed'] > 0 && $totals['generated'] === 0) {
            $io->error($summary);

            return Command::FAILURE;
        }

        if ($totals['failed'] > 0) {
            $io->warning($summary);

            return Command::SUCCESS;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }

    /**
     * @return array{generated: int, skipped: int, failed: int}
     */
    private function processVolume(
        SymfonyStyle $io,
        ProfileConfig $profile,
        string $volumeName,
        string $subPath,
        bool $force,
        bool $dryRun,
        int $limit,
        int &$processedGlobal,
    ): array {
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
        $images = $this->scanner->iterateRasterImages($profile, $volume, $subPath);

        $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $bar = null;

        foreach ($images as $img) {
            if ($bar === null) {
                $bar = $io->createProgressBar();
                $bar->start();
            }
            if ($limit > 0 && $processedGlobal >= $limit) {
                break;
            }

            if (!$force && $this->thumbnailManager->hasCachedThumbnail($profile, $img['canonical'])) {
                ++$stats['skipped'];
                $bar->advance();
                continue;
            }

            if ($img['size'] > self::MAX_SOURCE_BYTES) {
                ++$stats['skipped'];
                $bar->advance();
                continue;
            }

            ++$processedGlobal;
            if ($dryRun) {
                ++$stats['generated'];
                $bar->advance();
                continue;
            }

            try {
                $binary = $this->readBoundedFile($filesystem, $img['path'], self::MAX_SOURCE_BYTES);
                if ($binary === null || $binary === '') {
                    ++$stats['skipped'];
                    $bar->advance();
                    continue;
                }
                $result = $force
                    ? $this->thumbnailManager->regenerate($profile, $img['canonical'], $binary)
                    : $this->thumbnailManager->generateIfMissing($profile, $img['canonical'], $binary);

                if ($result !== null) {
                    ++$stats['generated'];
                } else {
                    ++$stats['failed'];
                }
            } catch (Throwable) {
                ++$stats['failed'];
            }

            $bar->advance();
        }

        if ($bar !== null) {
            $bar->finish();
            $io->newLine(2);
        }

        return $stats;
    }

    private function resolveDriverSafely(ProfileConfig $profile): ?object
    {
        try {
            return $this->thumbnailManager->resolveDriver($profile);
        } catch (Throwable) {
            return null;
        }
    }

    private function readBoundedFile(\League\Flysystem\FilesystemOperator $filesystem, string $path, int $maxBytes): ?string
    {
        $stream = $filesystem->readStream($path);
        if (!is_resource($stream)) {
            return '';
        }

        try {
            $buffer = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    return '';
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }

                    return '';
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    return null;
                }
            }

            return $buffer;
        } finally {
            fclose($stream);
        }
    }
}
