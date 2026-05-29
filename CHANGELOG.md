# Changelog – seo_urls Plugin

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

---

## [v1.3.3] – 2026-05-29

### Behoben

- **`slugify()` – Großakzente wurden nicht transliteriert (echter Bug)**
  `mb_strtolower()` wurde bisher *nach* `str_replace()` aufgerufen. Da die Map
  nur Kleinbuchstaben-Akzente enthielt (é, à, …), überlebten Großakzente wie
  É, À die Transliteration und wurden anschließend von `preg_replace()` ersatzlos
  weggeworfen. Beispiel: `"CAFÉ"` → `"caf"` statt `"cafe"`.
  Fix: `mb_strtolower()` läuft jetzt *vor* `str_replace()`. Die redundanten
  Großbuchstaben-Keys `Ä`, `Ö`, `Ü` wurden aus der Map entfernt.

- **`unserialize()` gehärtet**
  `applyMetaKeywordsDescription()` rief `@unserialize($raw)` ohne Options-Array
  auf. Nun: `@unserialize($raw, ['allowed_classes' => false])` – schließt
  PHP Object-Injection aus (Low Risk, da lokale Datei, aber kostenlose Härtung).

- **`rewriteOutput()` Lookahead explizit erweitert**
  Protokoll-relative URLs (`//cdn…`), `javascript:`- und `data:`-URIs wurden
  bisher nur indirekt durch `buildSlugUrl()` ignoriert (das `null` zurückgibt).
  Der Regex-Lookahead wurde um `\/\/`, `javascript:` und `data:` ergänzt –
  macht die Ausschluss-Absicht explizit statt implizit.

- **`PLUGIN_DIR`-Fallback Trailing-Slash abgesichert**
  Falls `PLUGIN_DIR` in einer künftigen moziloCMS-Version ohne Trailing-Slash
  definiert wird, war die Pfadkonkatenation fehlerhaft. Jetzt: `rtrim(PLUGIN_DIR, '/') . '/'`.
  In moziloCMS 3.0.x irrelevant (greift den `BASE_DIR`-Zweig), aber zukunftssicher.

### Tests
- 5 neue Tests: `testSlugifyGrossAkzente()`, `testSlugifyGrossUmlaute()`,
  `testRewriteOutputIgnoriertProtokollRelativeUrls()`,
  `testRewriteOutputIgnoriertJavascriptLinks()`,
  `testRewriteOutputIgnoriertDataUris()`
- Gesamtzahl: 62 Tests

---

## [v1.3.2] – 2026-05-29

### Neu
- **Sitemap-Regel-Prüfung**: `isHtaccessValid()` prüft jetzt zusätzlich, ob die
  Sitemap-Regel (`RewriteRule ^sitemap\.xml$ index.php [L,QSA]`) in der `.htaccess`
  vorhanden ist. Fehlt sie, deaktiviert sich das Plugin – die `?action=sitemap`-
  Umschreibung würde sonst ins Leere laufen.
- **Spezifische Fehlermeldungen**: `checkHtaccess()` zeigt im Admin-Info-Tab an,
  welcher Eintrag fehlt (Sitemap-Regel, Catch-All-Regeln oder beides), statt einer
  generischen Meldung.

### Behoben
- **Auskommentierte Regeln werden korrekt als inaktiv erkannt**: `parseHtaccessRules()`
  wertet `# RewriteRule ...` nicht mehr fälschlich als aktive Regel
  (negative Lookahead `^(?![ \t]*#)` mit `m`-Flag).

### Geändert
- **`$htaccessValid`-Cache entfernt**: Die `.htaccess` wird bei jedem Aufruf neu
  gelesen statt das Ergebnis in einer statischen Property zu cachen. Unter PHP-FPM/
  OPcache können statische Properties zwischen Requests im selben Worker erhalten
  bleiben – das Caching hätte dort eine korrigierte `.htaccess` erst nach Worker-
  Neustart erkannt. Die Datei-Lesung pro Request ist auf Shared Hosting vernachlässigbar.

### Refactoring
- **`parseHtaccessRules(string $content): array` extrahiert**: Die Regex-Muster für
  Sitemap- und Catch-All-Regeln sind jetzt an einer einzigen Stelle definiert und
  werden von `isHtaccessValid()` und `checkHtaccess()` gemeinsam genutzt (keine
  Redundanz mehr). Rückgabe: `['hasSitemap' => bool, 'hasCatchAll' => bool]`.

### Tests
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
- `isHtaccessValid()` neu: gecachte Prüfung der `.htaccess` (einmal pro Request).
  Designentscheidungen: `BASE_DIR` nicht definiert → laufen lassen;
  Datei nicht lesbar → laufen lassen; Datei fehlt oder Catch-All unvollständig → deaktivieren.
- `checkHtaccess()` neu: HTML-Statusanzeige (grün/rot) für den Admin-Info-Tab.
- `$htaccessValid` neu: statisches Cache-Property (null/true/false).
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
  Dadurch werden individuelle Meta-Angaben pro Seite korrekt ausgespielt, obwohl
  MetaKeywordsDescription alphabetisch vor `_seo_urls` geladen wird und die
  `$_GET`-Parameter zu diesem Zeitpunkt noch nicht gesetzt sind.
  Ist MetaKeywordsDescription nicht installiert, passiert nichts – vollständig
  rückwärtskompatibel.

### Hinweis
Das `MetaKeywordsDescription` Plugin kann nach dem Befüllen der Descriptions
**deaktiviert** werden – `_seo_urls` liest die `plugin.conf.php` direkt und
befüllt die Platzhalter selbst. Das Plugin muss nur zum **Pflegen der Inhalte**
aktiviert werden. Im deaktivierten Zustand entfällt der unnötige Durchlauf des
Plugins bei jedem Request.

### Technische Details
- `applyMetaKeywordsDescription()` neu: liest und deserialisiert `plugin.conf.php`,
  ermittelt bei Kategorie-Einstiegsseiten die erste Unterseite via `get_FirstPageOfCat()`,
  ersetzt `{WEBSITE_DESCRIPTION}` und `{WEBSITE_KEYWORDS}` direkt im `$template`.
- Neue Klassenkonstante `META_PLUGIN_NAME` für den Plugin-Namen (einzige Pflegestelle).
- `PLUGIN_DIR` Fallback auf `BASE_DIR . 'plugins/'` für moziloCMS 3.0.x Kompatibilität.
- `empty()` statt `isset()` für `$_GET['page']` – moziloCMS setzt `false` bei Kategorie-Einstiegsseiten.
- `get_FirstPageOfCat()` ohne vorherige `exists_CatPage()`-Prüfung – robuster und direkt.

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
