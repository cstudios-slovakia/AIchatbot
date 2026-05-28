<?php

namespace cstudiossro\craftcschatbot\web\assets;

use craft\web\AssetBundle;

class WidgetAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/widget';
        $this->css = ['widget.css'];
        $this->js = ['widget.js'];
        parent::init();
    }
}
