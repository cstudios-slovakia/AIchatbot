<?php

namespace cstudiossro\craftcschatbot\helpers;

use Craft;

/**
 * Bridges API differences between Craft 4 and Craft 5.
 *
 * - Sections moved service in Craft 5: what was `Craft::$app->sections`
 *   (craft\services\Sections) in Craft 4 became part of
 *   `Craft::$app->entries` (craft\services\Entries) in Craft 5.
 * - The various `...ByUid()` lookups are not present on every Craft 4
 *   service (e.g. Globals/Categories), so we resolve by scanning the
 *   `getAll*()` collections instead — works identically on both versions.
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
        foreach (self::getAllSections() as $section) {
            if ($section->uid === $uid) {
                return $section;
            }
        }
        return null;
    }

    public static function getCategoryGroupByUid(string $uid): ?\craft\models\CategoryGroup
    {
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($group->uid === $uid) {
                return $group;
            }
        }
        return null;
    }

    public static function getGlobalSetByUid(string $uid): ?\craft\elements\GlobalSet
    {
        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            if ($set->uid === $uid) {
                return $set;
            }
        }
        return null;
    }
}
