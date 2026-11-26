# 01 — Prérequis, dépendances et installation

[← Index de la documentation](README.md) | [Suivant : 02 — Configuration et profils →](02-configuration-and-profiles.md)

Ce guide vous accompagne pas à pas pour installer LiveUX FileManagerBundle de zéro, en expliquant **chaque étape et son utilité**, même s'il s'agit de votre tout premier composant Symfony UX.

> [!NOTE]
> **En termes simples : à quoi sert ce bundle ?**  
> Il ajoute un gestionnaire de fichiers complet (à la manière de Google Drive ou de l'Explorateur Windows) à votre application Symfony — qu'il s'agisse d'un back-office d'administration, d'une boutique e-commerce ou d'un espace client. Vos utilisateurs peuvent **téléverser des fichiers, organiser des dossiers, recadrer des images, prévisualiser des PDF et des vidéos, et sélectionner des fichiers directement depuis vos formulaires** en un seul clic.  
> Il ne nécessite **aucun framework CSS** (Bootstrap, Tailwind, etc.) ni **jQuery**, et fonctionne en 5 minutes avec Symfony AssetMapper sans Node.js ni npm.

---

## 1. Prérequis système

Avant d'installer le bundle, vérifiez que votre poste local ou votre serveur respecte ces versions minimales :

| Composant | Version minimale | Pourquoi c'est nécessaire | Commande de vérification |
| :--- | :--- | :--- | :--- |
| **PHP** | `8.3` ou supérieure | Utilise les fonctionnalités PHP 8.3/8.4 (classes `readonly`, constantes de classe typées). | `php -v` |
| **Symfony** | `6.4 LTS`, `7.2+` ou `8.x` | Entièrement testé et pris en charge sur Symfony 6.4 LTS, 7.2, 7.3, 7.4 et 8.x. | `php bin/console --version` |
| **Composer** | `2.5+` | Installation des paquets et chargement automatique PSR-4. | `composer --version` |
| **Navigateur** | Navigateur moderne à jour | Couche supérieure HTML5 `<dialog>`, modules ES et propriétés personnalisées CSS (Chrome, Edge, Firefox, Safari). | — |

---

## 2. Extensions PHP

Les extensions PHP ajoutent des capacités natives à PHP, comme l'analyse binaire des fichiers, le traitement XML ou la manipulation d'images.

### Extensions PHP requises
Activées par défaut dans la quasi-totalité des installations PHP modernes :

| Extension | Rôle | Exemple concret |
| :--- | :--- | :--- |
| `ext-fileinfo` | Inspecte le **contenu réel du fichier (octets magiques / magic bytes)**, et pas uniquement son extension. | Si un attaquant renomme `shell.php` en `photo.jpg` pour tenter de le téléverser, `ext-fileinfo` le détecte et le rejette immédiatement. |
| `ext-dom` & `ext-libxml` | Analyse en toute sécurité l'arbre XML des fichiers `.svg` téléversés (`SvgSanitizer`). | Supprime les balises `<script>` malveillantes ou les charges XXE dissimulées dans un logo SVG tout en conservant le graphique propre. |

*(Remarque : `ext-json` est intégré au cœur de PHP depuis PHP 8.0 et ne nécessite aucune installation séparée.)*

### Extensions PHP optionnelles (recommandées)
Si l'une d'entre elles est absente, le gestionnaire de fichiers **continue de fonctionner** et bascule automatiquement en mode dégradé sans erreur :

| Extension | Rôle | Comportement de repli si absente |
| :--- | :--- | :--- |
| `ext-gd` **ou** `ext-imagick` | Génère des miniatures rapides en WebP/JPEG et exécute l'optimisation automatique des images lors du téléversement. | Affiche l'image originale ou une icône de fichier selon l'option `thumbnail.on_missing_driver`. |
| `ext-exif` | Lit les métadonnées d'orientation EXIF des smartphones avec `ext-gd`, et redresse automatiquement les photos pivotées (`auto_orient: true`). | Les photos sont enregistrées dans leur orientation d'origine (non requis si `ext-imagick` est installé). |
| `ext-zip` (`ZipArchive`) | Active le téléchargement groupé en `.zip` **et** l'extraction sécurisée d'archives (`zip.allow_extract: true`). | Les boutons **Télécharger en ZIP** et **Extraire le ZIP** sont masqués automatiquement. |

Vérifiez quelles extensions sont actives sur votre machine en une seule commande :

```bash
# Linux / macOS
php -m | grep -E "fileinfo|dom|libxml|gd|imagick|exif|zip"

# Windows PowerShell
php -m | Select-String "fileinfo|dom|libxml|gd|imagick|exif|zip"
```

---

## 3. Paquets Composer requis et optionnels

Lorsque vous lancez `composer require liveux/file-manager-bundle`, **tous les paquets requis sont installés automatiquement**. Connaître le rôle de chaque dépendance facilite grandement le débogage :

### Paquets requis (installés automatiquement)
| Paquet | Contrainte de version | Rôle |
| :--- | :--- | :--- |
| `league/flysystem` | `^3.35.3` | Abstraction unifiée du système de fichiers pour disque local, AWS S3, Cloudflare R2, Supabase, MinIO et SFTP. |
| `symfony/ux-twig-component` | `^2.20\|^3.0` | Rend le gestionnaire de fichiers via `{{ component('LiveUX:FileManager:Browser') }}` avec un SSR en 0 ms. |
| `symfony/stimulus-bundle` | `^2.20\|^3.0` | Pilote le cache de répertoires côté client, le glisser-déposer, les menus contextuels, la Lightbox et les sélecteurs de formulaire. |
| `symfony/framework-bundle` | `^6.4\|^7.2\|^8.0` | Cœur d'exécution Symfony, routage et pools de cache (`cache.app`). |
| `symfony/config` & `symfony/dependency-injection` | `^6.4\|^7.2\|^8.0` | Validation du schéma `liveux_file_manager.yaml` et câblage du conteneur de services. |
| `symfony/event-dispatcher` | `^6.4\|^7.2\|^8.0` | Déclenche 9 événements métier (`FileUploadedEvent`, `FileDeletedEvent`, `ItemRenamedEvent`, etc.). |
| `symfony/http-foundation` | `^6.4\|^7.2\|^8.0` | Téléchargements sécurisés, streaming vidéo HTTP 206 (`Range`), ETags et en-têtes de cache HTTP. |
| `symfony/twig-bundle` | `^6.4\|^7.2\|^8.0` | Templates Twig, helpers d'icônes/traductions et thème de formulaire automatique. |
| `symfony/form` | `^6.4\|^7.2\|^8.0` | Champ de formulaire `FileManagerPickerType` pour sélection simple et galerie multi-fichiers. |
| `symfony/mime` | `^6.4\|^7.2\|^8.0` | Détection côté serveur du type MIME des fichiers téléversés. |
| `symfony/string` | `^6.4\|^7.2\|^8.0` | Translittère les noms de fichiers multilingues (français, turc, arabe, cyrillique, etc.) en slugs SEO propres. |
| `symfony/security-core` & `symfony/security-csrf` | `^6.4\|^7.2\|^8.0` | RBAC par Voter à 17 permissions et protection CSRF `X-LiveUX-FM-CSRF`. |
| `symfony/cache-contracts` & `symfony/translation-contracts` | `^3.5` | Contrats de mise en cache des répertoires et d'interface multilingue. |

### Paquets optionnels (à installer uniquement selon vos besoins)
| Paquet | Commande d'installation | Quand l'installer |
| :--- | :--- | :--- |
| `symfony/asset-mapper` | `composer require symfony/asset-mapper` | Livraison JS et CSS sans étape de build, sans Node.js ni npm *(recommandé)*. |
| `symfony/security-bundle` | `composer require symfony/security-bundle` | Permissions basées sur les rôles (`ROLE_USER` / `ROLE_ADMIN`) et isolation par utilisateur `{user_id}`. |
| `symfony/translation` | `composer require symfony/translation` | S'intègre au Translator de Symfony pour les **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) ou vos locales applicatives personnalisées. |
| `symfony/console` & `symfony/yaml` | `composer require symfony/console symfony/yaml` | Commandes CLI (`file-manager:doctor`, `thumbs:warmup`, `trash:purge`, etc.) et fichiers de configuration YAML. |
| `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` | `composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php` | Connexion à AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO ou Google Cloud Storage. |
| `doctrine/dbal` + `doctrine/doctrine-bundle` | `composer require doctrine/doctrine-bundle` | Déploiements multi-serveurs / derrière répartiteur de charge avec `defaults.metadata.driver: 'doctrine'` (voir [Chapitre 13](13-doctrine-integration.md)). |
| `easycorp/easyadmin-bundle` | `composer require easycorp/easyadmin-bundle` | Champ intégré `FileManagerField` pour les back-offices EasyAdmin 4/5 (voir [Chapitre 05](05-formtype-and-editor-integrations.md)). |
| `symfony/ai-bundle` + `symfony/ai-platform` | `composer require symfony/ai-bundle symfony/ai-platform` | **✨ Suggérer avec l'IA** pour générer automatiquement texte alternatif, légende et tags (voir [Chapitre 09](09-symfony-ai-integration.md)). |

---

## 4. Installation étape par étape

Suivez ces 7 étapes dans l'ordre pour disposer d'un gestionnaire de fichiers opérationnel.

### Étape 1 : Installer le bundle

**Installation standard (via Packagist) :**
```bash
composer require liveux/file-manager-bundle
```

**Dépôt local de type `path` (si vous développez dans `bundles/liveux-file-manager-bundle`) :**  
Ajoutez une entrée `repositories` dans votre fichier `composer.json` racine :

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./bundles/liveux-file-manager-bundle",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Puis installez le paquet local :
```bash
composer require liveux/file-manager-bundle:@dev
```

---

### Étape 2 : Vérifier `config/bundles.php`

Symfony Flex enregistre normalement le bundle automatiquement. Sinon, vérifiez que ces entrées figurent bien dans `config/bundles.php` :

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true], // Si vous utilisez l'authentification/les rôles
    Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    LiveUX\FileManagerBundle\FileManagerBundle::class => ['all' => true],
];
```

> [!NOTE]
> `FileManagerBundle` **enregistre automatiquement** son espace de noms Twig (`@LiveUXFileManager`), son chemin AssetMapper (`@liveux/file-manager-bundle`) et son thème de formulaire (`@LiveUXFileManager/form/picker.html.twig`) au démarrage du noyau. Vous n'avez pas besoin de modifier `twig.yaml` ni `asset_mapper.yaml`.

---

### Étape 3 : Importer les routes

Créez le fichier `config/routes/liveux_file_manager.yaml` pour activer les points de terminaison API AJAX, téléchargement, miniatures et téléversement du bundle :

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
```

Ce simple import enregistre **12 points de terminaison sécurisés** :

| Méthode et chemin | Rôle |
| :--- | :--- |
| `GET /_liveux-file-manager/dialog` | Charge le HTML du gestionnaire de fichiers dans une fenêtre modale ou un tiroir OffCanvas. |
| `GET /_liveux-file-manager/api/list` | Retourne au format JSON la liste des fichiers et sous-dossiers d'un répertoire. |
| `GET /_liveux-file-manager/api/tree` | Récupère l'arborescence complète des dossiers pour la barre latérale en une seule requête. |
| `GET /_liveux-file-manager/api/trash` | Liste les éléments placés dans la corbeille (suppression logique). |
| `GET /_liveux-file-manager/api/preview-text` | Retourne un aperçu texte/code sécurisé (plafonné à 64 Ko) pour `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, etc. |
| `POST /_liveux-file-manager/api/action` | Exécute les mutations : création de dossier, déplacement, copie, renommage, suppression, restauration/purge de corbeille, couleurs/icônes de dossier, métadonnées, enrichissement IA, import par URL distante et extraction ZIP. |
| `POST /_liveux-file-manager/upload` | Gère les téléversements standards et découpés en morceaux (chunked upload). |
| `POST /_liveux-file-manager/image-edit` | Enregistre les images recadrées, redimensionnées ou pivotées depuis l'éditeur d'image Canvas intégré. |
| `GET /_liveux-file-manager/download` | Sert les téléchargements sécurisés, les aperçus inline PDF/vidéo (avec support `Range`) et les volumes `private: true`. |
| `GET /_liveux-file-manager/share` | Sert les liens de partage temporaires signés par HMAC. |
| `POST /_liveux-file-manager/zip` | Diffuse en flux continu les fichiers et dossiers sélectionnés sous forme d'une archive `.zip` unique. |
| `GET /_liveux-file-manager/thumb` | Génère et sert des miniatures WebP/JPEG/PNG avec ETag et en-têtes de cache HTTP sensibles au contexte. |

---

### Étape 4 : Créer `config/packages/liveux_file_manager.yaml`

Créez le fichier `config/packages/liveux_file_manager.yaml`. La configuration de départ ci-dessous déclare explicitement **les 17 permissions granulaires** :

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5' # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled
        locale: null        # null = hérite automatiquement de la locale active de Symfony (repli garanti sur 'en')
                            # Ou figez une locale précise comme 'fr', 'en', 'tr', 'de', 'es', 'ar', 'ja', 'zh_CN'

    volumes:
        local_uploads:
            label: 'Mes fichiers'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['local_uploads']
            upload_max_size: '15M'
            ui:
                icon_set: 'windows11' # classic | windows11 | macos | linux | minimal | vscode
                inspector_mode: 'on_select'
            permissions:
                view: 'ROLE_USER'
                upload: 'ROLE_USER'
                create_folder: 'ROLE_USER'
                rename: 'ROLE_USER'
                clone: 'ROLE_USER'
                move: 'ROLE_USER'
                copy: 'ROLE_USER'
                delete: 'ROLE_USER'
                trash_restore: 'ROLE_USER'
                trash_purge: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false        # L'extraction ZIP est désactivée par défaut (requiert aussi zip.allow_extract: true)
                share: 'ROLE_USER'
                clear_cache: 'ROLE_USER'
                edit_metadata: 'ROLE_USER'
                ai_enrich: 'ROLE_USER'
```

> [!IMPORTANT]
> **Note de sécurité critique (`permissions`) :**  
> Toute clé **omise** sous `permissions` (à l'exception de `extract_zip`) vaut `true` par défaut. Ici, `true` signifie **« tout utilisateur authentifié »** — et NON les visiteurs anonymes. Si seul `ROLE_ADMIN` doit pouvoir vider définitivement la corbeille (`trash_purge`) ou créer des liens de partage (`share`), définissez explicitement ces clés à `'ROLE_ADMIN'`.

> [!TIP]
> **Vous testez en local sans système d'authentification pour l'instant ?**  
> Pendant un prototypage local sans connexion utilisateur, vous pouvez temporairement définir `profiles.default.security.voter_enabled: false`. **Ne laissez JAMAIS `voter_enabled: false` en production** — consultez le guide [06 — Sécurité et autorisations](06-security-and-authorization.md).

> [!NOTE]
> **12 langues d'interface intégrées et RTL automatique (`defaults.locale: null`) :**  
> En laissant `locale: null`, le gestionnaire de fichiers suit automatiquement la locale active de la requête Symfony parmi les **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), active automatiquement le **RTL (`dir="rtl"`)** pour l'arabe et les autres langues RTL (persan, hébreu, ourdou, etc.), normalise les locales BCP-47 et POSIX (`fr_FR.UTF-8`, `de_DE.UTF-8`, `zh-Hans-CN`), et bascule proprement sur **l'anglais (`en`)** pour les locales non prises en charge.

---

### Étape 5 : Brancher CSS et JavaScript (zéro configuration !)

**Vous ne savez pas si votre projet utilise AssetMapper ou Webpack Encore ?**  
Regardez à la racine de votre projet :
- Si vous voyez `importmap.php` → suivez l'**Option A (AssetMapper)**.
- Si vous voyez `webpack.config.js` → suivez l'**Option B (Webpack Encore)**.

> [!TIP]
> **Prise en charge `autoimport` zéro configuration :**  
> LiveUX FileManagerBundle déclare **`autoimport`** dans son `assets/package.json` pour `@liveux/file-manager-bundle/styles/core.css` ainsi que le contrôleur `dialog_controller.js` chargé de manière anticipée (`eager`), qui charge `file_manager_api.js`. Dès que `assets/controllers.json` est activé, **vous n'avez pas besoin d'ajouter la moindre instruction `import` manuelle dans `assets/app.js` !**

#### Option A : Symfony AssetMapper (recommandé — sans Node.js ni npm)

1. Vérifiez que les contrôleurs du bundle sont activés dans `assets/controllers.json` (Symfony Flex l'ajoute automatiquement ; sinon ajoutez ce bloc) :
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

2. Les projets Symfony 6.4 / 7.2+ / 8.x appellent déjà `startStimulusApp()` dans `assets/bootstrap.js` ou `assets/stimulus_bootstrap.js`, ce qui lit `controllers.json` automatiquement :
```javascript
// assets/stimulus_bootstrap.js (Symfony 8.x) ou assets/bootstrap.js (Symfony 6.4/7.x)
import { startStimulusApp } from '@symfony/stimulus-bundle';

export const app = startStimulusApp();
```

3. Assurez-vous que votre gabarit de base (`templates/base.html.twig`) inclut `importmap('app')` :
```twig
{# templates/base.html.twig #}
{% block javascripts %}
    {{ importmap('app') }}
{% endblock %}
```

4. *(Optionnel — lors d'un déploiement en production)* Compilez les assets statiques :
```bash
php bin/console asset-map:compile
```

> [!NOTE]
> **Assets de production pré-minifiés (`assets/dist/`) :**  
> Comme AssetMapper ne minifie pas les fichiers JS/CSS par défaut, LiveUX FileManagerBundle embarque des modules JavaScript et `core.css` pré-minifiés avec `esbuild` dans `assets/dist/` (`747 Ko → 410 Ko` minifiés ; `~93 Ko` en Gzip, ou **`~77 Ko`** en Brotli). `FileManagerBundle` mappe automatiquement `assets/dist/` dans AssetMapper : **ni votre poste ni votre serveur de production n'ont besoin de Node.js ou de npm**.  
> Sur Symfony 7.3+ / 8.x, vous pouvez aussi activer `precompress: true` dans `config/packages/asset_mapper.yaml` pour générer automatiquement les fichiers `.br` et `.gz` lors de `asset-map:compile`.

#### Option B : Webpack Encore

1. Pour les projets Webpack Encore (y compris Symfony 8 + StimulusBundle 3.x), vérifiez que `@symfony/stimulus-bridge` est installé :
```bash
npm install --save-dev @symfony/stimulus-bridge
```

2. Vérifiez les 3 contrôleurs Stimulus et le bloc `autoimport` dans `assets/controllers.json` :
```json
{
    "controllers": {
        "@liveux/file-manager-bundle": {
            "dialog": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@liveux/file-manager-bundle/styles/core.css": true
                }
            },
            "browser": { "enabled": true, "fetch": "lazy" },
            "picker": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

3. Vérifiez que `startStimulusApp` est bien initialisé dans `assets/bootstrap.js` (`assets/app.js` l'importe déjà — aucun import CSS/JS manuel n'est nécessaire) :
```javascript
// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

export const app = startStimulusApp(
    require.context('@symfony/stimulus-bridge/lazy-controller-loader!./controllers', true, /\.[jt]sx?$/)
);
```

4. Compilez vos assets :
```bash
npm run build
# Ou surveillez en continu pendant le développement : npm run watch
```

---

### Étape 6 : `DialogHost` (injection automatique — aucun balisage à ajouter !)

Pour ouvrir une fenêtre modale, un tiroir OffCanvas, un `FileManagerPickerType` ou un sélecteur TinyMCE/CKEditor/Tiptap depuis n'importe quelle page, un conteneur `<dialog>` doit être présent dans le DOM.

L'écouteur `AutoInjectDialogHostSubscriber` du bundle injecte automatiquement cet hôte juste avant `</body>` sur les réponses HTML complètes (`defaults.auto_inject_dialog_host: true`) — vous n'avez donc pas à modifier `base.html.twig`.

- **Détection intelligente :** Ne modifie jamais les réponses JSON, AJAX, Turbo Frame, les redirections ni les téléchargements binaires, et ignore l'injection si un `DialogHost` manuel est déjà présent.
- **0 ms de surcharge au chargement de la page :** N'interroge ni les répertoires ni l'arborescence au chargement initial ; les données ne sont chargées qu'au moment où l'utilisateur ouvre la boîte de dialogue.
- **Vous préférez un placement manuel sur certaines pages uniquement ?** Définissez `defaults.auto_inject_dialog_host: false` et incluez cet extrait juste avant `</body>` dans les gabarits de votre choix :

```twig
{# Optionnel — requis uniquement si auto_inject_dialog_host: false #}
    {% include '@LiveUXFileManager/components/DialogHost.html.twig' with { profile: 'default' } %}
</body>
```

---

### Étape 7 : Vérifier l'installation (`file-manager:doctor`)

Videz le cache et lancez la commande de diagnostic intégrée :

```bash
php bin/console cache:clear
php bin/console file-manager:doctor
```

Si vous avez configuré des volumes cloud (S3, R2, Supabase, MinIO), ajoutez `--check-remote` pour tester la connectivité réelle :
```bash
php bin/console file-manager:doctor --check-remote
```

Exemple de sortie saine (9 vérifications d'environnement + tableau d'état des profils/volumes) :
```text
LiveUX FileManagerBundle — System Diagnostics
=============================================
  PHP Version                 OK       8.4.15
  upload_max_filesize         INFO     2M (Chunked upload automatically handles larger files)
  post_max_size               INFO     8M
  cURL CA Bundle              OK       C:\wamp64\bin\php\cacert.pem
  Thumbnail Driver            OK       active=gd (imagick=no, gd=yes)
  Image Optimizer             OK       active=gd
  EXIF Auto-Orient (ext-exif) OK       Available
  WebP Support                OK       Available
  ZIP Extension (ext-zip)     OK       ZipArchive enabled

Configured Profiles & Volumes
-----------------------------
  Profile   Volume         Driver           Status
  default   local_uploads  LocalFileSystem  OK (writable)
```

> [!TIP]
> **Utilisateurs Windows (`cURL error 60`) :** Si `cURL CA Bundle` affiche un avertissement, faites pointer `curl.cainfo` et `openssl.cafile` dans `php.ini` vers un fichier `cacert.pem` à jour, ou renseignez `driver_options.ca_bundle` sur votre volume S3.
>
> **Permissions fichiers sous Linux :** Assurez-vous que `public/uploads`, `public/media-cache` et `var/file-manager` sont accessibles en écriture par l'utilisateur de votre serveur web (`www-data`, `nginx`, etc.).
>
> **Vous utilisez un assistant de code IA (Antigravity, Claude Code, Cursor, Copilot) ?**  
> Transmettez à votre assistant toutes les conventions et recettes du bundle en une seule commande (voir [Chapitre 12](12-ai-agent-skills-guide.md)) :
> ```bash
> php bin/console file-manager:skills:install --target=all
> ```

---

## 5. Créer votre première page fonctionnelle

Créons un contrôleur Symfony et un template Twig simples pour tout vérifier de bout en bout.

### 1) Contrôleur (`src/Controller/MediaController.php`)
```php
<?php
// src/Controller/MediaController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media')]
    public function index(): Response
    {
        return $this->render('admin/media.html.twig');
    }
}
```

### 2) Template Twig (`templates/admin/media.html.twig`)
```twig
{# templates/admin/media.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Médiathèque</h1>

    {# Option 1 : Gestionnaire de fichiers intégré (inline) #}
    <div style="height: 680px;">
        {{ component('LiveUX:FileManager:Browser', {
            profile: 'default',
            mode: 'inline'
        }) }}
    </div>

    {# Option 2 : Ouvrir une modale depuis n'importe quel bouton et récupérer les fichiers sélectionnés #}
    <div style="margin-top: 20px;">
        <button type="button" onclick="pickFiles()">Choisir des fichiers en modale</button>
        <p id="selection-result"></p>
    </div>

    <script>
        async function pickFiles() {
            const files = await window.LiveUXFileManager.open({
                profile: 'default',
                mode: 'modal',
                multiple: true
            });

            if (files.length === 0) {
                return; // L'utilisateur a fermé la fenêtre sans rien sélectionner
            }

            document.getElementById('selection-result').textContent =
                'Sélectionné : ' + files.map((file) => file.url).join(', ');
        }
    </script>
{% endblock %}
```

Ouvrez `/admin/media` dans votre navigateur. Félicitations — votre gestionnaire de fichiers est en ligne !

---

## 6. Et ensuite ?

- Configurer des profils personnalisés, des limites de téléversement et des règles d'images → **[02 — Configuration et profils](02-configuration-and-profiles.md)**
- Ajouter un sélecteur d'image ou de galerie à un formulaire Symfony → **[05 — FormType et intégrations éditeurs](05-formtype-and-editor-integrations.md)**
- Durcir le RBAC et la sécurité avant la mise en production → **[06 — Sécurité et autorisations](06-security-and-authorization.md)**

---

[← Index de la documentation](README.md) | [Suivant : 02 — Configuration et profils →](02-configuration-and-profiles.md)
