<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../images/banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="../images/banner-light.png">
    <img alt="LiveUX FileManagerBundle — Symfony UX Dateimanager und DAM" src="../images/banner-light.png" width="100%">
  </picture>
</p>

# LiveUX FileManagerBundle — Deutsche Dokumentation

**Sprache:** [English](../en/README.md) · [Türkçe](../tr/README.md) · [Français](../fr/README.md) · **Deutsch** · [Español](../es/README.md) · [العربية](../ar/README.md) · [Italiano](../it/README.md) · [Português](../pt/README.md) · [Nederlands](../nl/README.md) · [Polski](../pl/README.md) · [日本語](../ja/README.md) · [简体中文](../zh_CN/README.md) · [Haupt-README](../../README.md)

**LiveUX FileManagerBundle** ist ein moderner, performanter und Enterprise-tauglicher **Symfony UX Dateimanager & Digital Asset Manager (DAM)** für Symfony 6.4 LTS, 7.2+ und 8.x (PHP 8.3+). Das Bundle kommt **komplett ohne CSS-Framework-Abhängigkeiten** aus, bietet 14 integrierte Sicherheitsebenen sowie umfassende Mehrsprachigkeit (12 integrierte Oberflächensprachen, BCP-47-/POSIX-Locale-Erkennung und native RTL-Unterstützung).

---

## Live-Vorschau & Screenshots

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
  <img src="../images/demo-showcase.gif" alt="LiveUX FileManagerBundle Interaktive Demo" width="100%">
</p>

> **Full-HD-Showcase-Video (1080p, ~3 Min., 10 Kapitel)** für YouTube und Releases: [YouTube (1080p)](https://www.youtube.com/watch?v=H2CAf0RiDd8) · [`docs/images/demo-youtube-showcase.mp4`](../images/demo-youtube-showcase.mp4) · Kurzvorschau: [`docs/images/demo-showcase.mp4`](../images/demo-showcase.mp4)

| Heller Modus (`Light`) | Dunkler Modus (`Dark`) |
| :---: | :---: |
| ![Dateimanager im hellen Modus](../images/browser-light.png) | ![Dateimanager im dunklen Modus](../images/browser-dark.png) |

| Anpassung von Ordnerfarben & -Symbolen | Über-Dialog (`LiveUX`) |
| :---: | :---: |
| ![Anpassung von Ordnerfarbe und Badge](../images/folder-customize.png) | ![Über-Dialog](../images/about-modal.png) |

### Rich-Text-Editoren, EasyAdmin & Dynamische Formulare (GIF-Demos)

| TinyMCE 5/6/7/8 | CKEditor 5 |
| :---: | :---: |
| ![TinyMCE-Integration](../images/demo-editor-tinymce.gif) | ![CKEditor-5-Integration](../images/demo-editor-ckeditor5.gif) |

| Tiptap 2 (ProseMirror) | Quill 2 |
| :---: | :---: |
| ![Tiptap-2-Integration](../images/demo-editor-tiptap.gif) | ![Quill-2-Integration](../images/demo-editor-quill.gif) |

| Trix (EasyAdmin `TextEditorField`) | Summernote |
| :---: | :---: |
| ![Trix-Integration](../images/demo-editor-trix.gif) | ![Summernote-Integration](../images/demo-editor-summernote.gif) |

| Editor.js (Block-Editor) | GrapesJS (Visueller Page-Builder) |
| :---: | :---: |
| ![Editor.js-Integration](../images/demo-editor-editorjs.gif) | ![GrapesJS-Integration](../images/demo-editor-grapesjs.gif) |

| Meta Lexical | EasyAdmin 4/5 (`FileManagerField`) |
| :---: | :---: |
| ![Meta-Lexical-Integration](../images/demo-editor-lexical.gif) | ![EasyAdmin-5-Integration](../images/demo-easyadmin.gif) |

| Symfony `CollectionType` & `LiveCollectionType` |
| :---: |
| ![CollectionType- und LiveCollectionType-Integration](../images/demo-collections.gif) |

---

## 2-Minuten-Schnellstart-Wegweiser

Sie wissen nicht genau, wo Sie anfangen sollen? Springen Sie direkt zu dem Leitfaden, der zu Ihrem Ziel passt:

- **Erstinstallation:** [01 — Voraussetzungen & Installation](01-requirements-and-installation.md) → [02 — Konfiguration & Profile](02-configuration-and-profiles.md)
- **Bild- oder Galerie-Auswahl in ein Symfony-Formular einbauen:** [05 — FormType & Editor-Integrationen](05-formtype-and-editor-integrations.md)
- **Sicherheit & Zugriffsrechte (RBAC) für die Produktion absichern:** [06 — Sicherheit & Autorisierung](06-security-and-authorization.md)
- **Supabase, AWS S3 oder Cloudflare R2 anbinden:** [07 — Cloud-Speicher & Dynamische Konfiguration](07-cloud-storage-and-dynamic-config.md)
- **Metadaten über Doctrine ORM / DBAL speichern:** [13 — Doctrine-Integration](13-doctrine-integration.md)
- **Löschen oder Verschieben von in der Datenbank referenzierten Assets verhindern:** [10 — Asset Usage Guard](10-asset-usage-guard.md)

---

## Inhaltsverzeichnis (Dokumentationsübersicht)

| Kapitel | Leitfaden | Zusammenfassung |
| :---: | :--- | :--- |
| **01** | [Voraussetzungen, Abhängigkeiten & Installation](01-requirements-and-installation.md) | Systemanforderungen für PHP 8.3+ / Symfony 6.4 LTS, 7.2+ & 8.x, PHP-Erweiterungen (inkl. `ext-exif`, `ext-zip`), Composer-Abhängigkeiten, AssetMapper & Webpack Encore, `file-manager:doctor` und Ihre erste funktionierende Seite |
| **02** | [Konfiguration & Profile (YAML-Referenz)](02-configuration-and-profiles.md) | `defaults`, `volumes`, `profiles`, kollisionsfreie dynamische Pfade (`{user_id}`, `{Y}/{m}`), Upload-/Chunk-Limits, automatische Benennung, Bildoptimierung & Constraints, Vorschaubilder, Papierkorb, ZIP, 5 produktionsreife Rezepte sowie die vollständige YAML-Schemareferenz |
| **03** | [Verwendungsmodi & JavaScript-API](03-usage-modes-and-js-api.md) | Inline-`TwigComponent`, `<dialog>`-Modal & 4-Wege-OffCanvas, vollständige Referenz für `window.LiveUXFileManager.open()`, benutzerdefinierte DOM-Events, RTL/IME/WCAG-2.1-AA-Barrierefreiheit, Kontextmenüs, integrierter Bildeditor und Tastenkombinationen |
| **04** | [Themes, Icon-Sets & Anpassung](04-themes-icons-and-customization.md) | 9 WCAG-2.1-AA-konforme Standard-Themes (Bootstrap 5, Tailwind, Shadcn, Flowbite 4, DaisyUI 5, Tabler, Preline, Material, Unstyled), automatischer/manueller Dark Mode, 6 SVG-Icon-Sets (49 UI- + 14 Badge-Icons), Twig-Helper und 3 Stufen der visuellen Anpassung |
| **05** | [FormType & Editor-Integrationen](05-formtype-and-editor-integrations.md) | `FileManagerPickerType` (Einzel- und barrierefreie Mehrfach-Galerie-Sortierung + POST-Sicherheitsprüfung), 5 Varianten (`compact`, `card`, `dropzone`, `avatar`, `input`), `CollectionType` / `LiveCollectionType`, eigene Templates, TinyMCE, CKEditor 5, Tiptap und das EasyAdmin-`FileManagerField` mit 16 Methoden |
| **06** | [Sicherheit & Autorisierung (RBAC)](06-security-and-authorization.md) | 17 Voter-Berechtigungen, eigene `unanimous`-Voter, `security.yaml`-Firewall-Regeln, 14 Schutzebenen (CSRF, Path Traversal, 69 Erweiterungen / 47 MIME-Typen, `SvgSanitizer` mit 55 erlaubten Tags, SSRF-DNS-Rebinding-Schutz, `ConfinedLocalFilesystemAdapter` gegen Symlink-Ausbrüche, HMAC-Freigabelinks, Audit-Logging) sowie Apache-/Nginx-Härtung |
| **07** | [Cloud-Speicher (S3/R2/MinIO/Supabase) & Dynamische Konfiguration](07-cloud-storage-and-dynamic-config.md) | Multi-Volume-Architektur (lokal & Cloud), Anbieter-Anleitungen ([Supabase](cloud/supabase-storage.md), [AWS S3](cloud/aws-s3.md), [Cloudflare R2](cloud/cloudflare-r2.md), [Backblaze B2](cloud/backblaze-b2.md), [MinIO/RustFS](cloud/minio-rustfs.md), [Google Cloud Storage](cloud/google-cloud-storage.md), [SFTP & benutzerdefiniertes Flysystem](cloud/sftp-and-custom-flysystem.md)), `FlysystemServiceLocatorPass`, `ConfigProviderInterface` und eigene Metadaten-Speicher |
| **08** | [Events, CLI-Befehle, Caching & Fehlerbehebung](08-events-commands-cache-and-troubleshooting.md) | 9 Symfony-Domänen-Events, 10 Konsolenbefehle (`doctor`, `stats`, `duplicates`, `ai:enrich`, `skills:install` usw.), 3-stufiges Caching & kontextbewusste HTTP-304-/CDN-Isolierung, globales Übersetzungssystem in 12 Sprachen mit BCP-47/POSIX/RTL (493 Schlüssel) und FAQ |
| **09** | [Symfony-AI-Integration & Intelligente Anreicherung](09-symfony-ai-integration.md) | Abhängigkeitsfreies `AssetEnricherInterface`, `symfony/ai-bundle`-Bridge (OpenAI, Gemini, Claude, Ollama), **✨ Mit KI vorschlagen**, Stapelverarbeitung über `file-manager:ai:enrich`, asynchrone Messenger-Beispiele sowie 11-sprachiger Filter gegen LLM-Ablehnungen und Prompt-Injection |
| **10** | [Asset Usage Guard](10-asset-usage-guard.md) | Optionales `AssetUsageCheckerInterface`, Richtlinien `warn` / `block`, zweistufiger serverseitiger Schutz (`enforce_server_side`) für **`delete`, `rename`, `move` und `overwrite`** sowie Doctrine-Beispiele für MySQL, PostgreSQL und SQLite |
| **11** | [Mitwirken & Pull-Request-Leitfaden](11-contributing-and-pr-guide.md) | Einsteigerfreundlicher Open-Source-PR-Workflow, `composer qa`-Qualitätsprüfung (PHPStan Level 8, 360+ PHPUnit-Tests, 37+ JS-Tests), 6-Job-GitHub-Actions-CI und Einrichtung von GitHub Branch Protection |
| **12** | [Leitfaden für KI-Agenten-Skills (`SKILL.md` & `AGENTS.md`)](12-ai-agent-skills-guide.md) | 6 modulare `SKILL.md`-Pakete für Antigravity, Claude Code, Cursor, GitHub Copilot, Codex und Symfony AI Mate, Ein-Befehl-Installation via `file-manager:skills:install` und CI-Validierung |
| **13** | [Doctrine ORM & DBAL-Integration](13-doctrine-integration.md) | Integrierter Treiber `metadata.driver: 'doctrine'` (`DoctrineDbalMetadataStorage` — atomares UPSERT, PostgreSQL-`text_pattern_ops`-Index, Batch-`saveMany()`), plattformübergreifende Migrationen, Entity + `FileManagerPickerType`, eigener `ResetInterface`-kompatibler ORM-Speicher und DB-Synchronisierung über `ItemRenamedEvent` |
| **14** | [Sicherheitsaudit, Logikprüfung & Änderungsbericht](../tr/14-guvenlik-denetim-ve-degisiklik-raporu.md) *(nur auf Türkisch)* | Zeilenweises Sicherheitsaudit (Runden 1–18), kollisionsfreie Mandantentrennung mit `{user_id}`, Härtung von DI/Voter/Parser/SVG-`<use>`-Schrittbudget sowie CDN-/Origin-Spoofing-Schutz, Symlink-Eingrenzung, PostgreSQL `text_pattern_ops` & atomares UPSERT, WCAG-2.1-AA-Kontrast-/Fokus-Audit für 9 Themes, mobile Dialoge und i18n-Audit-Protokoll |

---

## Architektur-Highlights

- **0-ms-Hybrid-Geschwindigkeit:** Schnelle SSR-`TwigComponent` + speicherbasierter Browser-Cache (`dirCache`) + vollständiger Ordnerbaum in einer einzigen Anfrage. Filtern, Suchen, Sortieren, Blättern, der Wechsel zwischen Raster- und Listenansicht sowie das Auf- und Zuklappen von Ordnern erfolgen sofort ohne zusätzliche AJAX-Roundtrips.
- **Keine CSS-Framework-Abhängigkeiten & WCAG 2.1 AA:** Die gesamte Oberfläche nutzt gekapselte `.liveux-fm-*`-Klassen und CSS Custom Properties (`--liveux-fm-*`). Sie läuft reibungslos neben **Bootstrap 5, Tailwind CSS, Shadcn/UI, Flowbite 4, DaisyUI 5, Tabler, Preline, Material** oder **komplett ohne CSS-Framework** — ganz ohne Stilkonflikte und ohne zusätzliche Tailwind-Purge-Konfiguration.
- **Natives HTML5-`<dialog>`-Top-Layer & Mobile-First-Ergonomie:** Modal- und OffCanvas-Dialoge (`start`, `end`, `top`, `bottom`) werden im Top-Layer des Browsers gerendert und passen sich auf Mobilgeräten automatisch als Vollbild (`100dvh`) oder natives Bottom-Sheet mit Ziehgriff an.
- **14-stufige Enterprise-Sicherheit:** Symfony-`Voter`-RBAC, CSRF-Schutz (`X-LiveUX-FM-CSRF`), `PathNormalizer`, `FileSecurityGuard`, `SvgSanitizer` mit 55 erlaubten Tags, DNS-Rebinding-sicherer `RemoteUrlImporter`, `ConfinedLocalFilesystemAdapter` zur Symlink-Eingrenzung, Schutz gegen Dekompressions-/Pixel-Bomben sowie standardmäßig aktive Header `CSP: sandbox` + `nosniff`.
- **Globale Mehrsprachigkeits-Architektur:** **12 integrierte Oberflächensprachen** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` mit jeweils 493 synchronisierten Schlüsseln), BCP-47- und POSIX-`.UTF-8`-Locale-Normalisierung, garantiertes `'en'`-Fallback, automatisches RTL (`dir="rtl"`) für Arabisch und 14 weitere RTL-Sprachen sowie Schutz bei CJK-IME-Eingaben.
- **Multi-Server- & Cloud-ready:** Integriertes `DoctrineDbalMetadataStorage` (PostgreSQL `text_pattern_ops`, atomares UPSERT unter MySQL/MariaDB/SQLite), Flysystem v3 (S3, R2, Supabase, B2, MinIO, GCS, SFTP) sowie `ResetInterface`-Kompatibilität für FrankenPHP und Symfony-Messenger-Worker.

---

## 5-Minuten-Schnellstart-Checkliste

1. **Bundle installieren:** `composer require liveux/file-manager-bundle`
2. **Routen importieren:** `config/routes/liveux_file_manager.yaml`
3. **Profile definieren:** `config/packages/liveux_file_manager.yaml`
4. **CSS & JS (Zero Config):** Symfony UX `autoimport` lädt `core.css`, `file_manager_api.js` und alle 3 Stimulus-Controller automatisch über `assets/controllers.json` — sowohl für AssetMapper als auch für Webpack Encore.
5. **Dialog-Host (Automatisch):** `AutoInjectDialogHostSubscriber` fügt den `DialogHost` automatisch direkt vor `</body>` auf vollständigen HTML-Seiten ein (`defaults.auto_inject_dialog_host: true`) — Änderungen an `base.html.twig` sind nicht erforderlich.
6. **Einrichtung prüfen:** `php bin/console file-manager:doctor`

> Neu bei Symfony UX? Starten Sie Schritt für Schritt mit **[01 — Voraussetzungen, Abhängigkeiten & Installation](01-requirements-and-installation.md)**.

---

**Organisation:** [LiveUX auf GitHub](https://github.com/LiveUX) · [Repository](https://github.com/LiveUX/file-manager-bundle)  
**Lizenz:** MIT
