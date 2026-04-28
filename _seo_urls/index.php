<?php
if (!defined('IS_CMS')) die();

/**
 * Plugin:   seo_urls
 * @author:  B.Unger
 * @version: v1.2.2  (siehe Klassenkonstante VERSION)
 * @license: GPL
 *
 * Wandelt Kategorie- und Seitennamen in SEO-freundliche URL-Slugs um.
 * Läuft als plugin_first — vor createGetCatPageFromModRewrite().
 *
 * Siehe htaccess_snippet.txt und README.md für die Installationsanleitung.
 *
 * Änderungen gegenüber v1.2.1:
 *  - fix: handleRequest() ignoriert Requests mit bereits gesetzten moziloCMS-Parametern (cat/page)
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
     * URL-kodierter Name der ersten CMS-Kategorie (= Homepage).
     * Wird in handleRequest() genutzt, um /startseite/ → / zu redirecten.
     */
    private static $homeCatName = null;

    /**
     * Kanonischer Slug-Pfad der aktuellen Anfrage (z.B. '/kontakt/' oder '/kontakt/anfahrt/').
     * Wird von handleRequest() gesetzt und in rewriteOutput() genutzt, um den
     * <link rel="canonical">-Tag zu korrigieren — moziloCMS gibt bei Kategorie-Einstiegsseiten
     * oft die erste Unterseite als Canonical aus statt der Kategorie-URL selbst.
     */
    private static $resolvedCanonicalPath = null;

    /**
     * Optionaler Redirect-Callback für Tests.
     * Im Normalbetrieb null → redirect() sendet header() + exit.
     * In PHPUnit-Tests wird hier eine Closure eingehängt, die den Redirect
     * abfängt ohne exit auszuführen — der Test-Runner läuft weiter.
     */
    private static $redirector = null;

    /**
     * Plugin-Version als Klassenkonstante.
     * Einzige Stelle die bei einem Versions-Update geändert werden muss —
     * alle anderen Stellen im Code referenzieren self::VERSION.
     */
    const VERSION = 'v1.2.2';

    /**
     * Pfade die nie von diesem Plugin angefasst werden dürfen.
     * Als Konstante definiert → wird einmalig kompiliert, nicht bei jedem Request neu gebaut.
     */
    const SYSTEM_PATHS = array(
        'admin',
        'cms',
        'plugins',
        'templates',
        'layouts',
        'galerien',
        'kategorien',
        'data',
        'files'
    );

    // -----------------------------------------------------------------------
    // Pflichtmethoden moziloCMS Plugin-API
    // -----------------------------------------------------------------------

    function getContent($value) {
        return '';
    }

    function getPluginContent($value) {
        if ($value === 'plugin_first') {

            self::buildMaps();

            if (!empty($_GET['seo_debug']) && $this->settings->get('debug_enabled') === 'true') {
                header('Content-Type: text/plain; charset=utf-8');
                echo "=== seo_urls Plugin " . self::VERSION . " – Slug-Map ===\n\n";
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
';

        $info = array(
            '<b>seo_urls</b> ' . self::VERSION,
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

        if (!empty($cats)) {
            self::$homeCatName = reset($cats);
        }

        foreach ($cats as $catName) {
            $catDecoded = urldecode($catName);

            $catSlug = self::makeUnique(
                self::slugify($catDecoded),
                self::$catBySlug
            );

            self::$catBySlug[$catSlug]    = $catName;
            self::$catToSlug[$catName]    = $catSlug;
            self::$catToSlug[$catDecoded] = $catSlug;

            $pages = $CatPage->get_PageArray($catName, $pageTypes, true);

            $pageSlugsForCat = isset(self::$pageBySlug[$catSlug]) ? self::$pageBySlug[$catSlug] : array();

            foreach ($pages as $pageName) {
                $pageDecoded = urldecode($pageName);
                $pageSlug = self::makeUnique(
                    self::slugify($pageDecoded),
                    $pageSlugsForCat
                );
                $pageSlugsForCat[$pageSlug] = true;

                self::$pageBySlug[$catSlug][$pageSlug]        = $pageName;
                self::$pageToSlug[$catName][$pageName]        = $pageSlug;
                self::$pageToSlug[$catName][$pageDecoded]     = $pageSlug;
                self::$pageToSlug[$catDecoded][$pageName]     = $pageSlug;
                self::$pageToSlug[$catDecoded][$pageDecoded]  = $pageSlug;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Redirect-Helfer
    // -----------------------------------------------------------------------

    /**
     * Sendet einen HTTP-Redirect und beendet die Ausführung.
     *
     * Im Normalbetrieb: header() + exit.
     * In PHPUnit-Tests: $redirector-Callback wird aufgerufen (kein exit),
     * damit der Test-Runner nach dem Redirect-Aufruf weiterlaufen kann.
     *
     * @param string $url   Ziel-URL (absolut oder relativ)
     * @param int    $code  HTTP-Statuscode (Standard: 301)
     */
    protected static function redirect(string $url, int $code = 301): void {
        if (self::$redirector !== null) {
            call_user_func(self::$redirector, $url, $code);
            return;
        }
        header('Location: ' . $url, true, $code);
        exit;
    }

    // -----------------------------------------------------------------------
    // Origin-Helfer
    // -----------------------------------------------------------------------

    /**
     * Gibt den validierten Origin (Schema + Host) zurück.
     * Liefert einen leeren String wenn HTTP_HOST ungültig ist (z.B. Header-Injection).
     *
     * Zentralisiert die HTTP_HOST-Validierung die vorher in rewriteSitemap() und
     * rewriteOutput() dupliziert war.
     */
    private static function getSafeOrigin(): string {
        $rawHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $rawHost)) {
            return '';
        }
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        return $scheme . '://' . $rawHost;
    }

    // -----------------------------------------------------------------------
    // Eingehende Anfrage verarbeiten
    // -----------------------------------------------------------------------

    private static function handleRequest() {
        // REQUEST_URI enthält den vollständigen Pfad inkl. Query-String,
        // z.B. "/mozilo/ueber-uns/?foo=bar" — wir brauchen nur den Pfadteil.
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        // Query-String abschneiden: "/ueber-uns/?foo=bar" → "/ueber-uns/"
        $qpos = strpos($uri, '?');
        if ($qpos !== false) {
            $uri = substr($uri, 0, $qpos);
        }

        // Wenn moziloCMS-Parameter bereits im Query-String stehen,
        // hat das CMS die Seite bereits aufgelöst → Plugin nicht eingreifen.
        if (isset($_GET['cat']) || isset($_GET['page'])) {
            return;
        }

        // URL_BASE-Präfix entfernen, falls moziloCMS in einem Unterverzeichnis läuft.
        if (defined('URL_BASE') && URL_BASE !== '/' && strpos($uri, URL_BASE) === 0) {
            $uri = '/' . substr($uri, strlen(URL_BASE));
        }

        // .html-Endung merken BEVOR wir sie entfernen
        $hadHtmlSuffix = (bool) preg_match('/\.html$/i', $uri);

        $uri = self::stripHtmlSuffix($uri);
        $uri = rtrim($uri, '/');

        if ($uri === '' || $uri === '/') {
            return;
        }

        // Segmente extrahieren
        $parts = array_values(array_filter(
            explode('/', $uri),
            function ($s) {
                return $s !== '';
            }
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

        if (self::isSlug($rawCat) && isset(self::$catBySlug[$rawCat])) {
            self::resolveSlugRequest($rawCat, $rawPage, $hadHtmlSuffix);
        } else {
            self::resolveRawCatRequest($rawCat, $rawPage);
        }
    }

    /**
     * Verarbeitet eine eingehende Slug-URL (z.B. /ueber-uns/unser-team/).
     * Setzt $_GET['cat'] und $_GET['page'] oder löst 301-Redirect aus bei:
     *  - Homepage-Slug → /
     *  - .html-Suffix → Slug-URL ohne Suffix
     */
    private static function resolveSlugRequest(
        string $rawCat,
        ?string $rawPage,
        bool $hadHtmlSuffix
    ): void {
        // Homepage-Slug → 301 auf /
        if (
            self::$homeCatName !== null && $rawPage === null &&
            self::$catBySlug[$rawCat] === self::$homeCatName
        ) {
            $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
            self::redirect($base . '/');
            return;
        }

        // .html-Suffix → 301 auf Slug-URL
        if ($hadHtmlSuffix) {
            $catRaw  = self::$catBySlug[$rawCat];
            $pageRaw = ($rawPage !== null && isset(self::$pageBySlug[$rawCat][$rawPage]))
                ? self::$pageBySlug[$rawCat][$rawPage]
                : $rawPage;
            $slugUrl = self::buildSlugUrl($catRaw, $pageRaw);
            if ($slugUrl !== null) {
                self::redirect($slugUrl);
                return;
            }
        }

        $_GET['cat'] = self::$catBySlug[$rawCat];

        if ($rawPage !== null) {
            if (self::isSlug($rawPage) && isset(self::$pageBySlug[$rawCat][$rawPage])) {
                $_GET['page'] = self::$pageBySlug[$rawCat][$rawPage];
                self::$resolvedCanonicalPath = '/' . $rawCat . '/' . $rawPage . '/';
            } else {
                $_GET['page'] = $rawPage;
            }
        } else {
            self::$resolvedCanonicalPath = '/' . $rawCat . '/';
        }
    }

    /**
     * Verarbeitet eine eingehende Umlaut/Leerzeichen-URL (z.B. /Über Uns/).
     * Bei GET: 301-Redirect auf Slug-URL (außer Draft-Modus).
     * Bei POST: $_GET['cat'] und $_GET['page'] direkt setzen (kein Redirect,
     * da POST-Daten beim Redirect verloren gehen).
     */
    private static function resolveRawCatRequest(
        string $rawCat,
        ?string $rawPage
    ): void {
        $method  = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $isPost  = ($method === 'POST');
        $isDraft = isset($_GET['draft']) && $_GET['draft'] === 'true';

        if (!$isPost && !$isDraft && isset(self::$catToSlug[$rawCat])) {

            // Homepage-Rohname → 301 auf /
            if (
                self::$homeCatName !== null && $rawPage === null &&
                $rawCat === urldecode(self::$homeCatName)
            ) {
                $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
                self::redirect($base . '/');
                return;
            }

            $slugUrl = self::buildSlugUrl($rawCat, $rawPage);
            if ($slugUrl !== null) {
                self::redirect($slugUrl);
                return;
            }
        }

        // Bei POST: Kategorie/Seite korrekt auflösen
        if ($isPost && isset(self::$catToSlug[$rawCat])) {
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

        // getSafeOrigin() liefert validierten Origin oder leeren String
        $currentOrigin = self::getSafeOrigin();

        $xml = preg_replace_callback(
            '|<loc>(https?://[^/]+)(/[^<]*)</loc>|',
            function ($m) use ($currentOrigin) {
                $path = $m[2];

                $relative = $path;
                if (defined('URL_BASE') && URL_BASE !== '/') {
                    $base = rtrim(URL_BASE, '/');
                    if (strpos($relative, $base) === 0) {
                        $relative = substr($relative, strlen($base));
                    }
                }

                $relative = self::stripHtmlSuffix($relative);
                $relative = rtrim(urldecode($relative), '/');
                $parts    = array_values(array_filter(
                    explode('/', $relative),
                    function ($s) {
                        return $s !== '';
                    }
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
     */
    public static function rewriteOutput($html) {
        $html = preg_replace_callback(
            '/(href|action)=(["\'])(?!https?:\/\/|mailto:|tel:)([^"\'#?]+(?:\.html|\/))(\\?[^"\'#]*)?(\#[^"\']*)?\2/i',
            array('_seo_urls', 'rewriteCallback'),
            $html
        );

        return self::rewriteCanonical($html);
    }

    /**
     * Korrigiert den <link rel="canonical">-Tag im HTML-Output.
     * moziloCMS setzt bei Kategorie-Einstiegsseiten oft die erste Unterseite
     * als Canonical statt der Kategorie-URL selbst.
     * Wird nur aktiv wenn $resolvedCanonicalPath gesetzt ist.
     */
    private static function rewriteCanonical(string $html): string {
        if (self::$resolvedCanonicalPath === null) {
            return $html;
        }
        $origin = self::getSafeOrigin();
        if ($origin === '') {
            return $html;
        }
        $base          = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
        $canonicalHref = $origin . $base . self::$resolvedCanonicalPath;

        return preg_replace_callback(
            '/<link\b[^>]*\brel=["\']canonical["\'][^>]*>/i',
            function ($m) use ($canonicalHref) {
                return preg_replace(
                    '/\bhref=["\'][^"\']*["\']/',
                    'href="' . $canonicalHref . '"',
                    $m[0]
                );
            },
            $html
        );
    }

    /**
     * Wird als preg_replace_callback-Callback aufgerufen – muss public static bleiben.
     */
    public static function rewriteCallback($m) {
        $attr      = $m[1];
        $quote     = $m[2];
        $origPath  = $m[3];
        $queryStr  = isset($m[4]) ? $m[4] : '';
        $anchor    = isset($m[5]) ? $m[5] : '';
        $rewritten = self::rewritePath($origPath);

        return $rewritten !== null
            ? $attr . '=' . $quote . $rewritten . $queryStr . $anchor . $quote
            : $m[0];
    }

    private static function rewritePath($path) {
        $clean = urldecode($path);

        if (defined('URL_BASE') && URL_BASE !== '/') {
            $base = rtrim(URL_BASE, '/');
            if (strpos($clean, $base) === 0) {
                $clean = substr($clean, strlen($base));
            }
        }

        $clean = self::stripHtmlSuffix($clean);
        $clean = rtrim($clean, '/');

        $parts = array_values(array_filter(
            explode('/', $clean),
            function ($s) {
                return $s !== '';
            }
        ));

        if (count($parts) === 0) {
            return null;
        }

        $catName  = $parts[0];
        $pageName = isset($parts[1]) ? $parts[1] : null;

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
        static $map = array(
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ñ' => 'n',
            'ç' => 'c',
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
     */
    private static function stripHtmlSuffix($path) {
        return preg_replace('/\.html$/i', '', $path);
    }

    /**
     * Prüft ob ein String ein gültiger Slug ist.
     */
    private static function isSlug($s) {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $s);
    }

    /**
     * Gibt einen Slug zurück der noch nicht als Key in $existing vorkommt.
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
