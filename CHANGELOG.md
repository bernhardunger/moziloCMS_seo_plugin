# Changelog – seo_urls Plugin

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

---

## [v1.2.2] – 2026-04-28

### Behoben
- **Konflikt mit moziloCMS Query-Parametern**: URLs der Form
  `/Kategorie/Seite%20Name.html?cat=Kategorie&page=114&action=114`
  wurden fälschlicherweise vom Plugin umgeleitet. Dabei ging der komplette
  Query-String verloren und moziloCMS konnte die Zielseite nicht mehr finden.
  Betroffen waren vor allem versteckte Seiten die über interne moziloCMS-Parameter
  direkt angesteuert werden.
  `handleRequest()` prüft jetzt zu Beginn ob `$_GET['cat']` oder `$_GET['page']`
  bereits gesetzt sind und greift in diesem Fall nicht ein.

### Tests
- 1 neuer Test: `testHandleRequestGetMoziloCmsQueryParamsWerdenIgnoriert()`
- 57 Tests, alle grün

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
  Betroffen waren `/startseite/` und `/Startseite.html`, die vorher eine
  zweistufige Redirect-Kette erzeugten und als Duplicate Content crawlbar blieben.
- **Canonical-Tag-Korrektur**: moziloCMS setzt bei Kategorie-Einstiegsseiten
  (Aufruf ohne explizite Unterseite, z.B. `/kontakt/`) als `<link rel="canonical">`
  die erste Unterseite der Kategorie. `rewriteOutput()` überschreibt diesen Tag
  jetzt mit der korrekten Slug-URL der tatsächlich aufgerufenen Seite.

### Neu
- Static property `$homeCatName`: speichert den URL-kodierten Namen der ersten
  CMS-Kategorie als Homepage-Referenz (wird in `buildMaps()` befüllt).
- Static property `$resolvedCanonicalPath`: speichert den kanonischen Slug-Pfad
  der aktuellen Anfrage (wird in `handleRequest()` gesetzt, in `rewriteOutput()` genutzt).

---

## [v1.1.2] – 2025

### Behoben
- HTTP_HOST wird vor Verwendung in der Sitemap-XML validiert und bereinigt
  (verhindert Header-Injection).
- `isSlug()` verbietet jetzt auch trailing Bindestriche.
- `buildSlugUrl()` erzeugt keine doppelten Trailing-Slash-Redirects mehr.

### Verbessert
- `SYSTEM_PATHS` als Klassenkonstante (kein Array-Rebuild per Request).
- `slugify()`-Map als `static` Variable (kein Array-Rebuild pro Aufruf).
- `makeUnique()` nutzt `isset()`-Hash-Lookup O(1) statt `in_array()` O(n).
- `rewriteOutput()` verarbeitet `href` und `action` in einem einzigen Regex-Durchlauf.
- `stripHtmlSuffix()`-Hilfsmethode eingeführt (DRY – 3-fache Duplizierung entfernt).
- `REQUEST_METHOD` mit sicherem Default-Wert abgesichert.

---

## [v1.1.1] – 2025

### Behoben
- 301-Redirect für `.html`-Suffix-URLs (z.B. `/ueber-uns.html` → `/ueber-uns/`)
  verhindert Duplicate Content zwischen beiden URL-Varianten.

### Neu
- Versionsstring als Klassenkonstante `VERSION` (einzige Pflegestelle).

---

## [v1.0.1] – 2025

### Behoben
- POST-Requests werden nicht mehr weitergeleitet (Formulardaten gingen verloren).
- Draft-Modus (`?draft=true`) wird korrekt durchgereicht.
- i18n-Query-Parameter (`?i18n=en`) bleiben bei Slug-URLs erhalten.
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
