<?php
namespace PublishPressBundledTranslations;

/**
 * Forces WordPress to use a plugin's bundled translations instead of
 * the global translations downloaded from wordpress.org.
 */
class BundledTranslations
{
    /**
     * The plugin text domain.
     *
     * @var string
     */
    private $domain;

    /**
     * Absolute path to the plugin's bundled languages directory.
     *
     * @var string
     */
    private $languagesDir;

    /**
     * Absolute path to the main plugin file.
     *
     * @var string
     */
    private $pluginFile;

    /**
     * Track redirected mo files for debug notice.
     *
     * @var array
     */
    private $redirectedFiles = [];

    /**
     * @param string $domain       The plugin text domain.
     * @param string $languagesDir Absolute path to the plugin's bundled languages directory.
     * @param string $pluginFile   Absolute path to the main plugin file.
     */
    public function __construct($domain, $languagesDir, $pluginFile)
    {
        $this->domain = $domain;
        $this->languagesDir = rtrim($languagesDir, '/\\');
        $this->pluginFile = $pluginFile;

        $this->init();
    }

    /**
     * Initialize the translation override.
     *
     * @return void
     */
    private function init()
    {
        if (! $this->isEnabled()) {
            return;
        }

        add_filter('load_textdomain_mofile', [$this, 'filterMoFile'], 10, 2);
        add_action('admin_notices', [$this, 'showDebugNotice']);
        add_action('wp_ajax_publishpress_dismiss_bundled_translations_notice', [$this, 'dismissNotice']);
    }

    /**
     * Check whether bundled translations are enabled.
     * @return bool
     */
    private function isEnabled()
    {
        $enabled = defined('PUBLISHPRESS_BUNDLED_TRANSLATIONS_ENABLED')
            ? PUBLISHPRESS_BUNDLED_TRANSLATIONS_ENABLED
            : true;

        $enabled = apply_filters(
            'publishpress_bundled_translations_enabled',
            $enabled,
            $this->domain,
            $this->pluginFile
        );

        return (bool) $enabled;
    }

    /**
     * Filter the .mo file path to use the plugin's bundled translations
     * when WordPress tries to load from the global languages directory.
     *
     * @param string $mofile Path to the .mo file.
     * @param string $domain Text domain.
     * @return string Filtered path to the .mo file.
     */
    public function filterMoFile($mofile, $domain)
    {
        if ($domain !== $this->domain) {
            return $mofile;
        }

        if (false === strpos($mofile, WP_LANG_DIR . '/plugins/')) {
            return $mofile;
        }

        $locale = determine_locale();
        $pluginMofile = $this->languagesDir . '/' . $this->domain . '-' . $locale . '.mo';

        if (! file_exists($pluginMofile)) {
            return $mofile;
        }

        $this->redirectedFiles[] = [
            'from' => $mofile,
            'to' => $pluginMofile,
            'locale' => $locale
        ];

        return $pluginMofile;
    }

    /**
     * Show admin notice with mo file paths being used (for debugging).
     *
     * @return void
     */
    public function showDebugNotice()
    {
        if (empty($this->redirectedFiles)) {
            return;
        }

        $dismissKey = 'publishpress_bundled_translations_notice_' . md5($this->domain);
        
        if (get_user_meta(get_current_user_id(), $dismissKey, true)) {
            return;
        }

        $file = $this->redirectedFiles[0];
        ?>
        <div class="notice notice-info is-dismissible" data-dismiss-key="<?php echo esc_attr($dismissKey); ?>">
            <p>
                <strong><?php echo esc_html($this->domain); ?> - Bundled Translations Active</strong><br>
                Using: <code><?php echo esc_html($file['to']); ?></code><br>
                <small>Instead of: <code><?php echo esc_html($file['from']); ?></code></small>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '[data-dismiss-key="<?php echo esc_js($dismissKey); ?>"] .notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'publishpress_dismiss_bundled_translations_notice',
                    dismiss_key: '<?php echo esc_js($dismissKey); ?>',
                    nonce: '<?php echo wp_create_nonce('dismiss_bundled_translations'); ?>'
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle AJAX request to dismiss the notice.
     *
     * @return void
     */
    public function dismissNotice()
    {
        check_ajax_referer('dismiss_bundled_translations', 'nonce');
        
        $dismissKey = sanitize_text_field($_POST['dismiss_key']);
        update_user_meta(get_current_user_id(), $dismissKey, true);
        
        wp_send_json_success();
    }
}
