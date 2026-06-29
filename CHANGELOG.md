# Changelog – seo_urls Plugin

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

---

## [1.3.7] - tbd

### Geändert
- Locale-Schema der Admin-Sprachdateien auf moziloCMS-Konvention umgestellt:
  `admin_language_de.txt` → `admin_language_deDE.txt`,
  `admin_language_en.txt` → `admin_language_enEN.txt`

### Behoben
- Admin-Info: `<h4>`-Überschriften durch `<b>` ersetzt (HTML-Validator-Warnung
  wegen fehlender Überschriften-Hierarchie im Admin-Kontext)

---

## [v1.3.6] – 2026-06-28

### Verbessert

- **F18: Admin-Info mehrsprachig (de/en)**
  `getInfo()` lieferte die Plugin-Info bisher hartkodiert auf Deutsch.
  Die Texte sind jetzt in Sprachdateien ausgelagert und erscheinen in der
  im moziloCMS-Admin eingestellten Sprache (Deutsch oder Englisch).
  Fallback auf Deutsch für alle übrigen Sprachen.
  - `private ?Language $admin_lang = null;` deklariert (PHP 8.2-clean,
    keine dynamische Property)
  - `resolvePluginLanguage(?string $code): string` – normalisiert auf
    Kurzcode: `deDE`/`deCH`/`deAT` → `de`; `enUS`/`enGB` → `en`;
    unbekannt/`null` → `de`
  - `sprachen/admin_language_de.txt` + `sprachen/admin_language_en.txt`
    (je 5 Keys, HTML-Werte einzeilig)
  - `$info[5] = []` (plugin_first-Rewrite-Plugin ohne einfügbaren Tag)
  - `$htaccessStatus` aus `checkHtaccess()` bleibt inline (dynamisch)

### Behoben

- **F17: CRLF-Stripping in `redirect()` griff nur im `header()`-Zweig**
  `str_replace(["\r", "\n"], '', $url)` wurde vor der Verzweigung in
  `header()`-Pfad und `$redirector`-Test-Seam angewendet. Bisher erhielt
  der `$redirector`-Seam den unbereinigten String – kein Produktionsrisiko
  (Seam ist nur in PHPUnit aktiv), aber konzeptionell falsch.

### Refactoring

- **TODO 1: `MetaKeywordsDescriptionAdapter` kapselt Fremdformat**
  Neue finale Klasse `MetaKeywordsDescriptionAdapter` (vor `_seo_urls`
  definiert) bündelt das gesamte Wissen über die `plugin.conf.php` des
  MetaKeywordsDescription-Plugins an einer Stelle:
  Pfadaufbau, `"php die();"`-Header-Skip, `unserialize`,
  Schlüsselformat `@=rawurlencode(cat):page=@`, Typ-Checks.
  `applyMetaKeywordsDescription()` delegiert nur noch an
  `MetaKeywordsDescriptionAdapter::lookup()`.
  Ändert MetaKeywordsDescription sein Speicherformat, ist nur
  diese Klasse anzupassen.
  Zusätzlich: `htmlspecialchars(ENT_QUOTES, 'UTF-8')` auf Description
  und Keywords (waren bisher roh ins Template geschrieben).

### Tests

- **F15: Security-Regressionstests**
  Drei neue Tests dokumentieren korrekte Abwehr bekannter Angriffsmuster:
  Path Traversal (nur Map-Lookup, kein Dateisystemzugriff),
  CRLF-Header-Injection in `redirect()`,
  XSS-Escaping in Meta-Description via `htmlspecialchars(ENT_QUOTES)`.

- **F16: Unicode-Edge-Cases für `slugify()`**
  Vier neue Tests sichern definiertes Verhalten bei exotischem Input ab:
  Emoji → via `iconv //IGNORE` entfernt, Slug bleibt valide;
  CJK-Zeichen (`日本語`) → Fallback `'seite'` (dokumentiertes Verhalten);
  malformed UTF-8 → kein Fatal Error;
  Name > 255 Zeichen → kein Fehler.

- **F18: `resolvePluginLanguage()`-Tests**
  11 neue Tests: exakte Locale-Codes, Varianten (`deCH`, `deAT`, `enGB`),
  Kurzcode direkt, Fallback für unbekannte Locales, `null`, Leerstring,
  Großschreibung (`DEDE` → `de`). `callInstance()`-Helper für private
  Instanzmethoden ergänzt.

- 112 Tests / 170 Assertions / 1 skipped – alle grün

---

## [v1.3.5] – 2026-06-01

### Behoben

- **F5: DocBlock `isHtaccessValid()` korrigiert**
  Die frühere Begründung für das Nicht-Cachen ("statische Properties bleiben unter
  PHP-FPM/OPcache zwischen Requests erhalten") war sachlich falsch: PHP verwirft den
  gesamten Klassenzustand am Requestende, auch unter FPM. Korrekter Kommentar:
  `isHtaccessValid()` liest die `.htaccess` bei jedem Request neu, damit eine
  Korrektur sofort wirkt. Kein funktionaler Code geändert.

- **F6: `rewriteOutput()` – Rückgabetyp und Null-Absicherung**
  Signatur von `rewriteOutput($html)` auf `rewriteOutput(string $html): string`
  erweitert. `preg_replace_callback()` kann `null` zurückgeben (Regex-Fehler);
  das würde `rewriteCanonical(string $html)` mit einem `TypeError` abbrechen.
  Fix: `return self::rewriteCanonical($html ?? '');`

- **F8: `@unserialize` durch `try-catch(\Throwable)` ersetzt**
  `@`-Operator in `applyMetaKeywordsDescription()` entfernt; `unserialize()` in
  `try-catch(\Throwable)` eingewickelt. `allowed_classes => false` bleibt.
  Hinweis: `unserialize()` emittiert E_WARNING auf korruptem Input (kein Exception);
  der Catch schützt vor zukünftigen PHP-Exceptions. E_WARNING erscheint weiterhin
  im PHP-Error-Log (gewünscht für Debugging auf Shared Hosting).

- **F4: POST-Zweig – Raw-Page-Name wird durchgereicht**
  In `resolveRawCatRequest()` fehlte im POST-Fall ein `else`-Zweig: War `$rawPage`
  nicht in der Slug-Map auflösbar, blieb `$_GET['page']` ungesetzt und der
  Seitenkontext ging verloren. Fix: `else { $_GET['page'] = $rawPage; }` analog
  zu `resolveSlugRequest()`.

- **F10: `is_string()`-Guard für `$template`-Zugriffe**
  `$template` ist eine globale CMS-Variable. Alle `str_replace()`-Aufrufe darauf
  sind jetzt mit `isset($template) && is_string($template)` abgesichert – verhindert
  PHP-Warnings falls der CMS-Kern die Variable nicht als String liefert.

### Verbessert

- **`rewriteCanonical()` – stripos()-Vorcheck, Limit 1 und htmlspecialchars()**
  Vor dem Regex-Lauf prüft `stripos()` ob überhaupt ein `canonical`-Tag im Output
  vorhanden ist – spart den Regex komplett wenn kein Tag da ist (z.B. auf Seiten
  ohne resolvedCanonicalPath). Limit 1 im `preg_replace_callback` macht explizit
  dass pro Seite genau ein Canonical-Tag erwartet wird. Die Canonical-URL wird
  zusätzlich mit `htmlspecialchars()` abgesichert (defensiv – URL ist intern gebaut,
  enthält aber theoretisch CMS-Daten). Innerer `preg_replace` entfernt: Tag wird
  komplett neu gebaut statt href-Attribut zu suchen/ersetzen. `static function`
  Closure analog zu ob_start-Callback (kein `$this`-Binding).

- **F9: Request-weites Caching für `isHtaccessValid()`**
  Neue Property `private static ?bool $htaccessValidCache = null;` speichert das
  Prüfergebnis für die Dauer des Requests. Hintergrund: moziloCMS ruft Plugin-Methoden
  mehrfach pro Seitenaufruf auf – ohne Cache würde die `.htaccess` bei jedem Aufruf
  neu gelesen. Der Cache lebt nur für einen Request: PHP verwirft static Properties
  am Requestende (klassische SAPIs: mod_php, CGI, FPM). Änderungen per FTP wirken
  damit sofort beim nächsten Seitenaufruf, nicht erst nach Server-Neustart.

### Refactoring

- **F7: Array-Syntax modernisiert (PHP 8.1)**
  Alle 15 `array()`-Literale auf Kurzschreibweise `[]` umgestellt. Die vier
  statischen Slug-Map-Properties erhalten explizite `array`-Typ-Deklaration:
  `private static array $catBySlug = []` etc. Rein syntaktisch, kein funktionaler
  Unterschied.

- **F2: `slugify()` – iconv-Transliteration statt statischer Zeichenmap**
  Das 24-Einträge-`static $map` ersetzt durch dreistufige Pipeline:
  (1) `mb_strtolower()` mit `strtolower()`-Fallback,
  (2) 4-Einträge `$germanMap` (ä/ö/ü/ß, läuft vor iconv),
  (3) `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', …)` für übrige Akzente.
  Auf Linux/IONOS (Produktion) liefert iconv `é→e` direkt. Auf Windows/Laragon
  (Entwicklung) gibt libiconv Akzente als Präfix-Zeichen aus (`é→'e`); ein
  `str_replace(["'","\`","^","~",'"'])` danach entfernt diese Artefakte (No-op
  auf Linux). Typ-Deklaration: `slugify(string $text): string`.

- **F11: `rewriteOutput()`/`rewriteCallback()` – Information Hiding**
  Beide Methoden waren `public static`, obwohl sie ausschließlich intern als
  ob_start-Callback genutzt werden. Umgestellt auf `private static`. Der
  ob_start-Aufruf nutzt jetzt eine `static function`-Closure statt des
  Array-Callbacks `['_seo_urls', 'rewriteOutput']` – verhindert `$this`-Binding
  und eliminiert das Lifetime-Risiko im CMS-Lifecycle. Interner Callable
  `['_seo_urls', 'rewriteCallback']` → `[self::class, 'rewriteCallback']`.

### Dokumentation

- **F3: EXT_PAGE/EXT_HIDDEN-Asymmetrie in `buildMaps()` erklärt**
  Inline-Kommentar: `EXT_PAGE` ist in moziloCMS 3.0.x immer definiert (Kernkonstante,
  Voraussetzung laut `getInfo()`). `EXT_HIDDEN` ist optional – daher der separate
  `defined()`-Check. Kein Code geändert.

### Tests

- 11 neue Tests: `testSlugifyIconv()`, `testApplyMetaKeywordsDescriptionKorrupteKonfiguration()`,
  `testApplyMetaKeywordsDescriptionValideKonfiguration()`,
  `testApplyMetaKeywordsDescriptionTemplateNullKeinFehler()`,
  `testIsHtaccessValidCacheBefuelltNachErstemAufruf()`,
  `testIsHtaccessValidCacheWirdDurchResetZurueckgesetzt()`,
  `testIsHtaccessValidLiestDateiNurEinmalProRequest()`,
  `testHandleRequestPostUnbekannteSeiteSetzGetPageFallback()`,
  `testHandleRequestPostUnbekannteCatKeinGetGesetzt()`
- `resetStaticState()` um `htaccessValidCache = null` ergänzt
- Alle `_seo_urls::rewriteOutput()`-Testaufrufe auf `callStatic()` migriert (Visibility-Änderung F11)
- 84 Tests grün, 1 skipped (unverändert)

---

## [v1.3.4] – 2026-05-30

### Refactoring

- **`splitPath(string $uri): array` eingeführt**
  Dasselbe Muster — URL_BASE abschneiden → `.html` entfernen → `rtrim('/')` →
  `explode`/`array_filter` zu `$parts` — stand dreifach nahezu identisch im Code:
  in `handleRequest()`, im `rewriteSitemap()`-Callback und in `rewritePath()`.
  Der neue private Helfer kapselt genau diese Logik und gibt `[?catPart, ?pagePart]`
  zurück (`null` wenn der Pfad leer ist).
  Bewusst minimal gehalten: kein `urldecode` (Aufrufer entscheiden selbst),
  kein SYSTEM_PATHS-Check, kein Sitemap-Check.

- **`dumpDebugMap()` aus `getPluginContent()` herausgezogen**
  Der `seo_debug`-Block (Präsentationslogik) war direkt in der Lifecycle-Methode.
  Jetzt: `private static function dumpDebugMap(): void` — `getPluginContent()`
  ruft nur noch `self::dumpDebugMap()` auf.

### UX & Dokumentation

- **`checkHtaccess()`: Statusmeldung präzisiert**
  `"PLUGIN DEAKTIVIERT"` ersetzt durch `"SEO-URLs inaktiv"`. Neuer Hinweis:
  Das Plugin bleibt aktiviert und nimmt den Betrieb automatisch wieder auf,
  sobald die `.htaccess` korrekt konfiguriert ist — kein erneutes Aktivieren
  im Admin nötig.

- **`getInfo()`: Fußnote zur Versionsangabe `"2.0 / 3.0"`**
  Erklärt im Admin-Info-Tab, warum der Versionsstring die Ziffer `"2"` enthalten
  muss: `admin/plugins.php` prüft via `strpos()` auf `"2"` — fehlt sie, deaktiviert
  moziloCMS das Plugin automatisch. Das Plugin unterstützt ausschließlich
  moziloCMS 3.0.x.

- **`getInfo()`: Installationshinweis aktualisiert**
  Bullet über unvollständige `.htaccess` spricht jetzt von "SEO-URLs inaktiv" und
  Auto-Reaktivierung statt "deaktiviert sich das Plugin automatisch".

- **README.md**: ❌-Bullet an neue Terminologie angepasst.

### Bereinigung

- **Datei-Header verschlankt**: Nur noch `@version`, `@license`, einzeiliger
  Beschreibungstext und Verweis auf `CHANGELOG.md`. Kein inline-Changelog mehr.

### Tests
- 7 neue Tests für `splitPath()` und `dumpDebugMap()`:
  `testSplitPathKatUndSeite`, `testSplitPathNurKategorie`,
  `testSplitPathMitHtmlSuffix`, `testSplitPathLeererPfad`,
  `testSplitPathKodiertZeichenWerdenNichtDekodiert`,
  `testGetPluginContentGibtLeerZurueckFuerNichtPluginFirst`
  + 1 skipped: `testSplitPathUrlBaseWirdAbgeschnitten`
  (`URL_BASE` ist im Test-Harness als Konstante `'/'` definiert und kann nicht
  neu gesetzt werden – der Branch `URL_BASE !== '/'` ist im Test-Kontext nie aktiv)
- alle Tests grün

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
- alle Tests grün

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
- alle Tests grün

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
- Keine neuen Tests (getInfo() und checkHtaccess() sind Admin-Methoden), alle Tests grün

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
- alle Tests weiterhin grün
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
- alle Tests grün

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
- Vollständige Abdeckung aller Hilfsmethoden: `slugify()`, `isSlug()`,
  `stripHtmlSuffix()`, `makeUnique()`, `getSafeOrigin()`, `buildSlugUrl()`,
  `rewriteOutput()` (Links, Sitemap, Canonical),
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
