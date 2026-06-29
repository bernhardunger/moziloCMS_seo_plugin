<?php
if (!defined('IS_CMS')) die();

/**
 * Plugin:   seo_urls
 * @license: GPL
 *
 * Wandelt Kategorie- und Seitennamen in SEO-freundliche URL-Slugs um.
 * Änderungshistorie: siehe CHANGELOG.md
 */

/**
 * Kapselt den Zugriff auf die plugin.conf.php des
 * MetaKeywordsDescription-Plugins.
 *
 * Das Fremdformat (PHP-Serialisierung, URL-kodierte Schlüssel,
 * "php die();"-Header) ist an genau einer Stelle gebündelt.
 * Ändert MetaKeywordsDescription sein Speicherformat, ist nur
 * diese Klasse anzupassen – nicht applyMetaKeywordsDescription().
 *
 * Gibt null zurück wenn das Plugin nicht installiert ist,
 * die Datei nicht lesbar ist oder kein Eintrag existiert.
 * Graceful degradation: kein Fatal Error, kein leerer Meta-Tag.
 */
final class MetaKeywordsDescriptionAdapter
{
    /**
     * Liest Meta-Konfiguration für eine Kategorie/Seite aus der
     * plugin.conf.php des MetaKeywordsDescription-Plugins.
     *
     * @param string $cat  Kategoriename (URL-decoded, wie im CMS gespeichert)
     * @param string $page Seitenname    (URL-decoded, wie im CMS gespeichert)
     * @return array{description: string, keywords: string}|null
     *         null wenn Plugin nicht installiert, Datei nicht lesbar,
     *         Format unbekannt oder kein Eintrag für cat/page vorhanden.
     */
    public static function lookup(string $cat, string $page): ?array
    {
        // 1. Pfad zur plugin.conf.php aufbauen
        //    BASE_DIR . 'plugins/' analog zu bestehendem Plugin-Pfad-Muster
        //    Analog zu isHtaccessValid(): graceful degradation außerhalb CMS-Kontext
        if (!defined('BASE_DIR')) {
            return null;
        }
        $confPath = BASE_DIR . 'plugins/MetaKeywordsDescription/plugin.conf.php';
        if (!is_file($confPath) || !is_readable($confPath)) {
            return null;
        }

        // 2. Datei lesen und "php die();"-Header überspringen
        $raw = file_get_contents($confPath);
        if ($raw === false) {
            return null;
        }
        $start = strpos($raw, 'a:');
        if ($start === false) {
            return null; // unbekanntes Format
        }
        $raw = substr($raw, $start);

        // 3. Deserialisieren (kein @ – try-catch wie in F8)
        try {
            $conf = unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable $e) {
            return null; // korrupte Datei -> graceful degradation
        }
        if (!is_array($conf)) {
            return null;
        }

        // 4. Schlüssel bilden: "@=rawurlencoded-cat:page=@"
        //    $cat: URL-decoded übergeben, rawurlencode() hier anwenden
        //    $page: moziloCMS 3.0.x übergibt $_GET['page'] ohne URL-Encoding –
        //           urldecode() am Aufrufer liefert identischen Wert, kein
        //           rawurlencode() nötig. Annahme gilt für moziloCMS 3.0.x;
        //           bei Formatänderung ist nur diese Zeile anzupassen.
        $key = '@=' . rawurlencode($cat) . ':' . $page . '=@';
        if (!isset($conf[$key]) || !is_array($conf[$key])) {
            return null;
        }

        // 5. Werte zurückgeben – unescaped (MetaKeywordsDescription
        //    speichert roh, htmlspecialchars() obliegt dem Aufrufer)
        return [
            'description' => (string) ($conf[$key]['description'] ?? ''),
            'keywords'    => (string) ($conf[$key]['keywords'] ?? ''),
        ];
    }
}

class _seo_urls extends Plugin {

    // -----------------------------------------------------------------------
    // Slug-Maps
    // -----------------------------------------------------------------------

    private static array $catBySlug  = [];  // catSlug  → originalCatName
    private static array $catToSlug  = [];  // originalCatName → catSlug
    private static array $pageBySlug = [];  // catSlug → [ pageSlug → originalPageName ]
    private static array $pageToSlug = [];  // originalCatName → [ originalPageName → pageSlug ]
    private static $mapsBuilt  = false;

    /**
     * Request-weiter Cache für isHtaccessValid().
     * Lebt nur für die Dauer eines Requests – PHP verwirft static Properties
     * am Requestende (klassische SAPIs). Verhindert redundante
     * file_get_contents()-Aufrufe innerhalb eines Requests.
     */
    private static ?bool $htaccessValidCache = null;

    /**
     * URL-kodierter Name der ersten CMS-Kategorie (= Homepage).
     */
    private static $homeCatName = null;

    /**
     * Kanonischer Slug-Pfad der aktuellen Anfrage.
     */
    private static $resolvedCanonicalPath = null;

    /**
     * Optionaler Redirect-Callback für Tests.
     */
    private static $redirector = null;

    /** Sprachobjekt für Admin-Info (getInfo). Null bis zur ersten Nutzung. */
    private ?Language $admin_lang = null;

    /** Standard-Sprache für Admin-Info. */
    private const DEFAULT_LANGUAGE = 'deDE';

    /** Unterstützte Sprachen für Admin-Info. */
    private const SUPPORTED_LANGUAGES = ['deDE', 'enEN'];

    const VERSION = 'v1.3.7';

    const SYSTEM_PATHS = [
        'admin',
        'cms',
        'plugins',
        'templates',
        'layouts',
        'galerien',
        'kategorien',
        'data',
        'files'
    ];

    /**
     * Name des MetaKeywordsDescription Plugins.
     * Einzige Stelle die geändert werden muss falls das Plugin umbenannt wird.
     */
    const META_PLUGIN_NAME = 'MetaKeywordsDescription';

    // -----------------------------------------------------------------------
    // Pflichtmethoden moziloCMS Plugin-API
    // -----------------------------------------------------------------------

    function getContent($value) {
        return '';
    }

    function getPluginContent($value) {
        if ($value === 'plugin_first') {

            // .htaccess-Prüfung: Plugin deaktiviert sich wenn Konfiguration unvollständig.
            // Verhindert dass eine fehlerhafte .htaccess den Admin-Bereich unzugänglich macht.
            if (!self::isHtaccessValid()) {
                return '';
            }

            self::buildMaps();

            if (!empty($_GET['seo_debug']) && $this->settings->get('debug_enabled') === 'true') {
                self::dumpDebugMap();
            }

            self::handleRequest();

            // MetaKeywordsDescription-Kompatibilität:
            // Setzt {WEBSITE_DESCRIPTION} und {WEBSITE_KEYWORDS} im Template,
            // nachdem $_GET['cat'] und $_GET['page'] korrekt gesetzt wurden.
            // $_GET['cat'] ist URL-kodiert (CMS-intern); lookup() erwartet URL-decoded.
            $metaRawCat  = isset($_GET['cat'])   ? (string) $_GET['cat']  : '';
            $metaRawPage = !empty($_GET['page']) ? (string) $_GET['page'] : '';
            if ($metaRawPage === '') {
                global $CatPage;
                if (isset($CatPage)) {
                    $firstPage   = $CatPage->get_FirstPageOfCat($metaRawCat);
                    $metaRawPage = ($firstPage !== false) ? (string) $firstPage : '';
                }
            }
            self::applyMetaKeywordsDescription(
                urldecode($metaRawCat),
                urldecode($metaRawPage)
            );

            ob_start(static function(string $html): string {
                return self::rewriteOutput($html);
            });
        }

        return '';
    }

    private static function dumpDebugMap(): void {
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== seo_urls Plugin " . self::VERSION . " – Slug-Map ===\n\n";
        foreach (self::$catBySlug as $catSlug => $catRaw) {
            $catDecoded = urldecode($catRaw);
            echo "  [{$catDecoded}] → /{$catSlug}/\n";
            $pages = isset(self::$pageBySlug[$catSlug]) ? self::$pageBySlug[$catSlug] : [];
            foreach ($pages as $pageSlug => $pageRaw) {
                echo "       [" . urldecode($pageRaw) . "] → /{$catSlug}/{$pageSlug}/\n";
            }
        }
        exit;
    }

    function getConfig() {
        global $ADMIN_CONF;
        $config = [];
        if (!isset($ADMIN_CONF)) { return $config; }

        $this->initAdminLang();

        $config['debug_enabled'] = [
            'type'        => 'checkbox',
            'description' => $this->admin_lang->getLanguageValue('config_debug')
        ];

        return $config;
    }

    /**
     * Ermittelt den Sprachcode für die Admin-Info-Sprachdatei.
     *
     * Empfängt den rohen Sprachcode aus $ADMIN_CONF (z.B. 'deDE', 'enUS', 'de').
     * Normalisiert auf Kleinschreibung und prüft via str_starts_with() den
     * 2-Zeichen-Prefix gegen SUPPORTED_LANGUAGES. Liefert beim ersten Treffer
     * den moziloCMS-Locale-Code (z.B. 'deDE'). Fällt bei unbekannten Locales
     * auf DEFAULT_LANGUAGE zurück.
     *
     * @param string|null $code  Sprachcode aus $ADMIN_CONF->get('language')
     * @return string            Locale-Code ('deDE' oder 'enEN')
     */
    private function resolvePluginLanguage(?string $code): string
    {
        if ($code !== null) {
            $lower = strtolower($code);
            foreach (self::SUPPORTED_LANGUAGES as $supported) {
                if (str_starts_with($lower, substr($supported, 0, 2))) {
                    return $supported;
                }
            }
        }
        return self::DEFAULT_LANGUAGE;
    }

    private function initAdminLang(): void
    {
        global $ADMIN_CONF;
        $lang = $this->resolvePluginLanguage(
            $ADMIN_CONF->get('language') ?? self::DEFAULT_LANGUAGE
        );
        $this->admin_lang = new Language(
            $this->PLUGIN_SELF_DIR . 'sprachen/admin_language_' . $lang . '.txt'
        );
    }

    function getInfo()
    {
        global $ADMIN_CONF;

        $this->initAdminLang();

        $htaccessStatus = self::checkHtaccess(
            $this->admin_lang->getLanguageValue('htaccess_ok'),
            $this->admin_lang->getLanguageValue('htaccess_missing'),
            $this->admin_lang->getLanguageValue('htaccess_incomplete_header'),
            $this->admin_lang->getLanguageValue('htaccess_incomplete_body'),
            $this->admin_lang->getLanguageValue('htaccess_required')
        );

        $description =
            $this->admin_lang->getLanguageValue('info_intro') .
            "\n" . $htaccessStatus . "\n" .
            $this->admin_lang->getLanguageValue('info_requirements') .
            $this->admin_lang->getLanguageValue('info_install') .
            $this->admin_lang->getLanguageValue('info_features') .
            $this->admin_lang->getLanguageValue('info_examples');

        $info = [
            '<b>seo_urls</b> ' . self::VERSION,
            '2.0 / 3.0',  // Hinweis: moziloCMS prüft ob '2' im String enthalten ist.
            // Fehlt die '2', deaktiviert der Admin das Plugin automatisch.
            // Das Plugin unterstützt nur moziloCMS 3.0.x – siehe getInfo().
            $description,
            '',
            '',
            []  // plugin_first-Plugin: kein einfügbarer {_seo_urls}-Tag
        ];

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

        // EXT_PAGE ist in moziloCMS 3.0.x immer definiert (Voraussetzung laut getInfo()).
        // EXT_HIDDEN ist optional – daher der separate defined()-Check.
        $pageTypes = [EXT_PAGE];
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

            $pageSlugsForCat = isset(self::$pageBySlug[$catSlug]) ? self::$pageBySlug[$catSlug] : [];

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
    // MetaKeywordsDescription-Kompatibilität
    // -----------------------------------------------------------------------

    /**
     * Setzt {WEBSITE_DESCRIPTION} und {WEBSITE_KEYWORDS} im Template.
     *
     * Delegiert das Lesen der plugin.conf.php an MetaKeywordsDescriptionAdapter::lookup().
     * Wird nach handleRequest() aufgerufen, wenn $cat/$page bereits korrekt
     * ermittelt wurden.
     *
     * @param string $cat  Kategoriename (URL-decoded)
     * @param string $page Seitenname    (URL-decoded)
     */
    private static function applyMetaKeywordsDescription(
        string $cat,
        string $page
    ): void {
        global $template;
        if (!isset($template) || !is_string($template)) {
            return;
        }

        $setting = MetaKeywordsDescriptionAdapter::lookup($cat, $page);
        if ($setting === null) {
            return;
        }

        if (strlen($setting['description']) > 2) {
            $template = str_replace(
                '{WEBSITE_DESCRIPTION}',
                htmlspecialchars($setting['description'], ENT_QUOTES, 'UTF-8'),
                $template
            );
        }
        if (strlen($setting['keywords']) > 2) {
            $template = str_replace(
                '{WEBSITE_KEYWORDS}',
                htmlspecialchars($setting['keywords'], ENT_QUOTES, 'UTF-8'),
                $template
            );
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
        $url = str_replace(["\r", "\n"], '', $url);
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
    // .htaccess-Prüfung
    // -----------------------------------------------------------------------

    /**
     * Prüft den .htaccess-Inhalt auf die erforderlichen Plugin-Regeln.
     * Einzige Stelle wo die Regex-Muster definiert sind –
     * wird von isHtaccessValid() und checkHtaccess() gemeinsam genutzt.
     *
     * Regex-Erklärungen:
     *  ^(?![ \t]*#) → Zeilenanfang (m-Flag), negative Lookahead:
     *                 kein optionales Whitespace gefolgt von # –
     *                 auskommentierte Zeilen werden ignoriert
     *  [ \t]*       → optionales führendes Whitespace vor der Direktive
     *  m-Flag       → ^ matcht Anfang jeder Zeile
     *
     *  Sitemap \\   → matcht echten Backslash \ in der .htaccess
     *  Sitemap \.   → matcht den Punkt .
     *  zusammen \\\.→ matcht \. im Zieltext
     *
     * Die drei Catch-All-Zeilen werden einzeln geprüft –
     * so wird erkannt wenn nur eine davon auskommentiert ist.
     *
     * @param  string $content  Inhalt der .htaccess-Datei
     * @return array{hasSitemap: bool, hasCatchAll: bool}
     */
    private static function parseHtaccessRules(string $content): array {

        // Sitemap-Regel: RewriteRule ^sitemap\.xml$ index.php [L,QSA]
        $hasSitemap = (bool) preg_match(
            '/^(?![ \t]*#)[ \t]*RewriteRule\s+\^sitemap\\\.xml\$\s+index\.php/im',
            $content
        );

        // Catch-All: jede der drei Zeilen einzeln prüfen
        $hasCatchAllF = (bool) preg_match(
            '/^(?![ \t]*#)[ \t]*RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-f/im',
            $content
        );
        $hasCatchAllD = (bool) preg_match(
            '/^(?![ \t]*#)[ \t]*RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-d/im',
            $content
        );
        $hasCatchAllRule = (bool) preg_match(
            '/^(?![ \t]*#)[ \t]*RewriteRule\s+\^\(\.\*\)\$\s+index\.php/im',
            $content
        );

        return [
            'hasSitemap'  => $hasSitemap,
            'hasCatchAll' => $hasCatchAllF && $hasCatchAllD && $hasCatchAllRule,
        ];
    }

    /**
     * Prüft ob die .htaccess alle erforderlichen Regeln enthält.
     * Ergebnis wird request-weit gecacht ($htaccessValidCache): PHP verwirft
     * static Properties am Requestende (klassische SAPIs), daher wirken
     * Änderungen per FTP sofort beim nächsten Seitenaufruf. Innerhalb eines
     * Requests verhindert der Cache redundante file_get_contents()-Aufrufe
     * (moziloCMS ruft Plugin-Methoden mehrfach auf).
     *
     * Geprüft wird:
     *  1. Sitemap-Regel:    RewriteRule ^sitemap\.xml$ index.php [L,QSA]
     *  2. Catch-All-Regeln: RewriteCond !-f + RewriteCond !-d + RewriteRule ^(.*)$
     *
     * Designentscheidungen:
     *  - BASE_DIR nicht definiert → true  (nicht prüfbar, laufen lassen)
     *  - .htaccess nicht lesbar  → true  (kein falsches Deaktivieren bei Rechteproblemen)
     *  - .htaccess fehlt         → false (Plugin deaktiviert sich)
     *  - Sitemap-Regel fehlt/auskommentiert     → false (Plugin deaktiviert sich)
     *  - Catch-All fehlt/auskommentiert         → false (Plugin deaktiviert sich)
     */
    private static function isHtaccessValid(): bool {
        if (self::$htaccessValidCache !== null) {
            return self::$htaccessValidCache;
        }
        if (!defined('BASE_DIR')) {
            return self::$htaccessValidCache = true;
        }
        $htaccess = BASE_DIR . '.htaccess';
        if (!file_exists($htaccess)) {
            return self::$htaccessValidCache = false;
        }
        $content = file_get_contents($htaccess);
        if ($content === false) {
            return self::$htaccessValidCache = true;
        }
        $rules = self::parseHtaccessRules($content);
        return self::$htaccessValidCache = $rules['hasSitemap'] && $rules['hasCatchAll'];
    }

    /**
     * Gibt einen HTML-Statusblock für die Admin-Anzeige zurück.
     * Grün = .htaccess korrekt konfiguriert, Rot = Fehler mit spezifischer Meldung.
     * Wird nur in getInfo() aufgerufen.
     */
    private static function checkHtaccess(
        string $msgOk,
        string $msgMissing,
        string $msgIncompleteHeader,
        string $msgIncompleteBody,
        string $msgRequired
    ): string {
        if (!defined('BASE_DIR')) {
            return '';
        }
        $htaccess = BASE_DIR . '.htaccess';
        if (!file_exists($htaccess)) {
            return '<p style="color:red;font-weight:bold;">' . $msgMissing . '</p>';
        }
        $content = file_get_contents($htaccess);
        if ($content === false) {
            return '';
        }

        $rules = self::parseHtaccessRules($content);

        if (!$rules['hasSitemap'] || !$rules['hasCatchAll']) {
            return '<p style="color:red;font-weight:bold;">' . $msgIncompleteHeader . '</p>'
                . '<p>' . $msgIncompleteBody . '</p>'
                . '<p>' . $msgRequired . '</p>'
                . '<pre style="background:#f4f4f4;padding:8px;font-size:12px;">'
                . 'RewriteRule ^sitemap\.xml$ index.php [L,QSA]' . "\n"
                . 'RewriteCond %{REQUEST_FILENAME} !-f' . "\n"
                . 'RewriteCond %{REQUEST_FILENAME} !-d' . "\n"
                . 'RewriteRule ^(.*)$ index.php [QSA,L]'
                . '</pre>';
        }

        return '<p style="color:green;">' . $msgOk . '</p>';
    }

    // -----------------------------------------------------------------------
    // Eingehende Anfrage verarbeiten
    // -----------------------------------------------------------------------

    private static function handleRequest() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        $qpos = strpos($uri, '?');
        if ($qpos !== false) {
            $uri = substr($uri, 0, $qpos);
        }

        $rawQueryString = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        parse_str($rawQueryString, $rawQueryParams);
        if (isset($rawQueryParams['cat']) || isset($rawQueryParams['page'])) {
            return;
        }

        $hadHtmlSuffix = (bool) preg_match('/\.html$/i', $uri);

        [$catPart, $pagePart] = self::splitPath($uri);

        if ($catPart === null) {
            return;
        }

        if (strtolower($catPart) === 'sitemap.xml') {
            self::rewriteSitemap();
            return;
        }

        if (in_array(strtolower($catPart), self::SYSTEM_PATHS, true)) {
            return;
        }

        $rawCat  = urldecode($catPart);
        $rawPage = $pagePart !== null ? urldecode($pagePart) : null;

        if (self::isSlug($rawCat) && isset(self::$catBySlug[$rawCat])) {
            self::resolveSlugRequest($rawCat, $rawPage, $hadHtmlSuffix);
        } else {
            self::resolveRawCatRequest($rawCat, $rawPage);
        }
    }

    /**
     * Verarbeitet eine eingehende Slug-URL.
     */
    private static function resolveSlugRequest(
        string $rawCat,
        ?string $rawPage,
        bool $hadHtmlSuffix
    ): void {
        if (
            self::$homeCatName !== null && $rawPage === null &&
            self::$catBySlug[$rawCat] === self::$homeCatName
        ) {
            $base = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
            self::redirect($base . '/');
            return;
        }

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
     * Verarbeitet eine eingehende Umlaut/Leerzeichen-URL.
     */
    private static function resolveRawCatRequest(
        string $rawCat,
        ?string $rawPage
    ): void {
        $method  = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $isPost  = ($method === 'POST');
        $isDraft = isset($_GET['draft']) && $_GET['draft'] === 'true';

        if (!$isPost && !$isDraft && isset(self::$catToSlug[$rawCat])) {

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

        if ($isPost && isset(self::$catToSlug[$rawCat])) {
            $_GET['cat'] = isset(self::$catBySlug[self::$catToSlug[$rawCat]])
                ? self::$catBySlug[self::$catToSlug[$rawCat]]
                : $rawCat;
            if ($rawPage !== null && isset(self::$pageToSlug[$rawCat][$rawPage])) {
                $pageSlug = self::$pageToSlug[$rawCat][$rawPage];
                $_GET['page'] = isset(self::$pageBySlug[self::$catToSlug[$rawCat]][$pageSlug])
                    ? self::$pageBySlug[self::$catToSlug[$rawCat]][$pageSlug]
                    : $rawPage;
            } elseif ($rawPage !== null) {
                $_GET['page'] = $rawPage; // Fallback: Raw-Page-Name durchreichen
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
        if ($xml === false) {
            return;
        }

        $currentOrigin = self::getSafeOrigin();

        $xml = preg_replace_callback(
            '|<loc>(https?://[^/]+)(/[^<]*)</loc>|',
            function ($m) use ($currentOrigin) {
                $path = $m[2];

                [$catPart, $pagePart] = self::splitPath($path);

                if ($catPart === null) {
                    return $m[0];
                }

                $catName  = urldecode($catPart);
                $pageName = $pagePart !== null ? urldecode($pagePart) : null;

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

    private static function rewriteOutput(string $html): string {
        $html = preg_replace_callback(
            '/(href|action)=(["\'])(?!https?:\/\/|\/\/|mailto:|tel:|javascript:|data:)([^"\'#?]+(?:\.html|\/))(\\?[^"\'#]*)?(\#[^"\']*)?\2/i',
            [self::class, 'rewriteCallback'],
            $html
        );

        return self::rewriteCanonical($html ?? '');
    }

    /**
     * Korrigiert den <link rel="canonical">-Tag im HTML-Output.
     * stripos()-Vorcheck spart den Regex wenn kein canonical-Tag im Output ist.
     * Limit 1 macht explizit dass pro Seite genau ein Canonical-Tag erwartet wird.
     */
    private static function rewriteCanonical(string $html): string {
        if (self::$resolvedCanonicalPath === null) {
            return $html;
        }
        if (stripos($html, 'canonical') === false) {
            return $html;
        }
        $origin = self::getSafeOrigin();
        if ($origin === '') {
            return $html;
        }
        $base         = defined('URL_BASE') ? rtrim(URL_BASE, '/') : '';
        $canonicalUrl = $origin . $base . self::$resolvedCanonicalPath;

        return preg_replace_callback(
            '~<link\b[^>]*\brel=["\']canonical["\'][^>]*>~i',
            static function () use ($canonicalUrl): string {
                return '<link rel="canonical" href="' .
                    htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') .
                    '">';
            },
            $html,
            1
        );
    }

    /**
     */
    private static function rewriteCallback(array $m): string {
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
        // rewritePath() dekodiert vor dem Split – Aufrufer-Kontext ist bereits HTML-Output
        [$catPart, $pagePart] = self::splitPath(urldecode($path));

        if ($catPart === null) {
            return null;
        }

        if (self::isSlug($catPart)) {
            return null;
        }

        return self::buildSlugUrl($catPart, $pagePart);
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

    public static function slugify(string $text): string {
        // 1. Sicheres Lowercase (mit Fallback, falls mbstring fehlt)
        $text = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
        // 2. Deutsche Umlaute und ß vorab sichern (bevor iconv greift)
        //    iconv würde sonst ä -> a statt ae, ß -> b statt ss machen.
        $germanMap = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $text = str_replace(array_keys($germanMap), array_values($germanMap), $text);
        // 3. iconv-Trick für alle anderen romanischen Akzente (é -> e, ñ -> n, ç -> c).
        //    Kein intl nötig; läuft auf Standard-PHP. IGNORE verhindert Abbruch bei
        //    nicht-konvertierbaren Zeichen. Auf Linux/IONOS (Produktion) liefert iconv
        //    é -> e direkt – der Strip ist dort ein No-op. Auf Windows (Laragon/Entwicklung)
        //    gibt libiconv Akzente als Präfix-Zeichen aus (é -> 'e, è -> `e, ê -> ^e) –
        //    der Strip entfernt diese Artefakte und ist primär für Windows relevant.
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = str_replace(["'", '`', '^', '~', '"'], '', $converted);
            }
        }
        // 4. Bereinigung: Alles außer a-z und 0-9 zu Bindestrichen
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'seite';
    }

    // -----------------------------------------------------------------------
    // Hilfsmethoden
    // -----------------------------------------------------------------------

    /**
     * Zerlegt einen rohen URI-Pfad in [catPart, pagePart].
     * Schneidet URL_BASE ab, entfernt .html, dekodiert NICHT
     * (Dekodierung entscheidet der Aufrufer).
     * @return array{0: ?string, 1: ?string}  catPart=null wenn leer
     */
    private static function splitPath(string $uri): array {
        if (defined('URL_BASE') && URL_BASE !== '/' && strpos($uri, URL_BASE) === 0) {
            $uri = '/' . substr($uri, strlen(URL_BASE));
        }
        $uri   = rtrim(self::stripHtmlSuffix($uri), '/');
        $parts = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));
        return [$parts[0] ?? null, $parts[1] ?? null];
    }

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
