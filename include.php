<?php

if (! class_exists('PublishPressBundledTranslations\\BundledTranslations')) {
    if (! class_exists('PublishPressBundledTranslations\\Autoloader')) {
        require_once __DIR__ . '/core/Autoloader.php';
    }

    $autoloader = new PublishPressBundledTranslations\Autoloader();
    $autoloader->register();
    $autoloader->addNamespace('PublishPressBundledTranslations', __DIR__ . '/core');
}
