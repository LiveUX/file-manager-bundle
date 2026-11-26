# 12 — Guide des Agent Skills IA (`SKILL.md` & `AGENTS.md`)

[← Précédent : 11 — Guide de contribution & Pull Request](11-contributing-and-pr-guide.md) | [Index de la documentation](README.md) | [Suivant : 13 — Intégration Doctrine →](13-doctrine-integration.md)

> [!TIP]
> **En termes simples : à quoi servent les Agent Skills (`SKILL.md`) ?**
> Si vous utilisez un assistant de développement IA tel qu'**Antigravity, Cursor, Claude Code, GitHub Copilot ou OpenAI Codex**, exécutez `php bin/console file-manager:skills:install` une fois dans votre projet. Votre assistant apprend alors les clés de configuration YAML exactes du bundle, ses invariants de sécurité et ses exemples idiomatiques — afin de générer du code fonctionnel du premier coup plutôt que d'inventer des options inexistantes.

**LiveUX FileManagerBundle** embarque un pack de skills conforme à la spécification ouverte **Agent Skills (`SKILL.md`)** permettant aux assistants de code IA modernes (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** et **Symfony AI Mate**) d'intégrer le bundle avec précision, sécurité et sans approximation.

---

## 1. Les 6 skills spécialisés inclus dans le bundle

Les skills sont organisés selon le principe de **divulgation progressive (*progressive disclosure*)** : votre assistant IA ne charge que le skill pertinent pour votre tâche en cours, afin de garder la consommation de contexte légère et ciblée :

| Nom du skill (`skills/<name>/`) | Quand il s'active | Fichier de référence |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Installation, routage, AssetMapper/Encore, profils & volumes (Local/S3/R2/MinIO/Supabase), quotas (`storage_quota`, `show_quota`), optimisation d'images, détection des doublons | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (simple/galerie), 5 variantes intégrées, templates Twig personnalisés pour FormType (`liveux:file-manager:picker-changed`), `FileManagerField` pour EasyAdmin, API JS, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 thèmes intégrés, mode sombre (`color_scheme`), thèmes personnalisés purement CSS ou Twig en 10 blocs, système d'icônes SVG en 3 couches (49 icônes UI + 14 badges de dossiers) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | Pare-feu `security.yaml`, 17 permissions de Voter, Voters personnalisés au niveau dossier (`unanimous`), Asset Usage Guard (`AssetUsageCheckerInterface` — suppression/renommage/déplacement/écrasement), liens de partage signés, journal d'audit, durcissement Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Configuration dynamique en BDD (`ConfigProviderInterface`), stockage personnalisé (`FolderMetadataStorageInterface`), 9 écouteurs d'événements Symfony, Symfony AI (`AssetEnricherInterface`) et toutes les commandes CLI | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Règles de développement du bundle pour la compatibilité PHP 8.3 / Symfony 7.2–8.0, invariants de sécurité, parité des traductions et porte qualité PR `composer qa` | `AGENTS.md` (racine du package) |

---

## 2. Trois façons d'installer les skills dans votre projet

### Méthode A — Commande Symfony Console intégrée (recommandée, zéro dépendance supplémentaire)

```bash
# Pour Antigravity, Codex, OpenCode et Windsurf (.agents/skills/)
php bin/console file-manager:skills:install

# Pour Claude Code (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Pour Cursor (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# Pour GitHub Copilot (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Installer dans tous les outils IA pris en charge en une seule commande
php bin/console file-manager:skills:install --target=all
```

### Toutes les options de la commande

| Option / Raccourci | Description | Exemple |
| :--- | :--- | :--- |
| `-t` / `--target` | Outil IA cible (`agents`, `claude`, `cursor`, `copilot`, `all`). Par défaut : `agents`. | `-t all` |
| `--dir` | Répertoire cible personnalisé. **Règle de sécurité :** doit impérativement être un sous-répertoire à l'intérieur de `%kernel.project_dir%` (`..`, octets NUL, racine du projet elle-même et répertoires parents via lien symbolique sont rejetés). | `--dir=.custom/skills` |
| `--only` | Installe uniquement les skills sélectionnés (noms courts ou complets séparés par des virgules). | `--only=setup,security` |
| `-f` / `--force` | Écrase les répertoires de skills existants avec la dernière version. | `--force` |
| `--symlink` | Crée des liens symboliques au lieu de copier les fichiers (bascule automatiquement sur la copie sous Windows si les privilèges de lien symbolique ne sont pas disponibles). | `--symlink` |
| `--dry-run` | Prévisualise quels skills seraient installés et à quel emplacement, sans rien écrire sur le disque. | `--dry-run` |

### Méthode B — Découverte automatique avec Symfony AI Mate (`symfony/ai-mate`)

Le fichier `composer.json` du bundle déclare `extra.ai-mate.skills` :

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Si `symfony/ai-mate` est installé dans votre projet, une seule commande découvre et synchronise les 6 skills dans votre espace de travail :

```bash
vendor/bin/mate discover
```

### Méthode C — CLI ouverte Agent Skills (`npx skills`)

Installez directement depuis GitHub dans n'importe quel projet :

```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Fichier racine `AGENTS.md`

Le fichier `AGENTS.md` à la racine du bundle fournit des règles actives en permanence pour les agents IA contribuant directement au bundle lui-même :
- Zéro dépendance à un framework CSS et règles relatives aux ponts (*bridges*) optionnels
- Typage PHP strict (`declare(strict_types=1)`), bouchons de tests compatibles Symfony 7.2 / 8.0
- Interdiction du filtre Twig `|raw`, utilisation obligatoire de `isSafeUrl()` / `escapeHtml()` en JavaScript
- Validation CSRF en priorité, isolation du cache pour les volumes privés/Voters et parité de traduction à 100 % sur les **12 langues d'interface intégrées** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`)

---

## 4. Vérification CI automatisée

À chaque PR et exécution CI, `SkillsPackageIntegrityTest` vérifie automatiquement que :
1. `AGENTS.md` existe, respecte le budget de contexte **`<= 24 000 octets`** et ne contient pas de frontmatter YAML par accident.
2. `composer.json` définit correctement `extra.ai-mate.skills`.
3. Chaque dossier sous `skills/` contient un fichier `SKILL.md` doté d'un frontmatter YAML valide où `name` correspond exactement au nom du dossier, `license: MIT` est déclaré, `description` comporte au moins `40` caractères et chaque fichier respecte la limite de `<= 500` lignes.
4. Chaque lien relatif `references/*.md` à l'intérieur de `SKILL.md` pointe vers un fichier existant sur le disque.

---

[← Précédent : 11 — Guide de contribution & Pull Request](11-contributing-and-pr-guide.md) | [Index de la documentation](README.md) | [Suivant : 13 — Intégration Doctrine →](13-doctrine-integration.md)
