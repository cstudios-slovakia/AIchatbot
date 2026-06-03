<?php

namespace cstudiossro\craftcschatbot\helpers;

use Craft;

/**
 * Bridges API differences between Craft 4 and Craft 5.
 *
 * Sections moved service in Craft 5: what was `Craft::$app->sections`
 * (craft\services\Sections) in Craft 4 became part of
 * `Craft::$app->entries` (craft\services\Entries) in Craft 5. Category
 * groups and global sets kept their services in both versions, so they
 * do not need bridging.
 */
class CraftCompat
{
    /**
     * The service that exposes section methods on this Craft version.
     */
    private static function sectionsService(): object
    {
        $entries = Craft::$app->getEntries();
        if (method_exists($entries, 'getAllSections')) {
            return $entries; // Craft 5
        }
        return Craft::$app->getSections(); // Craft 4
    }

    /**
     * @return \craft\models\Section[]
     */
    public static function getAllSections(): array
    {
        return self::sectionsService()->getAllSections();
    }

    public static function getSectionByUid(string $uid): ?\craft\models\Section
    {
        return self::sectionsService()->getSectionByUid($uid);
    }
}
