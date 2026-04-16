<?php

if (! class_exists('PublishPress\BundledTranslations\\BundledTranslations')) {
    if (! class_exists('PublishPress\BundledTranslations\\Autoloader')) {
        require_once __DIR__ . '/core/Autoloader.php';
    }

    $autoloader = new PublishPress\BundledTranslations\Autoloader();
    $autoloader->register();
    $autoloader->addNamespace('PublishPress\BundledTranslations', __DIR__ . '/core');
}
