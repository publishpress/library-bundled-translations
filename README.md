# PublishPress Bundled Translations

Forces WordPress plugins to use their bundled translations instead of the global translations downloaded from translate.wordpress.org.

## How It Works

This library hooks into WordPress's `load_textdomain_mofile` filter. When WordPress tries to load a `.mo` file from the global `wp-content/languages/plugins/` directory, the filter redirects it to the plugin's own bundled `languages/` directory instead.

## Requirements

- PHP >= 7.2.5
- WordPress

## Installation

Add the package to your plugin's `lib/composer.json`:

```json
{
    "require": {
        "publishpress/bundled-translations": "^1.0"
    }
}
```

Then run:

```bash
cd lib && composer update
```

## Usage

In your plugin's main PHP file, include the library and instantiate it:

```php
// Include the library
$includeFileRelativePath = '/publishpress/bundled-translations/include.php';

if (file_exists(__DIR__ . '/lib/vendor' . $includeFileRelativePath)) {
    require_once __DIR__ . '/lib/vendor' . $includeFileRelativePath;
} elseif (defined('MY_PLUGIN_LIB_VENDOR_PATH') && file_exists(MY_PLUGIN_LIB_VENDOR_PATH . $includeFileRelativePath)) {
    require_once MY_PLUGIN_LIB_VENDOR_PATH . $includeFileRelativePath;
}

// Initialize bundled translations
if (class_exists('PublishPressBundledTranslations\\BundledTranslations')) {
    new PublishPressBundledTranslations\BundledTranslations(
        'my-text-domain',        // Plugin text domain
        __DIR__ . '/languages',  // Path to the plugin's bundled languages directory
        __FILE__                 // Path to the main plugin file
    );
}
```

## Disabling

The library can be disabled in two ways:

### Via PHP Constant

Add to `wp-config.php` or anywhere before the plugin loads:

```php
define('PUBLISHPRESS_BUNDLED_TRANSLATIONS_ENABLED', false);
```

### Via WordPress Filter

```php
// Disable for all plugins
add_filter('publishpress_bundled_translations_enabled', '__return_false');

// Disable for a specific plugin
add_filter('publishpress_bundled_translations_enabled', function($enabled, $domain, $pluginFile) {
    if ($domain === 'my-text-domain') {
        return false;
    }
    return $enabled;
}, 10, 3);
```

## License

GPL-3.0-or-later
