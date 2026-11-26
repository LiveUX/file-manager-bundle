<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\EventListener;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\FolderCreatedEvent;
use LiveUX\FileManagerBundle\Event\FolderDeletedEvent;
use LiveUX\FileManagerBundle\Event\ItemClonedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use LiveUX\FileManagerBundle\Event\TrashPurgedEvent;
use LiveUX\FileManagerBundle\Event\TrashRestoredEvent;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Optional structured PSR-3 audit trail listener for FileManager mutations.
 * Disabled by default (defaults.audit_log.enabled: false); can be toggled globally
 * or per-profile (profiles.<name>.security.audit_log: true|false).
 * Degrades to a silent no-op if no PSR-3 logger is available.
 */
final readonly class AuditLogListener implements EventSubscriberInterface
{
    private const array ALLOWED_LEVELS = ['debug', 'info', 'notice', 'warning'];

    public function __construct(
        private bool $enabled = false,
        private string $level = 'info',
        private ?LoggerInterface $logger = null,
        private ?Security $security = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onFileUploaded',
            FileDeletedEvent::class => 'onFileDeleted',
            FolderCreatedEvent::class => 'onFolderCreated',
            FolderDeletedEvent::class => 'onFolderDeleted',
            ItemRenamedEvent::class => 'onItemRenamed',
            ItemMovedEvent::class => 'onItemMoved',
            ItemClonedEvent::class => 'onItemCloned',
            TrashRestoredEvent::class => 'onTrashRestored',
            TrashPurgedEvent::class => 'onTrashPurged',
        ];
    }

    public function onFileUploaded(FileUploadedEvent $event): void
    {
        $this->record($event, 'file.uploaded', [
            'size' => $event->size,
            'mime' => $event->mimeType,
        ]);
    }

    public function onFileDeleted(FileDeletedEvent $event): void
    {
        $this->record($event, 'file.deleted', [
            'movedToTrash' => $event->movedToTrash,
        ]);
    }

    public function onFolderCreated(FolderCreatedEvent $event): void
    {
        $this->record($event, 'folder.created');
    }

    public function onFolderDeleted(FolderDeletedEvent $event): void
    {
        $this->record($event, 'folder.deleted', [
            'movedToTrash' => $event->movedToTrash,
        ]);
    }

    public function onItemRenamed(ItemRenamedEvent $event): void
    {
        $this->record($event, $event->isFolder ? 'folder.renamed' : 'file.renamed', [
            'newPath' => $event->newPath,
        ]);
    }

    public function onItemMoved(ItemMovedEvent $event): void
    {
        $verb = $event->isCopy ? 'copied' : 'moved';
        $kind = $event->isFolder ? 'folder' : 'file';
        $this->record($event, $kind . '.' . $verb, [
            'targetVolume' => $event->targetVolume->name,
            'targetPath' => $event->targetPath,
        ]);
    }

    public function onItemCloned(ItemClonedEvent $event): void
    {
        $this->record($event, 'item.cloned', [
            'clonedPath' => $event->clonedPath,
        ]);
    }

    public function onTrashRestored(TrashRestoredEvent $event): void
    {
        $this->record($event, 'trash.restored', [
            'restoredPath' => $event->path,
        ]);
    }

    public function onTrashPurged(TrashPurgedEvent $event): void
    {
        $this->record($event, 'trash.purged', [
            'purgedCount' => $event->purgedCount,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function record(AbstractFileManagerEvent $event, string $action, array $extra = []): void
    {
        if ($this->logger === null || !$this->isEnabledForProfile($event->profile)) {
            return;
        }

        $request = $this->requestStack?->getCurrentRequest();
        $safePath = $this->sanitizeLogString($event->path);
        $rawUser = $this->security?->getUser()?->getUserIdentifier() ?? 'anonymous';
        $safeUser = $this->sanitizeLogString($rawUser);
        $sanitizedExtra = [];
        foreach ($extra as $k => $v) {
            $sanitizedExtra[$k] = is_string($v) ? $this->sanitizeLogString($v) : $v;
        }
        $context = [
            'action' => $action,
            'profile' => $this->sanitizeLogString($event->profile->name),
            'volume' => $this->sanitizeLogString($event->volume->name),
            'path' => $safePath,
            'user' => $safeUser,
            'ip' => $this->sanitizeLogString($request?->getClientIp() ?? 'cli'),
            ...$sanitizedExtra,
        ];

        $safeLevel = in_array($this->level, self::ALLOWED_LEVELS, true) ? $this->level : 'info';
        $this->logger->log($safeLevel, '[LiveUX FileManager Audit] {action} "{path}"', $context);
    }

    private function sanitizeLogString(string $value): string
    {
        $scrubbed = mb_scrub($value, 'UTF-8');
        $clean = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '?', $scrubbed);

        return is_string($clean) ? $clean : str_replace(["\r", "\n", "\0"], '?', $scrubbed);
    }

    private function isEnabledForProfile(ProfileConfig $profile): bool
    {
        $override = $profile->security['audit_log'] ?? null;
        if (is_bool($override)) {
            return $override;
        }

        return $this->enabled;
    }
}
