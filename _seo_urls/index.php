<?php
if(!defined('IS_CMS')) die();

/**
 * Plugin:   seo_urls
 * @author:  B.Unger
 * @version: v1.1.1
 * @license: GPL
 *
 * Wandelt Kategorie- und Seitennamen in SEO-freundliche URL-Slugs um.
 * Läuft als plugin_first — vor createGetCatPageFromModRewrite().
 *
 * Siehe htaccess_snippet.txt und README.md für die Installationsanleitung.
 *
 * Änderungen gegenüber v1.0.2:
 *  - Sicherheit:   HTTP_HOST wird vor Verwendung in XML validiert/bereinigt
 *  - Sicherheit:   isSlug() verbietet jetzt auch trailing-Bindestriche
 *  - Performance:  SYSTEM_PATHS als Klassenkonstante (kein Array-Rebuild per Request)
 *  - Performance:  slugify()-Map als static Variable (kein Array-Rebuild per Aufruf)
 *  - Performance:  makeUnique() nutzt Hash-Lookup O(1) statt in_array() O(n)
 *  - Performance:  rewriteOutput() in einem einzigen Regex-Durchlauf statt zwei
 *  - Architektur:  stripHtmlSuffix()-Hilfsmethode (DRY – 3-fache Duplizierung entfernt)
 *  - Architektur:  Versionsstring konsistent, REQUEST_METHOD mit sicherem Default
 */

class _seo_urls extends Plugin {

    // -----------------------------------------------------------------------
    // Slug-Maps
    // -----------------------------------------------------------------------

    private static $catBySlug  = array();  // catSlug  → originalCatName
    private static $catToSlug  = array();  // originalCatName → catSlug
    private static $pageBySlug = array();  // catSlug → [ pageSlug → originalPageName ]
    private static $pageToSlug = array();  // originalCatName → [ originalPageName → pageSlug ]
    private static $mapsBuilt  = false;

    /**
     * Pfade die nie von diesem Plugin angefasst werden dürfen.
     * Als Konstante definiert → wird einmalig kompiliert, nicht bei jedem Request neu gebaut.
     */
    const SYSTEM_PATHS = array(
        'admin', 'cms', 'plugins', 'templates', 'layouts',
        'galerien', 'kategorien', 'data', 'files'
    );

    // -----------------------------------------------------------------------
    // Pflichtmethoden moziloCMS Plugin-API
    // -----------------------------------------------------------------------

    function getContent($value) {
        // Wird aus Template-Platzhaltern aufgerufen: {PLUGIN(seo_urls|...)}
        // Für plugin_first ist getPluginContent() zuständig (siehe unten)
        return '';
    }

    function getPluginContent($value) {
        // Wird von moziloCMS für plugin_first-Plugins aufgerufen
        if ($value === 'plugin_first') {
            self::buildMaps();

            // Debug-Ausgabe: /?seo_debug=1  (nur wenn im Admin aktiviert!)
            if (!empty($_GET['seo_debug']) && $this->settings->get('debug_enabled') === 'true') {
                header('Content-Type: text/plain; charset=utf-8');
                echo "=== seo_urls Plugin v1.1.1 – Slug-Map ===\n\n";
                foreach (self::$catBySlug as $catSlug => $catRaw) {
                    $catDecoded = urldecode($catRaw);
                    echo "  [{$catDecoded}] → /{$catSlug}/\n";
                    $pages = isset(self::$pageBySlug[$catSlug]) ? self::$pageBySlug[$catSlug] : array();
                    foreach ($pages as $pageSlug => $pageRaw) {
                        echo "       [" . urldecode($pageRaw) . "] → /{$catSlug}/{$pageSlug}/\n";
                    }
                }
                exit;
            }

            self::handleRequest();

            // rewriteOutput und rewriteCallback müssen public static bleiben,
            // da ob_start() keinen Zugriff auf private/protected Methoden hat.
            ob_start(array('_seo_urls', 'rewriteOutput'));
        }

        return '';
    }

    function getConfig() {

        $config = array();

        $config['debug_enabled'] = array(
            'type'        => 'checkbox',
            'description' => 'Debug-Modus aktivieren (Slug-Map unter /?seo_debug=1 abrufbar)'
        );

        return $config;
    }

    function getInfo() {

        $description = '
<p>Wandelt Kategorie- und Seitennamen in SEO-freundliche URL-Slugs um.
Läuft als <code>plugin_first</code> – vor <code>createGetCatPageFromModRewrite()</code>.</p>

<h4>Funktionen</h4>
<table>
  <tr><td><b>Umlaut-Ersetzung</b></td><td>ä→ae, ö→oe, ü→ue, ß→ss (+ gängige Akzente)</td></tr>
  <tr><td><b>Leerzeichen</b></td><td>Werden durch Bindestriche ersetzt</td></tr>
  <tr><td><b>Link-Rewriting</b></td><td>Alle internen <code>href</code>-Links im HTML-Output werden umgeschrieben</td></tr>
  <tr><td><b>301-Redirect</b></td><td>Alte Pfade mit Umlauten/Leerzeichen werden permanent weitergeleitet</td></tr>
  <tr><td><b>Kollisionsschutz</b></td><td>Identische Slugs erhalten automatisch ein Suffix (<code>-2</code>, <code>-3</code> …)</td></tr>
  <tr><td><b>Sitemap-kompatibel</b></td><td><code>?action=sitemap</code> funktioniert unverändert, Links werden ebenfalls umgeschrieben</td></tr>
</table>

<h4>Beispiele</h4>
<table>
  <tr><td>Original-Pfad</td><td>Slug-URL</td></tr>
  <tr><td><code>/Über Uns/</code></td><td><code>/ueber-uns/</code></td></tr>
  <tr><td><code>/Über Uns/Unser Team/</code></td><td><code>/ueber-uns/unser-team/</code></td></tr>
  <tr><td><code>/Häufige Fragen/</code></td><td><code>/haeufige-fragen/</code></td></tr>
</table>

<h4>Hinweise</h4>
<p><b>Sitemap (<code>?action=sitemap</code>):</b> Vollständig kompatibel. Der <code>?action=sitemap</code>-Aufruf
wird vom Plugin nicht abgefangen. Die vom CMS gerenderten Sitemap-Links werden vom Output-Buffer
automatisch in Slug-URLs umgeschrieben.<br>
<i>Edge-Case:</i> Falls eine Kategorie den Namen <code>sitemap</code> hat, würde ein direkter Aufruf
von <code>/sitemap/</code> als Slug-URL interpretiert. Der Menü-Link <code>href="/?action=sitemap"</code>
funktioniert davon unabhängig weiterhin korrekt.</p>

<p><b>URL_BASE:</b> Falls moziloCMS in einem Unterverzeichnis läuft (z.&nbsp;B. <code>/mozilo/</code>),
wird die <code>URL_BASE</code>-Konstante automatisch berücksichtigt. Slug-URLs werden dann als
<code>/mozilo/ueber-uns/team/</code> ausgegeben.</p>

<p><b>Titeländerungen im Admin:</b> Wird ein Kategorie- oder Seitenname umbenannt, ändert sich der
zugehörige Slug. Bestehende Bookmarks oder externe Links auf den alten Slug laufen dann ins Leere.
In diesem Fall empfiehlt es sich, den alten Slug manuell als statische <code>Redirect</code>-Regel
in der <code>.htaccess</code> einzutragen.</p>

<h4>Debugging</h4>
<p>Slug-Mapping aller Kategorien und Seiten ausgeben:<br>
<code>https://deine-domain.de/?seo_debug=1</code><br>
<i>Nur im Entwicklungsmodus verwenden!</i></p>
';

        $info = array(
            '<b>seo_urls</b> v1.1.1',
            '2.0 / 3.0',
            $description,
            '',
            '',
            array('seo', 'url', 'rewrite', 'slug')
        );

        return $info;
    }

    // -----------------------------------------------------------------------
    // Maps aus CatPage aufbauen
    // -----------------------------------------------------------------------

    private static function buildMaps() {
        if (self::$mapsBuilt) {
            return;
        }
        self::$mapsBuilt = true;

        global $CatPage;

        $pageTypes = array(EXT_PAGE);
        if (defined('EXT_HIDDEN')) {
            $pageTypes[] = EXT_HIDDEN;
        }

        $cats = $CatPage->get_CatArray(false, false, $pageTypes);

        foreach ($cats as $catName) {
            $catDecoded = urldecode($catName);
            $catSlug = self::makeUnique(
                self::slugify($catDecoded),
                self::$catBySlug  // gesamtes Array übergeben, makeUnique nutzt Keys
            );

            self::$catBySlug[$catSlug]    = $catName;    // kodiert → für $_GET['cat']
            self::$catToSlug[$catName]    = $catSlug;    // kodiert → slug (href-Matching)
            self::$catToSlug[$catDecoded] = $catSlug;    // dekodiert → slug (fallback)

            $pages = $CatPage->get_PageArray($catName, $pageTypes, true);

            $pageSlugsForCat = isset(self::$pageBySlug[$catSlug]) ? self::$pageBySlug[$catSlug] : array();

            foreach ($pages as $pageName) {
                $pageDecoded = urldecode($pageName);
                $pageSlug = self::makeUnique(
                    self::slugify($pageDecoded),
                    $pageSlugsForCat  // nur innerhalb dieser Kategorie auf Kollision prüfen
                );
                $pageSlugsForCat[$pageSlug] = true;  // Hash-Set für makeUnique aktuell halten

                self::$pageBySlug[$catSlug][$pageSlug]        = $pageName;  // für $_GET['page']
                self::$pageToSlug[$catName][$pageName]        = $pageSlug;  // kodiert/kodiert
                self::$pageToSlug[$catName][$pageDecoded]     = $pageSlug;  // kodiert/dekodiert
                self::$pageToSlug[$catDecoded][$pageName]     = $pageSlug;  // dekodiert/kodiert
                self::$pageToSlug[$catDecoded][$pageDecoded]  = $pageSlug;  // dekodiert/dekodiert
            }
        }
    }

    // -----------------------------------------------------------------------
    // Eingehende Anfrage verarbeiten
    // -----------------------------------------------------------------------

    private static function handleRequest() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        // Query-String entfernen
        $qpos = strpos($uri, '?');
        if ($qpos !== false) {
            $uri = substr($uri, 0, $qpos);
        }

        // URL_BASE-Präfix entfernen
        if (defined('URL_BASE') && URL_BASE !== '/' && strpos($uri, URL_BASE) === 0) {
            $uri = '/' . substr($uri, strlen(URL_BASE));
        }

        // .html-Endung merken BEVOR wir sie entfernen —
        // wird in Block (A) genutzt um /ueber-uns.html → 301 → /ueber-uns/ umzuleiten
        $hadHtmlSuffix = (bool) preg_match('/\.html$/i', $uri);

        $uri = self::stripHtmlSuffix($uri);
        $uri = rtrim($uri, '/');

        if ($uri === '' || $uri === '/') {
            return;
        }

        // Segmente extrahieren
        $parts = array_values(array_filter(
            explode('/', $uri),
            function($s) { return $s !== ''; }
        ));

        if (count($parts) === 0) {
            return;
        }

        // sitemap.xml on-the-fly umschreiben
        if (strtolower($parts[0]) === 'sitemap.xml') {
            self::rewriteSitemap();
            return;
        }

        // Systempfade nie anfassen
        if (in_array(strtolower($parts[0]), self::SYSTEM_PATHS, true)) {
            return;
        }

        $rawCat  = urldecode($parts[0]);
        $rawPage = isset($parts[1]) ? urldecode($parts[1]) : null;

        // (A) Slug-URL → $_GET['cat'] / ['page'] setzen
        // Sonderfall: /ueber-uns.html → 301 → /ueber-uns/
        // Ohne diesen Redirect wären /ueber-uns.html und /ueber-uns/ Duplicate Content für Google.
        if (self::isSlug($rawCat) && isset(self::$catBySlug[$rawCat])) {
            if ($hadHtmlSuffix) {
                $catRaw  = self::$catBySlug[$rawCat];
                $pageRaw = ($rawPage !== null && isset(self::$pageBySlug[$rawCat][$rawPage]))
                    ? self::$pageBySlug[$rawCat][$rawPage]
                    : $rawPage;
                $slugUrl = self::buildSlugUrl($catRaw, $pageRaw);
                if ($slugUrl !== null) {
                    header('Location: ' . $slugUrl, true, 301);
                    exit;
                }
            }

            $_GET['cat'] = self::$catBySlug[$rawCat];

            if ($rawPage !== null) {
                if (self::isSlug($rawPage) && isset(self::$pageBySlug[$rawCat][$rawPage])) {
                    $_GET['page'] = self::$pageBySlug[$rawCat][$rawPage];
                } else {
                    $_GET['page'] = $rawPage;
                }
            }

            return;
        }

        // (B) Umlaut/Leerzeichen-Pfad → 301-Redirect
        // Nicht umleiten bei POST (Formular-Absendung) oder Draft-Modus —
        // POST-Daten gehen bei Redirect verloren, CMS braucht sie für Validierung
        $method  = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $isPost  = ($method === 'POST');
        $isDraft = isset($_GET['draft']) && $_GET['draft'] === 'true';

        if (!$isPost && !$isDraft && !self::isSlug($rawCat) && isset(self::$catToSlug[$rawCat])) {
            $slugUrl = self::buildSlugUrl($rawCat, $rawPage);
            if ($slugUrl !== null) {
                header('Location: ' . $slugUrl, true, 301);
                exit;
            }
        }

        // Bei POST: Kategorie/Seite trotzdem korrekt auflösen damit CMS die Seite findet
        if ($isPost && !self::isSlug($rawCat) && isset(self::$catToSlug[$rawCat])) {
            $_GET['cat'] = isset(self::$catBySlug[self::$catToSlug[$rawCat]])
                ? self::$catBySlug[self::$catToSlug[$rawCat]]
                : $rawCat;
            if ($rawPage !== null && isset(self::$pageToSlug[$rawCat][$rawPage])) {
                $pageSlug = self::$pageToSlug[$rawCat][$rawPage];
                $_GET['page'] = isset(self::$pageBySlug[self::$catToSlug[$rawCat]][$pageSlug])
                    ? self::$pageBySlug[self::$catToSlug[$rawCat]][$pageSlug]
                    : $rawPage;
            }
        }
    }

    // -----------------------------------------------------------------------
    // sitemap.xml on-the-fly umschreiben
    // -----------------------------------------------------------------------

    private static function rewriteSitemap() {
        $sitemapFile = BASE_DIR . 'sitemap.xml';

        if (!file_exists($sitemapFile)) {
            return;
        }

        $xml = file_get_contents($sitemapFile);

        // HTTP_HOST validieren: nur Hostnamen mit optionalem Port erlauben,
        // keine Leerzeichen oder Sonderzeichen (verhindert Header-Injection in XML)
        $rawHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $rawHost)) {
            $rawHost = '';
        }

        $scheme        = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $currentOrigin = $scheme . '://' . $rawHost;

        // Alle <loc>-URLs umschreiben
        $xml = preg_replace_callback(
            '|<loc>(https?://[^/]+)(/[^<]*)</loc>|',
            function($m) use ($currentOrigin) {
                $path = $m[2];

                // URL_BASE entfernen
                $relative = $path;
                if (defined('URL_BASE') && URL_BASE !== '/') {
                    $base = rtrim(URL_BASE, '/');
                    if (strpos($relative, $base) === 0) {
                        $relative = substr($relative, strlen($base));
                    }
                }

                // .html entfernen, dekodieren, Segmente extrahieren
                $relative = self::stripHtmlSuffix($relative);
                $relative = rtrim(urldecode($relative), '/');
                $parts    = array_values(array_filter(
                    explode('/', $relative),
                    function($s) { return $s !== ''; }
                ));

                if (count($parts) === 0) {
                    return $m[0];
                }

                $catName  = $parts[0];
                $pageName = isset($parts[1]) ? $parts[1] : null;

                $slugUrl = self::buildSlugUrl($catName, $pageName);

                if ($slugUrl === null) {
                    return $m[0];
                }

                $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
                return '<loc>' . $currentOrigin . $base . $slugUrl . '</loc>';
            },
            $xml
        );

        header('Content-Type: application/xml; charset=UTF-8');
        echo $xml;
        exit;
    }

    // -----------------------------------------------------------------------
    // Output-Buffer Callback
    // -----------------------------------------------------------------------

    /**
     * Wird als ob_start()-Callback aufgerufen – muss public static bleiben.
     * Schreibt href- und action-Attribute in einem einzigen Regex-Durchlauf um.
     */
    public static function rewriteOutput($html) {
        // href und action in einem einzigen Regex-Durchlauf verarbeiten.
        // (?:\.html|\/) am Ende des Pfad-Capture stellt sicher dass nur interne
        // CMS-Links (.html-Endung) und Slug-URLs (Trailing-Slash) gematcht werden.
        // Assets wie .css, .png, .pdf werden damit vom Regex gar nicht erst erfasst.
        $html = preg_replace_callback(
            '/(href|action)=(["\'])(?!https?:\/\/|mailto:|tel:)([^"\'#?]+(?:\.html|\/))(\\?[^"\'#]*)?(\#[^"\']*)?\2/i',
            array('_seo_urls', 'rewriteCallback'),
            $html
        );
        return $html;
    }

    /**
     * Wird als preg_replace_callback-Callback aufgerufen – muss public static bleiben.
     */
    public static function rewriteCallback($m) {
        $attr      = $m[1];           // 'href' oder 'action'
        $quote     = $m[2];
        $origPath  = $m[3];
        $queryStr  = isset($m[4]) ? $m[4] : '';  // z.B. ?i18n=en
        $anchor    = isset($m[5]) ? $m[5] : '';  // z.B. #abschnitt
        $rewritten = self::rewritePath($origPath);

        return $rewritten !== null
            ? $attr . '=' . $quote . $rewritten . $queryStr . $anchor . $quote
            : $m[0];
    }

    private static function rewritePath($path) {
        // URL-dekodieren: %C3%9Cber%20unsere → Über unsere
        $clean = urldecode($path);

        // URL_BASE entfernen: /mein-pfad/Über unsere Firma.html → /Über unsere Firma.html
        if (defined('URL_BASE') && URL_BASE !== '/') {
            $base = rtrim(URL_BASE, '/');
            if (strpos($clean, $base) === 0) {
                $clean = substr($clean, strlen($base));
            }
        }

        // .html-Endung und Trailing-Slash entfernen
        $clean = self::stripHtmlSuffix($clean);
        $clean = rtrim($clean, '/');

        $parts = array_values(array_filter(
            explode('/', $clean),
            function($s) { return $s !== ''; }
        ));

        if (count($parts) === 0) {
            return null;
        }

        $catName  = $parts[0];
        $pageName = isset($parts[1]) ? $parts[1] : null;

        // Bereits ein Slug → nicht doppelt verarbeiten
        if (self::isSlug($catName)) {
            return null;
        }

        return self::buildSlugUrl($catName, $pageName);
    }

    // -----------------------------------------------------------------------
    // Slug-URL bauen
    // -----------------------------------------------------------------------

    public static function buildSlugUrl($catName, $pageName) {
        if (!isset(self::$catToSlug[$catName])) {
            return null;
        }
        $catSlug = self::$catToSlug[$catName];
        $base    = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
        $url     = $base . '/' . $catSlug . '/';

        if ($pageName !== null && $pageName !== '') {
            if (!isset(self::$pageToSlug[$catName][$pageName])) {
                return null;
            }
            $url .= self::$pageToSlug[$catName][$pageName] . '/';
        }

        return $url;
    }

    // -----------------------------------------------------------------------
    // Slug-Generator
    // -----------------------------------------------------------------------

    public static function slugify($text) {
        // static: Array wird einmalig initialisiert, nicht bei jedem Aufruf neu gebaut
        static $map = array(
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'é' => 'e',  'è' => 'e',  'ê' => 'e',  'ë' => 'e',
            'á' => 'a',  'à' => 'a',  'â' => 'a',  'ã' => 'a',
            'ó' => 'o',  'ò' => 'o',  'ô' => 'o',  'õ' => 'o',
            'ú' => 'u',  'ù' => 'u',  'û' => 'u',
            'í' => 'i',  'ì' => 'i',  'î' => 'i',
            'ñ' => 'n',  'ç' => 'c',
        );

        $text = str_replace(array_keys($map), array_values($map), $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text ? $text : 'seite';
    }

    // -----------------------------------------------------------------------
    // Hilfsmethoden
    // -----------------------------------------------------------------------

    /**
     * Entfernt die .html-Endung aus einem Pfad (case-insensitive).
     * Zentralisiert eine Logik die vorher an drei Stellen dupliziert war.
     */
    private static function stripHtmlSuffix($path) {
        return preg_replace('/\.html$/i', '', $path);
    }

    /**
     * Prüft ob ein String ein gültiger Slug ist.
     * Erlaubt: Kleinbuchstaben, Ziffern und Bindestriche.
     * Bindestriche dürfen weder am Anfang noch am Ende stehen.
     */
    private static function isSlug($s) {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $s);
    }

    /**
     * Gibt einen Slug zurück der noch nicht als Key in $existing vorkommt.
     * Erwartet ein assoziatives Array (Keys = bereits vergebene Slugs).
     * Nutzt isset() für O(1)-Lookup statt in_array() mit O(n).
     */
    private static function makeUnique($slug, $existing) {
        if (!isset($existing[$slug])) {
            return $slug;
        }
        $i = 2;
        do {
            $candidate = $slug . '-' . $i++;
        } while (isset($existing[$candidate]));
        return $candidate;
    }
}
