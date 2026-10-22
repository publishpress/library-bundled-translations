<?php

declare(strict_types=1);

namespace Tests\Integration;

use PublishPress\BundledTranslations\BundledTranslations;
use PublishPress\BundledTranslations\HubTranslationNotice;
use Tests\Support\IntegrationTester;

/**
 * Integration tests for the admin warning that points non-English locales at PublishPress Hub.
 */
final class HubTranslationNoticeCest
{
    private const TEST_DOMAIN = 'library-bundled-translations-test-plugin';

    private string $languagesDir;

    private string $pluginFile;

    /**
     * @var int[]
     */
    private array $userIds = [];

    /**
     * @var callable|null
     */
    private $localeFilter;

    /**
     * @var string|null
     */
    private $originalSiteLanguage;

    public function _before(IntegrationTester $I): void
    {
        $pluginDir = dirname(__DIR__) . '/Support/Data/DummyPlugin';

        $this->languagesDir = $pluginDir . '/languages';
        $this->pluginFile   = $pluginDir . '/library-bundled-translations-test-plugin.php';

        HubTranslationNotice::resetRegisteredNotices();
        wp_set_current_user(0);
        add_filter('publishpress_bundled_translations_hub_notice_enabled', '__return_true');
        add_filter(
            'publishpress_bundled_translations_hub_notice_screens',
            static function (): array {
                return ['toplevel_page_hub-notice-test'];
            }
        );
        $this->setAdminScreen('toplevel_page_hub-notice-test');
    }

    public function _after(IntegrationTester $I): void
    {
        if (null !== $this->localeFilter) {
            remove_filter('locale', $this->localeFilter);
            $this->localeFilter = null;
        }

        if (null !== $this->originalSiteLanguage) {
            update_option('WPLANG', $this->originalSiteLanguage);
            $this->originalSiteLanguage = null;
        }

        remove_all_filters('publishpress_bundled_translations_hub_notice_enabled');
        remove_all_filters('publishpress_bundled_translations_hub_notice_screens');
        remove_all_filters('publishpress_bundled_translations_hub_plugin_active');
        remove_all_filters('publishpress_bundled_translations_enabled');

        HubTranslationNotice::resetRegisteredNotices();
        wp_set_current_user(0);

        if ([] === $this->userIds) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ($this->userIds as $userId) {
            wp_delete_user($userId);
        }

        $this->userIds = [];
    }

    public function showsNoticeWhenSiteLocaleIsAProvidedNonEnglishLanguage(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('en_US', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertTrue($notice->shouldDisplay());

        $html = $this->captureRender($notice);
        $I->assertStringContainsString('is-dismissible', $html);
        $I->assertStringContainsString('https://publishpress.com/hub', $html);
        $I->assertStringContainsString(
            'publishpress_bundled_translations_dismiss_hub_notice',
            $this->commonInlineScript()
        );
    }

    public function hidesNoticeAfterTheCurrentUserDismissesIt(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertTrue($notice->shouldDisplay());

        HubTranslationNotice::dismissForCurrentUser();

        $I->assertFalse($notice->shouldDisplay());

        $this->loginWithLocale('de_DE', 'administrator');

        $I->assertTrue(
            $notice->shouldDisplay(),
            'Dismissal is stored per user, so another administrator still sees the notice.'
        );
    }

    public function showsNoticeWhenSiteLanguageOptionDiffersFromTheRuntimeLocale(IntegrationTester $I): void
    {
        $this->setSiteLanguageOption('de_DE');
        $this->setRuntimeLocale('en_US');
        $this->loginWithLocale('en_US', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertTrue(
            $notice->shouldDisplay(),
            'The Settings → General language must trigger the notice even when get_locale() is English.'
        );
    }

    public function showsNoticeWhenUserLocaleIsAProvidedNonEnglishLanguage(IntegrationTester $I): void
    {
        $this->setSiteLocale('en_US');
        $this->loginWithLocale('fr_FR', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertTrue($notice->shouldDisplay());
    }

    public function showsNoticeForThreeLetterLocalesShippedByThePlugin(IntegrationTester $I): void
    {
        $this->setSiteLocale('en_US');
        $this->loginWithLocale('fil', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertTrue($notice->shouldDisplay());
    }

    public function hidesNoticeWhenBothSiteAndUserAreEnglish(IntegrationTester $I): void
    {
        $this->setSiteLocale('en_US');
        $this->loginWithLocale('en_GB', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function hidesNoticeWhenTheNonEnglishLocaleIsNotShipped(IntegrationTester $I): void
    {
        $this->setSiteLocale('pt_PT');
        $this->loginWithLocale('en_US', 'administrator');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse(
            $notice->shouldDisplay(),
            'pt_PT is not one of the bundled locales, so the Hub warning must stay hidden.'
        );
    }

    public function hidesNoticeWhenTheUserCannotInstallPlugins(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'subscriber');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function hidesNoticeWhenHubIsActive(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'administrator');
        add_filter('publishpress_bundled_translations_hub_plugin_active', '__return_true');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function hidesNoticeWhenTheCurrentScreenIsNotAllowed(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'administrator');
        $this->setAdminScreen('index');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function hidesNoticeWhenThePluginAllowsNoScreens(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'administrator');
        remove_all_filters('publishpress_bundled_translations_hub_notice_screens');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function limitsAllowedScreensToTheMatchingTextDomain(IntegrationTester $I): void
    {
        $dir = sys_get_temp_dir() . '/pp-bundled-translations-' . wp_generate_password(8, false);
        mkdir($dir);
        touch($dir . '/other-domain-de_DE.mo');

        try {
            $this->setSiteLocale('de_DE');
            $this->loginWithLocale('de_DE', 'administrator');
            remove_all_filters('publishpress_bundled_translations_hub_notice_screens');
            $ownDomain = self::TEST_DOMAIN;
            add_filter(
                'publishpress_bundled_translations_hub_notice_screens',
                static function (array $screens, string $domain) use ($ownDomain): array {
                    if ($ownDomain === $domain) {
                        $screens[] = 'toplevel_page_hub-notice-test';
                    }

                    return $screens;
                },
                10,
                2
            );

            $mine = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);
            $other = new HubTranslationNotice('other-domain', $dir);

            $I->assertTrue($mine->shouldDisplay());
            $I->assertFalse($other->shouldDisplay());
        } finally {
            unlink($dir . '/other-domain-de_DE.mo');
            rmdir($dir);
        }
    }

    public function hidesNoticeWhenTheNoticeFilterDisablesIt(IntegrationTester $I): void
    {
        $this->setSiteLocale('de_DE');
        $this->loginWithLocale('de_DE', 'administrator');
        add_filter('publishpress_bundled_translations_hub_notice_enabled', '__return_false');

        $notice = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);

        $I->assertFalse($notice->shouldDisplay());
    }

    public function recognisesJedJsonHashSuffixAsAProvidedLocale(IntegrationTester $I): void
    {
        $dir = sys_get_temp_dir() . '/pp-bundled-translations-' . wp_generate_password(8, false);
        mkdir($dir);
        $hash = str_repeat('ab', 16);
        touch($dir . '/custom-domain-ja-' . $hash . '.json');

        try {
            $this->setSiteLocale('en_US');
            $this->loginWithLocale('ja', 'administrator');

            $notice = new HubTranslationNotice('custom-domain', $dir);

            $I->assertTrue($notice->shouldDisplay());
        } finally {
            unlink($dir . '/custom-domain-ja-' . $hash . '.json');
            rmdir($dir);
        }
    }

    public function printsTheNoticeOnceWhenSeveralPluginsMatch(IntegrationTester $I): void
    {
        $dir = sys_get_temp_dir() . '/pp-bundled-translations-' . wp_generate_password(8, false);
        mkdir($dir);
        touch($dir . '/other-domain-de_DE.mo');

        try {
            $this->setSiteLocale('de_DE');
            $this->loginWithLocale('fr_FR', 'administrator');

            $first = new HubTranslationNotice(self::TEST_DOMAIN, $this->languagesDir);
            $second = new HubTranslationNotice('other-domain', $dir);
            $first->register();
            $second->register();

            $I->assertSame(10, has_action('admin_notices', [HubTranslationNotice::class, 'onAdminNotices']));

            ob_start();
            HubTranslationNotice::onAdminNotices();
            $html = ob_get_clean();

            $I->assertSame(1, substr_count((string) $html, 'publishpress-bundled-translations-hub-notice'));
            $I->assertStringContainsString('https://publishpress.com/hub', (string) $html);
        } finally {
            unlink($dir . '/other-domain-de_DE.mo');
            rmdir($dir);
        }
    }

    public function initRegistersTheHubNoticeWhenBundledTranslationsAreEnabled(IntegrationTester $I): void
    {
        $sut = new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir, $this->pluginFile);
        $sut->init();

        $I->assertNotFalse(has_action('admin_notices', [HubTranslationNotice::class, 'onAdminNotices']));

        remove_filter('load_translation_file', [$sut, 'filterTranslationFile'], 10);
        remove_filter('load_script_translation_file', [$sut, 'filterScriptTranslationFile'], 10);
    }

    public function initSkipsTheHubNoticeWhenBundledTranslationsAreDisabled(IntegrationTester $I): void
    {
        add_filter('publishpress_bundled_translations_enabled', '__return_false');

        $sut = new BundledTranslations(self::TEST_DOMAIN, $this->languagesDir, $this->pluginFile);
        $sut->init();

        $I->assertFalse(has_action('admin_notices', [HubTranslationNotice::class, 'onAdminNotices']));
    }

    private function setAdminScreen(string $screenId): void
    {
        if (! function_exists('set_current_screen')) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }

        set_current_screen($screenId);
    }

    private function setSiteLocale(string $locale): void
    {
        $this->setSiteLanguageOption($locale);
        $this->setRuntimeLocale($locale);
    }

    private function setSiteLanguageOption(string $locale): void
    {
        if (null === $this->originalSiteLanguage) {
            $current = get_option('WPLANG');
            $this->originalSiteLanguage = is_string($current) ? $current : '';
        }

        // Settings → General only lists installed language packs. Bypass that
        // check so the test can store a locale that is not installed in wp_test.
        $allowLocale = static function () use ($locale): string {
            return $locale;
        };
        add_filter('sanitize_option_WPLANG', $allowLocale, 99);
        update_option('WPLANG', $locale);
        remove_filter('sanitize_option_WPLANG', $allowLocale, 99);
    }

    private function setRuntimeLocale(string $locale): void
    {
        $this->localeFilter = static function () use ($locale): string {
            return $locale;
        };

        add_filter('locale', $this->localeFilter);
    }

    private function loginWithLocale(string $locale, string $role): void
    {
        $login = 'hub-notice-' . strtolower($locale) . '-' . $role . '-' . wp_generate_password(6, false);

        $userId = wp_insert_user(
            [
                'user_login' => $login,
                'user_pass'  => wp_generate_password(24, true),
                'user_email' => $login . '@example.com',
                'role'       => $role,
                'locale'     => $locale,
            ]
        );

        if (! is_int($userId)) {
            throw new \RuntimeException('Failed to create the locale test user.');
        }

        $this->userIds[] = $userId;
        wp_set_current_user($userId);
    }

    private function captureRender(HubTranslationNotice $notice): string
    {
        ob_start();
        $notice->render();
        $html = ob_get_clean();

        return (string) $html;
    }

    private function commonInlineScript(): string
    {
        $chunks = [];

        foreach (['before', 'after'] as $position) {
            $inline = wp_scripts()->get_data('common', $position);
            if (is_array($inline)) {
                $chunks[] = implode("\n", $inline);
            }
        }

        return implode("\n", $chunks);
    }
}
