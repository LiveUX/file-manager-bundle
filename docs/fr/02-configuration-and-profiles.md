# 02 — Configuration et profils (Référence complète des paramètres et recettes)

[← Précédent : 01 — Prérequis et installation](01-requirements-and-installation.md) | [Index de la documentation](README.md) | [Suivant : 03 — Modes d'utilisation et API JS →](03-usage-modes-and-js-api.md)

Ce guide explique **chaque option** de `config/packages/liveux_file_manager.yaml`, sa valeur par défaut, quand la personnaliser, ainsi que **5 recettes prêtes pour la production** à copier-coller directement dans votre projet.

---

## Modèle mental en 30 secondes : `defaults`, `volumes` et `profiles`

Voyez le fichier de configuration en **3 couches distinctes** :

1. **`defaults` (Valeurs par défaut globales) :** Socle commun hérité par tous les profils (thème, locale, schéma de couleurs, cache, driver de métadonnées, journal d'audit, paramètres IA par défaut). Sauf surcharge sur un profil précis, ces valeurs s'appliquent partout.
2. **`volumes` (Disques / Buckets de stockage) :** Définit **où résident physiquement les fichiers** (répertoire local, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage ou service Flysystem personnalisé).
3. **`profiles` (Profils d'utilisation) :** Définit les **règles, la mise en page de l'interface et les permissions** pour un écran ou un cas d'usage donné. Par exemple :
   - `product_catalog` n'autorise que les images produit carrées `1:1` et les convertit automatiquement en WebP ;
   - `customer_docs` se connecte à un bucket Supabase S3 privé, n'accepte que les PDF et isole chaque utilisateur dans son propre dossier ;
   - `editor_picker` ouvre une fenêtre modale épurée pour TinyMCE avec sélection par double-clic.

---

## 1. `defaults` — Valeurs par défaut globales

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = hérite de la locale active de Symfony (repli garanti sur 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Paramètre | Défaut | Rôle | Quand le modifier |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Répertoire racine par défaut pour les profils simplifiés qui définissent `path` directement sans `volumes`. | Lorsque vous stockez les fichiers locaux dans un autre dossier, par exemple `public/media`. |
| `theme` | `'bootstrap5'` | Thème d'interface par défaut pour tous les profils (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` ou le nom de votre thème personnalisé). | Pour correspondre au système de design de votre application (ex. `'shadcn'` ou `'tailwind'` sur un projet Tailwind). |
| `locale` | `null` | Lorsque la valeur est `null`, hérite automatiquement de la locale active de la requête/du Translator Symfony parmi les **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normalise les formats BCP-47 et POSIX (`fr_FR.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`), active automatiquement le RTL (`dir="rtl"`) pour l'arabe et les autres écritures RTL, et bascule sur `'en'` pour les locales inconnues. | Définissez explicitement une valeur (ex. `'fr'`, `'en'`, `'de'`) pour imposer une langue unique à tous les utilisateurs. |
| `color_scheme` | `'auto'` | Mode de couleur : `'auto'` (détecte `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (toujours clair) ou `'dark'` (toujours sombre). | Lorsque vous imposez un back-office exclusivement clair ou exclusivement sombre. |
| `z_index` | `1050` | Contexte d'empilement de base (`--liveux-fm-z-base`) pour les modales, tiroirs OffCanvas, Lightbox et menus contextuels (`>= 1`). | Augmentez-le (ex. `10050`) si la barre de navigation de votre administration utilise `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Ajoute automatiquement l'hôte `<dialog>` requis par `window.LiveUXFileManager.open()` avant `</body>` sur les pages HTML complètes (aucune édition de `base.html.twig` nécessaire). | Passez à `false` si vous préférez inclure manuellement `@LiveUXFileManager/components/DialogHost.html.twig` uniquement sur certains gabarits. |
| `dialog_host_profile` | `'default'` | Profil utilisé par défaut par le `DialogHost` auto-injecté lorsqu'aucun profil n'est transmis. | Lorsque votre profil principal ne se nomme pas `default` (ex. `admin_media`). |
| `cache.enabled` | `true` | Cache serveur PSR-6 pour les listes de répertoires et les arborescences de dossiers. | Passez à `false` en débogage local (ou désactivez-le par profil/volume). |
| `cache.pool` | `'cache.app'` | Pool de cache PSR-6 Symfony (Redis, Filesystem, APCu, etc.). Utilise automatiquement `<pool>.taggable` s'il existe pour une invalidation ciblée par tags. | Pour isoler les entrées du gestionnaire de fichiers dans un pool Redis dédié. |
| `cache.ttl` | `3600` | Durée de vie du cache en secondes (par défaut : 1 heure). Les mutations (téléversement, suppression, déplacement, renommage) invalident automatiquement les dossiers concernés. | Abaissez à `60`–`300` lorsque des fichiers sont fréquemment modifiés de l'extérieur via FTP ou une console cloud. |
| `metadata.enabled` | `true` | Enregistre les couleurs/icônes/épingles de dossiers ainsi que les métadonnées SEO des fichiers (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Passez à `false` pour des sélecteurs minimalistes n'ayant pas besoin de métadonnées éditoriales. |
| `metadata.driver` | `'json_file'` | Moteur de stockage des métadonnées : `'json_file'` (fichier local pour une application mono-serveur), `'doctrine'` (stockage DBAL intégré pour les architectures multi-serveurs / derrière répartiteur de charge sur MySQL, MariaDB, PostgreSQL ou SQLite), ou `'none'`. | Passez à `'doctrine'` dès que vous exécutez plusieurs instances applicatives (Kubernetes, répartiteur de charge, Docker Swarm) — voir [Chapitre 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Chemin de stockage pour le driver `json_file`. Un fichier compagnon de corbeille (`trash-meta.json` ou `<nom>.trash-meta.json`) est enregistré dans le même répertoire pour les buckets cloud restreignant les types MIME. | Déplacez-le sur un partage NFS/volume partagé si besoin. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | Nom de la connexion DBAL, de l'EntityManager, de la table (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) et création automatique de la table lorsque `driver: 'doctrine'`. | Définissez `auto_create_table: false` en production lorsque le schéma est géré via Doctrine Migrations (voir [Chapitre 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Écrit des entrées d'audit structurées PSR-3 (`user`, `ip`, `path`, `volume`) pour les 9 mutations de fichiers/dossiers (téléversement, suppression, déplacement, copie, renommage, clonage, création de dossier, restauration et purge de corbeille). | Activez (`true`) dans les environnements soumis à conformité où vous devez tracer *« qui a supprimé quoi, et quand ? »*. |
| `audit_log.level` | `'info'` | Niveau de log PSR-3 pour les entrées d'audit (`debug`, `info`, `notice`, `warning`). | Ajustez-le selon vos règles de routage/filtrage Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Modèle vision/texte utilisé par défaut lorsque le paquet optionnel `symfony/ai-bundle` est installé (voir [Chapitre 09](09-symfony-ai-integration.md)). | Basculez vers Gemini, Claude ou un modèle Ollama local. |
| `ai.platform_service` | `null` | Identifiant de service Symfony AI `PlatformInterface` personnalisé (`null` utilise la plateforme enregistrée automatiquement par défaut). | Lorsque votre application définit plusieurs plateformes Symfony AI. |

---

## 2. `volumes` — Volumes de stockage (local et cloud)

Un même profil peut monter plusieurs volumes simultanément (par exemple, un disque local et Supabase/R2/S3). Les utilisateurs peuvent glisser-déposer des fichiers directement d'un dossier local vers un bucket cloud.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disque local'
            icon: 'disk'              # Optionnel (détecté automatiquement selon le driver/endpoint si omis)
            group: 'local'            # Optionnel (null -> 'local' pour LocalFileSystem, 'network' pour S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # TTL par volume optionnel (0 = désactivé)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Également détecté automatiquement depuis l'endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Les modifications externes via la console cloud apparaissent sous 60 secondes
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Paramètre | Défaut | Rôle | Quand le modifier |
| :--- | :--- | :--- | :--- |
| `label` | Clé du volume | Nom affiché dans la barre latérale, le fil d'Ariane et le sélecteur de volume. | Définissez un libellé explicite (ex. `'CDN Médias Produits'`). |
| `icon` | `null` *(auto)* | Icône SVG dans la barre latérale. Si `null`, détectée automatiquement à partir de `driver` et `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Pointez vers un fichier personnalisé `<dir>/ui/mon_icone.svg` dans votre jeu d'icônes. |
| `group` | `null` *(auto)* | Section de regroupement dans la barre latérale. Si `null`, `LocalFileSystem` vaut `'local'` par défaut et `AwsS3` / `FlysystemService` valent `'network'`. | Surchargez si vous souhaitez regrouper un montage NFS sous `'network'` ou un disque cloud sous `'local'`. |
| `driver` | `'LocalFileSystem'` | Moteur de stockage : système de fichiers local (`LocalFileSystem`), stockage objet compatible S3 (`AwsS3`) ou service Flysystem existant (`FlysystemService`). | Utilisez `'AwsS3'` pour S3/R2/Supabase/B2/MinIO/GCS, ou `'FlysystemService'` pour SFTP/Azure/Dropbox. |
| `path` | `null` | Chemin physique du répertoire pour `LocalFileSystem` (prend en charge `{user_id}`, `{Y}`, `{m}`, `{d}`). | Définissez une racine personnalisée ou une isolation par utilisateur (`.../users/{user_id}`). |
| `url_prefix` | `null` | Préfixe d'URL publique utilisé par le navigateur pour accéder aux fichiers publics (`/uploads` ou `https://cdn.example.com`). Ignoré si `private: true`. | À renseigner lorsque les fichiers sont servis par un CDN ou un bucket S3/R2/Supabase public. |
| `private` | `false` | Lorsque la valeur est `true`, les fichiers ne sont jamais exposés par URL publique directe — tous les téléchargements, aperçus et miniatures passent par les contrôles Voter Symfony (`DownloadController`). | **Activez toujours `true`** pour les factures, pièces d'identité, contrats ou documents clients privés. |
| `cache_ttl` | `null` *(hérité)* | TTL de cache (en secondes) propre à ce volume. `0` désactive le cache pour ce volume ; `null` hérite du profil/des valeurs par défaut. | Conservez le disque local en cache pendant `3600` s tout en limitant un bucket cloud à `60` s ou `0`. |
| `driver_options` | `[]` | **AwsS3 :** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(jeton de session temporaire STS)*, `version` *(par défaut `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(auto `true` pour les endpoints personnalisés ; `false` par défaut sur AWS S3 standard — définissez `true` explicitement si *Bucket owner enforced* est activé)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService :** `service`. | Consultez le [Chapitre 07](07-cloud-storage-and-dynamic-config.md) pour les guides détaillés par fournisseur. |

---

## 3. `profiles` — Dictionnaire des paramètres de profil

### 3.1. Stockage de base, filtrage et quota
| Paramètre | Défaut | Rôle | Quand le modifier |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Surcharge la langue de l'interface et des messages d'erreur pour ce profil (`'fr'`, `'en'`, `'tr'`, `'de'`, `'ar'`, etc. ; `null` hérite de `defaults.locale` → locale de la requête Symfony → `'en'`). | Lorsque différents espaces d'une même application ciblent des langues distinctes. |
| `volumes` | `[]` | Liste ordonnée des clés de volumes accessibles dans ce profil (ex. `['local_uploads', 'supabase_s3']`). Le premier volume est ouvert par défaut. | Pour associer un ou plusieurs volumes locaux/cloud. |
| `ignore_dot_files` | `true` | Masque les fichiers cachés commençant par un point (`.env`, `.htaccess`, `.DS_Store`, `.trash`) dans les listes et bloque tout accès direct. | Conservez `true` pour la sécurité. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Masque et bloque l'accès direct aux fichiers ou dossiers correspondant à des expressions régulières ou motifs génériques. | Pour masquer des dossiers techniques tels que `/^cache$/i` ou `/^backups$/i`. |
| `sort_by` | `'name'` | Colonne de tri par défaut : `'name'`, `'date'`, `'size'` ou `'type'`. | Utilisez `'date'` + `sort_direction: 'desc'` pour afficher les téléversements les plus récents en premier. |
| `sort_direction` | `'asc'` | Sens de tri : `'asc'` (A→Z / du plus petit au plus grand) ou `'desc'` (Z→A / du plus récent au plus ancien). | `'desc'` convient parfaitement aux médiathèques et bibliothèques éditoriales. |
| `folders_first` | `true` | Affiche toujours les dossiers au-dessus des fichiers. | Passez à `false` pour un tri alphabétique ou chronologique plat. |
| `storage_quota` | `null` | Espace disque maximal autorisé pour le profil (ex. `'500M'`, `'2G'`). Une fois atteint, les téléversements sont rejetés côté serveur et une jauge d'utilisation apparaît dans la barre latérale. | Indispensable pour les applications SaaS multi-tenant ou les quotas par utilisateur (`{user_id}`). |
| `upload_subfolder` | `null` | Modèle de sous-dossier dynamique où les fichiers téléversés sont automatiquement rangés sous le dossier courant (ex. `'{Y}/{m}'`). | Pour classer automatiquement les téléversements dans des dossiers par date comme `2026/11`. |

### 3.2. Téléversements, découpage en morceaux (`chunk_upload`) et détection des doublons (`duplicate_detection`)
| Paramètre | Défaut | Rôle | Quand le modifier |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(tous les types sûrs)* | Liste blanche de types MIME acceptés (ex. `['image/jpeg', 'image/png', 'image/webp']`). Si elle est vide, tous les types sûrs absents de `upload_deny` sont autorisés. | Restreignez toujours à des types MIME précis pour les formulaires réservés aux images ou aux PDF. |
| `upload_deny` | **47 types MIME dangereux** | Types MIME strictement bloqués (PHP, HTML, XML non-SVG, JS, Shell, EXE, Java, CGI, etc. — validés côté serveur via les octets magiques). | Ajoutez d'autres types MIME interdits si votre politique de sécurité l'exige. |
| `upload_max_size` | `'10M'` | Taille **assemblée** maximale d'un fichier téléversé (ex. `'200M'`, `'1G'`). Avec le téléversement découpé activé, les fichiers bien plus lourds que la limite PHP de `2M` se téléversent sans encombre jusqu'à ce plafond. | Augmentez à `'200M'`–`'500M'` pour les profils vidéo/catalogues PDF, ou abaissez à `'2M'` pour les avatars. |
| `overwrite_policy` | `'rename'` | Comportement en cas de collision de nom : `'rename'` (`photo-1.jpg`), `'overwrite'` (écraser) ou `'reject'` (rejeter). | Conservez `'rename'` pour éviter toute perte accidentelle de données. |
| `chunk_upload.enabled` | `true` | Découpe les fichiers volumineux en petits morceaux avec Pause / Reprise / Annulation confirmée et nouvelle tentative automatique en cas de micro-coupure réseau. | Rarement désactivé — uniquement si vous téléversez exclusivement de très petits fichiers (< 1 Mo). |
| `chunk_upload.chunk_size` | `'2M'` | Taille cible d'un morceau. Automatiquement plafonnée à **80 %** des directives PHP `upload_max_filesize` et `post_max_size` (ex. 1,60 Mo avec une limite PHP à 2 Mo). | Augmentez à `'5M'` sur les serveurs disposant de limites PHP élevées (32M+) pour accélérer les gros transferts. |
| `chunk_upload.parallel_uploads` | `3` | Nombre maximal de téléversements simultanés (`1`–`10`). | Utilisez `2` sur des serveurs modestes, ou `4`–`5` sur des liaisons cloud/CDN rapides. |
| `chunk_upload.max_retries` | `3` | Nombre de tentatives avec temporisation exponentielle par morceau en cas d'erreur réseau (`0`–`10`). | `3`–`5` fonctionne très bien sur réseau mobile ou instable. |
| `duplicate_detection.enabled` | `false` | Détecte les fichiers en double lors du téléversement via empreinte SHA-256 du contenu. | Activez (`true`) pour éviter que les rédacteurs ne téléversent plusieurs fois la même photo produit. |
| `duplicate_detection.policy` | `'warn'` | Action lors de la détection d'un doublon : `'warn'` (téléverser et avertir), `'reject'` (bloquer le téléversement) ou `'reuse'` (retourner le fichier existant sans dupliquer le stockage). | `'reuse'` permet d'importantes économies de stockage sur les sites e-commerce et CMS. |
| `duplicate_detection.max_scan_files` | `5000` | Plafond de fichiers indexés pour la détection des doublons (`100`–`50000`) afin d'éviter les pics CPU/E/S sur les archives très volumineuses. | Augmentez à `10000`–`25000` pour les grandes médiathèques. |

### 3.3. Normalisation automatique des noms de fichiers (`naming`)
| Paramètre | Défaut | Rôle | Exemple (`"Photo Produit Été 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Translittère les caractères accentués/Unicode et espaces en slugs ASCII propres compatibles URL. | `photo-produit-ete-2026.jpg` |
| `naming.slugger_locale` | `null` | Locale de translittération (`null` hérite de la locale du profil ; ex. `'de'` transforme `ä/ö/ü/ß`, `'tr'` transforme `İ/ı/ş/ğ/ç/ö/ü`). | Indiquez la langue principale de vos contenus (ex. `'fr'`, `'de'`, `'tr'`). |
| `naming.transformer` | `'none'` | Transformation de casse/nommage : `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` ou `'folder'` (nomme le fichier d'après son dossier parent !). | `'snake'` → `photo_produit_ete_2026.jpg`, `'folder'` → `gourdes-1.jpg` |
| `naming.prepend` / `append` | `''` | Ajoute un préfixe ou suffixe statique ou dynamique (`{Y}`, `{m}`, `{d}`, `{uniqid}`). | `prepend: '{Y}{m}_'` → `202611_photo.jpg` |
| `naming.apply_on_rename` | `true` | Applique également les règles `slugger` et `transformer` lorsqu'un utilisateur renomme un fichier dans l'interface. | Conservez `true` pour garantir des noms propres après renommage. |
| `naming.apply_affixes_on_rename` | `false` | Évite de dupliquer les préfixes/suffixes `prepend`/`append` à chaque renommage du fichier. | Conservez `false`. |
| `naming.allow_extension_change` | `false` | Empêche les utilisateurs de modifier l'extension (ex. `.jpg` → `.php` ou `.png`) lors d'un renommage. | Conservez `false` pour la sécurité. |

### 3.4. Optimisation d'image (`image_optimization`) et contraintes (`image_constraints`)
- **`image_optimization` :** Corrige automatiquement l'orientation, supprime les métadonnées EXIF, redimensionne et compresse les photos volumineuses côté serveur lors du téléversement.
- **`image_constraints` :** Rejette les images non conformes **à la fois en 0 ms dans le navigateur et côté serveur** (par exemple pour bloquer des photos floues basse résolution ou des photos produit non carrées).

| Paramètre | Défaut | Rôle | Quand l'utiliser |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Active l'optimisation des images au téléversement (Imagick → GD → opération neutre silencieuse si aucun moteur n'est présent). | Activez (`true`) sur tout profil où les utilisateurs téléversent des photos brutes de smartphone. |
| `image_optimization.auto_orient` | `true` | Lit les balises EXIF d'orientation et redresse automatiquement les photos pivotées prises sur mobile. | Conservez `true` pour les téléversements mobiles. |
| `image_optimization.strip_exif` | `true` | Supprime les coordonnées GPS et les métadonnées EXIF de l'appareil photo (conformité RGPD + allègement des fichiers). | Conservez `true` pour toutes les images publiques. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Réduit proportionnellement les images dépassant ces limites en préservant le ratio (`>= 100`). | `1920`×`1920` constitue un excellent compromis pour le web. |
| `image_optimization.convert_to_webp` | `false` | Convertit automatiquement les JPEG/PNG téléversés en WebP (fichiers 30 à 60 % plus légers). | Activez (`true`) pour les profils orientés performance web moderne. |
| `image_optimization.quality` | `85` | Qualité de compression en sortie (`10`–`100`). | `80`–`85` offre le meilleur équilibre poids/qualité. |
| `image_constraints.min_width` / `min_height` | `null` | Dimensions minimales exigées en pixels. | Empêche les images floues (ex. couvertures d'articles d'au moins `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Dimensions maximales autorisées en pixels (vérifiées *après* le redimensionnement d'`image_optimization` si l'optimisation est active). | Pour imposer des dimensions strictes de bannières ou de gabarits d'impression. |
| `image_constraints.aspect_ratio` | `null` | Ratio d'aspect requis : `'1:1'` (carré), `'16:9'` (bannière panoramique), `'4:3'`, `'3:4'`, `'1.91:1'`, etc. | Vignettes produit e-commerce carrées (`'1:1'`) ou en-têtes vidéo/articles (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Écart toléré par rapport au ratio cible (`0.0`–`0.5` ; `0.05` = 5 % de tolérance, ce qui accepte aussi bien `1920×1080` que `1280×722`). | `0.05` convient à la quasi-totalité des besoins. |

### 3.5. Miniatures (`thumbnail`), corbeille (`trash`), ZIP (`zip`) et liens de partage signés (`share`)
| Paramètre | Défaut | Rôle |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Génère des miniatures mises en cache pour les images : `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` ou `'none'`. Les images cloud sont récupérées une seule fois puis mises en cache localement. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Comportement si ni GD ni Imagick n'est installé : `'fallback_original'` (affiche l'image originale), `'icon'` (affiche l'icône de fichier) ou `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Dimensions des miniatures (`>= 32`), format de sortie (`webp`, `jpeg`, `png`) et mode d'ajustement (`contain` = image entière visible, `cover` = recadrage centré). Envisagez `480`×`360` si vous utilisez `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Ajoute l'attribut natif `loading="lazy"` aux miniatures des cartes pour accélérer l'affichage des dossiers volumineux. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Répertoire local où sont mises en cache les miniatures générées. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Déplace les fichiers supprimés dans une corbeille plutôt que de les détruire immédiatement. Bascule automatiquement sur un fichier compagnon local `trash-meta.json` sur les buckets cloud restreignant les types MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Active le téléchargement en un clic de plusieurs fichiers/dossiers sous forme d'archive `.zip` (requiert l'extension PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | Extraction d'archives protégée sur 7 couches contre Zip-Slip et Zip-Bomb. Toujours bloquée tant que `allow_extract: false` ; son activation positionne automatiquement `permissions.extract_zip` à `true` (que vous pouvez restreindre à `'ROLE_ADMIN'` ou `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Génère des URL de partage temporaires signées par HMAC via `UriSigner` de Symfony. Passer `enabled: false` invalide immédiatement tous les liens de partage émis précédemment. |

### 3.6. Interface utilisateur (`ui`) — Disposition, dimensionnement et options de barre d'outils
| Paramètre | Défaut | Rôle | Quand le modifier |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Thème propre au profil (ex. `'bootstrap5'` dans le back-office, `'shadcn'` dans le portail client). | Lorsque différentes sections de l'application utilisent des identités visuelles distinctes. |
| `ui.color_scheme` | `null` *(defaults)* | Schéma de couleurs propre au profil (`auto`, `light`, `dark`). | Pour imposer `'dark'` dans un espace de travail sombre. |
| `ui.icon_set` | `'classic'` | Style d'icônes des fichiers/dossiers : `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`, ou chemin d'un répertoire SVG personnalisé (`'%kernel.project_dir%/assets/fm-icons'`). | Choisissez `'windows11'` ou `'macos'` pour un rendu familier type bureau, ou pointez vers vos SVG de marque. |
| `ui.z_index` | `null` *(defaults)* | Surcharge du `z-index` des couches superposées pour ce profil. | Lors d'une intégration dans une page comportant des éditeurs ou tiroirs à `z-index` élevé. |
| `ui.mobile_dialog_mode` | `'auto'` | Présentation des dialogues sur petits écrans (`<= 767.98px`) : `'auto'` (les modales et tiroirs latéraux passent en plein écran, les tiroirs haut/bas deviennent des feuilles natives arrondies), `'fullscreen'` (tout en plein écran) ou `'sheet'` (tout s'ouvre en feuille inférieure native). | Pour imposer `'sheet'` ou `'fullscreen'` sur l'ensemble des sélecteurs mobiles. |
| `ui.default_view` | `'grid'` | Affichage initial : `'grid'` (cartes à miniatures) ou `'list'` (tableau triable). | Utilisez `'list'` pour les archives PDF/documents et `'grid'` pour les galeries visuelles. |
| `ui.item_size` | `'md'` | Largeur des cartes en grille et taille des miniatures en liste : `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) ou entier exact en pixels (`96`–`320`). Passe automatiquement en espacement compact à `<= 132px`. | Utilisez `'lg'` / `'xl'` pour le contrôle visuel et `'sm'` / `'xs'` pour les sélecteurs compacts. |
| `ui.card_aspect_ratio` | `'4/3'` | Ratio du cadre d'aperçu des cartes : `'1/1'` (carré), `'4/3'` (standard), `'16/9'` (panoramique/vidéo) ou `'3/4'` (affiches verticales/catalogues). | Adaptez-le au format principal de vos médias (`'1/1'` pour les produits, `'16/9'` pour les vidéos/bannières). |
| `ui.folder_style` | `'tile'` | Présentation des dossiers en vue grille : `'tile'` (tuiles horizontales façon Google Drive au-dessus des fichiers) ou `'card'` (cartes pleine taille façon Windows/Finder identiques aux cartes fichiers). | Passez à `'card'` pour mettre davantage en valeur les dossiers. |
| `ui.show_size_slider` | `true` | Affiche le curseur dynamique de taille (`96px`–`320px`) et le sélecteur de style de dossiers dans le menu Paramètres. | Passez à `false` pour verrouiller la densité d'affichage. |
| `ui.volume_order` | `'local_first'` | Ordre des volumes dans la barre latérale et le sélecteur : `'local_first'`, `'network_first'` ou `'configured'` (ordre exact du YAML `volumes: [...]` sans séparateurs de groupe). | Utilisez `'network_first'` ou `'configured'` pour les workflows centrés sur le cloud. |
| `ui.show_folder_tree` | `true` | Affiche ou masque l'arborescence des dossiers dans le panneau gauche. | Passez à `false` pour les sélecteurs simples mono-dossier (TinyMCE ou avatar). |
| `ui.tree_lines` | `true` | Affiche les lignes de connexion hiérarchique dans l'arborescence. | Passez à `false` pour une barre latérale plus épurée. |
| `ui.show_folder_item_count` | `true` | Affiche un badge de compteur (`12`) à côté des dossiers. Respecte les autorisations afin de ne jamais exposer les compteurs des sous-dossiers restreints. | Conservez `true` pour une lecture rapide du contenu des dossiers. |
| `ui.folder_count_mode` | `'recursive_files'` | Mode de calcul des badges de dossier : `'recursive_files'` (tous les fichiers y compris sous-dossiers), `'files'` (fichiers directs uniquement) ou `'items'` (fichiers + sous-dossiers directs). | Choisissez `'files'` pour un décompte direct uniquement. |
| `ui.inspector_mode` | `'on_select'` | Panneau droit de détails/aperçu : `'on_select'` (s'ouvre dès qu'un élément est sélectionné), `'always'` ou `'hidden'`. | Choisissez `'hidden'` dans les fenêtres modales étroites. |
| `ui.folder_open_on` | `'dblclick'` | Geste d'ouverture des dossiers : `'dblclick'` (un simple clic sélectionne, un double-clic ouvre — convention bureau) ou `'click'` (un simple clic ouvre immédiatement — convention web/mobile). | Utilisez `'click'` pour une navigation rapide en un clic dans les back-offices. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Nombre d'éléments par page par défaut (`6`–`200`) et choix proposés dans le sélecteur. | Passez à `48` et `[24, 48, 96, 150]` pour les catalogues très fournis. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Affiche la jauge de quota dans la barre latérale et la modale cliquable d'utilisation détaillée du stockage. (Le blocage serveur `storage_quota` reste actif même si la jauge est masquée.) | Définissez `show_quota: false` si vous ne souhaitez pas exposer l'indicateur dans l'interface. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Contrôle l'aperçu PDF en Lightbox et la lecture vidéo HTML5 intégrée (appliqué à la fois dans l'UI et côté serveur dans `DownloadController`). | Passez à `false` pour forcer le téléchargement direct au lieu de l'aperçu dans le navigateur. |
| `ui.remember_last_dir` | `true` | Mémorise le dernier **volume + dossier** visité par l'utilisateur dans `localStorage` (revient automatiquement au dossier parent ou à la racine si ce dossier a été supprimé). | Passez à `false` si chaque session doit démarrer à la racine. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Demande une confirmation avant une suppression ou un déplacement par glisser-déposer. | Passez `confirm_move: false` pour accélérer la réorganisation des dossiers. |
| `ui.double_click_to_select` | `true` | En mode sélecteur Modal/OffCanvas, un double-clic sur un fichier le sélectionne immédiatement et ferme le dialogue. | Passez à `false` si le double-clic doit prévisualiser plutôt que sélectionner. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Active les menus contextuels au clic droit et la barre de navigation rapide `[ ← \| → \| ↑ \| ↻ ]` sur clic droit dans une zone vide. | Passez à `false` pour des écrans finaux verrouillés. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Active le déplacement par glisser-déposer entre dossiers ainsi que le dépôt de fichiers/dossiers depuis le système d'exploitation pour téléverser. | Passez à `false` pour une bibliothèque en lecture seule. |
| `ui.keyboard_shortcuts` | `true` | Active les raccourcis clavier (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Delete`, `Space`, `/`, `R`, `F`, `?`, etc.). | Désactivez (`false`) si votre page hôte déclare des raccourcis globaux en conflit. |
| Indicateurs `ui.show_*` | *(majoritairement `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Activez ou masquez chaque contrôle de la barre d'outils pour épurer l'interface. *(Remarque : les entrées « À propos » et « GitHub » dans Paramètres sont des éléments d'attribution intégrés et ne se désactivent pas via YAML.)* |

### 3.7. Cache par profil (`cache`), sécurité (`security`), permissions (`permissions`), IA et garde d'utilisation

#### Cache (`cache`)
| Paramètre | Défaut | Description |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Active ou désactive le cache pour ce profil. Lorsqu'un profil définit `cache.enabled: false`, `volume.cache_ttl` ne réactive pas le cache. |
| `cache.ttl` | `null` *(defaults)* | Ordre de priorité du TTL : **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Sécurité (`security`)
| Paramètre | Défaut | Description |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Active le contrôle RBAC via `FileManagerVoter` et vos Voters personnalisés. Conservez toujours `true` en production. |
| `security.csrf_protection` | `true` | Exige un en-tête `X-LiveUX-FM-CSRF` valide sur chaque requête `POST`. |
| `security.sanitize_svg` | `true` | Assainit les fichiers SVG téléversés et modifiés selon une liste blanche stricte de 55 balises (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Active l'onglet **« Importer depuis une URL »** dans la fenêtre de téléversement (protégé contre le SSRF et le DNS rebinding par `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Surcharge la journalisation d'audit PSR-3 pour ce profil (`true` / `false` / `null`). |

#### 17 permissions granulaires (`permissions`)
> [!WARNING]
> **Important : que signifie `true` sous `permissions` ?**  
> Définir une permission à **`true`** ne signifie **PAS** *« accès public / anonyme »* ! `FileManagerVoter` n'accorde `true` **qu'aux utilisateurs authentifiés (`$token->getUser() !== null`)** et refuse les requêtes anonymes.  
> De plus, **`permissions.view`** est le verrou principal : si un utilisateur ne possède pas `view`, toutes les autres actions (`upload`, `delete`, `download`, etc.) sont automatiquement refusées.

Chaque permission accepte l'un de ces 4 formats :
- `true` → Tout utilisateur authentifié
- `false` → Personne (action complètement désactivée)
- `'ROLE_ADMIN'` → Uniquement les utilisateurs disposant de ce rôle
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Les utilisateurs disposant d'au moins un de ces rôles

| Clé de permission | Défaut | Actions contrôlées |
| :--- | :--- | :--- |
| `view` | `true` | Listage des dossiers, arborescence, aperçus et **prérequis indispensable à toutes les autres permissions**. |
| `upload` | `true` | Téléversements standards/découpés, enregistrement depuis l'éditeur d'image et import par URL distante. |
| `create_folder` | `true` | Création de nouveaux dossiers. |
| `rename` | `true` | Renommage des fichiers et dossiers. |
| `clone` | `true` | Duplication d'un fichier ou dossier. |
| `move` | `true` | Déplacement d'éléments par glisser-déposer ou Couper/Coller. |
| `copy` | `true` | Copie d'éléments via Copier/Coller ou la boîte de dialogue Déplacer/Copier. |
| `delete` | `true` | Suppression de fichiers/dossiers (mise à la corbeille ou suppression définitive). |
| `trash_restore` | `true` | Restauration d'éléments depuis la corbeille. |
| `trash_purge` | `true` | Suppression définitive d'éléments de la corbeille ou vidage complet de la corbeille. |
| `download` | `true` | Téléchargement de fichiers individuels, lecture en flux des PDF/vidéos et copie de l'URL du fichier. |
| `download_zip` | `true` | Téléchargement de plusieurs éléments sélectionnés sous forme d'archive `.zip`. |
| `extract_zip` | `null` *(auto)* | Forcé à `false` tant que `zip.allow_extract: false` ; devient automatiquement `true` lorsque `zip.allow_extract: true` (restreignez à `'ROLE_ADMIN'` si nécessaire). |
| `share` | `true` | Génération de liens de partage temporaires signés par HMAC. |
| `clear_cache` | `true` | Vidage du cache des répertoires depuis Paramètres et forçage via `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Modification des métadonnées `alt`/`title`/`caption`/`tags`/point focal des fichiers et couleur/icône/épinglage des dossiers. |
| `ai_enrich` | `true` | Appel à **✨ Suggérer avec l'IA** (nécessite `view` + `edit_metadata` + `ai_enrich`). |

#### Enrichissement IA (`ai`) et garde d'utilisation des fichiers (`usage_guard`)
| Paramètre | Défaut | Description |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Active les suggestions de métadonnées par IA ; `locale` définit la langue de génération (ex. `'fr'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Nombre maximal de tags suggérés (`1`–`20`) et limite de redimensionnement avant envoi à l'IA (`256`–`2048` px, pour économiser les jetons vision). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Empêche l'envoi des fichiers situés sur des volumes `private: true` vers des API IA externes ; enrichissement automatique optionnel après téléversement (voir [Chapitre 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Avertit ou bloque les opérations **`delete`, `rename`, `move` et `overwrite`** lorsqu'un fichier est référencé dans votre base de données (voir [Chapitre 10](10-asset-usage-guard.md)). |

---

## 4. 5 recettes de profils prêtes pour la production (copier-coller)

Voici les 5 configurations réelles les plus courantes, prêtes à être intégrées dans votre projet :

### Recette 1 : Catalogue produits e-commerce (cartes carrées + WebP + réutilisation des doublons)
> **Objectif :** N'accepter que des photos produit carrées `1:1` nettes ; redimensionner et convertir automatiquement les photos de smartphone en WebP ; réutiliser le fichier existant en cas de doublon ; bloquer la suppression accidentelle de fichiers rattachés à des produits actifs.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'fr'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # Grandes cartes produit de 204px
                card_aspect_ratio: '1/1' # Ratio vitrine carré
                folder_style: 'card'     # Cartes de dossiers pleine taille pour les catégories
                show_folder_item_count: true
```

### Recette 2 : Images de couverture blog / actualités (bannières `16:9` + texte alternatif par IA)
> **Objectif :** N'accepter que des images de couverture panoramiques `16:9` haute résolution (au moins `1200×630` px), classer automatiquement les téléversements dans des dossiers `2026/11` et générer le texte alternatif SEO ainsi que les tags par IA.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Couvertures les plus récentes en premier
            ai:
                enabled: true
                locale: 'fr'
                max_tags: 6
            ui:
                item_size: 'xl'           # Larges aperçus de couverture (260px)
                card_aspect_ratio: '16/9' # Ratio bannière panoramique
```

### Recette 3 : Coffre-fort documents & PDF clients (Supabase/S3 privé + isolation par utilisateur)
> **Objectif :** Isoler chaque client dans son propre dossier de factures PDF sur un bucket privé compatible S3, bloquer toute URL publique directe, actualiser les listes cloud toutes les 60 secondes et journaliser chaque action dans le journal d'audit.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Coffre-fort PDF clients'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Aucun accès par URL directe non authentifiée
            cache_ttl: 60          # Les modifications cloud externes apparaissent sous 60 s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Chaque utilisateur ne voit que son propre dossier
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Tableau triable idéal pour les PDF
                item_size: 'sm'          # Lignes compactes
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Recette 4 : Sélecteur rapide pour éditeur de texte riche (TinyMCE / CKEditor / Tiptap)
> **Objectif :** Une fenêtre modale légère et sans distraction où un double-clic sur une image l'insère immédiatement dans l'éditeur.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Recette 5 : Médiathèque partenaires / kit presse en lecture seule
> **Objectif :** Permettre aux partenaires de parcourir logos et catalogues, de télécharger des fichiers individuels ou des archives ZIP groupées et de générer des liens de partage signés — tout en interdisant tout téléversement, modification ou suppression.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 jours
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Variables dynamiques de chemin (`DynamicPathResolver`)

Vous pouvez utiliser des jetons dynamiques dans `path` (local), `url_prefix`, `driver_options.prefix` (cloud) et `upload_subfolder` (profil) :

| Variable | Description | Exemple de sortie |
| :--- | :--- | :--- |
| `{user_id}` | Identifiant sans collision et sûr pour le système de fichiers de l'utilisateur Symfony authentifié (`guest` si non authentifié). | `42` ou `alice_a--h-9f1c2e4b...` |
| `{Y}` | Année sur 4 chiffres | `2026` |
| `{m}` | Mois sur 2 chiffres | `11` |
| `{d}` | Jour sur 2 chiffres | `25` |

```yaml
volumes:
    user_space:
        label: 'Mon espace personnel'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Isolation multi-tenant `{user_id}` sans collision :**  
> `DynamicPathResolver` génère des clés de dossier **disjointes suffixées par SHA-256 `<slug>--h-<16-hex>`** pour les adresses e-mail (`alice.a@example.com` vs `alice_a@example.com`), les variantes de casse (`Alice` vs `alice`), les noms d'utilisateur Unicode (arabe, chinois, turc), les mots réservés (`guest`, `user`, identifiants se terminant par `--h-`) et les noms de périphériques Windows (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Deux utilisateurs distincts ne peuvent jamais entrer en collision sur le même dossier.
>
> **Comportement CLI :** Les commandes console (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, etc.) ignorent automatiquement les volumes contenant `{user_id}` afin de ne jamais créer de dossier `guest/` parasite.

---

## 6. Référence complète du schéma YAML (chaque option active)

L'extrait YAML ci-dessous recense **chaque clé de configuration active** définie dans `Configuration.php` avec sa valeur par défaut et ses commentaires. Dans votre propre projet, ne déclarez que les clés que vous souhaitez personnaliser : toutes les autres héritent automatiquement de leur valeur par défaut.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) VALEURS PAR DÉFAUT GLOBALES — Héritées par tous les profils sauf surcharge
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Répertoire racine par défaut pour les profils mono-disque simplifiés
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <personnalisé>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = locale de la requête/du Translator Symfony (12 langues intégrées, repli 'en') | 'fr' | 'en' | 'tr' | ...
        z_index: 1050                                      # z-index de base pour les couches superposées, popovers et modales (>= 1)
        auto_inject_dialog_host: true                      # Injecter automatiquement le DialogHost Modal/OffCanvas dans les réponses HTML complètes
        dialog_host_profile: 'default'                     # Profil par défaut utilisé par le DialogHost auto-injecté

        audit_log:
            enabled: false                                 # Journaliser les 9 mutations de fichiers/dossiers sur un canal PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Modèle IA par défaut si non surchargé sur un profil
            platform_service: null                         # ID de service Symfony AI PlatformInterface personnalisé (null = auto)

        cache:
            enabled: true                                  # Interrupteur global du cache PSR-6 des répertoires/arborescences
            pool: 'cache.app'                              # ID du service de pool de cache PSR-6 Symfony
            ttl: 3600                                      # Durée de vie globale du cache en secondes (0 = désactivé)

        metadata:
            enabled: true                                  # Couleurs/icônes/épingles de dossiers et alt/title/caption/tags/focalPoint des fichiers
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # Fichier de stockage pour le driver json_file
            connection: null                               # Nom de connexion DBAL optionnel pour le driver doctrine (null = connexion de l'entity_manager)
            entity_manager: 'default'                      # Nom de l'EntityManager / de la connexion pour le driver doctrine
            table: 'liveux_fm_metadata'                    # Nom de la table DBAL pour le driver doctrine (voir Chapitre 13)
            auto_create_table: true                        # Créer automatiquement la table de métadonnées si absente (mettre à false avec Migrations)

    # =========================================================================
    # 2) VOLUMES DE STOCKAGE — Définitions des systèmes de fichiers locaux et cloud
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Stockage local'                        # Nom affiché dans la barre latérale et le fil d'Ariane
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Chemin physique (prend en charge {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Préfixe d'URL publique navigateur (laisser null si private: true)
            private: false                                 # true = servir tous les fichiers via DownloadController protégé par RBAC
            cache_ttl: null                                # TTL de cache par volume en secondes (null = hériter, 0 = désactivé)
            driver_options: []                             # Vide pour LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Correspond à resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # URL publique du bucket/CDN (null si private: true)
            private: false
            cache_ttl: 60                                  # Rafraîchir les modifications cloud externes toutes les 60 secondes
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Nom du bucket
                region: '%env(AWS_S3_REGION)%'             # Région ('auto' pour Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # URL d'endpoint S3 personnalisé pour les fournisseurs hors AWS
                prefix: 'media/{Y}'                        # Préfixe racine optionnel dans le bucket (variables dynamiques supportées)
                use_path_style_endpoint: true              # true pour Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # Clé d'accès S3 (Access Key ID)
                secret: '%env(AWS_S3_SECRET)%'             # Clé secrète S3 (Secret Access Key)
                token: null                                # Jeton de session temporaire AWS STS optionnel
                version: 'latest'                          # Version de l'API AWS S3 (par défaut : 'latest')
                ca_bundle: null                            # Chemin optionnel vers un bundle CA .pem (corrige cURL error 60 sous Windows)
                disable_acl: true                          # Auto true pour endpoints personnalisés ; mettre true sur AWS S3 Bucket owner enforced
                retain_visibility: false                   # Auto false pour endpoints personnalisés (évite d'envoyer x-amz-acl)
                request_checksum_calculation: 'when_required'  # Compatibilité AWS SDK >= 3.337 (auto pour endpoints personnalisés)
                response_checksum_validation: 'when_required'  # Compatibilité AWS SDK >= 3.337 (auto pour endpoints personnalisés)

        custom_flysystem_example:
            label: 'Archive SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # ID du service League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PROFILS — Jeux de règles indépendants par cas d'usage
    # =========================================================================
    profiles:
        full_reference:
            # --- Volumes montés, locale et options raccourcies mono-disque ---
            locale: null                                   # null = hériter de defaults.locale | 'fr' | 'en' | 'tr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Si vide, un volume implicite est créé à partir de driver/path ci-dessous
            driver: 'LocalFileSystem'                      # Driver raccourci utilisé uniquement si volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Chemin raccourci utilisé uniquement si volumes: []
            url_prefix: '/uploads'                         # Préfixe d'URL raccourci utilisé uniquement si volumes: []
            private: false                                 # Option private raccourcie utilisée uniquement si volumes: []
            driver_options: []                             # Options driver raccourcies utilisées uniquement si volumes: []

            # --- Listage, masquage et tri par défaut ---
            ignore_dot_files: true                         # Masquer et bloquer les fichiers commençant par un point (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Motifs regex/jokers masqués des listes et de l'accès direct
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Toujours afficher les dossiers avant les fichiers

            # --- Téléversements, quota et gestion des collisions ---
            upload_subfolder: null                         # ex. '{Y}/{m}' — placer les téléversements dans des sous-dossiers dynamiques
            upload_allow: []                               # Vide = tous les types sûrs ; non vide = liste blanche MIME stricte
            upload_deny:                                   # Types MIME dangereux toujours bloqués (47 par défaut si omis)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Taille assemblée maximale pour un fichier unique (ex. '50M', '2G')
            storage_quota: null                            # Quota disque total du profil (ex. '500M', '5G' ; null = illimité)
            overwrite_policy: 'rename'                     # rename (ajoute -1) | overwrite | reject

            # --- Téléversements découpés (contourne upload_max_filesize de PHP) ---
            chunk_upload:
                enabled: true                              # Découper les gros fichiers en morceaux reprenables
                chunk_size: '2M'                           # Taille cible des morceaux (auto-plafonnée à 80 % des limites PHP)
                parallel_uploads: 3                        # Téléversements simultanés max (1..10)
                max_retries: 3                             # Nouvelles tentatives automatiques par morceau sur erreur réseau (0..10)

            # --- Détection des doublons (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Détecter les contenus en double lors du téléversement
                policy: 'warn'                             # warn | reject | reuse (retourner le fichier existant)
                max_scan_files: 5000                       # Nombre max de fichiers hachés pour l'index des doublons (100..50000)

            # --- Normalisation automatique des noms de fichiers ---
            naming:
                slugger: true                              # Translittérer les noms Unicode en slugs ASCII propres
                slugger_locale: null                       # null = hériter de la locale du profil (ex. 'fr', 'de', 'tr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Préfixe statique/dynamique (ex. 'produit-')
                append: ''                                 # Suffixe statique/dynamique avant l'extension (ex. '-{Y}{m}{d}')
                apply_on_rename: true                      # Appliquer les règles slugger/transformer lors d'un renommage
                apply_affixes_on_rename: false             # Réappliquer prepend/append lors d'un renommage
                allow_extension_change: false              # Autoriser le changement d'extension (.jpg -> .php) au renommage

            # --- Optimisation d'image au téléversement ---
            image_optimization:
                enabled: false                             # Redresser, supprimer EXIF, redimensionner et compresser au téléversement
                auto_orient: true                          # Corriger l'orientation EXIF des smartphones
                strip_exif: true                           # Supprimer les données EXIF GPS et appareil photo (vie privée et poids)
                max_width: 2400                            # Largeur max proportionnelle en px (>= 100)
                max_height: 2400                           # Hauteur max proportionnelle en px (>= 100)
                convert_to_webp: false                     # Convertir automatiquement les JPEG/PNG téléversés en WebP
                quality: 85                                # Qualité de sortie (10..100)

            # --- Contraintes de résolution et de ratio d'image ---
            image_constraints:
                min_width: null                            # Largeur minimale en px (ex. 800)
                min_height: null                           # Hauteur minimale en px (ex. 600)
                max_width: null                            # Largeur maximale en px (vérifiée après image_optimization)
                max_height: null                           # Hauteur maximale en px
                aspect_ratio: null                         # Ratio requis (ex. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Écart toléré par rapport au ratio (0.0..0.5 ; 0.05 = 5 %)

            # --- Moteur de miniatures ---
            thumbnail:
                enabled: true                              # Générer des aperçus en cache pour les cartes image
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Largeur de miniature en px (>= 32)
                height: 240                                # Hauteur de miniature en px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Qualité de compression (10..100)
                fit: 'contain'                             # contain (adapter dans le cadre) | cover (recadrer pour remplir)
                lazy_load: true                            # Ajouter loading="lazy" aux miniatures des cartes
                cache_dir: '%kernel.project_dir%/public/media-cache' # Répertoire local de cache des miniatures générées

            # --- Corbeille (suppression logique) ---
            trash:
                enabled: true                              # Déplacer les éléments supprimés vers la corbeille au lieu de supprimer définitivement
                directory: '.trash'                        # Nom du répertoire caché de la corbeille
                retention_days: 30                         # Durée de rétention en jours pour file-manager:trash:purge
                max_size: '500M'                           # Taille maximale cumulée de la corbeille

            # --- Téléchargement ZIP et extraction sécurisée d'archives ---
            zip:
                enabled: true                              # Activer les téléchargements ZIP multi-fichiers/dossiers
                max_files: 100                             # Nombre max de fichiers dans un téléchargement ZIP
                max_total_size: '250M'                     # Taille totale max pour un téléchargement ZIP
                allow_extract: false                       # Activer la décompression (désactivée par défaut ; protection sur 7 couches)
                max_extract_files: 200                     # Nombre max de fichiers extraits par archive (1..2000)
                max_extract_size: '100M'                   # Taille totale max extraite (protection Zip-Bomb)
                max_compression_ratio: 100                 # Taux de compression max autorisé (1..1000 — protection Zip-Bomb)

            # --- Liens de partage signés temporaires (HMAC) ---
            share:
                enabled: true                              # Autoriser la création d'URL de partage signées
                default_ttl: 86400                         # Durée d'expiration par défaut en secondes (86400 = 24 heures)
                max_ttl: 2592000                           # Durée maximale sélectionnable en secondes (2592000 = 30 jours)
                allow_inline_preview: true                 # Autoriser l'aperçu inline dans le navigateur pour les types MIME sûrs

            # --- Enrichissement Symfony AI (optionnel) ---
            ai:
                enabled: false                             # Afficher « ✨ Suggérer avec l'IA » dans la modale de métadonnées
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = locale du profil/de la requête (ex. 'fr' => texte alternatif/tags en français)
                max_tags: 6                                # Nombre max de tags suggérés (1..20)
                max_image_dimension: 1024                  # Limite de redimensionnement avant envoi à l'IA (256..2048 px)
                block_private_volumes: true                # true = bloquer les appels IA pour les volumes private: true (défaut : true)
                auto_enrich_on_upload: false               # Lancer automatiquement l'enrichissement IA après téléversement

            # --- Garde d'utilisation des fichiers (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Vérifier les références en base avant toute mutation
                policy: 'warn'                             # warn (afficher les références + autoriser le forçage) | block (interdire l'action)
                enforce_server_side: true                  # Appliquer aussi sur les appels API directs
                max_references: 20                         # Nombre max de références listées dans la modale (1..200)

            # --- Cache par profil ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | secondes (0 = désactivé)

            # --- Options de sécurité ---
            security:
                voter_enabled: true                        # Activer les contrôles RBAC via FileManagerVoter
                csrf_protection: true                      # Exiger X-LiveUX-FM-CSRF sur les requêtes POST
                sanitize_svg: true                         # Assainir les SVG téléversés/modifiés
                allow_remote_url_import: false             # Activer l'onglet « Importer depuis une URL » (protégé contre le SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 permissions RBAC granulaires (true = utilisateur authentifié | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Permission principale requise pour le listage et toutes les autres actions
                upload: true                               # Téléversements, enregistrements éditeur d'image et imports URL
                create_folder: true                        # Créer de nouveaux dossiers
                rename: true                               # Renommer fichiers et dossiers
                clone: true                                # Dupliquer fichiers/dossiers
                move: true                                 # Déplacer des éléments (glisser-déposer et couper/coller inclus)
                copy: true                                 # Copier des éléments (copier/coller inclus)
                delete: true                               # Supprimer des éléments (corbeille ou suppression définitive)
                trash_restore: true                        # Restaurer des éléments depuis la corbeille
                trash_purge: true                          # Purger définitivement des éléments / vider la corbeille
                download: true                             # Télécharger un fichier individuel et copier l'URL
                download_zip: true                         # Télécharger plusieurs fichiers en ZIP
                extract_zip: false                         # Extraire des archives ZIP (nécessite aussi zip.allow_extract: true)
                share: true                                # Créer des liens de partage signés
                clear_cache: true                          # Vider le cache depuis Paramètres et forcer ?refresh=1
                edit_metadata: true                        # Modifier alt/tags/point focal d'un fichier et couleur/icône/épingle d'un dossier
                ai_enrich: true                            # Exécuter l'enrichissement des métadonnées par IA

            # --- Indicateurs et dimensionnement de l'interface utilisateur (UI) ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <répertoire perso>
                z_index: null                              # null = defaults.z_index | entier (>= 1)
                mobile_dialog_mode: 'auto'                 # Comportement des dialogues sur mobile (<= 767px) : auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compact)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (tuile compacte) | card (carte de dossier pleine taille)
                show_size_slider: true                     # Afficher le curseur de taille dynamique et le style de dossier dans Paramètres
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Afficher l'arborescence des dossiers à gauche
                tree_lines: true                           # Afficher les lignes hiérarchiques dans l'arborescence
                show_folder_item_count: true               # Afficher les badges de compteur sur les dossiers
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Afficher la jauge de quota latérale et la pastille d'en-tête
                show_quota_details: true                   # Activer la modale cliquable d'utilisation détaillée du stockage
                per_page: 24                               # Nombre d'éléments par page par défaut (6..200)
                per_page_options: [24, 48, 96]             # Options du sélecteur d'éléments par page
                show_perf_badge: false                     # Afficher le badge CACHE HIT / DISK dans la barre d'outils
                show_nav_buttons: true                     # Interrupteur général du groupe Précédent / Suivant / Parent / Actualiser
                show_history_buttons: true                 # Afficher les boutons d'historique Précédent / Suivant
                show_up_button: true                       # Afficher le bouton Dossier parent
                show_refresh_button: true                  # Afficher le bouton Actualiser (?refresh=1)
                show_breadcrumbs: true                     # Afficher le fil d'Ariane cliquable et compatible dépôt
                show_search: true                          # Afficher le champ de recherche et la bascule de recherche approfondie
                show_type_filter: true                     # Afficher le filtre par type (Images / Vidéos / Documents / Dossiers)
                show_view_toggle: true                     # Afficher la bascule Grille / Liste
                show_pagination: true                      # Afficher la barre de pagination inférieure
                show_per_page_selector: true               # Afficher le menu déroulant d'éléments par page
                show_sort: true                            # Afficher le sélecteur de tri et le bouton d'inversion de sens
                show_fullscreen_button: true               # Afficher le bouton plein écran
                show_settings_button: true                 # Afficher le menu déroulant Paramètres (⚙)
                show_footer: true                          # Afficher la barre d'état inférieure
                show_context_nav: true                     # Afficher la bande de navigation rapide dans le menu contextuel sur zone vide
                allow_dropzone_upload: true                # Autoriser le dépôt de fichiers/dossiers depuis l'OS sur la liste
                remember_last_dir: true                    # Mémoriser le dernier volume et dossier (auto-réparation si supprimé)
                confirm_delete: true                       # Afficher une fenêtre de confirmation avant suppression
                confirm_move: true                         # Afficher une fenêtre de confirmation avant déplacement/copie
                double_click_to_select: true               # Un double-clic sélectionne le fichier et ferme le sélecteur Modal/OffCanvas
                allow_context_menu: true                   # Activer le menu contextuel au clic droit
                allow_pdf_preview: true                    # Lightbox PDF inline (false force le téléchargement)
                allow_video_preview: true                  # Lecteur vidéo inline (false force le téléchargement)
                keyboard_shortcuts: true                   # Activer les raccourcis clavier contextualisés
                allow_drag_move: true                      # Autoriser le glisser-déposer d'éléments dans des dossiers pour les déplacer
```

---

[← Précédent : 01 — Prérequis et installation](01-requirements-and-installation.md) | [Index de la documentation](README.md) | [Suivant : 03 — Modes d'utilisation et API JS →](03-usage-modes-and-js-api.md)
