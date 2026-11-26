<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\DirectoryCacheManager;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
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
    name: 'file-manager:images:optimize',
    description: 'Retroactively optimizes existing raster images (JPEG/PNG/WebP) according to profile image_optimization settings.',
)]
final class OptimizeImagesCommand extends Command
{
    private const int MAX_SOURCE_BYTES = 26_214_400; // 25 MB safety cap
    private const array OPTIMIZABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private ?\LiveUX\FileManagerBundle\Security\FileSecurityGuard $securityGuardInstance = null;

    public function __construct(
        private readonly VolumeRegistry $volumeRegistry,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly DirectoryCacheManager $cacheManager,
        private readonly ProfileFileScanner $scanner,
        private readonly ?FolderMetadataStorageInterface $metadataStorage = null,
        private readonly PathNormalizer $pathNormalizer = new PathNormalizer(),
    ) {
        parent::__construct();
        $this->securityGuardInstance = new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (processes all profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional subfolder path filter')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Optimize even when image_optimization.enabled is false on the profile')
            ->addOption('allow-webp-rename', null, InputOption::VALUE_NONE, 'Allow converting .jpg/.png filenames to .webp (may break existing DB URLs if not updated)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of images to process in this run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate optimization and display estimated savings without modifying files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $diag = $this->imageOptimizer->getDiagnostics();
        if (!$diag['available']) {
            $io->warning('Neither PHP Imagick nor GD extension is available on this server. Image optimization skipped safely.');

            return Command::SUCCESS;
        }

        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $pathOpt = $input->getOption('path');
        $limitOpt = $input->getOption('limit');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $subPath = is_string($pathOpt) ? trim($pathOpt) : '';
        $force = (bool) $input->getOption('force');
        $allowWebpRename = (bool) $input->getOption('allow-webp-rename');
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

        $io->title(sprintf('LiveUX FileManager — Batch Image Optimization (driver=%s)', $diag['driver']));

        $rows = [];
        $totalOptimized = 0;
        $totalSkipped = 0;
        $totalSavedBytes = 0;
        $processedGlobal = 0;
        $matchedVolumes = 0;
        $seenPhysicalVolumes = [];

        foreach ($profiles as $profile) {
            $enabled = ($profile->imageOptimization['enabled'] ?? false) === true;
            if (!$enabled && !$force) {
                $io->note(sprintf('Skipping profile "%s" (image_optimization.enabled is false; pass --force to override).', $profile->name));
                continue;
            }

            $effectiveProfile = $this->buildEffectiveProfile($profile, $allowWebpRename);

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
                $physKey = $this->buildPhysicalVolumeDedupKey($volume);
                if ($profileName === null && isset($seenPhysicalVolumes[$physKey])) {
                    continue;
                }
                $seenPhysicalVolumes[$physKey] = true;
                try {
                    $stats = $this->optimizeVolume(
                        $io,
                        $profile,
                        $effectiveProfile,
                        $volume->name,
                        $subPath,
                        $dryRun,
                        $limit,
                        $processedGlobal,
                    );
                } catch (FileManagerException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
                $rows[] = [
                    $profile->name,
                    $volume->name,
                    $stats['optimized'],
                    $stats['skipped'],
                    $this->scanner->formatBytes($stats['savedBytes']),
                ];
                $totalOptimized += $stats['optimized'];
                $totalSkipped += $stats['skipped'];
                $totalSavedBytes += $stats['savedBytes'];

                if (!$dryRun && $stats['optimized'] > 0) {
                    $this->invalidateVolumeAndOverlaps($profile, $volume);
                }

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
            $io->table(['Profile', 'Volume', 'Optimized', 'Skipped', 'Space Saved'], $rows);
        }

        $prefix = $dryRun ? '[Dry-Run] ' : '';
        $io->success(sprintf(
            '%sDone: %d optimized, %d skipped, %s saved.',
            $prefix,
            $totalOptimized,
            $totalSkipped,
            $this->scanner->formatBytes($totalSavedBytes),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{optimized: int, skipped: int, savedBytes: int}
     */
    private function optimizeVolume(
        SymfonyStyle $io,
        ProfileConfig $originalProfile,
        ProfileConfig $effectiveProfile,
        string $volumeName,
        string $subPath,
        bool $dryRun,
        int $limit,
        int &$processedGlobal,
    ): array {
        $volume = $this->volumeRegistry->getVolumeForProfile($originalProfile, $volumeName);
        $filesystem = $this->volumeRegistry->getFilesystem($originalProfile, $volume->name);
        $stats = ['optimized' => 0, 'skipped' => 0, 'savedBytes' => 0];
        $bar = $io->createProgressBar();
        $bar->start();

        $writtenPaths = [];
        foreach ($this->scanner->iterateRasterImages($originalProfile, $volume, $subPath, self::OPTIMIZABLE_EXTENSIONS) as $img) {
            if ($limit > 0 && $processedGlobal >= $limit) {
                break;
            }
            if (isset($writtenPaths[$img['path']])) {
                continue;
            }

            if ($img['size'] > self::MAX_SOURCE_BYTES) {
                ++$stats['skipped'];
                $bar->advance();
                continue;
            }

            $allowRename = ($effectiveProfile->imageOptimization['convert_to_webp'] ?? false) === true;
            $saved = $this->optimizeSingleImage($filesystem, $originalProfile, $effectiveProfile, $volume, $img, $dryRun, $allowRename, $writtenPaths);
            if ($saved !== null) {
                ++$processedGlobal;
                ++$stats['optimized'];
                $stats['savedBytes'] += max(0, $saved);
            } else {
                ++$stats['skipped'];
            }
            $bar->advance();
        }

        $bar->finish();
        $io->newLine(2);

        return $stats;
    }

    /**
     * @param array{path: string, canonical: string, extension: string, size: int} $img
     * @param array<string, bool>                                                  $writtenPaths
     */
    private function optimizeSingleImage(
        \League\Flysystem\FilesystemOperator $filesystem,
        ProfileConfig $originalProfile,
        ProfileConfig $effectiveProfile,
        VolumeConfig $volume,
        array $img,
        bool $dryRun,
        bool $allowRename = false,
        array &$writtenPaths = [],
    ): ?int {
        $tempFile = tempnam(sys_get_temp_dir(), 'liveux_fm_opt_');
        if ($tempFile === false) {
            return null;
        }

        try {
            $originalSize = $this->copyBoundedStreamToTemp($filesystem, $img['path'], $tempFile);
            if ($originalSize === null || $originalSize <= 0 || $originalSize > self::MAX_SOURCE_BYTES) {
                return null;
            }

            $origInfo = @getimagesize($tempFile);
            $origW = is_array($origInfo) ? (int) $origInfo[0] : 0;
            $origH = is_array($origInfo) ? (int) $origInfo[1] : 0;

            $mime = $this->detectSourceMime($tempFile, $origInfo, $img['extension']);
            $filename = PathNormalizer::basenameUtf8($img['path']);
            $result = $this->imageOptimizer->optimizeIfEnabled($tempFile, $filename, $mime, $effectiveProfile);

            if (!$result['optimized']) {
                return null;
            }

            $newInfo = @getimagesize($result['path']);
            $newW = is_array($newInfo) ? (int) $newInfo[0] : $origW;
            $newH = is_array($newInfo) ? (int) $newInfo[1] : $origH;
            $resized = ($newW !== $origW || $newH !== $origH);
            $renamed = $result['filename'] !== $filename;

            // Never rename/delete source images (e.g. .webp -> .jpg GD fallback) unless --allow-webp-rename was explicitly requested.
            if ($renamed && !$allowRename) {
                return null;
            }

            // Avoid replacing when output got larger and dimensions/format didn't change
            if ($result['size'] >= $originalSize && !$resized && !$renamed) {
                return null;
            }

            $dir = PathNormalizer::dirnameUtf8($img['path']);
            $targetPath = $dir === '' ? $result['filename'] : $dir . '/' . $result['filename'];

            $securityGuard = $this->securityGuardInstance ?? new \LiveUX\FileManagerBundle\Security\FileSecurityGuard();
            $securityGuard->assertSafeFilename($result['filename']);
            if ($renamed && $targetPath !== $img['path']) {
                $targetMime = strtolower(trim(explode(';', $result['mime'], 2)[0]));
                if ($securityGuard->matchesMimeList($targetMime, $originalProfile->uploadDeny)) {
                    return null;
                }
                if ($originalProfile->uploadAllow !== [] && !$securityGuard->matchesMimeList($targetMime, $originalProfile->uploadAllow)) {
                    return null;
                }
                if ($securityGuard->isPathIgnored($targetPath, $originalProfile)) {
                    return null;
                }
            }

            // Never silently overwrite an unrelated existing .webp file during rename.
            if ($renamed && $targetPath !== $img['path'] && ($filesystem->fileExists($targetPath) || $filesystem->directoryExists($targetPath))) {
                return null;
            }

            if (!$dryRun) {
                $outStream = fopen($result['path'], 'rb');
                if ($outStream === false) {
                    return null;
                }
                try {
                    $filesystem->writeStream($targetPath, $outStream);
                } finally {
                    fclose($outStream);
                }
                $writtenPaths[$targetPath] = true;

                if ($renamed && $targetPath !== $img['path']) {
                    $filesystem->delete($img['path']);
                    $newCanonical = $this->pathNormalizer->buildCanonicalKey($volume, $targetPath, $originalProfile->basePath);
                    $this->metadataStorage?->relocatePrefix($img['canonical'], $newCanonical);
                    $this->deleteThumbnailAcrossProfiles($originalProfile, $newCanonical, $volume, $targetPath);
                }

                $this->deleteThumbnailAcrossProfiles($originalProfile, $img['canonical'], $volume, $img['path']);
            }

            return max(0, $originalSize - $result['size']);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    private function invalidateVolumeAndOverlaps(ProfileConfig $profile, VolumeConfig $mutatedVolume): void
    {
        $mutatedRoot = $this->pathNormalizer->buildCanonicalKey($mutatedVolume, '', $profile->basePath);
        $mutatedScope = $this->cacheManager->resolveVolumeScopeForRoot($mutatedRoot);
        $this->cacheManager->invalidateVolumeScope($mutatedScope);

        $seenScopes = [$mutatedScope => true];
        foreach ($this->scanner->resolveTargetProfiles(null) as $otherProfile) {
            try {
                $otherVolumes = $this->volumeRegistry->getVolumesForProfile($otherProfile);
            } catch (Throwable) {
                continue;
            }

            foreach ($otherVolumes as $otherVolume) {
                if (!$this->volumesOverlap($mutatedVolume, $otherVolume)) {
                    continue;
                }
                $otherRoot = $this->pathNormalizer->buildCanonicalKey($otherVolume, '', $otherProfile->basePath);
                $otherScope = $this->cacheManager->resolveVolumeScopeForRoot($otherRoot);
                if (!isset($seenScopes[$otherScope])) {
                    $seenScopes[$otherScope] = true;
                    $this->cacheManager->invalidateVolumeScope($otherScope);
                }
            }

            $this->invalidateDynamicTemplatesForProfile($otherProfile, $mutatedVolume);
        }
    }

    private function invalidateDynamicTemplatesForProfile(ProfileConfig $otherProfile, VolumeConfig $mutatedVolume): void
    {
        try {
            $rawVolumes = $this->volumeRegistry->getRawVolumesForProfile($otherProfile);
        } catch (Throwable) {
            return;
        }

        if ($mutatedVolume->isLocalFilesystem() && $mutatedVolume->path !== null) {
            $mutatedNorm = $this->normalizeComparablePath($mutatedVolume->path);
            if ($mutatedNorm === '') {
                return;
            }
            foreach ($rawVolumes as $rawVol) {
                if (!$rawVol->isLocalFilesystem() || !is_string($rawVol->path) || !str_contains($rawVol->path, '{')) {
                    continue;
                }
                $staticPrefix = $this->normalizeComparablePath(explode('{', $rawVol->path, 2)[0]);
                if (
                    $staticPrefix !== ''
                    && ($staticPrefix === $mutatedNorm || str_starts_with($staticPrefix, $mutatedNorm . '/') || str_starts_with($mutatedNorm, $staticPrefix . '/'))
                ) {
                    $this->cacheManager->invalidateProfile($otherProfile->name);

                    return;
                }
            }

            return;
        }

        if (strtolower($mutatedVolume->driver) === 'awss3') {
            $mutBucket = trim((string) ($mutatedVolume->driverOptions['bucket'] ?? ''));
            if ($mutBucket === '') {
                return;
            }
            $mutEp = PathNormalizer::normalizeS3Endpoint((string) ($mutatedVolume->driverOptions['endpoint'] ?? ''));
            $mutPref = trim((string) ($mutatedVolume->driverOptions['prefix'] ?? ''), '/');
            foreach ($rawVolumes as $rawVol) {
                if (strtolower($rawVol->driver) !== 'awss3') {
                    continue;
                }
                $candBucket = trim((string) ($rawVol->driverOptions['bucket'] ?? ''));
                $candEp = PathNormalizer::normalizeS3Endpoint((string) ($rawVol->driverOptions['endpoint'] ?? ''));
                $rawPref = (string) ($rawVol->driverOptions['prefix'] ?? '');
                if ($candBucket !== $mutBucket || $candEp !== $mutEp || !str_contains($rawPref, '{')) {
                    continue;
                }
                $staticPref = trim(explode('{', $rawPref, 2)[0], '/');
                if (
                    $mutPref === ''
                    || $staticPref === ''
                    || $staticPref === $mutPref
                    || str_starts_with($staticPref, $mutPref . '/')
                    || str_starts_with($mutPref, $staticPref . '/')
                ) {
                    $this->cacheManager->invalidateProfile($otherProfile->name);

                    return;
                }
            }
        }
    }

    private function buildPhysicalVolumeDedupKey(VolumeConfig $volume): string
    {
        $canonicalPath = $volume->path !== null ? $this->normalizeComparablePath($volume->path) : '';
        $endpoint = PathNormalizer::normalizeS3Endpoint((string) ($volume->driverOptions['endpoint'] ?? ''));

        return implode('|', [
            strtolower($volume->driver),
            $canonicalPath,
            $endpoint,
            trim((string) ($volume->driverOptions['bucket'] ?? '')),
            trim((string) ($volume->driverOptions['prefix'] ?? ''), '/'),
            trim((string) ($volume->driverOptions['service'] ?? '')),
        ]);
    }

    private function volumesOverlap(VolumeConfig $a, VolumeConfig $b): bool
    {
        if ($a->isLocalFilesystem() && $b->isLocalFilesystem() && $a->path !== null && $b->path !== null) {
            $normA = $this->normalizeComparablePath($a->path);
            $normB = $this->normalizeComparablePath($b->path);

            return $normA !== '' && $normB !== ''
                && ($normA === $normB || str_starts_with($normB, $normA . '/') || str_starts_with($normA, $normB . '/'));
        }

        if (strtolower($a->driver) === 'awss3' && strtolower($b->driver) === 'awss3') {
            $bucketA = trim((string) ($a->driverOptions['bucket'] ?? ''));
            $bucketB = trim((string) ($b->driverOptions['bucket'] ?? ''));
            $epA = PathNormalizer::normalizeS3Endpoint((string) ($a->driverOptions['endpoint'] ?? ''));
            $epB = PathNormalizer::normalizeS3Endpoint((string) ($b->driverOptions['endpoint'] ?? ''));
            if ($bucketA === '' || $bucketA !== $bucketB || $epA !== $epB) {
                return false;
            }
            $prefA = trim((string) ($a->driverOptions['prefix'] ?? ''), '/');
            $prefB = trim((string) ($b->driverOptions['prefix'] ?? ''), '/');

            return $prefA === '' || $prefB === '' || $prefA === $prefB
                || str_starts_with($prefB, $prefA . '/')
                || str_starts_with($prefA, $prefB . '/');
        }

        return false;
    }

    private function normalizeComparablePath(string $path): string
    {
        $real = @realpath($path);
        $resolved = is_string($real) ? $real : $path;
        $forward = rtrim(str_replace('\\', '/', $resolved), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($forward, 'UTF-8') : $forward;
    }

    private function copyBoundedStreamToTemp(
        \League\Flysystem\FilesystemOperator $filesystem,
        string $path,
        string $tempFile,
    ): ?int {
        $in = $filesystem->readStream($path);
        if (!is_resource($in)) {
            return null;
        }
        $out = @fopen($tempFile, 'wb');
        if ($out === false) {
            fclose($in);

            return null;
        }

        $total = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    if (feof($in)) {
                        break;
                    }

                    return null;
                }
                $len = strlen($chunk);
                $total += $len;
                if ($total > self::MAX_SOURCE_BYTES) {
                    return null;
                }
                $written = fwrite($out, $chunk);
                if ($written === false || $written !== $len) {
                    return null;
                }
            }

            return $total;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function deleteThumbnailAcrossProfiles(
        ProfileConfig $triggerProfile,
        string $canonical,
        ?VolumeConfig $volume = null,
        ?string $relativePath = null,
    ): void {
        $this->thumbnailManager->deleteCachedThumbnail($triggerProfile, $canonical);
        try {
            foreach ($this->scanner->resolveTargetProfiles(null) as $other) {
                if ($other->name === $triggerProfile->name) {
                    continue;
                }
                $targetCanonical = ($volume !== null && $relativePath !== null && $other->basePath !== $triggerProfile->basePath)
                    ? $this->pathNormalizer->buildCanonicalKey($volume, $relativePath, $other->basePath)
                    : $canonical;
                $this->thumbnailManager->deleteCachedThumbnail($other, $targetCanonical);
            }
        } catch (Throwable) {
        }
    }

    private function buildEffectiveProfile(ProfileConfig $profile, bool $allowWebpRename): ProfileConfig
    {
        $opt = $profile->imageOptimization;
        $opt['enabled'] = true;
        if (!$allowWebpRename) {
            $opt['convert_to_webp'] = false;
        }

        return $profile->withImageOptimization($opt);
    }

    /**
     * @param array<int|string, mixed>|false $imageInfo
     */
    private function detectSourceMime(string $tempFile, array|false $imageInfo, string $ext): string
    {
        if (is_array($imageInfo) && is_string($imageInfo['mime'] ?? null) && $imageInfo['mime'] !== '') {
            $detected = strtolower(trim(explode(';', $imageInfo['mime'], 2)[0]));
            if ($detected !== '') {
                return $detected;
            }
        }

        $guessed = \Symfony\Component\Mime\MimeTypes::getDefault()->guessMimeType($tempFile);
        if (is_string($guessed) && $guessed !== '' && $guessed !== 'application/octet-stream') {
            return strtolower(trim(explode(';', $guessed, 2)[0]));
        }

        return $this->extensionToMime($ext);
    }

    private function extensionToMime(string $ext): string
    {
        return match (strtolower($ext)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
