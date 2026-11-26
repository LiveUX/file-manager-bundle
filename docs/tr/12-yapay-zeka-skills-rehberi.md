# 12 — Yapay Zeka Agent Skills Rehberi (`SKILL.md` & `AGENTS.md`)

[← Önceki: 11 — Katkı & PR Rehberi](11-katki-ve-pr-rehberi.md) | [Ana İndeks](README.md) | [Sonraki: 13 — Doctrine Entegrasyonu →](13-doctrine-entegrasyonu.md)

> **En Basit Haliyle: Agent Skills (`SKILL.md`) Ne İşe Yarar?**  
> Projenizde **Antigravity, Cursor, Claude Code, GitHub Copilot veya Codex** gibi bir yapay zeka kod asistanı kullanıyorsanız, `php bin/console file-manager:skills:install` komutunu bir kez çalıştırmanız yeterlidir. Bu komut paketin tüm doğru YAML anahtarlarını, güvenlik kurallarını ve örneklerini yapay zekaya öğretir — böylece asistanınız *"yanlış config anahtarı uydurmadan"* ilk seferde çalışan kod yazar.

**LiveUX FileManagerBundle**, modern yapay zeka kodlama asistanlarının (**Antigravity, Claude Code, Cursor, GitHub Copilot, OpenAI Codex, Windsurf** ve **Symfony AI Mate**) paketi ilk seferde doğru, güvenli ve sıfır halüsinasyonla entegre edebilmesi için açık **Agent Skills (`SKILL.md`)** standardında yerleşik beceri paketiyle birlikte gelir.

---

## 1. Pakette Yer Alan 6 Uzman Skill

Skill'ler **kademeli yükleme (progressive disclosure)** prensibine göre bölünmüştür; yapay zeka yalnızca o an çalıştığınız konuda ilgili skill'i okur, bağlam penceresini gereksiz doldurmaz:

| Skill Adı (`skills/<ad>/`) | Hangi Durumda Devreye Girer? | Referans Dosyası |
| :--- | :--- | :--- |
| `liveux-file-manager-setup` | Kurulum, route, AssetMapper/Encore, profil & volume (Local/S3/R2/MinIO/Supabase), kota (`storage_quota`, `show_quota`), görsel optimizasyonu, duplicate tespiti | `references/config-schema.md` |
| `liveux-file-manager-forms-and-editors` | `FileManagerPickerType` (tekli/galeri), 5 hazır varyant, özel FormType Twig şablonu (`liveux:file-manager:picker-changed`), EasyAdmin `FileManagerField`, JS API, TinyMCE, CKEditor 5, Tiptap | `references/picker-and-js-api.md` |
| `liveux-file-manager-theming-and-icons` | 9 hazır tema, Dark Mode (`color_scheme`), CSS-only veya 10 Twig bloklu özel tema, 3 katmanlı SVG ikon sistemi (49 UI + 14 klasör rozeti) | `references/theme-tokens-and-blocks.md` |
| `liveux-file-manager-security-and-rbac` | `security.yaml` firewall, 17 Voter yetkisi, klasör bazlı özel Voter (`unanimous`), Kullanım Koruması (`AssetUsageCheckerInterface` — delete/rename/move/overwrite), imzalı paylaşım linkleri, Audit Log, Apache/Nginx | `references/permissions-and-security-checklist.md` |
| `liveux-file-manager-extensions-and-ai` | Dinamik DB `ConfigProviderInterface`, özel `FolderMetadataStorageInterface`, 9 Symfony Event dinleyici, Symfony AI (`AssetEnricherInterface`) ve tüm CLI komutları | `references/contracts-and-events.md` |
| `liveux-file-manager-contributing` | Bundle'ın kendi kaynak kodunu geliştirirken uyulması gereken PHP 8.3/SF 7.2+8.0 kuralları, güvenlik değişmezleri, çeviri eşitliği ve `composer qa` PR kapısı | `AGENTS.md` (paket kökü) |

---

## 2. Projenize Skill'leri Kurmanın 3 Yolu

### Yöntem A — Yerleşik Symfony Konsol Komutu (Önerilen, Sıfır Ek Bağımlılık)

```bash
# Antigravity, Codex, OpenCode ve Windsurf için (.agents/skills/)
php bin/console file-manager:skills:install

# Claude Code için (.claude/skills/)
php bin/console file-manager:skills:install -t claude

# Cursor için (.cursor/skills/)
php bin/console file-manager:skills:install -t cursor

# GitHub Copilot için (.github/skills/)
php bin/console file-manager:skills:install -t copilot

# Tüm desteklenen AI araçlarına tek komutla kurulum
php bin/console file-manager:skills:install --target=all
```

### Tüm Komut Seçenekleri

| Seçenek / Kısa Ad | Ne İşe Yarar? | Örnek |
| :--- | :--- | :--- |
| `-t` / `--target` | Hedef AI aracı (`agents`, `claude`, `cursor`, `copilot`, `all`). Varsayılan: `agents`. | `-t all` |
| `--dir` | Özel hedef klasör yolu. **Güvenlik kuralı:** Kesinlikle `%kernel.project_dir%` içinde bir alt klasör olmalıdır (`..`, NUL, proje kökünün kendisi ve symlink üst dizinler reddedilir). | `--dir=.custom/skills` |
| `--only` | Yalnızca seçilen skill'leri kurar (virgülle ayrılmış kısa veya tam adlar). | `--only=setup,security` |
| `-f` / `--force` | Mevcut skill klasörlerini güncel sürümle üzerine yazar. | `--force` |
| `--symlink` | Kopyalamak yerine sembolik bağ oluşturur (Windows'ta yetki yoksa otomatik kopyalamaya düşer). | `--symlink` |
| `--dry-run` | Diskte değişiklik yapmadan hangi skill'lerin nereye kurulacağını önizler. | `--dry-run` |

### Yöntem B — Symfony AI Mate (`symfony/ai-mate`) ile Otomatik Keşif

Bundle'ın `composer.json` dosyası `extra.ai-mate.skills` tanımını içerir:

```json
{
    "extra": {
        "ai-mate": {
            "skills": ["skills"]
        }
    }
}
```

Projenizde `symfony/ai-mate` kuruluysa şu komut tüm 6 skill'i otomatik algılayıp çalışma dizininize senkronize eder:
```bash
vendor/bin/mate discover
```

### Yöntem C — Açık Agent Skills CLI (`npx skills`)

GitHub reposu üzerinden herhangi bir projeye kurmak için:
```bash
npx skills add LiveUX/file-manager-bundle
```

---

## 3. Paket Kökündeki `AGENTS.md`

Bundle kök dizininde yer alan `AGENTS.md`, doğrudan paket üzerinde geliştirme yapan (katkıcı / maintainer) yapay zeka ajanları için otomatik aktif kurallar içerir:
- Sıfır CSS framework bağımlılığı ve opsiyonel köprü kuralları
- PHP `strict_types`, Symfony 7.2 / 8.0 uyumlu test stub'ları
- Twig'de `|raw` yasağı, JS'te `isSafeUrl()` / `escapeHtml()` zorunluluğu
- CSRF-önce doğrulama, özel/Voter cache izolasyonu ve `%100` 12 dilli (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN` — `493` anahtar) çeviri + `%placeholder%` eşitliği

---

## 4. CI Kalite Doğrulaması

`SkillsPackageIntegrityTest` her PR ve CI çalışmasında şunları otomatik doğrular:
1. `AGENTS.md` mevcut, **`<= 24.000 byte`** bağlam bütçesi altında ve yanlışlıkla YAML frontmatter taşımıyor.
2. `composer.json` içinde `extra.ai-mate.skills` tanımı doğru.
3. `skills/` altındaki her klasörde geçerli YAML frontmatter içeren `SKILL.md` bulunur; `name` alanı klasör adıyla birebir eşleşir, `license: MIT` tanımlıdır, `description` en az `40` karakterdir ve her dosya `<= 500` satır kuralına uyar.
4. `SKILL.md` içindeki tüm göreceli `references/*.md` bağlantıları diskte gerçek dosyalara işaret eder.

---

[← Önceki: 11 — Katkı & PR Rehberi](11-katki-ve-pr-rehberi.md) | [Ana İndeks](README.md) | [Sonraki: 13 — Doctrine Entegrasyonu →](13-doctrine-entegrasyonu.md)
