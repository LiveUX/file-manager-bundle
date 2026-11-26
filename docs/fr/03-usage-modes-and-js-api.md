# 03 — Modes d'utilisation et API JavaScript

[← Précédent : 02 — Configuration et profils](02-configuration-and-profiles.md) | [Index de la documentation](README.md) | [Suivant : 04 — Thèmes, jeux d'icônes et personnalisation →](04-themes-icons-and-customization.md)

Vous pouvez intégrer LiveUX FileManagerBundle **directement dans une page (inline)**, l'ouvrir dans une **fenêtre modale** centrée, le faire coulisser en tiroir **OffCanvas** sur 4 côtés ou l'invoquer par programmation depuis n'importe où grâce à son **API JavaScript à base de Promesses**.

> [!TIP]
> **Quel mode choisir ?**  
> - **Inline :** Pages dédiées de type *« Médiathèque »* où le gestionnaire de fichiers occupe la zone de contenu principale.  
> - **Modal :** Boîte de dialogue centrée déclenchée par un bouton *« Choisir une image »* lors de l'édition d'un produit ou d'un article — se referme dès que l'utilisateur valide sa sélection.  
> - **OffCanvas :** Panneau coulissant moderne depuis n'importe quel bord (`start`, `end`, `top`, `bottom`) qui garde votre formulaire visible en arrière-plan.  
> - **API JavaScript (`window.LiveUXFileManager.open()`) :** Déclenchez le sélecteur depuis n'importe quel bouton HTML, composant React/Vue/Alpine ou script JavaScript natif sans passer par Symfony Forms.

---

## 1. Mode intégré (Inline) — TwigComponent

![Vue du gestionnaire de fichiers intégré](../images/browser-light.png)

Intégrer un gestionnaire de fichiers complet en mode inline tient en un seul appel Twig. Placez-le dans un conteneur doté d'une hauteur explicite (comme `680px` ou `calc(100vh - 140px)`) :

```twig
<div style="height: 680px;">
    {{ component('LiveUX:FileManager:Browser', {
        profile: 'default',
        mode: 'inline'
    }) }}
</div>
```

Ou avec la syntaxe de balise composant `<twig:...>` de Symfony UX :

```twig
<div style="height: 680px;">
    <twig:LiveUX:FileManager:Browser
        profile="products"
        mode="inline"
        theme="flowbite"
        iconSet="macos"
        typeFilter="image"
        locale="fr"
    />
</div>
```

### Toutes les propriétés (`props`) du composant `Browser`

| Propriété | Type | Défaut | Description |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Clé du profil défini dans `liveux_file_manager.yaml`. |
| `volume` | `?string` | `null` | Clé du volume initial (`null` ouvre le premier volume du profil). |
| `path` | `string` | `''` | Sous-dossier initial (ex. `'products/2026'`). |
| `multiple` | `bool` | `false` | Active le mode de sélection multi-fichiers. |
| `mode` | `string` | `'inline'` | `'inline'`, `'modal'` ou `'offcanvas'`. |
| `typeFilter` | `string` | `'all'` | Filtre de type initial : `'all'`, `'folder'`, `'image'`, `'doc'` ou `'video'`. |
| `theme` | `?string` | `null` | Surcharge de thème à l'exécution (accepte les 9 thèmes intégrés : `bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled`). Les thèmes CSS/Twig personnalisés se configurent en YAML. |
| `iconSet` | `?string` | `null` | Surcharge du jeu d'icônes à l'exécution (`classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode`). Les répertoires SVG personnalisés se configurent en YAML. |
| `locale` | `?string` | `null` | Surcharge de locale à l'exécution parmi les **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`) ou toute locale BCP-47 / POSIX (ex. `'ar'`, `'zh_Hans_CN'`). |

---

## 2. Modal et OffCanvas — API JavaScript programmatique (Promise)

`DialogHost` est injecté automatiquement dans chaque réponse HTML complète (`defaults.auto_inject_dialog_host: true`). Vous pouvez donc appeler `window.LiveUXFileManager.open()` avec `async/await` depuis n'importe quelle page.

### Toutes les options de `open(options)`

| Option | Type | Défaut | Description |
| :--- | :--- | :--- | :--- |
| `profile` | `string` | `'default'` | Nom du profil à ouvrir. |
| `volume` | `?string` | `null` | Clé du volume de stockage initial. |
| `path` | `string` | `''` | Chemin du dossier initial (ex. `'banners'`). |
| `mode` | `'modal'\|'offcanvas'` | `'modal'` | Fenêtre modale centrée ou tiroir coulissant OffCanvas. |
| `position` | `'start'\|'end'\|'top'\|'bottom'` | `'end'` | Bord depuis lequel le panneau coulisse lorsque `mode: 'offcanvas'` (`start` = gauche, `end` = droite ; inversé automatiquement en RTL). |
| `mobileMode` | `'auto'\|'fullscreen'\|'sheet'` | `null` *(profil)* | Surcharge l'affichage sur écran mobile (`<= 767.98px`). |
| `multiple` | `bool` | `false` | `false` = fichier unique, `true` = sélection multiple. |
| `type` / `filetype` | `'all'\|'folder'\|'image'\|'doc'\|'video'\|'media'` | `'all'` | Ouvre le sélecteur préfiltré sur une catégorie précise (`filetype` est un alias de compatibilité TinyMCE ; `'media'` est automatiquement mappé sur `'video'`). |
| `theme` | `?string` | `null` | Surcharge à l'exécution pour l'un des 9 thèmes intégrés (les chemins de templates Twig personnalisés restent cantonnés au YAML côté serveur par sécurité). |
| `iconSet` | `?string` | `null` | Surcharge à l'exécution pour l'un des 6 jeux d'icônes intégrés. |
| `locale` | `?string` | `null` | Surcharge de locale à l'exécution (`'fr'`, `'en'`, `'tr'`, `'de'`, `'ar'`, `'ja'`, `'zh_CN'`, etc.). |

### Exemple concret : champ `<input>` caché + aperçu image en direct (sans Symfony Form)

Collez cet extrait dans n'importe quelle page HTML ou Twig pour qu'il fonctionne immédiatement :

```html
<!-- 1. Champ caché + aperçu en direct + bouton déclencheur -->
<div>
    <input type="hidden" id="cover-url" name="cover_url" value="">
    <img id="cover-preview" src="" alt="Aperçu" style="display:none; max-width:220px; border-radius:8px; margin-bottom:8px;">
    <br>
    <button type="button" id="cover-pick-btn">Choisir une image de couverture</button>
</div>

<script>
document.getElementById('cover-pick-btn').addEventListener('click', async () => {
    const selected = await window.LiveUXFileManager.open({
        profile: 'default',
        mode: 'offcanvas',
        position: 'end',
        mobileMode: 'auto',
        multiple: false,
        type: 'image',       // Ouvre préfiltré sur les images
        theme: 'shadcn',
        iconSet: 'windows11',
    });

    // L'utilisateur a appuyé sur Échap ou fermé la fenêtre sans rien sélectionner
    if (selected.length === 0) {
        return;
    }

    const file = selected[0];
    document.getElementById('cover-url').value = file.url;

    const img = document.getElementById('cover-preview');
    if (file.isImage) {
        img.src = file.url;
        img.alt = file.meta?.alt || file.name;
        img.style.display = 'block';
    }
});
</script>
```

### Extraits rapides par mode

```html
<!-- Fenêtre modale centrée -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'modal' })">Ouvrir la modale</button>

<!-- Tiroir latéral droit (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'end' })">Tiroir droit</button>

<!-- Feuille inférieure coulissante (OffCanvas) -->
<button type="button" onclick="window.LiveUXFileManager.open({ mode: 'offcanvas', position: 'bottom' })">Tiroir inférieur</button>
```

> [!NOTE]
> **Comportement en cas d'annulation :** Si l'utilisateur ferme la fenêtre sans choisir de fichier (`Échap`, bouton de fermeture ou clic sur l'arrière-plan), la Promesse **ne rejette pas d'erreur** — elle se résout proprement en un tableau vide (`[]`).
>
> **Adaptation mobile (`mobileMode` / `ui.mobile_dialog_mode`) :** Sur les écrans `<= 767.98px`, le dialogue s'adapte automatiquement : en mode `'auto'`, les modales et tiroirs latéraux (`start`/`end`) s'ouvrent bord à bord en plein écran (`100dvh`), tandis que les tiroirs `bottom`/`top` s'ouvrent comme des feuilles natives arrondies avec poignée de glissement. Sur mobile, l'arborescence gauche démarre repliée et s'ouvre d'un toucher.

| Panneau OffCanvas bureau (`position: 'end'`) | Feuille inférieure mobile (`mobileMode: 'sheet'`) |
| :---: | :---: |
| ![Vue OffCanvas sur bureau](../images/offcanvas-desktop.png) | ![Vue feuille inférieure sur mobile](../images/mobile-sheet.png) |

### Structure des objets fichier retournés

`window.LiveUXFileManager.open()` se résout en un tableau d'objets présentant la structure suivante :

```json
[
    {
        "kind": "file",
        "path": "products/matte-black-tumbler.webp",
        "url": "/uploads/products/matte-black-tumbler.webp",
        "name": "matte-black-tumbler.webp",
        "extension": "webp",
        "isImage": true,
        "meta": {
            "alt": "Gourde isotherme en acier inoxydable noir mat",
            "title": "Gourde Pro",
            "caption": "Bouteille isotherme sous vide 500 ml",
            "tags": ["gourde", "isotherme"],
            "focalPoint": { "x": 0.5, "y": 0.4 }
        }
    }
]
```

*(Remarque : sur les volumes `private: true`, `url` pointe vers le point de terminaison protégé par RBAC `/_liveux-file-manager/download?...&inline=1`.)*

### Événements DOM personnalisés

En plus d'attendre la résolution de `window.LiveUXFileManager.open()`, vous pouvez écouter directement les événements du cycle de vie de la boîte de dialogue sur `window` :

| Événement DOM (`window.addEventListener`) | Déclenchement | Charge utile `event.detail` |
| :--- | :--- | :--- |
| `liveux:file-manager:open` | Lorsqu'une demande d'ouverture de dialogue est émise (passerelle entre `file_manager_api.js` et `dialog_controller.js`). | Options de `open()` + `requestId` |
| `liveux:file-manager:dialog-opened` | Une fois le dialogue ouvert et son contenu monté. | `{ mode, position, profile }` |
| `liveux:file-manager:selected` | Lorsque l'utilisateur confirme une sélection (ou annule le dialogue). | `{ items: [...], requestId }` |

```javascript
window.addEventListener('liveux:file-manager:selected', (event) => {
    console.log('Nombre d’éléments sélectionnés :', event.detail.items.length);
});
```

---

## 3. Fonctionnalités intégrées pour l'utilisateur final

- **Menus contextuels compacts :**
  - *Sur un fichier ou dossier :* Barre d'actions rapides SVG sur une seule ligne façon Windows 11 (`Couper`, `Copier`, `Renommer`, `Télécharger`/`Épingler`, `Supprimer`), suivie d'actions groupées en un clic (Aperçu, Modifier l'image, Métadonnées et point focal, Extraire le ZIP, Copier l'URL, Créer un lien de partage, Déplacer/Copier, Cloner, Couleur et icône de dossier).
  - *Sur une zone vide :* Bande supérieure de navigation rapide (`← → ↑ ↻`), Nouveau dossier, Téléverser ici, Coller ici, Tout sélectionner, Trier et Éléments par page. *(Plein écran, Raccourcis clavier et À propos se trouvent dans le menu **⚙ Paramètres** de la barre d'outils.)*
  - *Mobile / Tactile :* S'ouvre en feuille inférieure ergonomique au toucher via le bouton `⋯` de chaque carte.
- **Visionneuse universelle (Lightbox v2) et inspecteur latéral droit :**
  - **Images :** Navigation dans la galerie (`←`/`→`), contrôles de zoom (`-` / `100%` / `+`) et indicateur de point focal.
  - **Vidéo (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`) :** Lecteur `<video controls>` en direct dans l'inspecteur + Lightbox plein écran + avance/retour rapide `HTTP 206 Partial Content` sur les volumes privés (activable par profil via `ui.allow_video_preview`).
  - **PDF (`.pdf`) :** Visionneuse PDF dans une `<iframe>` en bac à sable à l'intérieur de la Lightbox (activable via `ui.allow_pdf_preview`).
  - **Audio (`.mp3`, `.wav`, `.ogg`, `.m4a`, `.flac`, `.aac`) et Texte/Code (`.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.css`, `.sql`, etc. — plafonné à 64 Ko).**
  - En mode `inline`, un **double-clic** sur tout fichier prévisualisable (ou la touche `Espace`) ouvre immédiatement la Lightbox.
- **File de téléversement intelligente (Pause / Reprise / Annulation confirmée) :**
  - Téléversez des fichiers **ou des arborescences complètes de dossiers** (`webkitGetAsEntry`) par glisser-déposer, ou collez des images directement depuis le presse-papiers avec `Ctrl+V`.
  - Les téléversements découpés affichent le compteur de morceaux en direct (`2/5 • 40%`), des boutons **Pause (`⏸`) / Reprendre (`▶`)** en un clic, une **Annulation (`✕`)** avec confirmation, des actions groupées **Tout mettre en pause / Tout annuler**, ainsi que le nettoyage automatique côté serveur des morceaux temporaires partiels en cas d'annulation.
- **Sélection multiple (lasso inclus), presse-papiers et recherche approfondie :**
  - Cliquez-glissez sur une zone vide pour tracer un **rectangle de sélection (lasso / marquee)** autour de plusieurs fichiers et dossiers (maintenez `Ctrl`/`Cmd` pour ajouter à la sélection en cours).
  - Sélection par plage `Maj+Clic`, sélection individuelle `Ctrl+Clic`, tout sélectionner `Ctrl+A` et `Ctrl+C`/`Ctrl+X`/`Ctrl+V` entre dossiers et même entre volumes.
  - **En-têtes de colonnes triables en vue Liste :** Cliquez sur les colonnes **Nom**, **Type**, **Taille** ou **Date** pour basculer entre tri croissant et décroissant.
  - **Curseur dynamique de taille des cartes et cartes de dossiers :** Ajustez la taille des cartes en direct (`96 px – 320 px`) et basculez entre tuiles de dossier compactes (`tile`) et cartes de dossier pleine taille (`card`) depuis le menu Paramètres (`⚙`).
  - Activez le bouton Recherche approfondie dans la barre de recherche pour explorer récursivement tous les sous-dossiers, y compris les `#tags` et les métadonnées `alt`.
  - **⭐ Accès rapide épinglé** dans la barre latérale, modale cliquable de **Quota et détail d'utilisation du stockage**, et sélecteur de thème **Auto / Clair / Sombre**.
- **Validation en 0 ms de la résolution et du ratio d'image (`image_constraints`) :** Lorsque des règles de dimensions ou de ratio (`1:1`, `16:9`, etc.) sont configurées, les images non conformes sont rejetées instantanément dans le navigateur avant tout transfert (puis revérifiées côté serveur).
- **Métadonnées éditoriales, point focal et liens de partage signés :**
  - Modifiez `alt`, `title`, `caption`, `#tags`, le sélecteur cliquable de **Point focal** `[x, y]` et l'option **✨ Suggérer avec l'IA**.
  - Générez des liens de partage temporaires signés par HMAC (`15 min`, `1 heure`, `24 heures`, `7 jours`, `30 jours`).
- **Éditeur d'image Canvas intégré :** Recadrage libre ou à ratio verrouillé (`1:1`, `4:3`, `16:9`), redimensionnement en pixels (`Max W`/`Max H`), choix du format de sortie (`JPEG`/`PNG`/`WebP`) et de la qualité, rotation à 90°, miroir horizontal/vertical et enregistrement en copie ou remplacement.

| Métadonnées de fichier et point focal (`I`) | Éditeur d'image Canvas intégré (`E`) |
| :---: | :---: |
| ![Métadonnées de fichier et point focal](../images/file-metadata.png) | ![Éditeur d'image Canvas intégré](../images/image-editor.png) |

- **i18n globale, RTL et accessibilité (WCAG 2.1 AA) :**
  - **12 langues d'interface intégrées et RTL automatique (`dir="rtl"`) :** Prend en charge `en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja` et `zh_CN`. L'arabe (`ar`), le persan (`fa`), l'hébreu (`he`), l'ourdou (`ur`) et les autres locales RTL inversent automatiquement mises en page, fils d'Ariane, barres latérales et bords OffCanvas grâce aux propriétés CSS logiques (`inset-inline`, `margin-inline`).
  - **Sécurité CJK / IME :** Empêche `Entrée` ou les raccourcis de se déclencher prématurément pendant la composition de texte chinois, japonais ou coréen (`event.isComposing`).
  - **Accessibilité complète clavier et lecteur d'écran :** Piège de focus (`Tab`/`Maj+Tab`) dans les modales et la Lightbox, navigation par flèches dans les menus contextuels, sémantique `role="tree"` / `aria-expanded` / `aria-current` et restauration automatique du focus à la fermeture.
- **Raccourcis clavier (référence complète) :**
  - Tous les raccourcis s'activent uniquement lorsque le gestionnaire de fichiers a le focus, hors champs de saisie (`<input>`, `<textarea>`, `<select>`), et respectent strictement les **permissions Voter** de l'utilisateur (`ui.keyboard_shortcuts: true`).
  - Sur macOS, **`Cmd (⌘)`** fonctionne de manière transparente au même titre que `Ctrl`.
  - **Protection claviers internationaux et IME :** Utilise les codes de touches physiques (`event.code` `Key*`) avec correspondance de caractères en repli afin que les raccourcis fonctionnent sur les claviers AZERTY, QWERTZ et non latins, tout en restant silencieux pendant la composition IME CJK (`isComposing`).

#### A. Opérations sur les fichiers et dossiers
| Raccourci | Action | Condition / Remarque |
| :--- | :--- | :--- |
| `N` | Créer un nouveau dossier | Hors corbeille (permission `create_folder`) |
| `U` | Ouvrir la fenêtre de téléversement | Hors corbeille (permission `upload`) |
| `F2` | Renommer l'élément | Un seul fichier ou dossier sélectionné (permission `rename`) |
| `M` | Ouvrir la fenêtre Déplacer / Copier | 1 élément ou plus sélectionné (permission `move` ou `copy`) |
| `D` | Cloner (dupliquer) le fichier | **Un seul fichier** sélectionné (permission `clone`) |
| `I` | **Dossier :** Couleur et icône / **Fichier :** Métadonnées et point focal | Un seul élément sélectionné (permission `edit_metadata`) |
| `E` | Ouvrir l'éditeur d'image Canvas intégré | Une seule image matricielle (`.jpg`, `.jpeg`, `.png`, `.webp`) sélectionnée (permission `upload`) |
| `L` | Copier l'URL du fichier dans le presse-papiers | Un seul fichier sélectionné (permission `download`) |
| `S` | Ouvrir la modale de lien de partage temporaire HMAC | Un seul fichier sélectionné (permission `share` + `share.enabled: true`) |
| `Delete` | Supprimer les éléments sélectionnés (corbeille ou définitif) | 1 élément ou plus sélectionné (permission `delete`) |

#### B. Navigation et aperçu
| Raccourci | Action | Condition / Remarque |
| :--- | :--- | :--- |
| `/` ou `Ctrl + F` | Donner le focus au champ de recherche | Lorsque `show_search: true` (`Ctrl + F` fonctionne aussi dans les champs de saisie) |
| `←` `→` `↑` `↓` | Se déplacer entre les cartes en grille ou les lignes en liste | — |
| `Enter` | **Dossier :** ouvrir • **Fichier unique :** ouvrir la Lightbox en mode Inline, ou confirmer la sélection en mode Modal/OffCanvas | — |
| `Space` | Prévisualiser le fichier sélectionné dans la Lightbox | Un seul fichier sélectionné |
| `Backspace` ou `Alt + ↑` | Remonter au dossier parent | À l'intérieur d'un sous-dossier |
| `Alt + ←` / `Alt + →` | Reculer / Avancer dans l'historique | Automatiquement inversé dans les locales **RTL** (arabe, persan, hébreu) |
| `R` | Actualiser le dossier courant depuis le serveur (`?refresh=1`) | Lorsque `show_refresh_button: true` |

#### C. Sélection, presse-papiers et fermeture par niveaux
| Raccourci | Action | Condition / Remarque |
| :--- | :--- | :--- |
| `Ctrl + A` | Sélectionner tous les éléments visibles | Hors corbeille |
| `Ctrl + C` / `Ctrl + X` | Copier / Couper les éléments sélectionnés dans le presse-papiers | Lorsqu'aucun texte n'est surligné (permission `copy` / `move`) |
| `Ctrl + V` | Coller les fichiers/dossiers du presse-papiers dans le dossier courant **ou** téléverser directement une image/un fichier depuis le presse-papiers du système | Fonctionne également lorsque la fenêtre de téléversement est ouverte |
| `Shift + Click` / `Ctrl + Click` | Sélection par plage / bascule individuelle d'éléments | Vous pouvez aussi cliquer-glisser dans le vide pour la sélection au lasso (marquee) |
| `Esc` | **Fermeture intelligente en 4 étapes :** 1) Fermer le menu/la modale/la Lightbox/les paramètres/le tiroir mobile ouvert → 2) Effacer la sélection → 3) Vider le presse-papiers → 4) Quitter le plein écran inline | Ne ferme que la couche la plus haute à chaque appui |
| `Tab` / `Shift + Tab` | Parcourir les contrôles interactifs dans une modale ou une Lightbox ouverte (piège de focus) | — |

#### D. Affichage et aide
| Raccourci | Action |
| :--- | :--- |
| `V` | Basculer entre la vue Grille et la vue Liste |
| `B` | Afficher ou masquer l'arborescence des dossiers à gauche |
| `F` | Activer ou quitter le mode plein écran |
| `T` | Basculer vers la vue Corbeille (lorsque `trash.enabled: true`) |
| `?` ou `؟` arabe | Ouvrir la fenêtre d'aide des raccourcis clavier |

#### E. À l'intérieur de la Lightbox d'aperçu
| Raccourci | Action |
| :--- | :--- |
| `←` / `→` | Image précédente / suivante *(automatiquement inversé dans les locales RTL)* |
| `+`, `=` ou `Numpad +` | Zoomer |
| `-` ou `Numpad -` | Dézoomer |
| `0` ou `Numpad 0` | Réinitialiser le zoom à `100%` |
| `Esc` | Fermer la Lightbox |

#### F. À l'intérieur d'un menu contextuel ouvert
| Raccourci | Action |
| :--- | :--- |
| `↑` / `↓` | Monter / descendre dans les options du menu |
| `Home` / `End` | Aller à la première / dernière option du menu |
| `Enter` / `Space` | Exécuter l'action sélectionnée |
| `Tab` / `Esc` | Fermer le menu contextuel |

---

[← Précédent : 02 — Configuration et profils](02-configuration-and-profiles.md) | [Index de la documentation](README.md) | [Suivant : 04 — Thèmes, jeux d'icônes et personnalisation →](04-themes-icons-and-customization.md)
