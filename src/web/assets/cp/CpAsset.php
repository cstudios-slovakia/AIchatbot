<?php

namespace cstudiossro\craftcschatbot\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/** Shared control-panel styling for the plugin's own screens. */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['cp.css'];
        parent::init();
    }
}
