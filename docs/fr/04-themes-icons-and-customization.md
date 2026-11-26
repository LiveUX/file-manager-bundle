# 04 — Thèmes, mode sombre, personnalisation des dossiers, jeux d'icônes & design sur mesure

[← Précédent : 03 — Modes d'utilisation & API JS](03-usage-modes-and-js-api.md) | [Index](README.md) | [Suivant : 05 — FormType & intégrations d'éditeurs →](05-formtype-and-editor-integrations.md)

LiveUX FileManagerBundle est conçu avec **zéro dépendance à un framework CSS**. Que votre projet utilise **Bootstrap 5, Tailwind CSS v3/v4, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** ou **aucun framework CSS**, le bundle fonctionne immédiatement sans étape de compilation supplémentaire (aucune directive Tailwind `@source` ni configuration de purge n'est nécessaire).

> **En un coup d'œil : ce que vous allez apprendre dans ce guide**  
> 1. **9 thèmes intégrés & mode sombre :** Harmonisez l'apparence et la palette de couleurs avec votre application à l'aide d'une seule option YAML.  
> 2. **Coloration et icônes (badges) de dossiers :** Personnalisez vos dossiers depuis l'interface utilisateur ou par programme en PHP/BDD avec plus de 18 couleurs et 14 badges intégrés (plus un nombre illimité de badges SVG personnalisés).  
> 3. **Créer votre propre jeu d'icônes :** Guide étape par étape pour concevoir votre pack couvrant 9 icônes de fichiers, 49 icônes d'interface et des badges de dossier personnalisés à volonté.  
> 4. **Créer un thème personnalisé (3 niveaux de liberté) :** Adaptation à votre charte graphique via des variables CSS pures, surcharge Twig partielle avec `{% extends %}`, ou mise en page 100 % headless de A à Z.

---

## 1. Les 9 thèmes intégrés (lequel choisir ?)

Les thèmes peuvent être configurés via `defaults.theme`, `profiles.<name>.ui.theme`, la prop `theme` du TwigComponent ou l'option `theme` de `FileManagerPickerType`. **Les 9 thèmes respectent tous les critères de contraste WCAG 2.1 AA en mode clair comme en mode sombre.**

| Clé de thème | Idéal pour | Résumé du design |
| :--- | :--- | :--- |
| `bootstrap5` *(par défaut)* | Bootstrap 5 et panneaux d'administration Symfony classiques | Bleu classique (`#0d6efd`), arrondis `10px / 8px / 6px`, bordures neutres et nettes |
| `tailwind` | Applications web modernes construites avec Tailwind CSS v3/v4 | Accent Indigo (`#6366f1`), arrondis doux `14px / 12px / 8px`, palette Slate |
| `shadcn` | Tableaux de bord SaaS minimalistes et monochromes | Surfaces Zinc monochromes (`#18181b` / `#fafafa` en mode sombre), angles précis `8px / 6px` |
| `flowbite` | Back-offices basés sur Flowbite 4 + Tailwind CSS | Bleu Flowbite (`#1a56db`) et surfaces Gray-50/800/900, contrôles `8px` |
| `daisyui` | Projets utilisant DaisyUI 5 | **Hérite automatiquement** de `--color-primary`, `--color-base-100/200/300` et `--radius-box` dès que DaisyUI est chargé sur la page |
| `tabler` | Tabler.io ou back-offices d'entreprise EasyAdmin | Toile Slate professionnelle, accent bleu `#066fd1`, coins nets `4px / 6px` |
| `preline` | Applications Preline UI | Surfaces neutres pures (`#fafafa` / `#171717`) avec accent bleu vif (`#2563eb`) |
| `material` | Applications Google Material Design 3 | Surfaces violettes tonales (`#6750a4` / `#f3edf7`) et boutons pilules entièrement arrondis |
| `unstyled` | Styliser intégralement le gestionnaire de fichiers avec votre propre CSS | Hérite de la police de l'application hôte avec des surfaces transparentes, tout en conservant un contraste opaque `--liveux-fm-elevated-bg` / `--liveux-fm-elevated-text` pour les popovers et lightboxes |

| Mode sombre (`bootstrap5` + Dark) | Pont de thème DaisyUI 5 (`daisyui`) |
| :---: | :---: |
| ![Gestionnaire de fichiers en mode sombre](../images/browser-dark.png) | ![Aperçu du thème DaisyUI 5](../images/showcase-daisyui.png) |

> [!IMPORTANT]
> **Règle de sécurité (`theme` YAML vs. dynamique) :**  
> - Dans `liveux_file_manager.yaml`, vous pouvez configurer n'importe lequel des 9 thèmes intégrés, votre propre nom de thème CSS personnalisé (par ex. `'corporate'`, respectant `/^[a-zA-Z0-9_-]{1,40}$/`), ou un chemin vers un template Twig personnalisé (par ex. `'file_manager/custom.html.twig'` ou `'@App/file_manager'`).  
> - Pour des raisons de sécurité, les valeurs dynamiques de `theme` provenant du navigateur, des paramètres d'URL ou de `window.LiveUXFileManager.open({ theme: ... })` **n'acceptent que les 9 clés de thèmes intégrés** (`FileManagerThemes::sanitizeDynamic()`).

---

## 2. Mode sombre, RTL & réduction des animations

Les 9 thèmes (y compris `unstyled`) prennent en charge le **mode sombre automatique et manuel** :

### A) Détection automatique de l'application hôte (`color_scheme: 'auto'`)
Dès que votre page (`<html>` ou `<body>`) possède l'un des attributs ou classes suivants, le gestionnaire de fichiers, la modale `<dialog>` et les champs `FileManagerPickerType` basculent instantanément en mode sombre :
- `data-bs-theme="dark"` *(Bootstrap 5)*
- Classe `.dark` *(Tailwind CSS / Shadcn / Flowbite / Preline)*
- `data-theme="dark"` *(DaisyUI / thèmes personnalisés)*

### B) Schéma de couleurs par défaut ou par profil en YAML
```yaml
liveux_file_manager:
    defaults:
        color_scheme: 'auto'          # 'auto' (suit l'hôte) | 'light' | 'dark'
    profiles:
        default:
            ui:
                color_scheme: 'dark'  # Démarre uniquement ce profil en mode sombre
```

### C) Préférence de l'utilisateur final (menu Paramètres : `Auto / Clair / Sombre`)
Les utilisateurs peuvent basculer entre **Auto / ☀ Clair / 🌙 Sombre** en un clic depuis le menu **⚙ Paramètres** de la barre d'outils supérieure. Leur choix est mémorisé dans le `localStorage` du navigateur (`liveux_fm_color_scheme`).

### D) Support RTL global & `prefers-reduced-motion`
- L'attribut `dir="rtl"` est appliqué automatiquement pour 15 langues de droite à gauche, dont l'arabe (`ar`), le persan (`fa`), l'hébreu (`he`) et l'ourdou (`ur`). Tous les espacements dans `core.css` reposent sur les propriétés CSS logiques (`inset-inline`, `padding-inline`, `border-inline-end`).
- Les animations sont automatiquement réduites pour les utilisateurs ayant activé l'option *« Réduire les animations »* (`prefers-reduced-motion: reduce`) dans leur système d'exploitation.

---

## 3. Coloration et attribution d'icônes (badges) aux dossiers (étape par étape)

![Modale de palette de couleurs et de badges pour les dossiers](../images/folder-customize.png)

Vous pouvez attribuer une **couleur**, un **badge (icône)** et un **statut épinglé (⭐ Épingler)** à n'importe quel dossier. Ces fonctionnalités sont actives lorsque `defaults.metadata.enabled: true` et que l'utilisateur dispose de la permission `permissions.edit_metadata`.

### Méthode 1 : Depuis l'interface utilisateur (pour les utilisateurs finaux)
1. Effectuez un **clic droit** sur n'importe quel dossier et choisissez **Personnaliser** (ou appuyez sur **`I`** lorsqu'un dossier est sélectionné, ou cliquez sur **Personnaliser** dans le panneau d'inspection à droite).
2. Dans la modale **Personnaliser**, configurez trois options avec aperçu en direct :
   - **Palette de 18 couleurs prédéfinies :** Bleu (`#2563eb`), Ciel (`#0284c7`), Cyan (`#0891b2`), Sarcelle (`#0d9488`), Émeraude (`#059669`), Vert (`#16a34a`), Citron vert (`#65a30d`), Ambre (`#d97706`), Orange (`#ea580c`), Rouge (`#dc2626`), Rose vif (`#e11d48`), Rose (`#db2777`), Fuchsia (`#c026d3`), Violet (`#9333ea`), Pourpre (`#7c3aed`), Indigo (`#4f46e5`), Ardoise (`#475569`), Anthracite (`#1e293b`).
   - **Sélecteur de couleur personnalisé + champ HEX :** Choisissez n'importe quelle couleur de votre charte graphique via le sélecteur natif ou saisissez directement un code hexadécimal `#RRGGBB`. Cliquez sur **Réinitialiser par défaut** pour supprimer le style personnalisé.
   - **Grille d'icônes (badges) de dossiers :** Affiche les **14 badges intégrés** (`archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video`) ainsi que tous les fichiers SVG personnalisés ajoutés dans le sous-dossier `badges/` de votre jeu d'icônes.
3. Cliquez sur **Enregistrer** — le dossier est mis à jour instantanément dans la vue Grille/Liste ainsi que dans l'arborescence à gauche.

### Méthode 2 : Par programme en PHP (par ex. lors de la création d'un dossier client)
À l'aide de `FolderMetadataStorageInterface` et `PathNormalizer::buildCanonicalKey()`, vous pouvez définir les couleurs, badges et épinglages directement depuis votre code PHP (le même code fonctionne indifféremment avec les pilotes `json_file` et `doctrine`) :

```php
<?php
// src/Service/CustomerFolderInitializer.php

declare(strict_types=1);

namespace App\Service;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Metadata\FolderMetadata;
use LiveUX\FileManagerBundle\Metadata\FolderMetadataStorageInterface;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;

final readonly class CustomerFolderInitializer
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private VolumeRegistry $volumeRegistry,
        private PathNormalizer $pathNormalizer,
        private FolderMetadataStorageInterface $metadataStorage,
    ) {
    }

    public function decorateInvoiceFolder(string $relativeFolderPath): void
    {
        $profile = $this->configProvider->getProfile('default');
        $volume = $this->volumeRegistry->getVolumeForProfile($profile, 'local_uploads');

        // Construit la clé canonique (par ex. "local::invoices/2026")
        $canonicalKey = $this->pathNormalizer->buildCanonicalKey($volume, $relativeFolderPath, $profile->basePath);

        $this->metadataStorage->save($canonicalKey, new FolderMetadata(
            color: '#0d9488',   // Sarcelle (#RRGGBB)
            icon: 'lock',       // L'un des 14 badges intégrés ou un badge personnalisé badges/invoice.svg -> 'invoice'
            pinned: true,       // Ajoute le dossier aux ⭐ Dossiers épinglés dans la barre latérale
        ));
    }
}
```

### Méthode 3 : Directement dans `metadata.json` (avec `driver: 'json_file'`)
```json
{
    "local::products": { "color": "#2563eb", "icon": "bag",   "pinned": true  },
    "local::images":   { "color": "#0d9488", "icon": "image", "pinned": false },
    "local::invoices": { "color": "#64748b", "icon": "lock",  "pinned": false }
}
```

---

## 4. Jeux d'icônes & création de votre propre jeu d'icônes de A à Z

**Toutes les icônes** du bundle — types de fichiers, dossiers, barre d'outils supérieure, menu contextuel, indicateurs de stockage cloud, panneau d'inspection et badges de dossiers — proviennent d'un registre unifié `IconSetRegistry`. Les 6 jeux d'icônes intégrés sont mis en cache PSR-6 pendant 24 heures (`liveux_fm_iconset_*`).

### Les 6 jeux d'icônes intégrés (`profiles.<name>.ui.icon_set`)
| Jeu d'icônes | Style visuel | Couleur de dossier par défaut (`meta.json`) |
| :--- | :--- | :--- |
| `classic` *(par défaut)* | Icônes vectorielles plates et nettes | `#2563eb` |
| `windows11` | Dossiers jaune chaud bicolores façon Windows 11 et icônes de documents modernes | `#f59e0b` |
| `macos` | Dossiers bleu doux inspirés du Finder macOS | `#3b82f6` |
| `linux` | Esthétique open source épurée GNOME / Adwaita | `#3584e4` |
| `minimal` | Icônes minimalistes au trait fin (outline) | `#475569` |
| `vscode` | Icônes anguleuses orientées développeurs, style VS Code | `#dcb67a` |

### Étape par étape : créer votre propre jeu d'icônes personnalisé

#### Étape 1 : Créez un répertoire d'icônes dans votre projet
Par exemple, créez `assets/fm-icons/`. **Vous n'avez pas besoin de copier les icônes que vous ne souhaitez pas modifier** : toute icône absente bascule automatiquement sur les ensembles intégrés `classic` / `_ui` / `_folder_badges` !

```text
assets/fm-icons/
├── meta.json               # Couleur par défaut des dossiers : { "defaultFolderColor": "#7c3aed" }
├── folder.svg              # (Optionnel) Icône principale de dossier personnalisée
├── pdf.svg                 # (Optionnel) Surcharge uniquement l'icône PDF
├── ui/                     # (Optionnel) Surcharge ou ajoute des icônes de barre d'outils, menu contextuel et volumes
│   ├── upload.svg          # Surcharge l'icône d'envoi (Upload)
│   ├── new_folder.svg      # Surcharge l'icône Nouveau dossier
│   └── company_cloud.svg   # Ajoute une nouvelle icône de volume -> volumes.<name>.icon: 'company_cloud'
└── badges/                 # (Optionnel) Surcharge les badges existants ou en ajoute de NOUVEAUX
    ├── lock.svg            # Surcharge le badge intégré "lock"
    └── invoice.svg         # Ajoute un NOUVEAU badge -> apparaît automatiquement dans la modale Personnaliser !
```

#### Étape 2 : Créez `meta.json` et un `folder.svg` à couleur dynamique

> [!TIP]
> **Règle d'or (`currentColor`) :** Pour que votre fichier `folder.svg` personnalisé s'adapte automatiquement lorsque l'utilisateur change la couleur d'un dossier, utilisez **`fill="currentColor"`** (ou `stroke="currentColor"` pour les icônes au trait) au lieu d'un code couleur hexadécimal figé !

```json
// assets/fm-icons/meta.json
{
    "defaultFolderColor": "#7c3aed"
}
```

```xml
<!-- assets/fm-icons/folder.svg -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
    <path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h4.17a2 2 0 0 1 1.42.59l1.32 1.32A2 2 0 0 0 13.83 6.5H18.5A2.5 2.5 0 0 1 21 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11Z"
          fill="currentColor" fill-opacity="0.92"/>
</svg>
```

```xml
<!-- assets/fm-icons/badges/invoice.svg (Nouveau badge de dossier personnalisé) -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/>
    <path d="M8 8h8M8 12h8M8 16h5"/>
</svg>
```

#### Étape 3 : Pointez votre profil YAML vers ce répertoire
```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        company_disk:
            label: 'Archives entreprise'
            icon: 'company_cloud' # Utilise assets/fm-icons/ui/company_cloud.svg !
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

    profiles:
        default:
            volumes: ['company_disk']
            ui:
                icon_set: '%kernel.project_dir%/assets/fm-icons'
```

#### Sécurité et limites des icônes personnalisées (`IconSetRegistry`)
- Chaque fichier `.svg` ne doit pas dépasser **256 Ko** (`262 144` octets) et est automatiquement nettoyé via **`SvgSanitizer`** lors du chargement (suppression des balises `<script>`, gestionnaires `on*`, etc.).
- Les sous-dossiers `ui/` et `badges/` analysent jusqu'à **200 fichiers `.svg` personnalisés** en plus des clés intégrées.
- Les noms de fichiers personnalisés (hors `.svg`) doivent respecter **`/^[a-zA-Z0-9_-]{1,32}$/`** (lettres ASCII, chiffres, `-` et `_`, 32 caractères maximum).

#### Référence complète des clés d'icônes surchargeables
- **9 icônes de fichiers et dossiers (racine `<dir>/<key>.svg`) :**  
  `folder`, `file`, `pdf`, `excel`, `word`, `zip`, `video`, `audio`, `code`
- **49 icônes d'interface (`<dir>/ui/<key>.svg`) :**
  - *Navigation et mise en page :* `menu`, `back`, `forward`, `up`, `refresh`, `search`, `search_deep`, `grid`, `list`, `sort`, `fullscreen`, `exit_fullscreen`, `settings`, `close`, `more`, `sun`, `moon`
  - *Actions sur les fichiers et dossiers :* `new_folder`, `upload`, `cloud_upload`, `download`, `open`, `preview`, `edit_image`, `rename`, `move`, `duplicate`, `cut`, `copy`, `paste`, `trash`, `archive`, `select_all`, `link`, `share`, `color`, `tag`, `star`, `sparkles`, `quota`
  - *Stockage et fournisseurs cloud :* `disk`, `cloud`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`
- **14 badges de dossiers intégrés (`<dir>/badges/<key>.svg`) :**  
  `archive`, `bag`, `cart`, `code`, `doc`, `globe`, `heart`, `image`, `lock`, `pen`, `star`, `tag`, `users`, `video` *(plus tous les fichiers `*.svg` personnalisés que vous ajoutez !)*

---

## 5. Créer votre propre thème personnalisé (3 niveaux de liberté)

`Browser.html.twig` résout la valeur `theme` configurée sur votre profil selon cet **ordre de priorité strict** :
1. Se termine par `.html.twig` → Rend directement votre template Twig (`{% include theme %}`).
2. Commence par `@` → Rend `layout.html.twig` à l'intérieur de cet espace de noms Twig (`{% include theme ~ '/layout.html.twig' %}`).
3. Correspond à l'un des 9 thèmes intégrés → Rend le thème intégré du bundle.
4. Tout autre identifiant court (par ex. `'corporate'`, respectant `/^[a-zA-Z0-9_-]{1,40}$/`) → Enveloppe la mise en page de base dans `<div data-liveux-theme="corporate">`.

### Niveau 1 : Thème de marque en variables CSS pures (le plus rapide — aucun template !)

#### Référence complète des variables CSS (`--liveux-fm-*`)
| Catégorie | Variables CSS |
| :--- | :--- |
| **Typographie** | `--liveux-fm-font`, `--liveux-fm-mono` |
| **Surfaces & bordures** | `--liveux-fm-bg`, `--liveux-fm-surface-muted`, `--liveux-fm-surface-subtle`, `--liveux-fm-hover`, `--liveux-fm-border`, `--liveux-fm-border-strong`, `--liveux-fm-border-hover` |
| **Couleurs de texte** | `--liveux-fm-text`, `--liveux-fm-text-secondary`, `--liveux-fm-text-muted`, `--liveux-fm-elevated-bg`, `--liveux-fm-elevated-text` |
| **Couleurs d'accentuation** | `--liveux-fm-accent`, `--liveux-fm-accent-hover`, `--liveux-fm-accent-fg`, `--liveux-fm-accent-soft`, `--liveux-fm-accent-border`, `--liveux-fm-ring` |
| **Couleurs d'état sémantiques (WCAG AA)** | `--liveux-fm-danger`, `--liveux-fm-danger-hover`, `--liveux-fm-danger-solid`, `--liveux-fm-danger-soft`, `--liveux-fm-danger-border`, `--liveux-fm-success`, `--liveux-fm-success-solid`, `--liveux-fm-success-soft`, `--liveux-fm-warning`, `--liveux-fm-warning-soft` |
| **Rayons & ombres** | `--liveux-fm-radius-shell`, `--liveux-fm-radius-card`, `--liveux-fm-radius-ctrl`, `--liveux-fm-radius-modal`, `--liveux-fm-shadow-sm`, `--liveux-fm-shadow-card`, `--liveux-fm-shadow-float`, `--liveux-fm-shadow-modal`, `--liveux-fm-z-base` |

#### Thème de marque personnalisé prêt à copier-coller (`assets/styles/app.css`)
```css
/* 1) Palette de marque en mode clair */
[data-liveux-theme="corporate"] {
    --liveux-fm-bg: #ffffff;
    --liveux-fm-surface-muted: #f8f7ff;
    --liveux-fm-surface-subtle: #ede9fe;
    --liveux-fm-hover: #f3f0ff;
    --liveux-fm-border: #ddd6fe;
    --liveux-fm-border-strong: #c4b5fd;
    --liveux-fm-text: #1e1b4b;
    --liveux-fm-text-secondary: #3b2f6e;
    --liveux-fm-text-muted: #6d28d9;
    --liveux-fm-accent: #7c3aed;
    --liveux-fm-accent-hover: #6d28d9;
    --liveux-fm-accent-fg: #ffffff;
    --liveux-fm-accent-soft: #f5f3ff;
    --liveux-fm-accent-border: rgba(124, 58, 237, 0.28);
    --liveux-fm-ring: rgba(124, 58, 237, 0.30);
    --liveux-fm-radius-shell: 16px;
    --liveux-fm-radius-card: 12px;
    --liveux-fm-radius-ctrl: 8px;
}

/* 2) Variante mode sombre (couvre le navigateur et le sélecteur FormType, en mode auto et manuel) */
[data-bs-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
.dark .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-theme="dark"] .liveux-fm-root:not([data-fm-color-scheme="light"]) [data-liveux-theme="corporate"],
[data-bs-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.dark [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
[data-theme="dark"] [data-liveux-theme="corporate"].liveux-fm-picker:not([data-fm-color-scheme="light"]),
.liveux-fm-picker[data-fm-color-scheme="dark"][data-liveux-theme="corporate"],
[data-fm-color-scheme="dark"] [data-liveux-theme="corporate"] {
    --liveux-fm-bg: #13111c;
    --liveux-fm-surface-muted: #1b1829;
    --liveux-fm-surface-subtle: #242038;
    --liveux-fm-hover: #2d2846;
    --liveux-fm-border: #2e2948;
    --liveux-fm-text: #ede9fe;
    --liveux-fm-text-secondary: #d8d0fc;
    --liveux-fm-text-muted: #a78bfa;
    --liveux-fm-accent: #a78bfa;
    --liveux-fm-accent-hover: #8b5cf6;
    --liveux-fm-accent-fg: #13111c;
    --liveux-fm-accent-soft: rgba(167, 139, 250, 0.16);
}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        theme: 'corporate'
```

---

### Niveau 2 : Surcharges partielles avec Twig `{% extends %}` (recommandé !)

La mise en page de base s'articule autour de **10 blocs `{% block %}`** et de **7 partiels modulaires (`parts/`)**. Vous pouvez ainsi personnaliser uniquement la barre supérieure ou le pied de modale sans dupliquer l'intégralité du template :

| Nom du bloc | Périmètre | Partiel par défaut |
| :--- | :--- | :--- |
| `fm_shell` | Conteneur principal externe (`.liveux-fm-shell`) | Enveloppe tous les blocs imbriqués |
| `fm_topbar` | Barre de commandes supérieure (navigation, fil d'Ariane, recherche, tri, actions) | `parts/_topbar.html.twig` |
| `fm_settings_popover` | Menu déroulant Paramètres (mode couleur, taille des éléments, style des dossiers, cache, raccourcis) | `parts/_settings_popover.html.twig` |
| `fm_banners` | Bandeau de notification d'état + file d'attente d'upload | Balisage en ligne |
| `fm_body` | Zone centrale principale (enveloppe barre latérale + contenu + inspecteur) | `.liveux-fm-body` |
| `fm_sidebar` | Arborescence de dossiers à gauche + dossiers épinglés + barre de quota + corbeille | `parts/_sidebar.html.twig` |
| `fm_main` | Grille/liste de fichiers + barre flottante de sélection/presse-papiers + zone de dépôt + pagination | `parts/_main.html.twig` |
| `fm_inspector` | Panneau latéral droit d'aperçu et de métadonnées | `parts/_inspector.html.twig` |
| `fm_footer` | Barre de pied de page en mode Modal / OffCanvas | `parts/_footer.html.twig` |
| `fm_overlays` | Menu contextuel, modale, lightbox et templates raccourcis/à propos | `parts/_overlays.html.twig` |

#### Exemple — Bandeau d'en-tête aux couleurs de l'entreprise + pied de sélection personnalisé
```twig
{# templates/file_manager/corporate_layout.html.twig #}
{% extends '@LiveUXFileManager/base/layout.html.twig' %}

{% block fm_topbar %}
    <div style="padding: 6px 12px; background: #1e293b; color: #f8fafc; font-size: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
        <span>📁 Médiathèque de l'entreprise</span>
        <span>Profil : <strong>{{ this.profile }}</strong></span>
    </div>
    {{ parent() }}
{% endblock %}

{% block fm_footer %}
    {% if this.mode != 'inline' %}
        <div class="liveux-fm-footer">
            <span class="liveux-fm-muted" data-{{ ctrl }}-target="footerStatus"></span>
            <div class="liveux-fm-row-sm">
                <button type="button" class="liveux-fm-btn" data-action="{{ ctrl }}#closeDialog">Annuler</button>
                <button type="button" class="liveux-fm-btn liveux-fm-btn-primary"
                        data-{{ ctrl }}-target="confirmSelectionBtn"
                        data-action="{{ ctrl }}#confirmSelection">Confirmer la sélection</button>
            </div>
        </div>
    {% endif %}
{% endblock %}
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    profiles:
        default:
            ui:
                # 1) Chemin direct vers un template .html.twig :
                theme: 'file_manager/corporate_layout.html.twig'
                # 2) Ou un répertoire d'espace de noms Twig (charge automatiquement layout.html.twig dedans) :
                # theme: '@App/file_manager'
```

---

### Niveau 3 : Mise en page 100 % headless de A à Z (cibles Stimulus requises)

Si vous souhaitez construire votre propre structure HTML entièrement de zéro sans `{% extends %}`, il vous suffit de relier ces attributs `data-{{ ctrl }}-target` avec le préfixe `ctrl = 'liveux--file-manager-bundle--browser'` :

| Cible (`data-...-target`) | Requis ? | Rôle |
| :--- | :---: | :--- |
| `shell` | Recommandé | Échelle de taille des éléments (`--liveux-fm-card-min`), `data-size-tier`, `data-folder-style`, `data-sidebar-open` |
| `contentArea` | **Requis** | Zone principale où sont rendues les cartes et lignes de fichiers/dossiers |
| `modalBackdrop`, `modalTitle`, `modalBody` | **Requis** | Conteneur des modales Nouveau dossier, Upload, Suppression, Personnalisation, Quota et Métadonnées |
| `breadcrumbs` | Recommandé | Fil d'Ariane cliquable du chemin courant |
| `confirmSelectionBtn`, `footerStatus` | Recommandé (Modal/OffCanvas) | Bouton de confirmation de la sélection (automatiquement `disabled` si rien n'est sélectionné) et indicateur d'état |
| `statusBanner`, `uploadQueue` | Recommandé | Bandeau de messages d'erreur/succès et file de progression des uploads par morceaux |
| `tree` + `sidebar` + `pinnedFolders` | Optionnel | Arborescence de dossiers à gauche et dossiers épinglés |
| `contextMenu` | Optionnel | Conteneur du menu contextuel (clic droit) |
| `inspector` | Optionnel | Panneau droit d'aperçu et de métadonnées |
| `lightbox`, `lightboxStage`, `lightboxCaption`, `lightboxCounter`, `lightboxControls` | Optionnel | Visionneuse lightbox universelle pour les médias |

---

## 6. Les 7 fonctions Twig utilitaires pour vos templates personnalisés

| Fonction Twig | Rôle | Exemple d'utilisation |
| :--- | :--- | :--- |
| `liveux_fm_icon(key, iconSet, size, class, group, fallbackKey)` | Rend un SVG en ligne protégé contre les failles XSS (pas besoin de `\|raw`). `group` : `'ui'`, `'icons'`, `'badges'`. | `{{ liveux_fm_icon('upload', this.resolvedIconSet, 14, '', 'ui', 'file') }}` |
| `liveux_fm_thumb_url(profile, volume, path, w, h)` | Génère une URL sécurisée de miniature pour un fichier. | `<img src="{{ liveux_fm_thumb_url('default', 'local_uploads', 'a.jpg', 320, 240) }}">` |
| `liveux_fm_download_url(profile, volume, path, inline)` | Génère une URL sécurisée de téléchargement ou d'aperçu en ligne. | `<a href="{{ liveux_fm_download_url('default', 'customer_vault', 'invoice.pdf', false) }}">Télécharger</a>` |
| `liveux_fm_trans(key, params, locale)` | Traduit une clé de message du bundle avec repli garanti sur `'en'`. | `{{ liveux_fm_trans('ui.upload', {}, 'fr') }}` |
| `liveux_fm_bcp47_locale(locale)` | Convertit un code locale PHP/ICU tel que `fr_FR.UTF-8` en balise HTML `lang="..."` BCP-47 valide (`fr-FR`). | `<div lang="{{ liveux_fm_bcp47_locale(fmLocale) }}">` |
| `liveux_fm_is_rtl(locale)` | Retourne `true` si la locale indiquée s'écrit de droite à gauche (arabe, persan, hébreu, etc.). | `dir="{{ liveux_fm_is_rtl(fmLocale) ? 'rtl' : 'ltr' }}"` |
| `liveux_fm_normalize_field_items(value)` / `liveux_fm_describe_field_item(item)` | Normalise en toute sécurité les valeurs Form/EasyAdmin en descripteurs d'images ou de fichiers prêts à afficher (en bloquant les URL `javascript:`/`data:`). | Vues Index/Detail EasyAdmin et templates personnalisés |

---

[← Précédent : 03 — Modes d'utilisation & API JS](03-usage-modes-and-js-api.md) | [Index](README.md) | [Suivant : 05 — FormType & intégrations d'éditeurs →](05-formtype-and-editor-integrations.md)
