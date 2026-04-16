<?php

/*****************************************************************
 * This file is generated on composer update command by
 * a custom script.
 *
 * Do not edit it manually!
 ****************************************************************/

use PublishPress\BundledTranslations\Versions;

class VersionsCest
{
    public function testAllVersionsAreRegistered(WpunitTester $I)
    {
        $versions = Versions::getInstance();

        $registeredVersions = $versions->getVersions();

        $I->assertNotEmpty($registeredVersions);
        $I->assertEquals([
            '2.0.0.1' => 'PublishPress\BundledTranslations\initialize2Dot0Dot0Dot1',
            '2.0.0.2' => 'PublishPress\BundledTranslations\initialize2Dot0Dot0Dot2',
            '1.0.0' => 'PublishPress\BundledTranslations\initialize1Dot0Dot0',
        ], $registeredVersions);
    }

    public function testLatestVersionIsTheCurrentVersion(WpunitTester $I)
    {
        $versions = Versions::getInstance();

        $latestVersion = $versions->latestVersion();

        $I->assertEquals('1.0.0', $latestVersion);
    }

    public function testLatestVersionCallbackIsTheLastOne(WpunitTester $I)
    {
        $versions = Versions::getInstance();

        $latestVersionCallback = $versions->latestVersionCallback();

        $I->assertEquals('PublishPress\BundledTranslations\initialize1Dot0Dot0', $latestVersionCallback);
    }

    public function testInitializeLatestVersion(WpunitTester $I)
    {
        $versions = Versions::getInstance();

        $versions->initializeLatestVersion();

        $I->assertTrue(class_exists('PublishPress\BundledTranslations\BundledTranslations'));

        $didAction = (bool)did_action('publishpress_bundled_translations_1Dot0Dot0_initialized');
        $I->assertTrue($didAction);
    }
}
