# 11 — Guide de contribution & Pull Request (PR) (pas à pas pour débutants)

[← Précédent : 10 — Protection des fichiers utilisés (Asset Usage Guard)](10-asset-usage-guard.md) | [Index de la documentation](README.md) | [Suivant : 12 — Guide des Agent Skills IA →](12-ai-agent-skills-guide.md)

Ce guide a été rédigé de A à Z pour les développeurs qui n'ont **jamais encore ouvert de Pull Request (PR) sur un projet open-source**, ainsi que pour les mainteneurs souhaitant mettre en place une **porte qualité PR automatisée à la Symfony** dans leur propre dépôt. Chaque concept et chaque commande sont expliqués étape par étape.

---

## 1. Vue d'ensemble : comment fonctionnent les Pull Requests open-source

Dans les grands projets open-source comme Symfony, Laravel ou React, les contributeurs externes ne peuvent pas pousser directement sur la base de code principale (branche `main`). À la place, chaque modification passe par un **parcours sécurisé en 4 étapes** :

1. **Fork & Branch (obtenir sa propre copie) :** copiez le dépôt sur votre propre compte GitHub (*Fork*) et créez une branche de travail dédiée (*Branch*).
2. **Code & vérifications locales (`composer qa`) :** implémentez votre modification, écrivez les tests correspondants et exécutez l'intégralité de la suite qualité automatisée sur votre machine.
3. **Ouverture d'une Pull Request (PR) :** soumettez une demande invitant les mainteneurs à relire et fusionner votre correctif ou votre fonctionnalité dans le dépôt principal. GitHub affiche automatiquement un **tableau récapitulatif et une checklist de PR inspirés de Symfony** (`.github/PULL_REQUEST_TEMPLATE.md`).
4. **Vérifications CI automatisées (GitHub Actions) + revue de code :**
   - Dès qu'une PR est ouverte ou mise à jour, GitHub Actions (`.github/workflows/ci.yml`) lance automatiquement des runners cloud.
   - Le code est testé sous **PHP 8.3 & 8.4**, **Symfony 7.2, 7.4 & 8.0** et **Node 20 & 22**, et la CI contrôle les standards de code (**PHP-CS-Fixer**), la sûreté du typage statique (**PHPStan niveau 8**), la complétude des traductions (**TranslationParityTest**), la conformité du schéma YAML dans la documentation (**FullConfigReferenceDocTest**), l'intégrité des Agent Skills (**SkillsPackageIntegrityTest**) ainsi que la sécurité XSS/CSP/frontend (**DomAndCspSecurityTest + `dom_security_test.mjs` + `frontend_audit_test.mjs`**).
   - **Si ne serait-ce qu'une seule vérification échoue, GitHub bloque le bouton Merge.**

---

## 2. L'infrastructure qualité est-elle déjà en place ?

**Oui — elle est configurée à 100 % et prête à l'emploi.** Le bundle embarque une chaîne d'outillage qualité complète directement calquée sur les standards officiels Symfony UX :

| Fichier | Rôle (en termes simples) |
| :--- | :--- |
| [`composer.json`](../../composer.json) | Définit la suite de scripts `composer qa` exécutable en une commande ainsi que les dépendances de développement autonomes (`require-dev`). |
| [`phpunit.xml.dist`](../../phpunit.xml.dist) | Exécute tous les tests unitaires et de sécurité PHP (**57 fichiers de tests, 360 tests, plus de 3 500 assertions**) de façon autonome, sans application hôte requise. |
| [`phpstan.dist.neon`](../../phpstan.dist.neon) | Configure l'analyse statique **PHPStan niveau 8** pour détecter les incohérences de types, arguments invalides et appels de méthodes indéfinies avant l'exécution. |
| [`.php-cs-fixer.dist.php`](../../.php-cs-fixer.dist.php) | Applique le style de code PSR-12 + Symfony sur tous les fichiers PHP (`declare(strict_types=1)`, comparaisons strictes `===`, imports `use` triés par ordre alphabétique). |
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | Lance automatiquement **6 jobs CI en parallèle** à chaque création ou mise à jour de Pull Request. |
| [`.github/PULL_REQUEST_TEMPLATE.md`](../../.github/PULL_REQUEST_TEMPLATE.md) | Préremplit chaque nouvelle PR avec un tableau récapitulatif façon Symfony (`Bug fix?`, `BC breaks?`, `Tests pass?`) et une checklist. |
| [`.github/ISSUE_TEMPLATE/`](../../.github/ISSUE_TEMPLATE/) | Fournit des formulaires structurés pour les rapports de bugs (`bug_report.yml`) et les demandes de fonctionnalités (`feature_request.yml`). |
| [`CONTRIBUTING.md`](../../CONTRIBUTING.md) | Charte internationale de contribution automatiquement mise en lien par GitHub sur chaque écran de création de PR et d'Issue. |
| [`SECURITY.md`](../../SECURITY.md) | Politique de divulgation de sécurité expliquant comment signaler une vulnérabilité en privé plutôt que d'ouvrir une issue publique. |

### 7 règles critiques appliquées automatiquement

Même si un relecteur humain laisse passer l'un des problèmes ci-dessous, **la suite de tests automatisée le détecte et fait échouer la PR immédiatement** :

1. **Traductions manquantes (`TranslationParityTest`) :** si une nouvelle chaîne d'interface est ajoutée en anglais mais manque dans l'une des **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), le test **ÉCHOUE**.
2. **`strict_types` manquant (`TranslationParityTest`) :** si ne serait-ce qu'un seul fichier PHP sous `src/` omet `declare(strict_types=1);`, le test **ÉCHOUE**.
3. **Violations de sécurité XSS / CSP / frontend (`DomAndCspSecurityTest` + `dom_security_test.mjs` + `frontend_audit_test.mjs`) :** ajouter des gestionnaires inline tels que `onclick="..."` ou `|raw` dans Twig, ou casser la désinfection d'URL `javascript:` / DOM côté JS fait **ÉCHOUER** le test.
4. **Dérive du schéma YAML dans la documentation (`FullConfigReferenceDocTest`) :** tous les blocs YAML `liveux_file_manager:` de la référence [Configuration & Profils](02-configuration-and-profiles.md) (`docs/tr/02-yapilandirma-ve-profiller.md`) sont validés contre le véritable schéma de `Configuration.php`.
5. **Intégrité des Agent Skills (`SkillsPackageIntegrityTest`) :** valide le frontmatter, les budgets de lignes/taille et les liens de référence dans `AGENTS.md` ainsi que dans les 6 fichiers `skills/*/SKILL.md`.
6. **Style de code & typage statique (`PHP-CS-Fixer` + `PHPStan niveau 8`) :** toute violation PSR-12/Symfony ou erreur de type niveau 8 bloque la CI.
7. **Synchronisation des assets minifiés `assets/dist/` (`npm --prefix assets run build:check`) :** si vous modifiez `assets/controllers/*.js` ou `assets/styles/core.css` et oubliez de reconstruire `assets/dist/`, la CI **ÉCHOUE**.

---

## 2.5. Architecture de minification JS & CSS (`assets/dist/`) avec `esbuild`

> [!NOTE]
> **Pourquoi ce mécanisme existe-t-il ?**
> - Les utilisateurs de **Webpack Encore** bénéficient automatiquement de la minification JS/CSS lors de `npm run build`.
> - En revanche, **Symfony AssetMapper** (`asset-map:compile`) sert les fichiers tels quels sans les minifier. Sous forme source brute, `browser_controller.js` pèse `518 Ko` et `core.css` pèse `132 Ko`.
> - Pour garantir des chargements rapides aux utilisateurs d'AssetMapper sans exiger Node.js dans les projets hôtes, nous suivons exactement le modèle **Source → Distribution (`assets/dist/`)** utilisé par les packages officiels Symfony UX (`ux-live-component`, `ux-chartjs`, `ux-autocomplete`).

### Fonctionnement

1. **Fichiers sources lisibles :** tout le développement et les tests de sécurité s'exécutent sur des fichiers sources proprement formatés et commentés :
   - `assets/controllers/{browser,dialog,picker}_controller.js`
   - `assets/js/file_manager_api.js` et `assets/js/integrations/{tinymce,ckeditor5,tiptap}_adapter.js`
   - `assets/styles/core.css` et `assets/images/liveux-logo.webp`
2. **Sortie de production minifiée (`assets/dist/`) :** le script [`assets/build.mjs`](../../assets/build.mjs) utilise **`esbuild`** pour minifier chaque fichier JS en tant que module ESM autonome (en gardant `@hotwired/stimulus` externe et en préservant les imports relatifs) et compile `core.css` dans `assets/dist/`.
3. **Résolution automatique (zéro rupture de compatibilité) :**
   - `prependAssetMapper()` dans [`FileManagerBundle.php`](../../src/FileManagerBundle.php) associe automatiquement le namespace `@liveux/file-manager-bundle` à `assets/dist/` dès lors que `assets/dist` existe.
   - Les entrées `"exports"` et `"symfony.controllers.*.main"` dans [`assets/package.json`](../../assets/package.json) garantissent que Webpack Encore consomme également directement les fichiers de `dist/`.
   - Comme `assets/dist/` est versionné dans Git, **les utilisateurs finaux n'ont jamais besoin de Node.js ni de npm**. `.gitattributes` exclut uniquement les outils de build (`assets/node_modules`, `assets/build.mjs`, `assets/package-lock.json`) des archives Packagist tout en conservant `assets/package.json` et `assets/dist/`.

### Gains de taille mesurés

| Fichier | Source brute | Minifié (`assets/dist/`) | Gzip | Brotli |
| :--- | ---: | ---: | ---: | ---: |
| `controllers/browser_controller.js` | 518,3 Ko | **259,5 Ko** | 60,9 Ko | **49,2 Ko** |
| `controllers/picker_controller.js` | 45,0 Ko | **24,3 Ko** | 7,0 Ko | **6,2 Ko** |
| `controllers/dialog_controller.js` | 27,5 Ko | **13,9 Ko** | 4,7 Ko | **4,1 Ko** |
| `js/file_manager_api.js` + 3 adaptateurs éditeur | 24,5 Ko | **11,0 Ko** | 5,1 Ko | **4,6 Ko** |
| `styles/core.css` | 131,8 Ko | **101,5 Ko** | 15,3 Ko | **12,9 Ko** |
| **TOTAL (`-45,1 %` minifié)** | **747,2 Ko** | **410,2 Ko** | **92,9 Ko** | **76,9 Ko** |

### Commandes développeur (à chaque modification JS ou CSS)

```bash
# 1. Installer esbuild une seule fois (première utilisation uniquement) :
npm --prefix assets install

# 2. Reconstruire assets/dist/ après toute modification JS ou CSS (~50 ms) :
npm --prefix assets run build
# ou via le raccourci Composer :
composer assets:build

# 3. Vérifier que assets/dist/ est synchronisé à 100 % avec les sources (même contrôle que la CI) :
composer assets:check
```

---

## 3. Guide pas à pas pour soumettre une Pull Request

> [!TIP]
> **Même si vous n'avez jamais soumis de PR auparavant**, suivez ces 6 étapes dans l'ordre pour ouvrir une Pull Request propre qui passe la CI.

### Étape 1 : Forker, cloner et installer les dépendances

1. Ouvrez [`LiveUX/file-manager-bundle`](https://github.com/LiveUX/file-manager-bundle) sur GitHub et cliquez sur **Fork** en haut à droite pour créer une copie sur votre propre compte GitHub.
2. Clonez votre fork en local, ajoutez le dépôt principal comme `upstream`, puis installez les outils de développement PHP et Node :

```bash
git clone https://github.com/<YOUR-USERNAME>/file-manager-bundle.git
cd file-manager-bundle

# Suivre le dépôt principal via upstream (excellente habitude pour rester à jour)
git remote add upstream https://github.com/LiveUX/file-manager-bundle.git

# Installer les dépendances PHP de tests et d'analyse statique
composer install

# Installer le minifieur JS/CSS (esbuild) si vous prévoyez de modifier des fichiers JS/CSS
npm --prefix assets install
```

### Étape 2 : Créer une nouvelle branche depuis un `main` à jour

Ne commitez jamais directement sur `main`. Synchronisez d'abord `main`, puis créez une branche au nom explicite pour votre travail :
- Correctif de bug : `fix/folder-counter-cache`
- Nouvelle fonctionnalité : `feat/new-icon-set`
- Documentation : `docs/installation-examples`

```bash
git checkout main
git pull upstream main
git checkout -b fix/folder-counter-cache
```

### Étape 3 : Écrire le code, ajouter les tests et reconstruire `dist/` si nécessaire

- **Correction d'un bug :** commencez par écrire un test de non-régression ciblé qui reproduit le bug (rouge avant le correctif, vert après).
- **Ajout d'une fonctionnalité :**
  - Ajoutez des tests unitaires PHP sous `tests/Unit/...`.
  - Pour les évolutions UI/JS, enrichissez `tests/Js/dom_security_test.mjs` ou `tests/Js/frontend_audit_test.mjs`.
  - **Dès que vous touchez au JS ou au CSS, exécutez `composer assets:build`** (sinon la CI échouera à l'étape `build:check`).
  - Dès que vous ajoutez une nouvelle clé de traduction UI, ajoutez-la dans **les 12 catalogues de langues UI intégrés** : `translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`.

### Étape 4 : Exécuter toute la porte qualité en local d'une traite

Lancez exactement les mêmes contrôles que ceux exécutés par la CI avant d'ouvrir votre PR :

```bash
composer qa
composer assets:check
```

Ces commandes vérifient, dans l'ordre :
1. `composer validate --strict` — Valide `composer.json`.
2. `composer cs:check` — Vérifie les standards de code PSR-12/Symfony *(en cas d'écart signalé, lancez `composer cs:fix` pour formater automatiquement)*.
3. `composer stan` — Exécute l'analyse statique **PHPStan niveau 8**.
4. `composer test` — Lance la suite PHPUnit complète (tests unitaires, sécurité, parité des traductions et validation du schéma).
5. `composer test:js:syntax` — Exécute la validation syntaxique `node --check` sur tous les fichiers JS sources et `dist/`.
6. `composer test:js` — Exécute les suites Node.js de sécurité DOM et d'audit frontend (`dom_security_test.mjs` + `frontend_audit_test.mjs`).
7. `composer assets:check` — Confirme que `assets/dist/` correspond octet par octet aux fichiers sources actuels.

> [!TIP]
> **Tout est au vert ?** Vous êtes prêt à commiter et ouvrir votre Pull Request.

### Étape 5 : Créer un commit propre et pousser sur votre fork

```bash
git status            # Vérifier que seuls les fichiers voulus sont modifiés
git diff --stat       # Parcourir un résumé rapide de vos changements
git add -A
git commit -m "fix: invalidate stale cached folder tree missing item counts"
git push -u origin fix/folder-counter-cache
```

*(Utilisez des préfixes Conventional Commits clairs : `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `perf:`.)*

### Étape 6 : Ouvrir la Pull Request sur GitHub et vérifier que la CI passe au vert

1. Rendez-vous sur votre fork sur GitHub (`https://github.com/<YOUR-USERNAME>/file-manager-bundle`) et cliquez sur la bannière **« Compare & pull request »**.
2. Vérifiez les branches cibles : **Base repository :** `LiveUX/file-manager-bundle` (`base: main`) ← **Head repository :** `<YOUR-USERNAME>/file-manager-bundle` (`compare: fix/folder-counter-cache`).
3. Remplissez le tableau du **modèle de PR** prérempli (`Bug fix? yes`, `BC breaks? no`, `Tests pass? yes`) et cochez les éléments de la checklist (`[x]`). Si votre modification impacte l'interface utilisateur, joignez une capture d'écran ou un GIF.
4. Cliquez sur **« Create pull request »**.
5. Attendez que tous les contrôles GitHub Actions (`PHP 8.3/8.4`, `Node 20/22`, `PHPStan & CS-Fixer`) en bas de la page de PR affichent une **coche verte (✓)**.
6. Si un relecteur demande des ajustements, modifiez les fichiers sur la même branche puis exécutez `git commit && git push` — GitHub met à jour la PR et relance automatiquement la CI.

---

## 4. Pour les mainteneurs : activer la protection de branche & les Rulesets sur GitHub

Une fois les fichiers de workflow présents dans le dépôt, la CI tourne automatiquement — mais pour garantir que **personne (pas même un mainteneur par inadvertance) ne puisse cliquer sur Merge tant que des contrôles sont rouges**, configurez un **Ruleset** GitHub en 2 minutes :

1. Ouvrez le dépôt de votre bundle sur GitHub et allez dans **Settings**.
2. Dans la barre latérale gauche, sélectionnez **Rules → Rulesets** (ou **Branches → Add branch protection rule** dans la vue classique).
3. Cliquez sur **New branch ruleset** :
   - **Ruleset Name :** `Protect main (Symfony Quality Gate)`
   - **Enforcement status :** `Active`
   - **Target branches :** `Add target` → `Include default branch` (`main`)
4. Activez les 4 protections suivantes :
   - [x] **Restrict deletions** (empêche la suppression de `main`)
   - [x] **Block force pushes** (protège l'historique des commits)
   - [x] **Require a pull request before merging** (bloque les push directs sur `main` ; tous les changements passent par PR)
     - *Required approvals :* `1` (recommandé en équipe ; un mainteneur solo peut laisser `0`)
     - [x] *Dismiss stale pull request approvals when new commits are pushed*
   - [x] **Require status checks to pass** *(paramètre le plus important)*
     - [x] *Require branches to be up to date before merging*
     - Après la première exécution de la CI, utilisez **Add checks** pour exiger les **6 jobs** :
       - `PHP 8.3 (Symfony 7.2.*, lowest)`
       - `PHP 8.3 (Symfony 7.4.*, highest)`
       - `PHP 8.4 (Symfony 8.0.*, highest)`
       - `JavaScript Syntax & DOM Security (Node 20)`
       - `JavaScript Syntax & DOM Security (Node 22)`
       - `Static Analysis (PHPStan) & Coding Standards (CS-Fixer)`
5. Cliquez sur **Create** en bas de la page.

---

## 5. Checklist de publication du bundle en dépôt open-source autonome

Pour publier le bundle en tant que `LiveUX/file-manager-bundle` sur GitHub et Packagist :

1. Créez un nouveau dépôt **Public** vide nommé `file-manager-bundle` dans l'organisation GitHub `LiveUX`.
2. Poussez le contenu de `bundles/liveux-file-manager-bundle/` comme commit initial (`git init && git add . && git commit -m "chore: initial v0.1.0 release" && git push -u origin main`).
3. Vérifiez dans l'onglet **GitHub Actions** que les 6 jobs CI passent avec des coches vertes.
4. Activez les paramètres de **Ruleset** décrits dans la **Section 4** ci-dessus.
5. Créez le tag `v0.1.0` (`git tag -a v0.1.0 -m "v0.1.0" && git push origin v0.1.0`) et soumettez `https://github.com/LiveUX/file-manager-bundle` sur [Packagist.org](https://packagist.org).
6. N'importe qui peut désormais installer le bundle en une seule commande : `composer require liveux/file-manager-bundle`.

---

[← Précédent : 10 — Protection des fichiers utilisés (Asset Usage Guard)](10-asset-usage-guard.md) | [Index de la documentation](README.md) | [Suivant : 12 — Guide des Agent Skills IA →](12-ai-agent-skills-guide.md)
