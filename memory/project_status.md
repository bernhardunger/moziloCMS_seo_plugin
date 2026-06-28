# seo_urls – Projekt-Status

## Aktueller Stand
- **Plugin-Version**: v1.3.5 (const VERSION in index.php)
- **Ziel-Release**: v1.3.6
- **Aktiver Branch**: feature/finding-18-admin-i18n
- **Tests**: 96 Tests / 141 Assertions / 1 skipped – alle grün
  *(1 skipped: testSplitPathUrlBaseWirdAbgeschnitten – URL_BASE als Konstante nicht neu setzbar)*

## Offene Branches für v1.3.6
| Branch | Inhalt | Status |
|---|---|---|
| `tests/security-and-unicode` | F15 Security-Tests, F16 Unicode-Edge-Cases, F17 CRLF-Fix | ERLEDIGT, PR offen |
| `refactor/meta-adapter` | TODO 1 MetaKeywordsDescriptionAdapter | ERLEDIGT, PR offen |
| `feature/finding-18-admin-i18n` | F18 Admin-Info i18n (Sprachdateien de/en) | ERLEDIGT, PR offen |

Finding 13 (dynamischer Versionsstring) wartet auf moziloCMS 3.0.5 offiziell –
eigener Branch fix/cms-version-string, noch nicht begonnen.

## Neue Dateien / Strukturänderungen seit v1.3.5
- `_seo_urls/sprachen/admin_language_de.txt` – 5 Keys, HTML verbatim aus altem getInfo()
- `_seo_urls/sprachen/admin_language_en.txt` – 5 Keys, englische Übersetzung
- `tests/SeoUrlsTest.php`: callInstance()-Helper für private Instanzmethoden (ergänzt)

## Nächste Schritte
1. PRs für alle drei v1.3.6-Branches auf GitHub mergen
2. CHANGELOG.md für v1.3.6 schreiben (F15/F16/F17 + TODO 1 + F18)
3. Release-Commit: VERSION auf 'v1.3.6' setzen + Tag

## Kritische Constraints (Reminder)
- PLUGIN_DIR ist in moziloCMS 3.0.x NICHT definiert → immer $this->PLUGIN_SELF_DIR
  oder BASE_DIR.'plugins/_seo_urls/'
- $info[1] in getInfo() MUSS '2' enthalten (z.B. '2.0 / 3.0') –
  sonst deaktiviert moziloCMS Admin das Plugin automatisch (strpos-Check)
- $_GET['page'] ist false (nicht '') auf Kategorie-Einstiegsseiten → empty() statt isset()
- tests/ niemals auf den Webserver kopieren (export-ignore in .gitattributes)
- Static Properties: bewusst so – erzwungen durch ob_start()-Callback-Mechanismus
