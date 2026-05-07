<?php

declare(strict_types=1);

namespace Tests\Integration;

use PublishPress\BundledTranslations\BundledTranslations;
use Tests\Support\IntegrationTester;

/**
 * Integration tests for the {@see BundledTranslations} class.
 *
 * These tests directly exercise the class' public API against a real WordPress
 * environment (loaded by WPLoader) and assert the path-redirection behaviour
 * documented in the class.
 */
final class BundledTranslationsCest
{
    /**
     * Text domain used by the bundled-translations test plugin (see
     * tests/Support/Data/DummyPlugin/library-bundled-translations-test-plugin.php).
     *
     * The plugin ships .mo/.json files for several locales under its languages/
     * directory, so we can rely on those files being present at runtime.
     */
    private const TEST_DOMAIN = 'library-bundled-translations-test-plugin';

    /**
     * Locale that has bundled translations available in the dummy plugin.
     */
    private const BUNDLED_LOCALE = 'de_DE';

    /**
     * Locale that does NOT have bundled translations available in the dummy plugin.
     */
    private const UNBUNDLED_LOCALE = 'xx_XX';

    /**
     * Absolute path to the dummy plugin's bundled languages directory.
     */
    private string $languagesDir;

    /**
     * Absolute path to the dummy plugin's main file.
     */
    private string $pluginFile;

    public function _before(IntegrationTester $I): void
    {
        $pluginDir = dirname(__DIR__) . '/Support/Data/DummyPlugin';

        $this->languagesDir = $pluginDir . '/languages';
        $this->pluginFile   = $pluginDir . '/library-bundled-translations-test-plugin.php';
    }

    // ---------------------------------------------------------------------
    // Construction
    // ---------------------------------------------------------------------

    public function constructorTrimsTrailingSlashFromLanguagesDir(IntegrationTester $I): void
    {
        $sut = new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir . '/', $this->pluginFile);

        $globalPath = $this->buildGlobalPath('.mo', self::BUNDLED_LOCALE);
        $expected   = $this->buildBundledPath('.mo', self::BUNDLED_LOCALE);

        $I->assertSame(
            $expected,
            $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE),
            'Trailing slashes in $languagesDir must not produce double slashes in the bundled path.'
        );
    }

    public function constructorTrimsTrailingBackslashFromLanguagesDir(IntegrationTester $I): void
    {
        $sut = new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir . '\\', $this->pluginFile);

        $globalPath = $this->buildGlobalPath('.mo', self::BUNDLED_LOCALE);
        $expected   = $this->buildBundledPath('.mo', self::BUNDLED_LOCALE);

        $I->assertSame($expected, $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE));
    }

    // ---------------------------------------------------------------------
    // init()
    // ---------------------------------------------------------------------

    public function initRegistersTranslationFilters(IntegrationTester $I): void
    {
        $sut = new BundledTranslations('init-test-domain', $this->languagesDir, $this->pluginFile);
        $sut->init();

        $I->assertNotFalse(
            has_filter('load_translation_file', [$sut, 'filterTranslationFile']),
            'init() must register the load_translation_file filter.'
        );
        $I->assertNotFalse(
            has_filter('load_script_translation_file', [$sut, 'filterScriptTranslationFile']),
            'init() must register the load_script_translation_file filter.'
        );

        remove_filter('load_translation_file', [$sut, 'filterTranslationFile'], 10);
        remove_filter('load_script_translation_file', [$sut, 'filterScriptTranslationFile'], 10);
    }

    public function initDoesNotRegisterFiltersWhenDisabledByFilter(IntegrationTester $I): void
    {
        $disable = static function (): bool {
            return false;
        };

        add_filter('publishpress_bundled_translations_enabled', $disable);

        try {
            $sut = new BundledTranslations('disabled-via-filter', $this->languagesDir, $this->pluginFile);
            $sut->init();

            $I->assertFalse(has_filter('load_translation_file', [$sut, 'filterTranslationFile']));
            $I->assertFalse(has_filter('load_script_translation_file', [$sut, 'filterScriptTranslationFile']));
        } finally {
            remove_filter('publishpress_bundled_translations_enabled', $disable);
        }
    }

    public function disableFilterReceivesDomainAndPluginFile(IntegrationTester $I): void
    {
        $captured = [];

        $capture = static function ($enabled, $domain, $pluginFile) use (&$captured) {
            $captured = [
                'enabled'    => $enabled,
                'domain'     => $domain,
                'pluginFile' => $pluginFile,
            ];

            return $enabled;
        };

        $sut = new BundledTranslations('domain-args-test', $this->languagesDir, $this->pluginFile);

        add_filter('publishpress_bundled_translations_enabled', $capture, 10, 3);

        try {
            $sut->init();
        } finally {
            remove_filter('publishpress_bundled_translations_enabled', $capture, 10);
            remove_filter('load_translation_file', [$sut, 'filterTranslationFile'], 10);
            remove_filter('load_script_translation_file', [$sut, 'filterScriptTranslationFile'], 10);
        }

        $I->assertSame('domain-args-test', $captured['domain']);
        $I->assertSame($this->pluginFile, $captured['pluginFile']);
        $I->assertTrue($captured['enabled']);
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — file-type guards
    // ---------------------------------------------------------------------

    public function filterTranslationFileLeavesNonMoNonL10nPathsUntouched(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $jsonPath = $this->buildGlobalPath('.json', self::BUNDLED_LOCALE);
        $poPath   = $this->buildGlobalPath('.po', self::BUNDLED_LOCALE);

        $I->assertSame($jsonPath, $sut->filterTranslationFile($jsonPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE));
        $I->assertSame($poPath, $sut->filterTranslationFile($poPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE));
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — domain guard
    // ---------------------------------------------------------------------

    public function filterTranslationFileIgnoresOtherTextDomains(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.mo', self::BUNDLED_LOCALE);

        $I->assertSame(
            $globalPath,
            $sut->filterTranslationFile($globalPath, 'some-other-plugin', self::BUNDLED_LOCALE),
            'Files for other text domains must never be redirected.'
        );
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — Loco override guard
    // ---------------------------------------------------------------------

    public function filterTranslationFileLeavesLocoOverridesUntouched(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $locoPath = WP_LANG_DIR . '/loco/plugins/' . self::TEST_DOMAIN . '-' . self::BUNDLED_LOCALE . '.mo';

        $I->assertSame(
            $locoPath,
            $sut->filterTranslationFile($locoPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE),
            'Loco Translate overrides must take priority over bundled translations.'
        );
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — global plugin language packs
    // ---------------------------------------------------------------------

    public function filterTranslationFileRedirectsGlobalMoPathToBundled(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.mo', self::BUNDLED_LOCALE);
        $expected   = $this->buildBundledPath('.mo', self::BUNDLED_LOCALE);

        $I->assertFileExists($expected, 'Sanity check: bundled .mo fixture must be present.');
        $I->assertSame(
            $expected,
            $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE)
        );
    }

    public function filterTranslationFileLeavesPathUntouchedWhenBundledFileMissing(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.mo', self::UNBUNDLED_LOCALE);
        $bundled    = $this->buildBundledPath('.mo', self::UNBUNDLED_LOCALE);

        $I->assertFileDoesNotExist($bundled, 'Sanity check: unbundled locale must not have a fixture.');
        $I->assertSame(
            $globalPath,
            $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, self::UNBUNDLED_LOCALE),
            'When the bundled .mo file is missing, the original path must be returned.'
        );
    }

    public function filterTranslationFileWouldRedirectL10nPhpPathButFallsBackWhenMissing(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.l10n.php', self::BUNDLED_LOCALE);
        $bundled    = $this->buildBundledPath('.l10n.php', self::BUNDLED_LOCALE);

        $I->assertFileDoesNotExist($bundled, 'Sanity check: dummy plugin does not ship .l10n.php fixtures.');
        $I->assertSame(
            $globalPath,
            $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, self::BUNDLED_LOCALE),
            'Without a bundled .l10n.php file the original path must be preserved.'
        );
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — JIT / non-canonical paths under the plugin
    // ---------------------------------------------------------------------

    public function filterTranslationFileLeavesAlreadyCanonicalBundledPathUntouched(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $bundled = $this->buildBundledPath('.mo', self::BUNDLED_LOCALE);

        $I->assertSame(
            $bundled,
            $sut->filterTranslationFile($bundled, self::TEST_DOMAIN, self::BUNDLED_LOCALE),
            'Canonical bundled paths must not trigger another redirection.'
        );
    }

    public function filterTranslationFileNormalisesNonCanonicalPluginPathToBundled(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $nonCanonical = $this->languagesDir . '/' . self::TEST_DOMAIN . '-' . self::BUNDLED_LOCALE . '-old.mo';
        $expected     = $this->buildBundledPath('.mo', self::BUNDLED_LOCALE);

        $I->assertSame(
            $expected,
            $sut->filterTranslationFile($nonCanonical, self::TEST_DOMAIN, self::BUNDLED_LOCALE),
            'Plugin-resolved paths that do not match the canonical bundled name must be normalised.'
        );
    }

    // ---------------------------------------------------------------------
    // filterTranslationFile() — locale fallback
    // ---------------------------------------------------------------------

    public function filterTranslationFileFallsBackToDetermineLocaleWhenLocaleIsEmpty(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $forcedLocale = self::BUNDLED_LOCALE;

        $forceLocale = static function () use ($forcedLocale): string {
            return $forcedLocale;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $globalPath = $this->buildGlobalPath('.mo', $forcedLocale);
            $expected   = $this->buildBundledPath('.mo', $forcedLocale);

            $I->assertSame(
                $expected,
                $sut->filterTranslationFile($globalPath, self::TEST_DOMAIN, ''),
                'An empty locale must fall back to determine_locale().'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    // ---------------------------------------------------------------------
    // filterScriptTranslationFile()
    // ---------------------------------------------------------------------

    public function filterScriptTranslationFileRedirectsGlobalJsonPathToBundled(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.json', self::BUNDLED_LOCALE);
        $expected   = $this->buildBundledPath('.json', self::BUNDLED_LOCALE);

        $I->assertFileExists($expected, 'Sanity check: bundled .json fixture must be present.');

        $forceLocale = static function () : string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $I->assertSame(
                $expected,
                $sut->filterScriptTranslationFile($globalPath, 'some-handle', self::TEST_DOMAIN)
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    public function filterScriptTranslationFileIgnoresOtherTextDomains(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.json', self::BUNDLED_LOCALE);

        $I->assertSame(
            $globalPath,
            $sut->filterScriptTranslationFile($globalPath, 'some-handle', 'some-other-plugin')
        );
    }

    public function filterScriptTranslationFileLeavesPathUntouchedWhenBundledMissing(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $globalPath = $this->buildGlobalPath('.json', self::UNBUNDLED_LOCALE);
        $bundled    = $this->buildBundledPath('.json', self::UNBUNDLED_LOCALE);

        $I->assertFileDoesNotExist($bundled);

        $forceLocale = static function () : string {
            return self::UNBUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $I->assertSame(
                $globalPath,
                $sut->filterScriptTranslationFile($globalPath, 'some-handle', self::TEST_DOMAIN)
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    public function filterScriptTranslationFileLeavesLocoOverridesUntouched(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $locoPath = WP_LANG_DIR . '/loco/plugins/' . self::TEST_DOMAIN . '-' . self::BUNDLED_LOCALE . '.json';

        $I->assertSame(
            $locoPath,
            $sut->filterScriptTranslationFile($locoPath, 'some-handle', self::TEST_DOMAIN),
            'Loco Translate JSON overrides must take priority over bundled translations.'
        );
    }

    public function filterScriptTranslationFileLeavesCanonicalBundledPathUntouched(IntegrationTester $I): void
    {
        $sut = $this->makeSut();

        $bundled = $this->buildBundledPath('.json', self::BUNDLED_LOCALE);

        $forceLocale = static function () : string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $I->assertSame(
                $bundled,
                $sut->filterScriptTranslationFile($bundled, 'some-handle', self::TEST_DOMAIN)
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeSut(): BundledTranslations
    {
        return new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir, $this->pluginFile);
    }

    /**
     * Build a path under WP's global plugin language packs directory
     * ({@see WP_LANG_DIR}/plugins/) for the given suffix and locale.
     */
    private function buildGlobalPath(string $suffix, string $locale): string
    {
        return WP_LANG_DIR . '/plugins/' . self::TEST_DOMAIN . '-' . $locale . $suffix;
    }

    /**
     * Build the canonical bundled path (i.e. the path the class redirects to)
     * for the given suffix and locale.
     */
    private function buildBundledPath(string $suffix, string $locale): string
    {
        return $this->languagesDir . '/' . self::TEST_DOMAIN . '-' . $locale . $suffix;
    }
}
