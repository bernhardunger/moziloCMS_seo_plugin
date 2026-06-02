# seo_urls – Integrationstests (PHPUnit)

HTTP-Tests gegen eine laufende moziloCMS-Instanz mit aktiviertem `seo_urls`-Plugin.

## Voraussetzung

- PHP mit aktivierter `curl`-Extension
- PHPUnit installiert (`vendor/bin/phpunit` im Projektverzeichnis)

## Einrichtung

1. `SeoUrlsIntegrationTest.php.example` nach `SeoUrlsIntegrationTest.php` kopieren:
   ```
   cp tests/integration/SeoUrlsIntegrationTest.php.example tests/integration/SeoUrlsIntegrationTest.php
   ```

2. `BASE_URL` in `SeoUrlsIntegrationTest.php` auf die eigene Testinstanz anpassen:
   ```php
   private const BASE_URL = 'http://deine-testinstanz.de';
   ```

3. Projektspezifische Slug-URLs in den einzelnen Testmethoden anpassen
   (z.B. `/dein-slug/` durch echte Kategorienamen ersetzen).

> **Hinweis:** `SeoUrlsIntegrationTest.php` ist in `.gitignore` eingetragen und wird nicht gepusht –
> jede Entwicklungsumgebung pflegt ihre eigene lokale Konfiguration.

## Tests ausführen

Nur Integrationstests (Testserver muss erreichbar sein):
```
php vendor/bin/phpunit --testsuite Integration
```

Oder direkt per Verzeichnis:
```
php vendor/bin/phpunit tests/integration/
```

Oder per Group-Filter:
```
php vendor/bin/phpunit --group integration
```

## Testfälle

| # | Beschreibung | Erwartetes Ergebnis |
|---|---|---|
| 1 | Slug-URL aufrufen | 200 OK, HTML-Response |
| 2 | Umlaut-URL → Slug | 301, `Location`-Header mit Slug-URL |
| 3 | `.html`-URL → Slug | 301, `Location`-Header mit Slug-URL |
| 4 | Canonical-Tag | 200, `<link rel="canonical" href="https://...slug/">` im Body |
| 5 | Meta-Description | 200, `<meta name="description" content="...">` im Body |
| 6 | Sitemap | 200, `Content-Type: application/xml`, `<loc>`-Tags mit Slug-URLs |
| 7 | Startseite | 200 OK, kein Redirect-Loop |
| 8 | Admin-Bereich | 200 OK, kein Slug-Redirect |
| 9 | Debug-Map | 200, Slug-Map-Ausgabe sichtbar |
| 10 | Unbekannter Slug | Kein Fatal Error (kein 500) |

### Hinweis zu Testfall 9 (Debug-Map)

Der Debug-Modus muss in der Plugin-Konfiguration aktiviert sein:
moziloCMS Admin → Plugins → seo_urls → Konfiguration → Debug-Modus aktivieren.
Vor dem Go-Live wieder deaktivieren.
