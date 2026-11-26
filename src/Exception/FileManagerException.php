<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Exception;

use RuntimeException;
use Throwable;

class FileManagerException extends RuntimeException
{
    /**
     * @param array<string, int|string> $translationParams
     */
    public function __construct(
        string $message = '',
        private readonly ?string $translationKey = null,
        private readonly array $translationParams = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getTranslationKey(): ?string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, int|string>
     */
    public function getTranslationParams(): array
    {
        return $this->translationParams;
    }
}
