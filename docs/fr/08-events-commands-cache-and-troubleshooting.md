# 08 — Événements, Commandes CLI, Cache, Traductions & Dépannage (FAQ)

[← Précédent : 07 — Stockage Cloud & Configuration dynamique](07-cloud-storage-and-dynamic-config.md) | [Index principal](README.md) | [Suivant : 09 — Intégration Symfony AI →](09-symfony-ai-integration.md)

> [!TIP]
> **En un coup d'œil : à quoi servent les événements et les commandes CLI ?**
> - **Événements :** avertissent automatiquement votre application Symfony dès qu'un utilisateur téléverse, supprime, renomme ou déplace un fichier — par exemple : *« lorsqu'un fichier est renommé, mettre automatiquement à jour le chemin de l'image produit en base de données »*.
> - **Commandes CLI (`php bin/console file-manager:...`) :** outils de terminal intégrés pour diagnostiquer votre installation (`doctor`), invalider les caches (`cache:clear`), pré-générer les miniatures (`thumbs:warmup`) ou purger les éléments expirés de la corbeille (`trash:purge`).

---

## 1. Événements Symfony (9 événements métier)

Chaque événement du gestionnaire de fichiers hérite de la classe de base commune **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Écoutez ces événements pour synchroniser votre base de données, alimenter des journaux d'audit, purger des caches CDN ou déclencher des tâches asynchrones.

| Classe d'événement (`LiveUX\FileManagerBundle\Event\*`) | Déclenché lorsque | Propriétés |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Un fichier est téléversé, importé depuis une URL, enregistré par l'éditeur d'images ou extrait d'une archive ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Un fichier est supprimé (envoyé à la corbeille ou supprimé définitivement) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Un dossier est créé (y compris lors d'une extraction ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Un dossier est supprimé | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Un fichier ou un dossier est renommé | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Un élément est déplacé ou copié (y compris lors d'un transfert inter-volumes) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Un élément est dupliqué / cloné | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Un élément est restauré depuis la corbeille | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Un ou plusieurs éléments sont purgés définitivement de la corbeille | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Exemple d'écouteur d'événement (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Nouvelle ressource média téléversée.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Commandes Console (CLI) (10 commandes intégrées)

| Commande | Options / Alias | Description |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Vérifie la version de PHP, `upload_max_filesize`, `post_max_size`, le bundle CA cURL, GD/Imagick, EXIF, WebP, `ext-zip` et la configuration de chaque profil/volume. Ajoutez `--check-remote` pour exécuter un test de connectivité en direct S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Vide les caches de répertoires et de quotas pour tous les profils ou un profil/volume/chemin précis (`--volume`/`--path` nécessitent l'argument `profile` ; ne purge jamais les clés étrangères de `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Alias : `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Pré-génère les miniatures d'images à l'avance afin que les éditeurs n'attendent pas lors du premier chargement. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Supprime les miniatures en cache. `--orphans` ne supprime que les miniatures dont le fichier source n'existe plus (ne peut pas être combiné avec `--volume`/`--path` et protège automatiquement les répertoires partagés `{user_id}` / `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Optimise rétroactivement les images existantes (plafond mémoire/sécurité : `25 Mo` max par image). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Détecte les fichiers en double via leur empreinte SHA-256 et indique l'espace disque récupérable. |
| `file-manager:stats [profile]` | `--json` | Affiche l'espace utilisé, le nombre de fichiers/dossiers, l'utilisation du quota, les miniatures et les statistiques de la corbeille. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(par défaut `50`)*, `--dry-run` | Génère en lot par IA les textes `alt`, titres, légendes et étiquettes manquants. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = tout purger)*, `--dry-run` | Supprime définitivement les éléments expirés de la corbeille. Lorsque plusieurs profils partagent un même répertoire physique, la durée `retention_days` la plus longue est automatiquement respectée. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Installe les 6 AI Agent Skills du bundle dans `.agents/skills`, `.claude/skills`, `.cursor/skills` ou `.github/skills`. |

> [!NOTE]
> **Protection des variables dynamiques `{user_id}` et `{Y}/{m}/{d}` en mode CLI**
> Comme aucune session utilisateur authentifiée n'existe en CLI, toutes les commandes d'analyse et de nettoyage (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **ignorent automatiquement** les volumes contenant `{user_id}` (évitant ainsi la création accidentelle d'un dossier `guest/` vide). Pour les volumes contenant les variables de date `{Y}/{m}/{d}`, seule la période courante est parcourue et un message d'information est affiché.

### Exemple de tâche Cron pour le nettoyage automatique de la corbeille

```cron
# Purger les éléments expirés de la corbeille chaque nuit à 03h00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Performances, Architecture de cache & Symfony Profiler

- **TTL de cache à 3 niveaux & Actualisation manuelle (`↻`) :** le TTL du listing de répertoire est résolu dans l'ordre suivant : **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` désactive le cache pour ce volume ou profil). Cliquer sur **Actualiser (`↻`)** dans la barre d'outils ou appuyer sur `R` envoie `?refresh=1` *(le contournement du cache requiert la permission `CLEAR_CACHE`)*.
- **Pool de cache dédié & empreinte de profil :** pointez `defaults.cache.pool` vers un pool Redis/APCu dédié (si `<pool>.taggable` existe, il est utilisé automatiquement). Sans cache taggable, `DirectoryCacheManager` utilise des compteurs de génération sur 30 jours et n'appelle jamais `$cachePool->clear()` sur votre cache applicatif principal. Lorsque deux profils partagent le même dossier physique avec des règles différentes (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), leurs caches restent strictement isolés grâce à `buildProfilePolicyFingerprint()`.
- **Synchronisation par clé canonique :** si deux profils pointent vers le même répertoire physique, téléverser ou modifier un fichier dans l'un invalide automatiquement le cache de l'autre profil.
- **Auto-réparation de `remember_last_dir` :** si le dernier dossier visité par l'utilisateur a été supprimé depuis l'extérieur (via FTP ou la console S3), le gestionnaire remonte proprement vers le dossier parent existant le plus proche ou vers la racine au lieu d'afficher une erreur.
- **HTTP 304 & mise en cache contextuelle des miniatures (`ThumbnailController`) :**
  - **Contexte privé / authentifié** (volume `private: true`, volume dynamique `{user_id}`, profil protégé par un Voter, présence d'un en-tête `Authorization` ou d'un cookie de session) : renvoie `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` afin d'éviter toute fuite sur un CDN partagé.
  - **Contexte public :**
    - Avec le paramètre d'invalidation `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 jours).
    - Sans `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 heure + `ETag` / `304 Not Modified`).
    - Lorsque `cache.enabled: false` sur le profil → `Cache-Control: public, no-cache, must-revalidate`.
  - Chaque réponse de miniature ajoute également `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` et `Referrer-Policy: no-referrer`.
- **Barre de débogage Symfony (Web Debug Toolbar) :** en environnement `dev`, le panneau **FileManager** du profiler affiche le profil actif, le volume, le pilote de miniatures et les statistiques de succès/échecs (hit/miss) du cache.

---

## 4. Internationalisation globale (`493` clés), BCP-47 / POSIX / RTL & langues personnalisées

LiveUX FileManagerBundle est livré prêt à l'emploi avec **12 langues d'interface intégrées** — **anglais (`en` — langue de repli garantie), turc (`tr`), français (`fr`), allemand (`de`), espagnol (`es`), arabe (`ar`), italien (`it`), portugais (`pt`), néerlandais (`nl`), polonais (`pl`), japonais (`ja`) et chinois simplifié (`zh_CN`)** — avec une parité complète sur l'ensemble des **493 clés de traduction**.

- **Prise en charge BCP-47 & POSIX `.UTF-8` :** `MessageTranslator` résout automatiquement les locales régionales et POSIX telles que `tr_TR.UTF-8`, `de_DE@euro` et `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` génère des codes BCP-47 valides pour les attributs HTML `lang`.
- **Détection automatique droite-à-gauche (RTL) :** **15 langues et écritures RTL** — dont l'arabe (`ar`), le persan (`fa`), l'hébreu (`he`), l'ourdou (`ur`), le pachto (`ps`) et le kurde central (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa`, etc.) — sont détectées automatiquement, basculant la mise en page du composant en `dir="rtl"`.
- **Liste blanche des catalogues à la compilation :** `FileManagerBundle` n'accepte que les locales correspondant à de véritables catalogues de traduction, prévenant ainsi le gonflement du cache ou d'OPcache (DoS) par des paramètres de locale forgés.
- **Ajouter une nouvelle langue (ex. le suédois `sv`) ou surcharger partiellement des libellés :**
  Créez simplement `translations/LiveUXFileManagerBundle.sv.yaml` dans votre projet (ou surchargez uniquement certaines clés dans une langue existante comme `LiveUXFileManagerBundle.fr.yaml`). Toute clé omise retombe automatiquement sur l'anglais (`en`) — vos utilisateurs ne verront jamais de clés brutes comme `ui.upload`. Copiez le fichier `translations/LiveUXFileManagerBundle.en.yaml` du bundle comme modèle de départ complet.

---

## 5. Foire aux questions & Dépannage (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Vérifiez que `assets/app.js` contient bien `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Assurez-vous que `defaults.auto_inject_dialog_host: true` est activé (si vous l'avez désactivé, incluez manuellement `DialogHost.html.twig` dans `base.html.twig`).
- Exécutez `php bin/console cache:clear` puis effectuez un rechargement forcé du navigateur (`Ctrl+Shift+R`).

### 2. Chaque requête POST renvoie `403 Invalid or missing CSRF token`
- Vérifiez que `symfony/security-csrf` est installé et que `framework.csrf_protection: true` ainsi que `framework.session.enabled: true` sont actifs.
- Si vous envoyez directement des requêtes `fetch()` personnalisées, incluez l'en-tête `X-LiveUX-FM-CSRF`.

### 3. J'obtiens `403 Access denied`
- Vérifiez le bloc `permissions` de votre profil dans `liveux_file_manager.yaml`. Rappel : même une permission définie à `true` exige un utilisateur authentifié, et `permissions.view` est requise pour toutes les autres actions.
- Si vous avez implémenté un Voter Symfony personnalisé, vérifiez `access_decision_manager.strategy` (par ex. `unanimous` vs `affirmative`) dans `config/packages/security.yaml`.

### 4. Le téléversement de fichiers volumineux échoue (`upload_max_filesize`)
- Avec `chunk_upload.enabled: true`, la taille des morceaux est automatiquement plafonnée à `80 %` des limites PHP `upload_max_filesize`/`post_max_size` (ex. des morceaux de `1,60 Mo` pour une limite PHP de `2M`, jusqu'à 2 000 morceaux).
- La taille totale maximale autorisée est contrôlée par `upload_max_size` dans `liveux_file_manager.yaml` (ex. définissez `upload_max_size: '100M'` pour des vidéos de 100 Mo).

### 5. Télécharger un fichier casse la page (Symfony UX Turbo Drive)
- Tous les liens de téléchargement intégrés incluent déjà `data-turbo="false" download`. Si vous surchargez les templates Twig, veillez à conserver `data-turbo="false"` sur vos balises `<a>` de téléchargement personnalisées.

### 6. Les miniatures ne sont pas générées ; seules les images originales sont servies
- Exécutez `php bin/console file-manager:doctor` et vérifiez que la ligne `Thumbnail Driver` indique `gd` ou `imagick` comme actif.
- Vérifiez que `thumbnail.cache_dir` (`public/media-cache`) est accessible en écriture par l'utilisateur du serveur web.

### 7. `cURL error 60: SSL certificate problem` sous Windows lors de la connexion à S3 / Supabase
- Téléchargez un fichier `cacert.pem` à jour et configurez `curl.cainfo` ainsi que `openssl.cafile` dans `php.ini`, ou renseignez `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` sur le volume S3.

### 8. Les modifications front-end n'apparaissent pas dans le navigateur
- **Développement :** exécutez `php bin/console cache:clear` puis rechargez la page sans cache (`Ctrl+Shift+R`).
- **Production (AssetMapper) :** exécutez `php bin/console asset-map:compile`.

---

[← Précédent : 07 — Stockage Cloud & Configuration dynamique](07-cloud-storage-and-dynamic-config.md) | [Index principal](README.md) | [Suivant : 09 — Intégration Symfony AI →](09-symfony-ai-integration.md)
