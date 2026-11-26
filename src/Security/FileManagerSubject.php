<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use LiveUX\FileManagerBundle\Config\ProfileConfig;

final readonly class FileManagerSubject
{
    public function __construct(
        public ProfileConfig $profile,
        public string $path = '',
        public ?string $volume = null,
        public ?string $targetPath = null,
    ) {
    }
}
