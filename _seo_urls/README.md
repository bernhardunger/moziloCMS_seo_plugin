# seo_urls – SEO-freundliche URLs für moziloCMS 3.0.x

Wandelt Kategorie- und Seitennamen in saubere, Google-freundliche URL-Slugs um.

## Was das Plugin macht

| Funktion | Beschreibung |
|---|---|
| **Umlaut-Ersetzung** | ä→ae, ö→oe, ü→ue, ß→ss (+ gängige Akzente) |
| **Leerzeichen** | Werden durch Bindestriche ersetzt |
| **Link-Rewriting** | Alle internen `href`-Links im HTML-Output werden umgeschrieben |
| **301-Redirect** | Alte Pfade mit Umlauten/Leerzeichen werden permanent weitergeleitet |
| **Kollisionsschutz** | Identische Slugs erhalten automatisch ein Suffix (`-2`, `-3` …) |
| **Sitemap-kompatibel** | `?action=sitemap` funktioniert unverändert, Links werden ebenfalls umgeschrieben |

**Beispiele:**

| Original-Pfad | Slug-URL |
|---|---|
| `/Über Uns/` | `/ueber-uns/` |
| `/Über Uns/Unser Team/` | `/ueber-uns/unser-team/` |
| `/Häufige Fragen/` | `/haeufige-fragen/` |

---

## Funktionsweise

moziloCMS 3 liefert bereits lesbare Pfad-URLs (`/Über Uns/Team/`), diese enthalten jedoch Umlaute und Leerzeichen, die von Google nicht korrekt indexiert werden. Das Plugin greift als `plugin_first` ein, **bevor** moziloCMS den Pfad selbst auswertet:

```
Browser: GET /ueber-uns/team/
        ↓
.htaccess: Slug-Muster erkannt → index.php (REQUEST_URI bleibt erhalten)
        ↓
plugin_first: "ueber-uns" ist Slug → auflösen →
  $_GET['cat'] = "Über Uns"
  $_GET['page'] = "Team"
        ↓
createGetCatPageFromModRewrite() wird übersprungen (cat bereits gesetzt)
        ↓
CMS rendert Seite normal
        ↓
ob_start-Callback: alle href="/Über Uns/..." → href="/ueber-uns/..."
```

Ruft jemand noch eine alte URL mit Umlauten auf (`/Über Uns/`), antwortet das Plugin mit einem **301-Redirect** auf die Slug-URL.

---

## Installation

### 1. Plugin-Zipdatei installieren im moziloCMS admin Dialog

### 2. .htaccess anpassen

Den Inhalt aus `htaccess_snippet.txt` entsprechend einfügen:
```
# Grundeinstellungen (müssen ganz oben stehen)
Options -Indexes
RewriteEngine On
# Oder auch das Verzeichnis je nach Server-Einstellungen
RewriteBase /
# HTTPS und WWW erzwingen 
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} !^www\. [NC]
# !Domain-Namen anpassen!
RewriteRule ^(.*)$ https://www.meine-domain.de/$1 [L,R=301]
# mozilo generated - not change from here to mozilo_end
RewriteRule ^(.*)/mod_rewrite_t_e_s_t\.html$ $1/index\.php?moderewrite=ok [L]
# .html-URLs an moziloCMS übergeben (Kompatibilität mit alten Links)
RewriteRule \.html$ index\.php [QSA,L]
# Sitemap-Anfragen an moziloCMS weiterleiten (wird vom CMS dynamisch erzeugt)
RewriteRule ^sitemap\.xml$ index.php [L,QSA]
# Alles was keine echte Datei oder kein echter Ordner ist, an moziloCMS übergeben
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
# Fallback: Alle übrigen Anfragen an moziloCMS übergeben (zentraler Einstiegspunkt)
RewriteRule ^(.*)$ index.php [QSA,L]
# mozilo_end
```

### 3. Plugin aktivieren

Im moziloCMS Admin-Panel → Plugins → `seo_urls` aktivieren.

**Wichtig:** Da moziloCMS plugin_first-Plugins alphabetisch nach Ordnernamen sortiert, ist der Plugin-Ordner bewusst als _seo_urls benannt — der Unterstrich stellt sicher, dass das Plugin vor allen anderen plugin_first-Plugins ausgeführt wird.
Den Debug-Modus in der Plugin-Konfiguration nur im Testbetrieb aktivieren und vor dem Go-Live wieder deaktivieren.

---
## Hinweise

### Sitemap (`?action=sitemap`)

Vollständig kompatibel. Der `?action=sitemap`-Aufruf wird vom Plugin nicht abgefangen. Die vom CMS gerenderten Sitemap-Links enthalten Original-Pfade, die der Output-Buffer am Ende automatisch in Slug-URLs umschreibt.

**Edge-Case:** Falls eine Kategorie den Namen `sitemap` hat, würde ein direkter Aufruf von `/sitemap/` als Slug-URL interpretiert (nicht als `?action=sitemap`). Der Menü-Link `href="/?action=sitemap"` funktioniert davon unabhängig weiterhin korrekt.

### URL_BASE

Falls moziloCMS nicht im Webroot, sondern in einem Unterverzeichnis läuft (z. B. `/mozilo/`), wird die `URL_BASE`-Konstante automatisch berücksichtigt. Slug-URLs werden dann als `/mozilo/ueber-uns/team/` ausgegeben.

### Titeländerungen im Admin

Wird ein Kategorie- oder Seitenname im Admin umbenannt, ändert sich der zugehörige Slug. Bestehende Bookmarks oder externe Links auf den alten Slug laufen dann ins Leere. In diesem Fall empfiehlt es sich, den alten Slug manuell als statische `Redirect`-Regel in der `.htaccess` einzutragen.

---

## Debugging

Slug-Mapping aller Kategorien und Seiten ausgeben:

```
https://deine-domain.de/?seo_debug=1
```

> Nur im Entwicklungsmodus verwenden. Den Parameter danach entfernen und in der Plugin-Konfiguration ausschalten
