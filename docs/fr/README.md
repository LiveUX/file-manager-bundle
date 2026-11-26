<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Gestionnaire de fichiers et DAM pour Symfony UX" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — Documentation en français

**Langue :** [English](../en/README.md) · [Türkçe](../tr/README.md) · **Français** · [Deutsch](../de/README.md) · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [README principal](../../README.md)

**LiveUX FileManagerBundle** est un **gestionnaire de fichiers et gestionnaire d'actifs numériques (DAM) Symfony UX** moderne, haute performance et de niveau entreprise, conçu pour Symfony 6.4 LTS, 7.2+ et 8.x (PHP 8.3+). Il fonctionne avec **zéro dépendance à un framework CSS**, intègre 14 couches de sécurité natives et offre un support multilingue global (12 langues d'interface intégrées, détection de locale BCP-47 / POSIX et prise en charge RTL native).

---

## Aperçu interactif et captures d'écran

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.shields.io/badge/YouTube-Watch_Full_14--Chapter_1080p_Showcase_(03:52)-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch Full 14-Chapter 1080p Showcase on YouTube">
  </a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=H2CAf0RiDd8" target="_blank">
    <img src="https://img.youtube.com/vi/H2CAf0RiDd8/maxresdefault.jpg" alt="LiveUX FileManagerBundle — Full 14-Chapter 1080p YouTube Showcase" width="100%">
  </a>
</p>

<p align="center">
  <img src="../images/demo-showcase.gif" alt="Démo interactive de LiveUX FileManagerBundle" width="100%">
</p>

> **Vidéo de présentation Full HD (1080p, ~3 min) en 10 chapitres** pour YouTube et les notes de version : [YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Aperçu court : [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Mode clair (`Light`) | Mode sombre (`Dark`) |
| :---: | :---: |
| ![Gestionnaire de fichiers en mode clair](../images/browser-light.png) | ![Gestionnaire de fichiers en mode sombre](../images/browser-dark.png) |

| Personnalisation des couleurs et icônes de dossiers | Fenêtre « À propos » (`LiveUX`) |
| :---: | :---: |
| ![Personnalisation de la couleur et du badge d'un dossier](../images/folder-customize.png) | ![Modale À propos](../images/about-modal.png) |

### Démos éditeurs de texte riche, EasyAdmin et formulaires dynamiques (GIF)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![Intégration TinyMCE](../images/demo-editor-tinymce.gif) | ![Intégration CKEditor 5](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Intégration Tiptap 2](../images/demo-editor-tiptap.gif) | ![Intégration Quill 2](../images/demo-editor-quill.gif) |

| Trix (EasyAdmin `TextEditorField`) | Summernote |
| :---: | :---: |
| ![Intégration Trix](../images/demo-editor-trix.gif) | ![Intégration Summernote](../images/demo-editor-summernote.gif) |

| Editor.js (Éditeur par blocs) | GrapesJS (Constructeur visuel de pages) |
| :---: | :---: |
| ![Intégration Editor.js](../images/demo-editor-editorjs.gif) | ![Intégration GrapesJS](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Intégration Meta Lexical](../images/demo-editor-lexical.gif) | ![Intégration EasyAdmin 5](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` & `LiveCollectionType` |
| :---: |
| ![Intégration CollectionType et LiveCollectionType](../images/demo-collections.gif) |

---

## Feuille de route rapide (2 minutes)

Vous ne savez pas par où commencer ? Allez directement au guide correspondant à votre objectif :

- **Première installation :** [01 — Prérequis et installation](01-requirements-and-installation.md) → [02 — Configuration et profils](02-configuration-and-profiles.md)
- **Ajouter un sélecteur d'image ou de galerie à un formulaire Symfony :** [05 — FormType et intégrations éditeurs](05-formtype-and-editor-integrations.md)
- **Verrouiller la sécurité et le RBAC pour la production :** [06 — Sécurité et autorisations](06-security-and-authorization.md)
- **Connecter Supabase, AWS S3 ou Cloudflare R2 :** [07 — Stockage cloud et configuration dynamique](07-cloud-storage-and-dynamic-config.md)
- **Stocker les métadonnées avec Doctrine ORM / DBAL :** [13 — Intégration Doctrine](13-doctrine-integration.md)
- **Empêcher la suppression ou le déplacement de fichiers référencés en base de données :** [10 — Garde d'utilisation des fichiers (Asset Usage Guard)](10-asset-usage-guard.md)

---

## Table des matières (Index de la documentation)

| Chapitre | Guide | Résumé |
| :---: | :--- | :--- |
| **01** | [Prérequis, dépendances et installation](01-requirements-and-installation.md) | Prérequis PHP 8.3+ / Symfony 6.4 LTS, 7.2+ et 8.x, extensions PHP (dont `ext-exif`, `ext-zip`), dépendances Composer, configuration AssetMapper et Webpack Encore, `file-manager:doctor` et votre première page fonctionnelle |
| **02** | [Configuration et profils (Référence YAML)](02-configuration-and-profiles.md) | `defaults`, `volumes`, `profiles`, chemins dynamiques sans collision (`{user_id}`, `{Y}/{m}`), limites de téléversement et découpage en morceaux (chunks), nommage automatique, optimisation et contraintes d'image, miniatures, corbeille, ZIP, 5 recettes prêtes pour la production et schéma YAML complet |
| **03** | [Modes d'utilisation et API JavaScript](03-usage-modes-and-js-api.md) | `TwigComponent` intégré (inline), modale `<dialog>` et tiroir OffCanvas 4 directions, référence complète de `window.LiveUXFileManager.open()`, événements DOM personnalisés, accessibilité RTL/IME/WCAG 2.1 AA, menus contextuels, éditeur d'image intégré et raccourcis clavier |
| **04** | [Thèmes, jeux d'icônes et personnalisation](04-themes-icons-and-customization.md) | 9 thèmes intégrés conformes WCAG 2.1 AA (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), mode sombre automatique/manuel, 6 jeux d'icônes SVG (49 icônes UI + 14 badges), helpers Twig et 3 niveaux de personnalisation visuelle |
| **05** | [FormType et intégrations éditeurs](05-formtype-and-editor-integrations.md) | `FileManagerPickerType` (sélection simple et tri accessible de galerie multi-images + garde de sécurité POST), 5 variantes intégrées (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, templates personnalisés, TinyMCE, CKEditor 5, Tiptap et le `FileManagerField` EasyAdmin à 16 méthodes |
| **06** | [Sécurité et autorisations (RBAC)](06-security-and-authorization.md) | 17 permissions Voter, Voters personnalisés `unanimous`, règles de pare-feu `security.yaml`, 14 couches de défense (CSRF, traversée de chemin, 69 extensions / 47 types MIME, `SvgSanitizer` à 55 balises, protection SSRF anti-rebinding DNS, confinement de liens symboliques `ConfinedLocalFilesystemAdapter`, liens de partage HMAC, journal d'audit) et durcissement Apache/Nginx |
| **07** | [Stockage cloud (S3/R2/MinIO/Supabase) et configuration dynamique](07-cloud-storage-and-dynamic-config.md) | Architecture multi-volumes local/cloud, guides par fournisseur ([Supabase](cloud/supabase-storage.md), [AWS S3](cloud/aws-s3.md), [Cloudflare R2](cloud/cloudflare-r2.md), [Backblaze B2](cloud/backblaze-b2.md), [MinIO/RustFS](cloud/minio-rustfs.md), [Google Cloud Storage](cloud/google-cloud-storage.md), [SFTP et Flysystem personnalisé](cloud/sftp-and-custom-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface` et stockage de métadonnées personnalisé |
| **08** | [Événements, commandes CLI, cache et dépannage](08-events-commands-cache-and-troubleshooting.md) | 9 événements métier Symfony, 10 commandes console (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install`, etc.), cache à 3 niveaux et isolation HTTP 304/CDN sensible au contexte, système de traduction global en 12 langues BCP-47/POSIX/RTL (493 clés) et FAQ |
| **09** | [Intégration Symfony AI et enrichissement intelligent](09-symfony-ai-integration.md) | `AssetEnricherInterface` sans dépendance obligatoire, pont `symfony/ai-bundle` (OpenAI, Gemini, Claude, Ollama), **✨ Suggérer avec l'IA**, CLI par lot `file-manager:ai:enrich`, exemples Messenger asynchrones et filtrage multilingue (11 langues) des refus LLM et injections de prompt |
| **10** | [Garde d'utilisation des fichiers (Asset Usage Guard)](10-asset-usage-guard.md) | Contrat optionnel `AssetUsageCheckerInterface`, politiques `warn` / `block`, protection serveur à deux couches (`enforce_server_side`) pour **`delete`, `rename`, `move` et `overwrite`**, et exemples Doctrine pour MySQL, PostgreSQL et SQLite |
| **11** | [Guide de contribution et Pull Request](11-contributing-and-pr-guide.md) | Workflow PR open-source accessible aux débutants, portail qualité `composer qa` (PHPStan niveau 8, 360+ tests PHPUnit, 37+ tests JS), CI GitHub Actions à 6 jobs et configuration de GitHub Branch Protection |
| **12** | [Guide des Skills d'agents IA (`SKILL.md` et `AGENTS.md`)](12-ai-agent-skills-guide.md) | 6 packs `SKILL.md` modulaires pour Antigravity, Claude Code, Cursor, GitHub Copilot, Codex et Symfony AI Mate, installation en une commande `file-manager:skills:install` et validation CI |
| **13** | [Intégration Doctrine ORM et DBAL](13-doctrine-integration.md) | Driver intégré `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage` — UPSERT atomique, index PostgreSQL `text_pattern_ops`, `saveMany()` par lots), migrations multi-SGBD, Entité + `FileManagerPickerType`, stockage ORM personnalisé compatible `ResetInterface` et synchronisation BDD via `ItemRenamedEvent` |
| **14** | [Audit de sécurité, revue logique et journal des modifications](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(en turc uniquement)* | Audit de sécurité ligne par ligne (cycles 1 à 18), isolation multi-tenant `{user_id}` sans collision, durcissement DI/Voter/Parser/SVG `<use>` step-budget et anti-usurpation CDN/origine, confinement des liens symboliques, PostgreSQL `text_pattern_ops` et UPSERT atomique, audit contraste/focus WCAG 2.1 AA sur 9 thèmes, modales mobiles et journal d'audit i18n |

---

## Points forts architecturaux

- **Vitesse hybride 0 ms :** `TwigComponent` SSR rapide + cache mémoire dans le navigateur (`dirCache`) + arborescence complète chargée en une seule requête. Filtrage, recherche, tri, pagination, bascule Grille/Liste et ouverture/fermeture des dossiers s'exécutent instantanément sans aller-retour AJAX supplémentaire.
- **Zéro dépendance à un framework CSS et conformité WCAG 2.1 AA :** Toute l'interface utilise des classes isolées `.liveux-fm-*` et des propriétés personnalisées CSS (`--liveux-fm-*`). Elle cohabite proprement avec **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material**, ou **sans aucun framework CSS** — sans collision de styles ni configuration de purge Tailwind supplémentaire.
- **Couche supérieure native HTML5 `<dialog>` et ergonomie Mobile-First :** Les modales et tiroirs OffCanvas (`start`, `end`, `top`, `bottom`) s'affichent dans le Top Layer du navigateur et s'adaptent automatiquement sur mobile en plein écran (`100dvh`) ou en feuille inférieure (bottom sheet) native avec poignée de glissement.
- **Sécurité entreprise à 14 couches :** RBAC via Symfony `Voter`, protection CSRF (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, `SvgSanitizer` à 55 balises, `RemoteUrlImporter` protégé contre le DNS rebinding, confinement des liens symboliques `ConfinedLocalFilesystemAdapter`, protections contre les bombes de décompression/pixels, et en-têtes `CSP: sandbox` + `nosniff` activés par défaut.
- **Architecture multilingue globale :** **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` avec 493 clés synchronisées chacune), normalisation des locales BCP-47 et POSIX `.UTF-8`, repli garanti sur `'en'`, mode RTL automatique (`dir="rtl"`) pour l'arabe et 14 autres langues RTL, et sécurité de saisie IME pour les langues CJK.
- **Prêt pour le multi-serveur et le cloud :** `DoctrineDbalMetadataStorage` intégré (index PostgreSQL `text_pattern_ops`, UPSERT atomique sur MySQL/MariaDB/SQLite), Flysystem v3 (S3, R2, Supabase, B2, MinIO, GCS, SFTP) et compatibilité `ResetInterface` pour FrankenPHP et les workers Symfony Messenger.

---

## Liste de démarrage rapide (5 minutes)

1. **Installer le bundle :** `composer require liveux/file-manager-bundle`
2. **Importer les routes :** `config/routes/liveux_file_manager.yaml`
3. **Définir les profils :** `config/packages/liveux_file_manager.yaml`
4. **CSS & JS (zéro configuration) :** L'option `autoimport` de Symfony UX charge automatiquement `core.css`, `file_manager_api.js` et les 3 contrôleurs Stimulus via `assets/controllers.json`, aussi bien avec AssetMapper qu'avec Webpack Encore.
5. **Hôte de dialogue (automatique) :** `AutoInjectDialogHostSubscriber` injecte automatiquement `DialogHost` juste avant `</body>` sur les pages HTML complètes (`defaults.auto_inject_dialog_host: true`) — aucune modification de `base.html.twig` n'est nécessaire.
6. **Vérifier l'installation :** `php bin/console file-manager:doctor`

> Vous découvrez Symfony UX ? Suivez le guide pas à pas **[01 — Prérequis, dépendances et installation](01-requirements-and-installation.md)**.

---

**Organisation :** [LiveUX sur GitHub](https://github.com/LiveUX) · [Dépôt](https://github.com/LiveUX/file-manager-bundle)  
**Licence :** MIT
