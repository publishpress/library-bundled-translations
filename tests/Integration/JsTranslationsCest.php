<?php

declare(strict_types=1);

namespace Tests\Integration;

use PublishPress\BundledTranslations\BundledTranslations;
use Tests\Support\IntegrationTester;

/**
 * Integration tests for JavaScript string translations.
 *
 * These tests verify the full JS translation pipeline:
 *   1. The bundled JSON files are valid JED 1.x format and contain every string
 *      declared in assets/init.jsx with correct translations.
 *   2. WordPress' load_script_textdomain() + load_script_translation_file filter
 *      redirects to the bundled JSON so that wp_set_script_translations() serves
 *      the plugin-bundled strings instead of relying on global language packs.
 */
final class JsTranslationsCest
{
    private const TEST_DOMAIN    = 'library-bundled-translations-test-plugin';
    private const BUNDLED_LOCALE = 'de_DE';
    private const UNBUNDLED_LOCALE = 'xx_XX';

    /**
     * Script handle used in tests that exercise wp_set_script_translations().
     */
    private const SCRIPT_HANDLE = 'bundled-translations-test-js';

    /**
     * Source strings that appear in assets/init.jsx.
     */
    private const JS_STRINGS = [
        'Hello, world!',
        'Goodbye, world!',
        'This is a test string.',
        'Another string for translation.',
        'This string is only in the JS file.',
    ];

    /**
     * Expected German translations for every string in init.jsx.
     * Keyed by original source string → translated string (JED index [1]).
     */
    private const EXPECTED_DE_DE = [
        'Hello, world!'                      => 'Hallo, Welt!',
        'Goodbye, world!'                    => 'Auf Wiedersehen, Welt!',
        'This is a test string.'             => 'Dies ist ein Teststring.',
        'Another string for translation.'    => 'Ein weiterer String zur Übersetzung.',
        'This string is only in the JS file.' => 'Dieser Text befindet sich nur in der JS-Datei.',
    ];

    private string $languagesDir;
    private string $pluginFile;

    public function _before(IntegrationTester $I): void
    {
        $pluginDir = dirname(__DIR__) . '/Support/Data/DummyPlugin';

        $this->languagesDir = $pluginDir . '/languages';
        $this->pluginFile   = $pluginDir . '/library-bundled-translations-test-plugin.php';
    }

    // -------------------------------------------------------------------------
    // Bundled JSON — file-level sanity checks
    // -------------------------------------------------------------------------

    public function bundledJsonFileExistsForSupportedLocale(IntegrationTester $I): void
    {
        $I->assertFileExists(
            $this->buildBundledJsonPath(self::BUNDLED_LOCALE),
            'A bundled .json file must exist for the supported locale.'
        );
    }

    public function bundledJsonFileDoesNotExistForUnsupportedLocale(IntegrationTester $I): void
    {
        $I->assertFileDoesNotExist(
            $this->buildBundledJsonPath(self::UNBUNDLED_LOCALE),
            'No bundled .json file should exist for an unsupported locale.'
        );
    }

    // -------------------------------------------------------------------------
    // Bundled JSON — JED 1.x format
    // -------------------------------------------------------------------------

    public function bundledJsonHasValidJsonSyntax(IntegrationTester $I): void
    {
        $raw  = file_get_contents($this->buildBundledJsonPath(self::BUNDLED_LOCALE));
        $data = json_decode($raw, true);

        $I->assertNotNull($data, 'Bundled JSON must have valid JSON syntax.');
        $I->assertIsArray($data);
    }

    public function bundledJsonHasJedMetadataKey(IntegrationTester $I): void
    {
        $data = $this->decodeBundledJson(self::BUNDLED_LOCALE);

        $I->assertArrayHasKey(
            '',
            $data,
            'JED 1.x format requires an empty-string ("") metadata key.'
        );
        $I->assertIsArray($data['']);
    }

    public function bundledJsonMetadataCarriesCorrectDomain(IntegrationTester $I): void
    {
        $meta = $this->decodeBundledJson(self::BUNDLED_LOCALE)[''];

        $I->assertArrayHasKey('x-domain', $meta);
        $I->assertSame(
            self::TEST_DOMAIN,
            $meta['x-domain'],
            'The x-domain metadata entry must match the plugin text domain.'
        );
    }

    // -------------------------------------------------------------------------
    // Bundled JSON — JS string coverage
    // -------------------------------------------------------------------------

    public function bundledJsonContainsAllJsStrings(IntegrationTester $I): void
    {
        $data = $this->decodeBundledJson(self::BUNDLED_LOCALE);

        foreach (self::JS_STRINGS as $source) {
            $I->assertArrayHasKey(
                $source,
                $data,
                "Bundled JSON must contain a translation entry for: \"{$source}\""
            );
        }
    }

    public function bundledJsonTranslationEntriesAreArrays(IntegrationTester $I): void
    {
        $data = $this->decodeBundledJson(self::BUNDLED_LOCALE);

        foreach (self::JS_STRINGS as $source) {
            $I->assertIsArray(
                $data[$source],
                "Translation entry for \"{$source}\" must be an array (JED tuple)."
            );
        }
    }

    public function bundledJsonHasCorrectGermanTranslationsForAllJsStrings(IntegrationTester $I): void
    {
        $data = $this->decodeBundledJson(self::BUNDLED_LOCALE);

        foreach (self::EXPECTED_DE_DE as $source => $expectedTranslation) {
            $entry = $data[$source] ?? null;

            $I->assertNotNull($entry, "Missing translation entry for: \"{$source}\"");

            // JED tuple: [context_or_null, singular_translation, ...plural_forms]
            $actual = $entry[1] ?? null;

            $I->assertSame(
                $expectedTranslation,
                $actual,
                "Wrong German translation for \"{$source}\": expected \"{$expectedTranslation}\", got \"{$actual}\"."
            );
        }
    }

    public function bundledJsonContainsStringOnlyPresentInJsFile(IntegrationTester $I): void
    {
        $jsOnlyString = 'This string is only in the JS file.';
        $data         = $this->decodeBundledJson(self::BUNDLED_LOCALE);

        $I->assertArrayHasKey(
            $jsOnlyString,
            $data,
            'The string that exists only in init.jsx must be present in the bundled JSON.'
        );
        $I->assertSame(
            self::EXPECTED_DE_DE[$jsOnlyString],
            $data[$jsOnlyString][1],
            'The JS-only string must carry the correct German translation.'
        );
    }

    // -------------------------------------------------------------------------
    // wp_set_script_translations integration — full pipeline
    // -------------------------------------------------------------------------

    /**
     * Verify that after BundledTranslations::init() the WordPress script
     * translation pipeline serves the bundled JSON content.
     *
     * Strategy: register the script with an external (non-WP) URL so WordPress
     * cannot compute a WP-relative path and falls back to
     * load_script_translations(false, $handle, $domain). Our
     * filterScriptTranslationFile filter then intercepts the call (receiving
     * false coerced to "" for the string parameter) and redirects to the
     * bundled JSON, which WordPress reads and returns as the translation data.
     */
    public function scriptTranslationsAreLoadedFromBundledJsonViaFilter(IntegrationTester $I): void
    {
        $sut = $this->makeSut();
        $sut->init();

        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, self::TEST_DOMAIN);

            $I->assertIsString(
                $result,
                'load_script_textdomain() must return a non-false string when bundled JSON is present.'
            );

            $data = json_decode($result, true);

            $I->assertNotNull($data, 'The returned translation data must be valid JSON.');
            $I->assertArrayHasKey('Hello, world!', $data);
            $I->assertSame(
                'Hallo, Welt!',
                $data['Hello, world!'][1],
                '"Hello, world!" must be translated to German.'
            );
            $I->assertArrayHasKey('This string is only in the JS file.', $data);
            $I->assertSame(
                'Dieser Text befindet sich nur in der JS-Datei.',
                $data['This string is only in the JS file.'][1],
                'The JS-only string must also be translated correctly.'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    public function scriptTranslationsContainAllJsStringsFromInitJsx(IntegrationTester $I): void
    {
        $sut = $this->makeSut();
        $sut->init();

        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, self::TEST_DOMAIN);

            $I->assertIsString($result);

            $data = json_decode($result, true);

            foreach (self::JS_STRINGS as $source) {
                $I->assertArrayHasKey(
                    $source,
                    $data,
                    "Loaded translations must contain an entry for: \"{$source}\""
                );
            }
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    public function scriptTranslationsMatchExpectedGermanStrings(IntegrationTester $I): void
    {
        $sut = $this->makeSut();
        $sut->init();

        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, self::TEST_DOMAIN);

            $I->assertIsString($result);

            $data = json_decode($result, true);

            foreach (self::EXPECTED_DE_DE as $source => $expected) {
                $I->assertArrayHasKey($source, $data);
                $I->assertSame(
                    $expected,
                    $data[$source][1],
                    "Wrong translation served for \"{$source}\"."
                );
            }
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    public function scriptTranslationsReturnFalseForUnsupportedLocale(IntegrationTester $I): void
    {
        $sut = $this->makeSut();
        $sut->init();

        $forceLocale = static function (): string {
            return self::UNBUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, self::TEST_DOMAIN);

            $I->assertFalse(
                $result,
                'load_script_textdomain() must return false when no bundled JSON exists for the locale.'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    public function scriptTranslationsReturnFalseWhenFilterIsNotRegistered(IntegrationTester $I): void
    {
        // The active dummy plugin has already called init() on plugins_loaded, so its
        // load_script_translation_file hook is always present. We temporarily remove all
        // callbacks from that filter to simulate a fresh environment where init() was
        // never called, then restore the original hooks in the finally block.
        global $wp_filter;

        $backupHook = $wp_filter['load_script_translation_file'] ?? null;
        unset($wp_filter['load_script_translation_file']);

        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, self::TEST_DOMAIN);

            $I->assertFalse(
                $result,
                'Without the load_script_translation_file filter registered, no bundled JSON should be served.'
            );
        } finally {
            if ($backupHook !== null) {
                $wp_filter['load_script_translation_file'] = $backupHook;
            }
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    public function scriptTranslationsIgnoreOtherTextDomains(IntegrationTester $I): void
    {
        $sut = $this->makeSut();
        $sut->init();

        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            wp_register_script(self::SCRIPT_HANDLE, 'https://external.example.com/init.js', [], '1.0');

            $result = load_script_textdomain(self::SCRIPT_HANDLE, 'some-other-domain');

            $I->assertFalse(
                $result,
                'Bundled JSON must not be served for a different text domain.'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
            wp_deregister_script(self::SCRIPT_HANDLE);
        }
    }

    // -------------------------------------------------------------------------
    // filterScriptTranslationFile() — false $file edge cases
    //
    // WordPress's load_script_translation_file filter is documented to pass
    // string|false as $file (false = "no file found"). Our method signature
    // only accepts string, so PHP silently coerces false → "" in the caller's
    // non-strict context. The two failing tests below expose two related bugs:
    //
    //   Bug 1 – When the domain does not match and the filter should pass the
    //            value through unchanged, it returns "" instead of false.
    //
    //   Bug 2 – When the domain matches but the bundled JSON for the current
    //            locale is missing (so we also cannot serve anything), the
    //            method again returns "" instead of the original false.
    //
    // The correct behaviour in both cases is to preserve the original value
    // (false) so that downstream hooks and WordPress itself keep the right
    // "no file" signal.
    // -------------------------------------------------------------------------

    /**
     * WordPress passes false as $file when no translation file was found at any
     * of the standard lookup paths. When the plugin's domain does not match,
     * the filter must pass false through unchanged.
     *
     * Failing because: false is coerced to "" by PHP, so the method returns ""
     * instead of preserving the original false value.
     */
    public function filterScriptTranslationFilePreservesFalseForOtherTextDomain(IntegrationTester $I): void
    {
        // The dummy plugin's filter is already registered (init() was called on
        // plugins_loaded). Calling apply_filters with false lets it flow through
        // the non-strict hook invocation, reproducing what WordPress does.
        $forceLocale = static function (): string {
            return self::BUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $result = apply_filters('load_script_translation_file', false, 'some-handle', 'some-other-domain');

            $I->assertFalse(
                $result,
                'When WordPress passes false for an unrelated domain the filter must return false, not an empty string.'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    /**
     * WordPress passes false as $file when no translation file was found. When
     * the domain matches but no bundled JSON exists for the current locale, the
     * filter must still return false — not the empty string that results from
     * the false → "" coercion.
     *
     * Failing because: after coercion $filePath becomes "", and when the
     * bundled file is absent we return $filePath ("") instead of false.
     */
    public function filterScriptTranslationFilePreservesFalseWhenBundledFileMissing(IntegrationTester $I): void
    {
        $forceLocale = static function (): string {
            return self::UNBUNDLED_LOCALE;
        };

        add_filter('determine_locale', $forceLocale, 99);

        try {
            $I->assertFileDoesNotExist(
                $this->buildBundledJsonPath(self::UNBUNDLED_LOCALE),
                'Sanity check: no bundled JSON must exist for the unbundled locale.'
            );

            $result = apply_filters('load_script_translation_file', false, 'some-handle', self::TEST_DOMAIN);

            $I->assertFalse(
                $result,
                'When WordPress passes false and no bundled JSON exists for the locale the filter must return false, not an empty string.'
            );
        } finally {
            remove_filter('determine_locale', $forceLocale, 99);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSut(): BundledTranslations
    {
        return new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir, $this->pluginFile);
    }

    private function buildBundledJsonPath(string $locale): string
    {
        return $this->languagesDir . '/' . self::TEST_DOMAIN . '-' . $locale . '.json';
    }

    /**
     * Read and JSON-decode the bundled translation file for the given locale.
     *
     * @return array<string, mixed>
     */
    private function decodeBundledJson(string $locale): array
    {
        $path = $this->buildBundledJsonPath($locale);
        $raw  = file_get_contents($path);

        return json_decode($raw, true) ?? [];
    }
}
