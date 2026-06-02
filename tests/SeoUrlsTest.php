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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

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

    /**
     * Großakzente müssen korrekt transliteriert werden (Bug in v1.3.2 und früher:
     * mb_strtolower() lief nach str_replace(), Großakzente wie É, À wurden
     * von der Map nicht erfasst und von preg_replace() weggeworfen).
     */
    public function testSlugifyGrossAkzente(): void {
        $this->assertSame('cafe',        _seo_urls::slugify('CAFÉ'));
        $this->assertSame('a-la-carte',  _seo_urls::slugify('À LA CARTE'));
        $this->assertSame('eclair',      _seo_urls::slugify('ÉCLAIR'));
        $this->assertSame('resume',      _seo_urls::slugify('RÉSUMÉ'));
    }

    public function testSlugifyGrossUmlaute(): void {
        $this->assertSame('ueber-uns',   _seo_urls::slugify('ÜBER UNS'));
        $this->assertSame('oeffentlich', _seo_urls::slugify('ÖFFENTLICH'));
        $this->assertSame('uebung',      _seo_urls::slugify('ÜBUNG'));
        $this->assertSame('strasse',     _seo_urls::slugify('STRASSE'));
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

    /**
     * iconv-Pfad: ß via germanMap, romanische Akzente und nordische Zeichen via iconv.
     * Ångström: ö→oe (germanMap greift zuerst), å→a (iconv) → angstroem.
     */
    public function testSlugifyIconv(): void {
        $this->assertSame('fussball',  _seo_urls::slugify('Fußball'));
        $this->assertSame('strasse',   _seo_urls::slugify('Straße'));
        $this->assertSame('cafe',      _seo_urls::slugify('café'));
        $this->assertSame('angstroem', _seo_urls::slugify('Ångström'));
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
        $result = self::callStatic('rewriteOutput', $html);
        $this->assertSame($html, $result);
    }

    public function testRewriteOutputSlugUrlMitQueryParameterBleibtErhalten(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $html   = '<a href="/ueber-uns/?action=sitemap">Sitemap</a>';
        $result = self::callStatic('rewriteOutput', $html);

        $this->assertStringContainsString('href="/ueber-uns/?action=sitemap"', $result);
    }

    public function testRewriteOutputUmlautHrefWirdZuSlugUmgeschrieben(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );

        $html   = '<a href="/Über Uns/">Über Uns</a>';
        $result = self::callStatic('rewriteOutput', $html);

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

        $result = self::callStatic('rewriteOutput', $html);

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
        $result = self::callStatic('rewriteOutput', $html);

        $this->assertStringContainsString(
            'href="https://www.example.com/kontakt/"',
            $result
        );
    }

    public function testRewriteOutputCanonicalBleibtOhneResolvedPath(): void {
        $html   = '<link rel="canonical" href="https://www.example.com/Kontakt/Anfahrt.html">';
        $result = self::callStatic('rewriteOutput', $html);
        $this->assertSame($html, $result);
    }

    public function testRewriteOutputCanonicalAttributreihenfolgeEgal(): void {
        self::setStaticProp('resolvedCanonicalPath', '/ueber-uns/');

        $html   = '<link href="https://www.example.com/altes-ziel/" rel="canonical">';
        $result = self::callStatic('rewriteOutput', $html);

        $this->assertStringContainsString(
            'href="https://www.example.com/ueber-uns/"',
            $result
        );
    }

    public function testRewriteOutputCanonicalUnterseite(): void {
        self::setStaticProp('resolvedCanonicalPath', '/ueber-uns/unser-team/');

        $html   = '<link rel="canonical" href="https://www.example.com/irgendwas/">';
        $result = self::callStatic('rewriteOutput', $html);

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
     * POST, Kategorie bekannt, Seite nicht in Map → $_GET['page'] = rawPage (Fallback, F4).
     */
    public function testHandleRequestPostUnbekannteSeiteSetzGetPageFallback(): void {
        self::injectCatMap(
            ['ueber-uns' => 'Über%20Uns'],
            ['Über Uns' => 'ueber-uns', 'Über%20Uns' => 'ueber-uns']
        );
        self::injectPageMap('ueber-uns', 'Über%20Uns', []); // leere Page-Map → Seite unbekannt

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/Über Uns/Unbekannte Seite/';

        self::callStatic('handleRequest');

        $this->assertSame('Über%20Uns',       $_GET['cat']  ?? null);
        $this->assertSame('Unbekannte Seite', $_GET['page'] ?? null,
            'Fallback: Raw-Page-Name wird durchgereicht wenn nicht in Slug-Map');
    }

    /**
     * POST, Kategorie unbekannt → $_GET bleibt unverändert (Regression F4).
     */
    public function testHandleRequestPostUnbekannteCatKeinGetGesetzt(): void {
        self::injectCatMap([], []); // leere Map

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/Unbekannte-Kat/Unbekannte-Seite/';

        self::callStatic('handleRequest');

        $this->assertArrayNotHasKey('cat',  $_GET, '$_GET[cat] darf nicht gesetzt sein');
        $this->assertArrayNotHasKey('page', $_GET, '$_GET[page] darf nicht gesetzt sein');
    }

    /**
     * Wenn moziloCMS-Parameter bereits im Query-String stehen
     * (z.B. ?cat=Bungalow&page=114&action=114), darf das Plugin
     * nicht eingreifen – sonst gehen die Parameter beim Redirect verloren.
     */
    public function testHandleRequestGetMoziloCmsQueryParamsWerdenIgnoriert(): void {
        self::injectCatMap(
            ['kategorie' => 'Kategorie'],
            ['Kategorie' => 'kategorie']
        );

        // Query-String direkt in QUERY_STRING setzen – so wie Apache es tut
        $_SERVER['QUERY_STRING'] = 'cat=Kategorie&page=114&action=114';
        $_SERVER['REQUEST_URI']  = '/Kategorie/Seite%20Name.html?cat=Kategorie&page=114&action=114';

        $redirectCalled = false;
        self::setStaticProp('redirector', function () use (&$redirectCalled) {
            $redirectCalled = true;
        });

        self::callStatic('handleRequest');

        $this->assertFalse(
            $redirectCalled,
            'Kein Redirect wenn moziloCMS-Parameter bereits im Query-String stehen'
        );
    }

    public function testHandleRequestGetSlugOhneMoziloCmsQueryParamsWirdVerarbeitet(): void {
        self::injectCatMap(
            ['kontakt' => 'Kontakt'],
            ['Kontakt' => 'kontakt']
        );
        self::injectPageMap('kontakt', 'Kontakt', []);

        // Kein cat/page im Query-String – normaler Seitenaufruf
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_URI']  = '/kontakt/';

        self::callStatic('handleRequest');

        $this->assertSame(
            'Kontakt',
            $_GET['cat'] ?? null,
            '$_GET["cat"] muss korrekt gesetzt werden'
        );
    }

    // -----------------------------------------------------------------------
    // parseHtaccessRules()
    // -----------------------------------------------------------------------

    /**
     * Hilfsmethode: erzeugt eine .htaccess-Beispielinhalt mit allen Regeln.
     */
    private static function htaccessFull(): string {
        return implode("\n", [
            'RewriteEngine On',
            'RewriteRule ^sitemap\.xml$ index.php [L,QSA]',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^(.*)$ index.php [QSA,L]',
        ]);
    }

    public function testParseHtaccessRulesAlleRegelnVorhanden(): void {
        $rules = self::callStatic('parseHtaccessRules', self::htaccessFull());
        $this->assertTrue($rules['hasSitemap']);
        $this->assertTrue($rules['hasCatchAll']);
    }

    public function testParseHtaccessRulesSitemapFehlt(): void {
        $content = implode("\n", [
            'RewriteEngine On',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^(.*)$ index.php [QSA,L]',
        ]);
        $rules = self::callStatic('parseHtaccessRules', $content);
        $this->assertFalse($rules['hasSitemap']);
        $this->assertTrue($rules['hasCatchAll']);
    }

    public function testParseHtaccessRulesSitemapAuskommentiert(): void {
        $content = implode("\n", [
            'RewriteEngine On',
            '# RewriteRule ^sitemap\.xml$ index.php [L,QSA]',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^(.*)$ index.php [QSA,L]',
        ]);
        $rules = self::callStatic('parseHtaccessRules', $content);
        $this->assertFalse(
            $rules['hasSitemap'],
            'Auskommentierte Sitemap-Regel darf nicht als aktiv erkannt werden'
        );
        $this->assertTrue($rules['hasCatchAll']);
    }

    public function testParseHtaccessRulesCatchAllFehlt(): void {
        $content = implode("\n", [
            'RewriteEngine On',
            'RewriteRule ^sitemap\.xml$ index.php [L,QSA]',
        ]);
        $rules = self::callStatic('parseHtaccessRules', $content);
        $this->assertTrue($rules['hasSitemap']);
        $this->assertFalse($rules['hasCatchAll']);
    }

    public function testParseHtaccessRulesEineRewriteCondAuskommentiert(): void {
        $content = implode("\n", [
            'RewriteEngine On',
            'RewriteRule ^sitemap\.xml$ index.php [L,QSA]',
            '# RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule ^(.*)$ index.php [QSA,L]',
        ]);
        $rules = self::callStatic('parseHtaccessRules', $content);
        $this->assertTrue($rules['hasSitemap']);
        $this->assertFalse(
            $rules['hasCatchAll'],
            'Catch-All darf nicht als aktiv gelten wenn eine RewriteCond auskommentiert ist'
        );
    }

    public function testParseHtaccessRulesAllesFehlt(): void {
        $content = 'RewriteEngine On';
        $rules   = self::callStatic('parseHtaccessRules', $content);
        $this->assertFalse($rules['hasSitemap']);
        $this->assertFalse($rules['hasCatchAll']);
    }

    public function testIsHtaccessValidMitAllenRegeln(): void {
        // parseHtaccessRules() direkt testen da isHtaccessValid()
        // auf das Dateisystem zugreift
        $rules = self::callStatic('parseHtaccessRules', self::htaccessFull());
        $this->assertTrue($rules['hasSitemap'] && $rules['hasCatchAll']);
    }

    public function testIsHtaccessValidMitFehlendenRegeln(): void {
        $rules = self::callStatic('parseHtaccessRules', 'RewriteEngine On');
        $this->assertFalse($rules['hasSitemap'] && $rules['hasCatchAll']);
    }

    public function testIsHtaccessValidCacheBefuelltNachErstemAufruf(): void {
        // In Testumgebung: BASE_DIR nicht definiert → sofort true ohne Dateizugriff
        $this->assertNull(self::getStaticProp('htaccessValidCache'), 'Cache initial null');
        $result = self::callStatic('isHtaccessValid');
        $this->assertTrue($result);
        $this->assertTrue(self::getStaticProp('htaccessValidCache'), 'Cache nach Aufruf befüllt');
    }

    public function testIsHtaccessValidCacheWirdDurchResetZurueckgesetzt(): void {
        self::callStatic('isHtaccessValid');
        $this->assertNotNull(self::getStaticProp('htaccessValidCache'));
        self::resetStaticState();
        $this->assertNull(self::getStaticProp('htaccessValidCache'), 'Cache nach Reset null');
    }

    /**
     * Beweist dass isHtaccessValid() die Datei nur einmal pro Request liest:
     * Nach dem ersten Aufruf (Datei vorhanden und valide) wird die .htaccess
     * gelöscht – der zweite Aufruf muss dennoch true liefern (aus Cache).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsHtaccessValidLiestDateiNurEinmalProRequest(): void {
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo_urls_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($tmpBase);
        file_put_contents($tmpBase . '.htaccess', self::htaccessFull());
        define('BASE_DIR', $tmpBase);

        $result1 = self::callStatic('isHtaccessValid');
        $this->assertTrue($result1, 'Erster Aufruf: gültige .htaccess → true');

        unlink($tmpBase . '.htaccess'); // Datei weg – zweiter Aufruf darf nicht re-lesen

        $result2 = self::callStatic('isHtaccessValid');
        $this->assertTrue($result2, 'Zweiter Aufruf: Cache liefert true, nicht false (Datei fehlt)');

        rmdir($tmpBase);
    }

    // -----------------------------------------------------------------------
    // rewriteOutput() – Lookahead-Ausschlüsse
    // -----------------------------------------------------------------------

    /**
     * Protokoll-relative URLs (//cdn.example.com/…) dürfen nicht angefasst werden.
     */
    public function testRewriteOutputIgnoriertProtokollRelativeUrls(): void {
        self::injectCatMap(['ueber-uns' => '%C3%9Cber%20Uns'], ['Über Uns' => 'ueber-uns']);
        $html   = '<a href="//cdn.example.com/bild/">Bild</a>';
        $result = self::callStatic('rewriteOutput', $html);
        $this->assertSame($html, $result, 'Protokoll-relative URLs dürfen nicht umgeschrieben werden');
    }

    /**
     * javascript:-Links dürfen nicht angefasst werden.
     */
    public function testRewriteOutputIgnoriertJavascriptLinks(): void {
        self::injectCatMap(['ueber-uns' => '%C3%9Cber%20Uns'], ['Über Uns' => 'ueber-uns']);
        $html   = '<a href="javascript:void(0)/">Klick</a>';
        $result = self::callStatic('rewriteOutput', $html);
        $this->assertSame($html, $result, 'javascript:-Links dürfen nicht umgeschrieben werden');
    }

    /**
     * data:-URIs in action-Attributen dürfen nicht angefasst werden.
     */
    public function testRewriteOutputIgnoriertDataUris(): void {
        self::injectCatMap(['ueber-uns' => '%C3%9Cber%20Uns'], ['Über Uns' => 'ueber-uns']);
        $html   = '<form action="data:text/plain,foo/">';
        $result = self::callStatic('rewriteOutput', $html);
        $this->assertSame($html, $result, 'data:-URIs dürfen nicht umgeschrieben werden');
    }


    // -----------------------------------------------------------------------
    // splitPath()
    // -----------------------------------------------------------------------

    public function testSplitPathKatUndSeite(): void {
        $result = self::callStatic('splitPath', '/ueber-uns/team/');
        $this->assertSame(['ueber-uns', 'team'], $result);
    }

    public function testSplitPathNurKategorie(): void {
        $result = self::callStatic('splitPath', '/ueber-uns/');
        $this->assertSame(['ueber-uns', null], $result);
    }

    public function testSplitPathMitHtmlSuffix(): void {
        $result = self::callStatic('splitPath', '/ueber-uns/team.html');
        $this->assertSame(['ueber-uns', 'team'], $result);
    }

    /**
     * URL_BASE-Stripping ist im Test-Harness nicht prüfbar:
     * URL_BASE ist als PHP-Konstante '/' definiert (define() im Bootstrap)
     * und kann in PHP nicht neu gesetzt werden – der Branch
     * `URL_BASE !== '/'` ist im Test-Kontext nie aktiv.
     */
    public function testSplitPathUrlBaseWirdAbgeschnitten(): void {
        $this->markTestSkipped(
            'URL_BASE ist im Test-Harness als Konstante \'/\' definiert ' .
            'und kann in PHP nicht neu gesetzt werden.'
        );
    }

    public function testSplitPathLeererPfad(): void {
        $result = self::callStatic('splitPath', '/');
        $this->assertSame([null, null], $result);
    }

    public function testSplitPathKodiertZeichenWerdenNichtDekodiert(): void {
        $result = self::callStatic('splitPath', '/ueber-uns/unser%20team/');
        $this->assertSame(['ueber-uns', 'unser%20team'], $result);
    }

    // -----------------------------------------------------------------------
    // dumpDebugMap() – Regressionsschutz
    // -----------------------------------------------------------------------

    /**
     * dumpDebugMap() enthält echo + exit und ist nicht direkt unit-testbar.
     * Regressionsschutz: getPluginContent() mit einem Nicht-'plugin_first'-Wert
     * gibt '' zurück – der seo_debug-Zweig wird nie erreicht,
     * kein exit() bricht den Test-Runner ab.
     */
    public function testGetPluginContentGibtLeerZurueckFuerNichtPluginFirst(): void {
        $plugin = new _seo_urls();
        $this->assertSame('', $plugin->getPluginContent(''));
        $this->assertSame('', $plugin->getPluginContent('other_value'));
    }

    // -----------------------------------------------------------------------
    // applyMetaKeywordsDescription() – RunInSeparateProcess wegen BASE_DIR-Konstante
    // -----------------------------------------------------------------------

    /**
     * Korrupter $raw-String → kein Fatal Error, kein Meta-Tag gesetzt.
     * Testet graceful degradation: unserialize schlägt fehl (try-catch oder is_array-Guard),
     * $template bleibt unverändert.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplyMetaKeywordsDescriptionKorrupteKonfiguration(): void {
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo_urls_' . uniqid() . DIRECTORY_SEPARATOR;
        $confDir = $tmpBase . 'plugins' . DIRECTORY_SEPARATOR . 'MetaKeywordsDescription' . DIRECTORY_SEPARATOR;
        mkdir($confDir, 0777, true);
        // "a:" vorhanden damit strpos() passt – der Rest ist absichtlich korrupt
        file_put_contents($confDir . 'plugin.conf.php', "<?php die();\na:KORRUPT{{{");
        define('BASE_DIR', $tmpBase);

        global $template;
        $template = '{WEBSITE_DESCRIPTION}';
        $_GET = ['cat' => 'Kontakt', 'page' => 'team'];

        $ref = new \ReflectionMethod('_seo_urls', 'applyMetaKeywordsDescription');
        $ref->setAccessible(true);
        // unserialize() emittiert E_WARNING bei korruptem Input (kein Exception).
        // Im Test unterdrücken – in Production ist das Warning im Error-Log erwünscht.
        set_error_handler(static fn() => true, E_WARNING);
        $ref->invoke(null);
        restore_error_handler();

        $this->assertSame('{WEBSITE_DESCRIPTION}', $template, 'Kein Meta-Tag bei korrupter conf');

        unlink($confDir . 'plugin.conf.php');
        rmdir($confDir);
        rmdir($tmpBase . 'plugins');
        rmdir($tmpBase);
    }

    /**
     * Valider $raw-String → {WEBSITE_DESCRIPTION} wird korrekt ersetzt.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplyMetaKeywordsDescriptionValideKonfiguration(): void {
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo_urls_' . uniqid() . DIRECTORY_SEPARATOR;
        $confDir = $tmpBase . 'plugins' . DIRECTORY_SEPARATOR . 'MetaKeywordsDescription' . DIRECTORY_SEPARATOR;
        mkdir($confDir, 0777, true);

        $cat  = 'Kontakt';
        $page = 'team';
        $key  = '@=' . $cat . ':' . $page . '=@';
        $conf = [$key => ['description' => 'Meine Beschreibung', 'keywords' => 'php,test']];
        file_put_contents($confDir . 'plugin.conf.php', "<?php die();\n" . serialize($conf));
        define('BASE_DIR', $tmpBase);

        global $template;
        $template = 'VOR {WEBSITE_DESCRIPTION} NACH';
        $_GET = ['cat' => $cat, 'page' => $page];

        $ref = new \ReflectionMethod('_seo_urls', 'applyMetaKeywordsDescription');
        $ref->setAccessible(true);
        $ref->invoke(null);

        $this->assertSame('VOR Meine Beschreibung NACH', $template, 'Beschreibung korrekt ersetzt');

        unlink($confDir . 'plugin.conf.php');
        rmdir($confDir);
        rmdir($tmpBase . 'plugins');
        rmdir($tmpBase);
    }

    /**
     * $template = null → kein TypeError, kein str_replace-Aufruf, $template bleibt null.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplyMetaKeywordsDescriptionTemplateNullKeinFehler(): void {
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo_urls_' . uniqid() . DIRECTORY_SEPARATOR;
        $confDir = $tmpBase . 'plugins' . DIRECTORY_SEPARATOR . 'MetaKeywordsDescription' . DIRECTORY_SEPARATOR;
        mkdir($confDir, 0777, true);

        $cat  = 'Kontakt';
        $page = 'team';
        $key  = '@=' . $cat . ':' . $page . '=@';
        $conf = [$key => ['description' => 'Beschreibung', 'keywords' => 'test,php']];
        file_put_contents($confDir . 'plugin.conf.php', "<?php die();\n" . serialize($conf));
        define('BASE_DIR', $tmpBase);

        $GLOBALS['template'] = null;
        $_GET = ['cat' => $cat, 'page' => $page];

        $ref = new \ReflectionMethod('_seo_urls', 'applyMetaKeywordsDescription');
        $ref->setAccessible(true);
        $ref->invoke(null); // kein Fatal Error, kein TypeError

        $this->assertNull($GLOBALS['template'], 'template bleibt null – str_replace wird nicht aufgerufen');

        unlink($confDir . 'plugin.conf.php');
        rmdir($confDir);
        rmdir($tmpBase . 'plugins');
        rmdir($tmpBase);
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
        self::setStaticProp('htaccessValidCache',    null);
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
