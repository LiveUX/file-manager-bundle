<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Usage;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Opt-in contract for reporting where a file or folder is actively referenced
 * in the host application (e.g., Doctrine entities, CMS blocks, sliders).
 *
 * Host applications may implement one or more checkers; Symfony autoconfiguration
 * automatically tags every implementation with "liveux_file_manager.usage_checker".
 */
#[AutoconfigureTag('liveux_file_manager.usage_checker')]
interface AssetUsageCheckerInterface
{
    /**
     * Returns all known references to the given asset path inside the volume.
     *
     * @param string $path     Normalized path relative to the volume root (e.g., "catalog/thermos.jpg").
     * @param bool   $isFolder True when the target being deleted is a directory.
     *
     * @return list<AssetUsageReference>
     */
    public function findUsages(
        ProfileConfig $profile,
        VolumeConfig $volume,
        string $path,
        bool $isFolder = false,
    ): array;
}
