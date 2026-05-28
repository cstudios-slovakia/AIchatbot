<?php

namespace cstudiossro\craftcschatbot\web\assets\cpnav;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class CpNavAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->depends = [CpAsset::class];
        $this->js = ['cpnav.js'];
        parent::init();
    }
}
