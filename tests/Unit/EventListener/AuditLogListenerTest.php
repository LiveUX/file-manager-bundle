<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\EventListener;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Config\YamlConfigProvider;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\EventListener\AuditLogListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogListenerTest extends TestCase
{
    public function testSilentWhenDisabledAndLogsStructuredContextWhenEnabled(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/upload', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));

        $volume = new VolumeConfig('local', 'Local', 'local', 'LocalFileSystem', '/tmp');
        $defaultProfile = $this->createProfile([]);
        $optInProfile = $this->createProfile(['audit_log' => true]);
        $optOutProfile = $this->createProfile(['audit_log' => false]);

        // 1. Disabled globally -> logs nothing for defaultProfile, logs for optInProfile
        $disabledListener = new AuditLogListener(false, 'info', $logger, null, $requestStack);
        $disabledListener->onFileUploaded(new FileUploadedEvent($defaultProfile, $volume, 'a.jpg', 'local::a.jpg', 1234, 'image/jpeg'));
        self::assertCount(0, $logger->records);

        $disabledListener->onFileUploaded(new FileUploadedEvent($optInProfile, $volume, 'b.jpg', 'local::b.jpg', 5678, 'image/jpeg'));
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('file.uploaded', $logger->records[0]['context']['action']);
        self::assertSame('203.0.113.10', $logger->records[0]['context']['ip']);
        self::assertSame('anonymous', $logger->records[0]['context']['user']);
        self::assertSame(5678, $logger->records[0]['context']['size']);

        // 2. Enabled globally -> logs for defaultProfile, skipped for optOutProfile
        $enabledListener = new AuditLogListener(true, 'notice', $logger, null, $requestStack);
        $enabledListener->onFileDeleted(new FileDeletedEvent($optOutProfile, $volume, 'c.jpg', 'local::c.jpg', true));
        self::assertCount(1, $logger->records);

        $enabledListener->onFileDeleted(new FileDeletedEvent($defaultProfile, $volume, 'd.jpg', 'local::d.jpg', false));
        self::assertCount(2, $logger->records);
        self::assertSame('notice', $logger->records[1]['level']);
        self::assertSame('file.deleted', $logger->records[1]['context']['action']);
        self::assertFalse($logger->records[1]['context']['movedToTrash']);
    }

    /**
     * @param array<string, mixed> $security
     */
    private function createProfile(array $security): ProfileConfig
    {
        $provider = new YamlConfigProvider(
            ['theme' => 'bootstrap5', 'locale' => 'en', 'base_path' => '/tmp', 'url_prefix' => '/uploads'],
            ['local' => ['label' => 'Local', 'type' => 'local', 'driver' => 'LocalFileSystem', 'path' => '/tmp', 'url_prefix' => '/uploads', 'private' => false, 'options' => []]],
            ['default' => ['volumes' => ['local'], 'security' => $security]],
        );

        return $provider->getProfile('default');
    }
}
