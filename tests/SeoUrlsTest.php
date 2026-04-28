<?php

/**
 * PHPUnit Integrationstests für das seo_urls Plugin.
 * Nur lokal ausführen – nie deployen.
 */

// moziloCMS-Konstanten und Stub-Klassen simulieren
define('IS_CMS', true);
define('EXT_PAGE', 'page');
define('URL_BASE', '/');

// Stub für die moziloCMS Plugin-Basisklasse
class Plugin {
    protected $settings;
    public function __construct() {
    }
}

// Plugin-Klasse laden
require_once __DIR__ . '/../_seo_urls/index.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SeoUrlsTest extends TestCase {
    // -----------------------------------------------------------------------
    // Test-Setup: statische Maps vor jedem Test zurücksetzen
    // -----------------------------------------------------------------------

    protected function setUp(): void {
        self::resetStaticState();

        $_GET    = [];
        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';
        $_SERVER['HTTPS']          = 'on';
        $_SERVER['HTTP_HOST']      = 'www.example.com';
    }

    // -----------------------------------------------------------------------
    // slugify()
    // -----------------------------------------------------------------------

    public function testSlugifyUmlaute(): void {
        $this->assertSame('ueber-uns',       _seo_urls::slugify('Über Uns'));
        $this->assertSame('haeufige-fragen', _seo_urls::slugify('Häufige Fragen'));
        $this->assertSame('oeffentlich',     _seo_urls::slugify('Öffentlich'));
        $this->assertSame('gruesse',         _seo_urls::slugify('Grüße'));
    }

    public function testSlugifyLeerzeichen(): void {
        $this->assertSame('unser-team',  _seo_urls::slugify('Unser Team'));
        $this->assertSame('foo-bar-baz', _seo_urls::slugify('foo   bar   baz'));
    }

    public function testSlugifySonderzeichen(): void {
        $this->assertSame('impressum', _seo_urls::slugify('Impressum!'));
        $this->assertSame('foo-bar',   _seo_urls::slugify('foo & bar'));
    }

    public function testSlugifyFallback(): void {
        $this->assertSame('seite', _seo_urls::slugify('---'));
        $this->assertSame('seite', _seo_urls::slugify('!!!'));
    }

    // -----------------------------------------------------------------------
    // isSlug()
    // -----------------------------------------------------------------------

    #[DataProvider('validSlugProvider')]
    public function testIsSlugValid(string $slug): void {
        $this->assertTrue(
            self::callStatic('isSlug', $slug),
            "'{$slug}' sollte ein gültiger Slug sein"
        );
    }

    public static function validSlugProvider(): array {
        return [
            ['ueber-uns'],
            ['haeufige-fragen'],
            ['abc'],
            ['a1b2c3'],
        ];
    }

    #[DataProvider('invalidSlugProvider')]
    public function testIsSlugInvalid(string $slug): void {
        $this->assertFalse(
            self::callStatic('isSlug', $slug),
            "'{$slug}' sollte kein gültiger Slug sein"
        );
    }

    public static function invalidSlugProvider(): array {
        return [
            ['Über Uns'],
            ['-ueber-uns'],
            ['ueber-uns-'],
            ['ueber uns'],
            [''],
        ];
    }

    // -----------------------------------------------------------------------
    // stripHtmlSuffix()
    // -----------------------------------------------------------------------

    public function testStripHtmlSuffixEntferntEndung(): void {
        $this->assertSame('/ueber-uns', self::callStatic('stripHtmlSuffix', '/ueber-uns.html'));
        $this->assertSame('/kontakt',   self::callStatic('stripHtmlSuffix', '/kontakt.HTML'));
        $this->assertSame('/kontakt',   self::callStatic('stripHtmlSuffix', '/kontakt.Html'));
    }

    public function testStripHtmlSuffixOhneEndungUnveraendert(): void {
        $this->assertSame('/ueber-uns/', self::callStatic('stripHtmlSuffix', '/ueber-uns/'));
        $this->assertSame('/kontakt',    self::callStatic('stripHtmlSuffix', '/kontakt'));
        $this->assertSame('/',           self::callStatic('stripHtmlSuffix', '/'));
    }

    public function testStripHtmlSuffixNurAmEnde(): void {
        $this->assertSame(
            '/html-seite/kontakt',
            self::callStatic('stripHtmlSuffix', '/html-seite/kontakt')
        );
    }

    // -----------------------------------------------------------------------
    // makeUnique()
    // -----------------------------------------------------------------------

    public function testMakeUniqueOhneKollision(): void {
        $existing = ['kontakt' => true, 'impressum' => true];
        $result   = self::callStatic('makeUnique', 'ueber-uns', $existing);
        $this->assertSame('ueber-uns', $result);
    }

    public function testMakeUniqueErsteKollision(): void {
        $existing = ['ueber-uns' => true];
        $result   = self::callStatic('makeUnique', 'ueber-uns', $existing);
        $this->assertSame('ueber-uns-2', $result);
    }

    public function testMakeUniqueMehrfacheKollision(): void {
        $existing = [
            'ueber-uns'   => true,
            'ueber-uns-2' => true,
            'ueber-uns-3' => true,
        ];
        $result = self::callStatic('makeUnique', 'ueber-uns', $existing);
        $this->assertSame('ueber-uns-4', $result);
    }

    public function testMakeUniqueLeereExistingMap(): void {
        $result = self::callStatic('makeUnique', 'ueber-uns', []);
        $this->assertSame('ueber-uns', $result);
    }

    // -----------------------------------------------------------------------
    // getSafeOrigin()
    // -----------------------------------------------------------------------

    public function testGetSafeOriginGueltigerHost(): void {
        $_SERVER['HTTPS']     = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.com';

        $result = self::callStatic('getSafeOrigin');
        $this->assertSame('https://www.example.com', $result);
    }

    public function testGetSafeOriginHttp(): void {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'www.example.com';

        $result = self::callStatic('getSafeOrigin');
        $this->assertSame('http://www.example.com', $result);
    }

    public function testGetSafeOriginMitPort(): void {
        $_SERVER['HTTPS']     = 'on';
        $_SERVER['HTTP_HOST'] = 'www.example.com:8080';

        $result = self::callStatic('getSafeOrigin');
        $this->assertSame('https://www.example.com:8080', $result);
    }

    public function testGetSafeOriginUngueltigerHostGibtLeerstring(): void {
        $_SERVER['HTTPS']     = 'on';
        $_SERVER['HTTP_HOST'] = 'evil.com/inject';

        $result = self::callStatic('getSafeOrigin');
        $this->assertSame('', $result);
    }

    public function testGetSafeOriginFehlenderHostGibtLeerstring(): void {
        unset($_SERVER['HTTP_HOST']);

        $result = self::callStatic('getSafeOrigin');
        $this->assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // buildSlugUrl()
    // -----------------------------------------------------------------------

    public function testBuildSlugUrlNurKategorie(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $result = _seo_urls::buildSlugUrl('Über Uns', null);
        $this->assertSame('/ueber-uns/', $result);
    }

    public function testBuildSlugUrlKategorieUndSeite(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap(
            'ueber-uns',
            'Über%20Uns',
            ['unser-team' => 'Unser%20Team']
        );

        $result = _seo_urls::buildSlugUrl('Über Uns', 'Unser Team');
        $this->assertSame('/ueber-uns/unser-team/', $result);
    }

    public function testBuildSlugUrlUnbekannteKategorieGibtNull(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns']
        );

        $result = _seo_urls::buildSlugUrl('Nicht Vorhanden', null);
        $this->assertNull($result);
    }

    public function testBuildSlugUrlUnbekannteSeitGibtNull(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $result = _seo_urls::buildSlugUrl('Über Uns', 'Nicht Vorhanden');
        $this->assertNull($result);
    }

    public function testBuildSlugUrlMitKodiertemKategorienamen(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $result = _seo_urls::buildSlugUrl('Über%20Uns', null);
        $this->assertSame('/ueber-uns/', $result);
    }

    // -----------------------------------------------------------------------
    // rewriteOutput() – ?action=sitemap
    // -----------------------------------------------------------------------

    public function testRewriteOutputSitemapActionLinkBleibtUnveraendert(): void {
        $html   = '<a href="/?action=sitemap">Sitemap</a>';
        $result = _seo_urls::rewriteOutput($html);
        $this->assertSame($html, $result);
    }

    public function testRewriteOutputSlugUrlMitQueryParameterBleibtErhalten(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $html   = '<a href="/ueber-uns/?action=sitemap">Sitemap</a>';
        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString('href="/ueber-uns/?action=sitemap"', $result);
    }

    public function testRewriteOutputUmlautHrefWirdZuSlugUmgeschrieben(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $html   = '<a href="/Über Uns/">Über Uns</a>';
        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString('href="/ueber-uns/"', $result);
    }

    public function testRewriteOutputMehrereLinksWerdenAlleUmgeschrieben(): void {
        self::injectCatMap(
            [
                'ueber-uns' => 'Über%20Uns',
                'kontakt'   => 'Kontakt',
            ],
            [
                'Über Uns'   => 'ueber-uns',
                'Über%20Uns' => 'ueber-uns',
                'Kontakt'    => 'kontakt',
            ]
        );

        $html = implode("\n", [
            '<a href="/Über Uns/">Über Uns</a>',
            '<a href="/Kontakt/">Kontakt</a>',
            '<a href="/?action=sitemap">Sitemap</a>',
            '<a href="https://extern.de/">Extern</a>',
        ]);

        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString('href="/ueber-uns/"',       $result);
        $this->assertStringContainsString('href="/kontakt/"',          $result);
        $this->assertStringContainsString('href="/?action=sitemap"',   $result);
        $this->assertStringContainsString('href="https://extern.de/"', $result);
    }

    // -----------------------------------------------------------------------
    // rewriteOutput() – Canonical-Tag
    // -----------------------------------------------------------------------

    public function testRewriteOutputCanonicalWirdKorrigiert(): void {
        self::setStaticProp('resolvedCanonicalPath', '/kontakt/');

        $html   = '<link rel="canonical" href="https://www.example.com/Kontakt/Anfahrt.html">';
        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString(
            'href="https://www.example.com/kontakt/"',
            $result
        );
    }

    public function testRewriteOutputCanonicalBleibtOhneResolvedPath(): void {
        $html   = '<link rel="canonical" href="https://www.example.com/Kontakt/Anfahrt.html">';
        $result = _seo_urls::rewriteOutput($html);
        $this->assertSame($html, $result);
    }

    public function testRewriteOutputCanonicalAttributreihenfolgeEgal(): void {
        self::setStaticProp('resolvedCanonicalPath', '/ueber-uns/');

        $html   = '<link href="https://www.example.com/altes-ziel/" rel="canonical">';
        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString(
            'href="https://www.example.com/ueber-uns/"',
            $result
        );
    }

    public function testRewriteOutputCanonicalUnterseite(): void {
        self::setStaticProp('resolvedCanonicalPath', '/ueber-uns/unser-team/');

        $html   = '<link rel="canonical" href="https://www.example.com/irgendwas/">';
        $result = _seo_urls::rewriteOutput($html);

        $this->assertStringContainsString(
            'href="https://www.example.com/ueber-uns/unser-team/"',
            $result
        );
    }

    // -----------------------------------------------------------------------
    // handleRequest() – HTTP GET Redirects (301)
    // -----------------------------------------------------------------------

    public function testHandleRequestGetUmlautUrlRedirectsAufSlug(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_URI'] = '/Über Uns/';

        [$url, $code] = self::captureRedirect(function () {
            self::callStatic('handleRequest');
        });

        $this->assertSame('/ueber-uns/', $url);
        $this->assertSame(301, $code);
    }

    public function testHandleRequestGetUmlautUrlMitUnterseiteRedirects(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap(
            'ueber-uns',
            'Über%20Uns',
            ['unser-team' => 'Unser%20Team']
        );

        $_SERVER['REQUEST_URI'] = '/Über Uns/Unser Team/';

        [$url, $code] = self::captureRedirect(function () {
            self::callStatic('handleRequest');
        });

        $this->assertSame('/ueber-uns/unser-team/', $url);
        $this->assertSame(301, $code);
    }

    public function testHandleRequestGetHtmlSuffixRedirectsAufSlug(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_URI'] = '/ueber-uns.html';

        [$url, $code] = self::captureRedirect(function () {
            self::callStatic('handleRequest');
        });

        $this->assertSame('/ueber-uns/', $url);
        $this->assertSame(301, $code);
    }

    public function testHandleRequestGetHomepageSlugRedirectsAufRoot(): void {
        self::injectCatMap(
            ['startseite' => 'Startseite'],
            ['Startseite' => 'startseite']
        );
        self::setStaticProp('homeCatName', 'Startseite');

        $_SERVER['REQUEST_URI'] = '/startseite/';

        [$url, $code] = self::captureRedirect(function () {
            self::callStatic('handleRequest');
        });

        $this->assertSame('/', $url);
        $this->assertSame(301, $code);
    }

    public function testHandleRequestGetHomepageRohnameRedirectsAufRoot(): void {
        self::injectCatMap(
            ['startseite' => 'Startseite'],
            ['Startseite' => 'startseite']
        );
        self::setStaticProp('homeCatName', 'Startseite');

        $_SERVER['REQUEST_URI'] = '/Startseite/';

        [$url, $code] = self::captureRedirect(function () {
            self::callStatic('handleRequest');
        });

        $this->assertSame('/', $url);
        $this->assertSame(301, $code);
    }

    public function testHandleRequestGetDraftModusVerhindertRedirect(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_GET['draft']          = 'true';
        $_SERVER['REQUEST_URI'] = '/Über Uns/';

        $redirectCalled = false;
        self::setStaticProp('redirector', function () use (&$redirectCalled) {
            $redirectCalled = true;
        });

        self::callStatic('handleRequest');

        $this->assertFalse($redirectCalled, 'Im Draft-Modus darf kein Redirect ausgelöst werden');
    }

    // -----------------------------------------------------------------------
    // handleRequest() – HTTP GET (kein Redirect)
    // -----------------------------------------------------------------------

    public function testHandleRequestGetSlugSetzGetCat(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_URI'] = '/ueber-uns/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns', $_GET['cat'] ?? null);
        $this->assertArrayNotHasKey('page', $_GET);
    }

    public function testHandleRequestGetSlugMitUnterseiteSetzGetCatUndPage(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap(
            'ueber-uns',
            'Über%20Uns',
            ['unser-team' => 'Unser%20Team']
        );

        $_SERVER['REQUEST_URI'] = '/ueber-uns/unser-team/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns',   $_GET['cat']  ?? null);
        $this->assertSame('Unser%20Team', $_GET['page'] ?? null);
    }

    public function testHandleRequestGetSetzCanonicalPathFuerKategorie(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_URI'] = '/ueber-uns/';

        self::callStatic('handleRequest');

        $this->assertSame('/ueber-uns/', self::getStaticProp('resolvedCanonicalPath'));
    }

    public function testHandleRequestGetSetzCanonicalPathFuerUnterseite(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap(
            'ueber-uns',
            'Über%20Uns',
            ['unser-team' => 'Unser%20Team']
        );

        $_SERVER['REQUEST_URI'] = '/ueber-uns/unser-team/';

        self::callStatic('handleRequest');

        $this->assertSame('/ueber-uns/unser-team/', self::getStaticProp('resolvedCanonicalPath'));
    }

    public function testHandleRequestGetSystempfadWirdIgnoriert(): void {
        $_SERVER['REQUEST_URI'] = '/admin/';

        self::callStatic('handleRequest');

        $this->assertArrayNotHasKey('cat',  $_GET);
        $this->assertArrayNotHasKey('page', $_GET);
    }

    public function testHandleRequestGetRootUrlWirdIgnoriert(): void {
        $_SERVER['REQUEST_URI'] = '/';

        self::callStatic('handleRequest');

        $this->assertArrayNotHasKey('cat',  $_GET);
        $this->assertArrayNotHasKey('page', $_GET);
    }

    public function testHandleRequestGetDraftModusKeinRedirect(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_GET['draft']          = 'true';
        $_SERVER['REQUEST_URI'] = '/ueber-uns/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns', $_GET['cat'] ?? null);
    }

    // -----------------------------------------------------------------------
    // handleRequest() – HTTP POST
    // -----------------------------------------------------------------------

    public function testHandleRequestPostSetzGetCatOhneRedirect(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/Über Uns/';

        self::callStatic('handleRequest');

        $this->assertArrayHasKey('cat', $_GET);
        $this->assertSame('Über%20Uns', $_GET['cat']);
    }

    public function testHandleRequestPostSetzGetCatUndPage(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap(
            'ueber-uns',
            'Über%20Uns',
            ['unser-team' => 'Unser%20Team']
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/Über Uns/Unser Team/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns',   $_GET['cat']  ?? null);
        $this->assertSame('Unser%20Team', $_GET['page'] ?? null);
    }

    public function testHandleRequestPostAufSlugUrlSetzGetCat(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/ueber-uns/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns', $_GET['cat'] ?? null);
    }

    /**
     * Wenn moziloCMS-Parameter bereits im Query-String stehen
     * (z.B. ?cat=Bungalow&page=114&action=114), darf das Plugin
     * nicht eingreifen – sonst gehen die Parameter beim Redirect verloren.
     */
    public function testHandleRequestGetMoziloCmsQueryParamsWerdenIgnoriert(): void {
        self::injectCatMap(
            ['bungalow' => 'Bungalow'],
            ['Bungalow' => 'bungalow']
        );

        $_GET['cat']    = 'Bungalow';
        $_GET['page']   = '114';
        $_SERVER['REQUEST_URI'] = '/Bungalow/Buchung%20Bungalow%201.html?cat=Bungalow&page=114&action=114';

        $redirectCalled = false;
        self::setStaticProp('redirector', function () use (&$redirectCalled) {
            $redirectCalled = true;
        });

        self::callStatic('handleRequest');

        $this->assertFalse(
            $redirectCalled,
            'Kein Redirect wenn moziloCMS-Parameter bereits gesetzt sind'
        );
        $this->assertSame(
            'Bungalow',
            $_GET['cat'],
            '$_GET["cat"] darf nicht überschrieben werden'
        );
    }

    // -----------------------------------------------------------------------
    // Reflection- und Injektions-Hilfsmethoden
    // -----------------------------------------------------------------------

    private static function callStatic(string $method, mixed ...$args): mixed {
        $ref = new ReflectionMethod('_seo_urls', $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    private static function setStaticProp(string $prop, mixed $value): void {
        $ref = new ReflectionProperty('_seo_urls', $prop);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }

    private static function getStaticProp(string $prop): mixed {
        $ref = new ReflectionProperty('_seo_urls', $prop);
        $ref->setAccessible(true);
        return $ref->getValue(null);
    }

    private static function resetStaticState(): void {
        self::setStaticProp('catBySlug',             []);
        self::setStaticProp('catToSlug',             []);
        self::setStaticProp('pageBySlug',            []);
        self::setStaticProp('pageToSlug',            []);
        self::setStaticProp('mapsBuilt',             false);
        self::setStaticProp('homeCatName',           null);
        self::setStaticProp('resolvedCanonicalPath', null);
        self::setStaticProp('redirector',            null);
    }

    private static function injectCatMap(array $catBySlug, array $catToSlug): void {
        self::setStaticProp('catBySlug', $catBySlug);
        self::setStaticProp('catToSlug', $catToSlug);
        self::setStaticProp('mapsBuilt', true);
    }

    private static function injectPageMap(string $catSlug, string $catNameEnc, array $pages): void {
        self::setStaticProp('pageBySlug', [$catSlug => $pages]);

        $pageToSlug = [];
        foreach ($pages as $pageSlug => $pageNameEnc) {
            $pageNameDec = urldecode($pageNameEnc);
            $pageToSlug[$catNameEnc][$pageNameEnc]            = $pageSlug;
            $pageToSlug[$catNameEnc][$pageNameDec]            = $pageSlug;
            $pageToSlug[urldecode($catNameEnc)][$pageNameEnc] = $pageSlug;
            $pageToSlug[urldecode($catNameEnc)][$pageNameDec] = $pageSlug;
        }
        self::setStaticProp('pageToSlug', $pageToSlug);
    }

    /**
     * Führt eine Callable aus und fängt den ersten Redirect-Aufruf ab.
     * Gibt [url, code] zurück oder [null, null] wenn kein Redirect ausgelöst wurde.
     */
    private static function captureRedirect(callable $fn): array {
        $capturedUrl  = null;
        $capturedCode = null;

        self::setStaticProp('redirector', function (string $url, int $code) use (&$capturedUrl, &$capturedCode) {
            $capturedUrl  = $url;
            $capturedCode = $code;
        });

        $fn();

        return [$capturedUrl, $capturedCode];
    }
}
