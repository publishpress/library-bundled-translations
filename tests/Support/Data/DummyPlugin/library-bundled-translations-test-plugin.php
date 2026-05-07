<?php
/*
Plugin Name: Dummy Plugin
Description: A simple plugin for test purposes.
Version: 1.0
Author: Test Suite
*/

if (! defined('LIBRARY_BUNDLED_TRANSLATIONS_TEST_PLUGIN_VERSION')) {
    define('LIBRARY_BUNDLED_TRANSLATIONS_TEST_PLUGIN_VERSION', '1.0');
}

if (defined('PUBLISHPRESS_BUNDLED_TRANSLATIONS_INCLUDED')) {
    return;
}

define('PUBLISHPRESS_BUNDLED_TRANSLATIONS_INCLUDED', true);

if (! class_exists('PublishPress\BundledTranslations\BundledTranslations')) {
    require_once __DIR__ . '/lib/vendor/publishpress/bundled-translations/core/include.php';
}

add_action('plugins_loaded', function () {
    $bundledTranslations = new PublishPress\BundledTranslations\BundledTranslations(
        'library-bundled-translations-test-plugin',
        __DIR__ . '/languages',
        __FILE__
    );
    $bundledTranslations->init();
});

add_action('init', function () {
    echo __('Hello, world!', 'library-bundled-translations-test-plugin');
    echo "\n";
    echo __('Goodbye, world!', 'library-bundled-translations-test-plugin');
    echo "\n";
    echo __('This is a test string.', 'library-bundled-translations-test-plugin');
    echo "\n";
    echo __('Another string for translation.', 'library-bundled-translations-test-plugin');
});
