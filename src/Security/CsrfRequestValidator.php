<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CsrfRequestValidator
{
    public const string TOKEN_ID = 'liveux_file_manager';
    public const string HEADER_NAME = 'X-LiveUX-FM-CSRF';

    public function __construct(
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private bool $failClosedWhenUnavailable = false,
    ) {
    }

    public function getTokenValue(): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken(self::TOKEN_ID)->getValue();
    }

    public function assertValid(Request $request, ?ProfileConfig $profile = null): void
    {
        if ($profile !== null && ($profile->security['csrf_protection'] ?? true) !== true) {
            return;
        }

        if ($this->csrfTokenManager === null) {
            if ($this->failClosedWhenUnavailable) {
                throw new AccessDeniedException('CSRF protection is enabled on this profile, but Symfony CsrfTokenManagerInterface is unavailable.', 'errors.csrf_invalid');
            }

            return;
        }

        $submitted = $request->headers->get(self::HEADER_NAME);
        if (!is_string($submitted) || $submitted === '') {
            $postParams = $request->request->all();
            $formToken = $postParams['_token'] ?? null;
            $submitted = is_string($formToken) ? $formToken : '';
        }

        if ($submitted === '' || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submitted))) {
            throw new AccessDeniedException('Invalid or missing CSRF token.', 'errors.csrf_invalid');
        }
    }
}
