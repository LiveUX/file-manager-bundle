<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use LiveUX\FileManagerBundle\Security\FileManagerVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class FileManagerVoterTest extends TestCase
{
    public function testBooleanPermissionRules(): void
    {
        $adm = $this->createStub(AccessDecisionManagerInterface::class);
        $voter = new FileManagerVoter($adm);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createStub(UserInterface::class));

        $profile = $this->createProfile(['upload' => true, 'delete' => false]);
        $subject = new FileManagerSubject($profile, 'products');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $subject, [FileManagerPermissions::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $subject, [FileManagerPermissions::DELETE]));
    }

    public function testBooleanTruePermissionIsDeniedForAnonymousToken(): void
    {
        $voter = new FileManagerVoter($this->createStub(AccessDecisionManagerInterface::class));
        $anonymous = $this->createStub(TokenInterface::class);
        $anonymous->method('getUser')->willReturn(null);

        $subject = new FileManagerSubject($this->createProfile(['upload' => true, 'delete' => true]), 'products');

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($anonymous, $subject, [FileManagerPermissions::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($anonymous, $subject, [FileManagerPermissions::DELETE]));
    }

    public function testRoleListPermissionRuleGrantsMatchingRole(): void
    {
        $adm = $this->createStub(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => $attributes === ['ROLE_ADMIN']
        );

        $voter = new FileManagerVoter($adm);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createStub(UserInterface::class));

        $profile = $this->createProfile(['delete' => ['ROLE_EDITOR', 'ROLE_ADMIN']]);
        $subject = new FileManagerSubject($profile, 'products');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $subject, [FileManagerPermissions::DELETE]));
    }

    /**
     * @param array<string, mixed> $permissions
     */
    private function createProfile(array $permissions): ProfileConfig
    {
        return new ProfileConfig(
            name: 'test',
            basePath: '/tmp',
            theme: 'bootstrap5',
            locale: null,
            volumes: [],
            driver: 'LocalFileSystem',
            path: '/tmp',
            urlPrefix: '/uploads',
            private: false,
            driverOptions: [],
            ignoreDotFiles: true,
            excludePatterns: [],
            sortBy: 'name',
            sortDirection: 'asc',
            foldersFirst: true,
            uploadSubfolder: null,
            uploadAllow: [],
            uploadDeny: [],
            uploadMaxSize: '10M',
            overwritePolicy: 'rename',
            chunkUpload: [],
            naming: [],
            imageOptimization: [],
            thumbnail: [],
            trash: [],
            zip: [],
            ui: [],
            permissions: $permissions,
            security: ['voter_enabled' => true],
            cache: [],
            metadata: [],
        );
    }
}
