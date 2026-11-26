# 06 — Sécurité & autorisations (RBAC & défense en profondeur)

[← Précédent : 05 — FormType & intégrations d'éditeurs](05-formtype-and-editor-integrations.md) | [Index](README.md) | [Suivant : 07 — Stockage cloud & configuration dynamique →](07-cloud-storage-and-dynamic-config.md)

Les gestionnaires de fichiers constituent l'une des surfaces d'attaque les plus sensibles d'une application web. LiveUX FileManagerBundle est conçu autour d'une architecture de **défense en profondeur**, avec des protections multicouches activées par défaut.

> **En un coup d'œil : verrouiller la sécurité en 3 étapes**  
> 1. **Protéger les routes (`security.yaml`) :** Empêchez les visiteurs non authentifiés d'accéder aux endpoints du gestionnaire de fichiers.  
> 2. **Définir les permissions par rôle (`permissions`) :** Indiquez explicitement dans chaque profil qui peut consulter (`view`), envoyer (`upload`) et supprimer (`delete`).  
> 3. **Ajouter un `prefix` de route pour les applications multi-pare-feu :** Si votre application utilise des pare-feu distincts pour un back-office (`/admin`) et les utilisateurs publics (`/`), montez les routes du bundle sous `prefix: /admin` dans `config/routes/liveux_file_manager.yaml` afin que la session administrateur soit correctement résolue et que les membres classiques ne puissent pas atteindre les fichiers d'administration.

---

## 1. Protéger les routes dans `security.yaml` (première étape)

En production, protégez systématiquement tous les endpoints sous `/_liveux-file-manager` derrière le pare-feu de Symfony :

```yaml
# config/packages/security.yaml (Applications à pare-feu unique)
security:
    access_control:
        # À laisser public uniquement si vous utilisez des liens de partage signés pour des destinataires externes :
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Si vous disposez de deux pare-feu distincts — par ex. une zone d'administration (`/admin`) et les membres standards du site (`/`) :**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Autorisations par profil (`permissions` & `FileManagerVoter`)

Chaque profil vous permet de définir des règles d'accès indépendantes pour **17 opérations distinctes**.

### Que signifient les valeurs de permission ?
- **`true` :** **Utilisateurs authentifiés uniquement (`$token->getUser() !== null`) !** *(N'accorde JAMAIS l'accès aux visiteurs anonymes.)*
- **`false` :** Personne (l'opération est entièrement désactivée).
- **`'ROLE_ADMIN'` :** Uniquement les utilisateurs possédant ce rôle précis.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']` :** Les utilisateurs possédant l'un des rôles listés.

### Les 4 règles d'or des autorisations (appliquées automatiquement)
1. **`permissions.view` est la porte d'entrée principale :** Si un utilisateur n'a pas la permission `view`, les opérations `upload`, `delete`, `download` et toutes les autres lui sont automatiquement refusées.
2. **Sécurité par défaut — *Fail-Closed* (`FileManagerAuthorizer`) :** Lorsque `security.voter_enabled: true` est activé mais que `AuthorizationCheckerInterface` (c'est-à-dire `symfony/security-bundle`) n'est pas installé, le bundle **refuse l'accès par sécurité (`AccessDeniedException`)** au lieu de laisser passer les requêtes.
3. **Vérification de la chaîne des dossiers parents (`isPathAndAncestorsGranted`) :** Lorsqu'un utilisateur accède à `confidential/2026/invoice.pdf`, vos Voters personnalisés sont automatiquement évalués non seulement sur `confidential/2026/invoice.pdf`, mais aussi sur ses dossiers parents **`confidential`** et **`confidential/2026`**, empêchant de contourner un répertoire restreint en devinant une URL.
4. **Double verrou pour l'extraction ZIP (`zip.allow_extract`) :** Tant que `zip.allow_extract: false`, l'extraction d'archives est refusée côté serveur quelle que soit la valeur de `permissions.extract_zip`.

### Référence complète des 17 permissions du Voter

| Clé YAML | Constante du Voter (`FileManagerPermissions::*`) | Opérations protégées |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Listage de répertoires, arborescence, miniatures, aperçus en ligne, modales et **prérequis pour toutes les autres permissions** |
| `upload` | `FILE_MANAGER_UPLOAD` | Envois de fichiers, uploads par morceaux, imports par URL distante, enregistrements depuis l'éditeur d'images |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Création de nouveaux dossiers |
| `rename` | `FILE_MANAGER_RENAME` | Renommage de fichiers et dossiers |
| `clone` | `FILE_MANAGER_CLONE` | Clonage/duplication de fichiers et dossiers |
| `move` | `FILE_MANAGER_MOVE` | Déplacement d'éléments (glisser-déposer et modale Déplacer — les chemins source et destination sont tous deux vérifiés) |
| `copy` | `FILE_MANAGER_COPY` | Copie d'éléments (les chemins source et destination sont tous deux vérifiés) |
| `delete` | `FILE_MANAGER_DELETE` | Suppression d'éléments (corbeille / suppression définitive) et vérifications `checkUsage` |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Restauration d'éléments depuis la corbeille |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Suppression définitive d'éléments ou vidage de la corbeille |
| `download` | `FILE_MANAGER_DOWNLOAD` | Téléchargement d'un fichier, diffusion PDF/vidéo et copie d'URL |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Téléchargement d'archives ZIP multi-fichiers (vérifié individuellement pour chaque chemin sélectionné) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Extraction ZIP sécurisée (requiert également `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Génération de liens de partage temporaires signés par HMAC (requiert également `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Vidage des caches de répertoire/profil/miniatures, ainsi que les contournements `?refresh=1` et `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Mise à jour des couleurs/icônes/épinglages de dossiers ainsi que des textes alternatifs, titres, tags et points focaux des fichiers |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Suggestions de métadonnées par IA (requiert `view` + `edit_metadata` + `ai.enabled: true`) |

### Exemple de configuration de profil d'entreprise

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **Interface synchronisée avec les permissions :** Les boutons d'action auxquels l'utilisateur n'a pas droit (par exemple le bouton Supprimer pour un `ROLE_EDITOR` quand `delete: 'ROLE_ADMIN'`) sont automatiquement masqués dans l'interface **et** strictement bloqués avec une erreur `HTTP 403 Forbidden` en cas d'appel direct à l'API.

---

## 3. Écrire un Voter Symfony personnalisé (règles fines par dossier et utilisateur)

Lorsque les rôles YAML ne suffisent plus — par exemple *« empêcher les utilisateurs de supprimer le dossier `locked/` ou d'y déplacer des fichiers »* — créez votre propre Voter Symfony.

> [!WARNING]
> **Piège le plus courant pour les débutants : `affirmative` vs. `unanimous` !**  
> La stratégie `access_decision_manager.strategy` par défaut de Symfony est **`affirmative`** (il suffit qu'un seul Voter renvoie `true` pour accorder l'accès). Par conséquent, si le `FileManagerVoter` intégré au bundle renvoie `true` pour un utilisateur `ROLE_ADMIN`, le vote **`false` (refus)** de votre Voter personnalisé est **IGNORÉ**, et l'utilisateur peut quand même supprimer le fichier !  
>  
> Pour garantir que les règles de **refus** de votre Voter personnalisé soient réellement appliquées, définissez la stratégie sur **`unanimous`** dans `config/packages/security.yaml` (tous les Voters participants doivent approuver ; un seul `false` bloque l'action) :
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(Le bundle détecte automatiquement les Voters personnalisés de votre application via `DetectExternalVotersPass` et applique un filtrage Voter élément par élément sur l'arborescence et les compteurs de fichiers afin de préserver la confidentialité et la précision.)*

### Propriétés de `FileManagerSubject`

Le paramètre `$subject` transmis à la méthode `voteOnAttribute()` de votre Voter personnalisé expose 4 propriétés en lecture seule (`readonly`) :

| Propriété | Type | Description | Exemple |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Configuration du profil actif (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Chemin relatif du fichier/dossier source depuis la racine du volume | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Nom du volume actif | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Chemin de destination** pour les opérations `MOVE`, `COPY`, `RENAME` ou `CLONE` (`null` pour toutes les autres) | `'locked/new-name.pdf'` |

### Exemple de Voter personnalisé (verrouillage du dossier `locked/` contre la suppression, le renommage et le déplacement)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Bloque la suppression, le déplacement ou le renommage de "locked" ou de son contenu
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Bloque également le déplacement ou le renommage de fichiers VERS le dossier "locked"
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. Les 14 couches intégrées de défense en profondeur (durcies face aux attaques réelles)

| # | Vecteur d'attaque | Protection intégrée au bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Chaque requête `POST` d'écriture, suppression, upload, ZIP et édition d'image valide l'en-tête `X-LiveUX-FM-CSRF` **avant** de traiter la moindre donnée (`CsrfRequestValidator`). |
| 2 | **Traversée de répertoires, Unicode & astuces OS** | `PathNormalizer` bloque `../`, `..\`, les octets nuls (`\0`), les caractères de contrôle, `C:\`, les wrappers de flux `phar://`/`php://`, les points/espaces finaux, les caractères Unicode de largeur nulle/bidi, les points idéographiques CJK (`。`), les flux de données alternatifs NTFS (`file.txt:stream`) et les noms de périphériques Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), tout en imposant au maximum **32 niveaux de profondeur**, **255 octets/segment** et **1024 caractères UTF-8 / 3072 octets** pour la longueur totale du chemin. |
| 3 | **Fichiers exécutables, polyglottes & doubles extensions** | `FileSecurityGuard` applique **69 extensions interdites** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, etc.), **47 types MIME bloqués**, la détection des doubles extensions (`shell.php.jpg`) et la validation **magic-bytes anti-polyglotte** — strictement appliquées lors des uploads, renommages (même si `allow_extension_change: true`) et téléchargements. |
| 4 | **Accès direct aux dossiers cachés et à la corbeille** | Les chemins situés sous `.trash`, `.env`, `.git`, `.htaccess` et `exclude_patterns` sont rejetés sur tous les endpoints ; `ZipDownloadController` effectue une vérification Voter `DOWNLOAD_ZIP` distincte sur **chaque chemin sélectionné**. |
| 5 | **XSS SVG, XXE & bombes DoS `<use>`** | `SvgSanitizer` rejette `<!DOCTYPE`/`<!ENTITY`, impose une **liste blanche stricte de 55 balises**, plafonne la taille SVG à **5 Mo**, filtre la syntaxe CSS (`@import`, `expression()`, `url(javascript:)`), supprime `<script>`/`<foreignObject>`/`on*`/`javascript:`, et borne l'expansion `<use>` contre les attaques DoS (max `100` `<use>`, profondeur `4`, `1000` nœuds développés, `5000` étapes d'analyse, `8` ID dupliqués). |
| 6 | **SSRF & DNS Rebinding (import par URL distante)** | `RemoteUrlImporter` est désactivé par défaut (`allow_remote_url_import: false`). Une fois activé, il valide les adresses IP via `inet_pton()` pour bloquer le loopback, les plages privées, `0.0.0.0`, le CGNAT `100.64.0.0/10`, les métadonnées cloud (`169.254.169.254`), les adresses IPv6 mappées IPv4 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 et les contournements IP UTS-46 IDNA / pleine chasse / octal / hexadécimal, tandis que l'**épinglage IP via `CURLOPT_RESOLVE`** élimine les attaques par DNS rebinding. |
| 7 | **RCE polyglotte dans l'éditeur d'images** | `ImageEditorController` restreint `overwrite` aux fichiers raster sûrs déjà existants (`jpg/jpeg/png/webp`), et applique `upload_max_size`, les règles `upload_allow`/`upload_deny` du profil, les contrôles Asset Usage Guard ainsi qu'une validation réelle par `getimagesizefromstring()`. |
| 8 | **Bombes de décompression (pixels) & ImageTragick** | `GdThumbnailDriver` et `ImagickThumbnailDriver` inspectent les dimensions via `getimagesizefromstring()` avant le décodage en mémoire (plafond de `8192×8192` et `40 MP`), et appliquent des limites strictes de mémoire, de temps et de threads sur Imagick. |
| 9 | **Évasion par liens symboliques & confinement** | `ConfinedLocalFilesystemAdapter` vérifie `is_link()` + le confinement racine `realpath()` sur chaque segment de chemin pour toute lecture, écriture, déplacement, copie et suppression en local ; les suppressions/déplacements récursifs de sous-arborescences contenant des liens symboliques sont rejetés, et les listages imposent `SKIP_LINKS`. |
| 10 | **Prévention des collisions multi-tenant (`{user_id}`)** | `DynamicPathResolver` isole les répertoires utilisateurs avec des suffixes SHA-256 uniques `<slug>--h-<16-hex>` pour les e-mails, casses mixtes, caractères Unicode, noms réservés (`guest`, `user`) et noms de périphériques Windows — rendant toute collision de répertoire entre deux utilisateurs impossible. |
| 11 | **Isolation des téléchargements, miniatures & caches CDN** | `DownloadController`, `ThumbnailController` et `ZipDownloadController` émettent `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` et `Content-Security-Policy: default-src 'none'; sandbox` ; imposent `Cache-Control: private` + `Vary: Cookie, Authorization` dans les contextes privés/Voter/session/`{user_id}` ; exigent `CLEAR_CACHE` pour `?refresh=1` et `?fallback=1` ; et forcent `Content-Disposition: attachment` pour HTML/JS/SVG/XML. |
| 12 | **Protection POST du FormType & origine Twig** | `FileManagerPickerType` rejette côté serveur les charges utiles POST falsifiées (protocoles XSS, `../`, `.trash/`, JSON > 256 Ko, > 500 éléments, UTF-8 invalide, dépassement de `max_files`, tableaux HPP) ; `FileManagerTwigExtension` et la fonction front-end `isSafeUrl()` empêchent l'usurpation d'origine par des URL externes ou dangereuses. |
| 13 | **Répertoire arbitraire de thème/icônes & DOM XSS** | À l'exécution, `dialog?theme=` n'accepte que les 9 thèmes intégrés et `dialog?iconSet=` uniquement les 6 jeux d'icônes intégrés ; `sanitizeHexColor()` empêche l'injection de couleurs ; les répertoires temporaires d'uploads par morceaux sont isolés par utilisateur (`0750`). |
| 14 | **DoS, injection de journaux & isolation du cache** | La suppression groupée est plafonnée à 200 éléments, l'upload par morceaux à 2000 segments et la copie récursive de dossiers à 5000 éléments ; `AuditLogListener` nettoie les charges d'injection de logs (`\r`/`\n`/séquences ANSI/caractères de contrôle) ; `DirectoryCacheManager` n'efface jamais le pool `cache.app` de l'application hôte (aucun appel à `clear()`). |

---

## 5. Durcissement du serveur web (Apache & Nginx)

Par précaution supplémentaire, désactivez toujours l'exécution de PHP et de scripts au niveau du serveur web dans les répertoires d'upload exposés publiquement tels que `public/uploads` :

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```

---

## 6. Liens de partage signés & garde d'utilisation des fichiers

- **Liens de partage signés et temporaires (`ShareController`) :** Lorsque `share.enabled: true`, les URL temporaires sont signées avec le service `UriSigner` de Symfony (HMAC + horodatage `expires`). Passer `share.enabled: false` sur un profil révoque immédiatement tous les liens existants avec `403 Forbidden`, les liens expirés renvoient `410 Gone`, et toutes les réponses incluent `Cache-Control: private, no-store` ainsi qu'une CSP stricte.
- **Garde d'utilisation des fichiers (`AssetUsageCheckerInterface`) :** Empêche de supprimer, renommer, déplacer ou écraser des fichiers activement référencés dans votre base de données (voir [10 — Garde d'utilisation des fichiers](10-asset-usage-guard.md)).

---

## 7. Journalisation d'audit — Configuration de production et exemples

`AuditLogListener` enregistre les 9 événements de modification de fichiers/dossiers avec un contexte PSR-3 structuré (`action`, `profile`, `volume`, `path`, `user`, `ip`, ainsi que les métadonnées propres à chaque événement) et nettoie automatiquement les caractères de contrôle, CRLF et BiDi afin de prévenir toute injection de logs.

### Étape 1 : Activer la journalisation d'audit (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Activer ou surcharger par profil
        public_temp:
            security:
                audit_log: false
```

### Étape 2 : Router les entrées d'audit vers un journal JSON rotatif dédié (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # Rétention et rotation sur 90 jours
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Exemple de sortie JSON structurée

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### Les 9 événements du cycle de vie audités

| Événement Symfony | Clé `action` | Champs de contexte supplémentaires |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Étape 3 (optionnelle) : Persister les journaux d'audit dans une table Doctrine

Pour afficher un historique d'audit consultable dans votre panneau d'administration, abonnez-vous aux mêmes événements Symfony :

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> Sur les applications à fort trafic, envoyez un message asynchrone via Symfony **Messenger** plutôt que d'appeler `$this->em->flush()` en ligne afin de garder les opérations sur les fichiers non bloquantes.

---

[← Précédent : 05 — FormType & intégrations d'éditeurs](05-formtype-and-editor-integrations.md) | [Index](README.md) | [Suivant : 07 — Stockage cloud & configuration dynamique →](07-cloud-storage-and-dynamic-config.md)
