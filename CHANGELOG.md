# Changelog – seo_urls Plugin

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

---

## [v1.3.2] – 2026-05-29

### Neu
- **Erweiterte .htaccess-Prüfung**: Neben den Catch-All-Regeln wird jetzt auch die
  Sitemap-Regel geprüft (`RewriteRule ^sitemap\.xml$ index.php [L,QSA]`).
  Fehlt eine der erforderlichen Regeln oder ist sie auskommentiert, deaktiviert
  sich das Plugin automatisch.
- **Kommentar-Erkennung**: Auskommentierte Regeln (`# RewriteRule ...`) werden
  korrekt als inaktiv erkannt – sie galten vorher fälschlicherweise als vorhanden.
- **Snippet-Ausgabe im Fehlerfall**: Die Fehlermeldung im Admin-Info-Tab zeigt
  jetzt direkt die erforderlichen .htaccess-Regeln an – kein separates Nachschlagen
  in README oder htaccess_snippet.txt nötig.
- **Installationshinweis**: Regeln immer vollständig eintragen oder vollständig
  entfernen – eine teilweise Konfiguration kann den Admin-Bereich unzugänglich
  machen (Apache verarbeitet .htaccess vor PHP).

### Refactoring
- **`parseHtaccessRules(string $content): array`** neu: zentrale Methode für alle
  Regex-Prüfungen, wird von `isHtaccessValid()` und `checkHtaccess()` gemeinsam
  genutzt – keine Regex-Redundanz mehr.
  Die drei Catch-All-Zeilen werden einzeln geprüft, sodass auch teilweise
  Auskommentierung erkannt wird.
- **`$htaccessValid` Cache entfernt**: Kein Caching mehr um Probleme mit PHP-FPM/
  OPcache zu vermeiden, wo statische Properties zwischen Requests im selben Worker
  erhalten bleiben können.

### Technische Details
- Regex `^(?![ \t]*#)` mit `m`-Flag: negativer Lookahead ignoriert auskommentierte Zeilen
- Sitemap-Regex `\\\.`: `\\` matcht echten Backslash, `\.` matcht Punkt im Zieltext

### Tests
- 8 neue Tests für `parseHtaccessRules()`: valide Konfiguration, fehlende Regeln,
  auskommentierte Regeln, teilweise Auskommentierung
- 66 Tests, alle grün

---

## [v1.3.1] – 2026-05-26

### Neu
- **Automatische .htaccess-Prüfung**: Das Plugin prüft beim Start ob die
  erforderlichen Catch-All-Regeln in der `.htaccess` vorhanden sind.
  Fehlen sie oder sind sie unvollständig, deaktiviert sich das Plugin automatisch
  um den Admin-Bereich vor unzugänglichen Zuständen zu schützen.
  Im Admin-Bereich (Plugin-Info-Tab) wird der Status direkt angezeigt:
  grün = korrekt konfiguriert, rot = PLUGIN DEAKTIVIERT.
- **Verbesserte Installationshinweise in `getInfo()`**: Voraussetzungen klar
  dokumentiert (moziloCMS 3.0.x, PHP 8.1+), deutlicher Hinweis dass kein
  `template.html`-Eintrag nötig ist, `.htaccess`-Anforderungen direkt im Admin sichtbar.

### Technische Details
- `isHtaccessValid()` neu: Prüfung der `.htaccess`, kein Caching.
  Designentscheidungen: `BASE_DIR` nicht definiert → laufen lassen;
  Datei nicht lesbar → laufen lassen; Datei fehlt oder Catch-All unvollständig → deaktivieren.
- `checkHtaccess()` neu: HTML-Statusanzeige (grün/rot) für den Admin-Info-Tab.
- Versionsstring `'2.0 / 3.0'` beibehalten – moziloCMS Admin prüft ob `'2'`
  im String enthalten ist; fehlt die `'2'`, deaktiviert der Admin das Plugin
  automatisch (Bug in `admin/plugins.php` – keine Prüfung auf `'3'`).

### Tests
- 58 Tests, alle grün
- Keine neuen Tests (getInfo() und checkHtaccess() sind Admin-Methoden)

---

## [v1.3.0] – 2026-04-30

### Neu
- **MetaKeywordsDescription-Kompatibilität**: Liest die `plugin.conf.php` des
  MetaKeywordsDescription Plugins (falls installiert) und setzt `{WEBSITE_DESCRIPTION}`
  und `{WEBSITE_KEYWORDS}` zum richtigen Zeitpunkt im Template – nach `handleRequest()`,
  wenn `$_GET['cat']` und `$_GET['page']` bereits korrekt gesetzt sind.
  Ist MetaKeywordsDescription nicht installiert, passiert nichts – vollständig
  rückwärtskompatibel.

### Hinweis
Das `MetaKeywordsDescription` Plugin kann nach dem Befüllen der Descriptions
**deaktiviert** werden – `_seo_urls` liest die `plugin.conf.php` direkt und
befüllt die Platzhalter selbst. Das Plugin muss nur zum **Pflegen der Inhalte**
aktiviert werden.

### Technische Details
- `applyMetaKeywordsDescription()` neu: liest und deserialisiert `plugin.conf.php`,
  ermittelt bei Kategorie-Einstiegsseiten die erste Unterseite via `get_FirstPageOfCat()`,
  ersetzt `{WEBSITE_DESCRIPTION}` und `{WEBSITE_KEYWORDS}` direkt im `$template`.
- Neue Klassenkonstante `META_PLUGIN_NAME` für den Plugin-Namen (einzige Pflegestelle).
- `PLUGIN_DIR` Fallback auf `BASE_DIR . 'plugins/'` für moziloCMS 3.0.x Kompatibilität.
- `empty()` statt `isset()` für `$_GET['page']` – moziloCMS setzt `false` bei Kategorie-Einstiegsseiten.

### Tests
- Bestehende 57 Tests weiterhin grün
- Manuell getestet mit moziloCMS 3.0.x und MetaKeywordsDescription Plugin

---

## [v1.2.2] – 2026-04-28

### Behoben
- **Konflikt mit moziloCMS Query-Parametern**: URLs der Form
  `/Kategorie/Seite%20Name.html?cat=Kategorie&page=114&action=114`
  wurden fälschlicherweise vom Plugin umgeleitet. Dabei ging der komplette
  Query-String verloren und moziloCMS konnte die Zielseite nicht mehr finden.
  Betroffen waren vor allem versteckte Seiten die über interne moziloCMS-Parameter
  direkt angesteuert werden.
  `handleRequest()` prüft jetzt zu Beginn ob `cat`/`page` bereits im
  `$_SERVER['QUERY_STRING']` stehen und greift in diesem Fall nicht ein.
  Hinweis: die Prüfung erfolgt auf `QUERY_STRING` (nicht `$_GET`), da moziloCMS
  `$_GET['cat']` intern selbst setzt – eine `$_GET`-Prüfung würde reguläre
  Seitenaufrufe fälschlicherweise blockieren.

### Tests
- 2 neue Tests: `testHandleRequestGetMoziloCmsQueryParamsWerdenIgnoriert()`
  und `testHandleRequestGetSlugOhneMoziloCmsQueryParamsWirdVerarbeitet()`
- 58 Tests, alle grün

---

## [v1.2.1] – 2026-04-19

### Refactoring
- **`redirect(string $url, int $code = 301): void`** extrahiert
  Zentralisiert alle `header() + exit`-Aufrufe aus `handleRequest()`.
  Ermöglicht 301-Redirect-Tests via `$redirector`-Callback ohne `exit`.
- **`getSafeOrigin(): string`** extrahiert
  HTTP_HOST-Validierung war in `rewriteSitemap()` und `rewriteOutput()` dupliziert.
  Gibt validierten Origin (`https://www.example.com`) oder Leerstring zurück.
- **`rewriteCanonical(string $html): string`** isoliert
  Canonical-Tag-Korrektur aus `rewriteOutput()` herausgelöst –
  klare Einzelverantwortung, leichter testbar.
- **`handleRequest()` aufgeteilt** in `resolveSlugRequest()` und `resolveRawCatRequest()`
  - `resolveSlugRequest()`: verarbeitet bereits korrekte Slug-URLs
  - `resolveRawCatRequest()`: verarbeitet Umlaut/Leerzeichen-URLs und POST-Requests

### Tests
- PHPUnit 12 als dev-dependency eingerichtet (nur lokal, nie deployed)
- 56 Tests, 80 Assertions – vollständige Abdeckung aller Hilfsmethoden:
  `slugify()`, `isSlug()`, `stripHtmlSuffix()`, `makeUnique()`, `getSafeOrigin()`,
  `buildSlugUrl()`, `rewriteOutput()` (Links, Sitemap, Canonical),
  `handleRequest()` (GET, GET-Redirect 301, POST, Draft-Modus)

---

## [v1.2.0] – 2025

### Behoben
- **Homepage-Redirect**: Die erste CMS-Kategorie (z.B. „Startseite") wird jetzt
  generisch erkannt und löst einen direkten 301-Redirect auf `/` aus —
  unabhängig vom tatsächlichen Kategorienamen.
- **Canonical-Tag-Korrektur**: moziloCMS setzt bei Kategorie-Einstiegsseiten
  als `<link rel="canonical">` die erste Unterseite. `rewriteOutput()` überschreibt
  diesen Tag jetzt mit der korrekten Slug-URL der tatsächlich aufgerufenen Seite.

### Neu
- Static property `$homeCatName` und `$resolvedCanonicalPath`.

---

## [v1.1.2] – 2025

### Behoben
- HTTP_HOST-Validierung vor Verwendung in der Sitemap-XML (verhindert Header-Injection).
- `isSlug()` verbietet jetzt auch trailing Bindestriche.
- `buildSlugUrl()` erzeugt keine doppelten Trailing-Slash-Redirects mehr.

### Verbessert
- `SYSTEM_PATHS` als Klassenkonstante, `slugify()`-Map als `static` Variable.
- `makeUnique()` mit O(1) Hash-Lookup, `rewriteOutput()` in einem Regex-Durchlauf.
- `stripHtmlSuffix()`-Hilfsmethode eingeführt (DRY).

---

## [v1.1.1] – 2025

### Behoben
- 301-Redirect für `.html`-Suffix-URLs verhindert Duplicate Content.

### Neu
- Versionsstring als Klassenkonstante `VERSION`.

---

## [v1.0.1] – 2025

### Behoben
- POST-Requests werden nicht mehr weitergeleitet (Formulardaten gingen verloren).
- Draft-Modus (`?draft=true`) wird korrekt durchgereicht.
- i18n-Query-Parameter bleiben bei Slug-URLs erhalten.
- Sitemap-Hostname wird korrekt aus dem aktuellen Request bezogen.

---

## [v1.0.0] – 2025

### Erstveröffentlichung
- Slug-Generierung für Kategorien und Seiten (Umlaute, Leerzeichen, Sonderzeichen).
- Output-Buffer-Rewriting aller internen `href`- und `action`-Attribute.
- 301-Redirects für Umlaut-/Leerzeichen-Pfade.
- Kollisionsschutz bei identischen Slugs (`-2`, `-3` …).
- Sitemap-Kompatibilität (`?action=sitemap`).
- Debug-Modus (`?seo_debug=1`).
