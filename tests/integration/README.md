# seo_urls – Integrationstests (VS Code REST Client)

Manuelle HTTP-Tests gegen eine laufende moziloCMS-Instanz mit aktiviertem `seo_urls`-Plugin.

## Voraussetzung: VS Code Extension installieren

**REST Client** von Huachao Mao (`humao.rest-client`)

Installation über den VS Code Marketplace:
```
Erweiterungen (Strg+Shift+X) → "REST Client" suchen → Installieren
```

Oder direkt über die Kommandozeile:
```
code --install-extension humao.rest-client
```

## Einrichtung

1. `seo_urls.http.example` nach `seo_urls.http` kopieren:
   ```
   cp seo_urls.http.example seo_urls.http
   ```

2. `@baseUrl` in `seo_urls.http` auf die eigene Testinstanz anpassen:
   ```
   @baseUrl = http://deine-testinstanz.de
   ```

3. Testfälle mit projektspezifischen Slug-URLs anpassen
   (Kategorie- und Seitennamen der eigenen Installation eintragen).

> **Hinweis:** `seo_urls.http` ist in `.gitignore` eingetragen und wird nicht gepusht –
> jede Entwicklungsumgebung pflegt ihre eigene lokale Konfiguration.

## Tests ausführen

`.http`-Datei in VS Code öffnen → über jedem Request erscheint **"Send Request"** → anklicken →
Response öffnet sich in einem geteilten Fenster.

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
| 9 | Debug-Map | 200, text/plain, Slug-Map-Ausgabe sichtbar |
| 10 | Unbekannter Slug | Kein Fatal Error, sinnvolle Response |

### Hinweise zu Testfall 4 und 5

REST Client zeigt die vollständige Response an – `Canonical`-Tag und `Meta-Description`
müssen **manuell** im Response-Body gesucht werden (Strg+F im Response-Panel).

### Hinweis zu Testfall 9 (Debug-Map)

Der Debug-Modus muss in der Plugin-Konfiguration aktiviert sein
(moziloCMS Admin → Plugins → seo_urls → Konfiguration → Debug-Modus aktivieren).
Vor dem Go-Live wieder deaktivieren.

### Hinweis zu Redirects (Testfälle 2 und 3)

REST Client folgt Redirects standardmäßig **nicht** – der Status `301` und der
`Location`-Header sind direkt im Response-Panel sichtbar. Das ist gewünscht,
um den Redirect selbst zu verifizieren.
