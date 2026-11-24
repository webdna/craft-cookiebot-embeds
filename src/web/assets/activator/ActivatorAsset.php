<?php

namespace webdna\cookiebotembeds\web\assets\activator;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * The Vue mode activator (BR-19), registered in the head of every site page in Vue mode so that
 * `window.CookiebotEmbeds.ready()` exists before the site's Vue app mounts. An external file rather
 * than an inline script, so a strict CSP needs no `unsafe-inline`.
 */
class ActivatorAsset extends AssetBundle
{
    /** The section 7 test hook: the activator's script tag. */
    public const ID = 'cookiebot-embeds-activator';

    public function init(): void
    {
        $this->sourcePath = __DIR__;
        // Only the script is published; this class file stays out of the web root.
        $this->publishOptions = ['only' => ['activator.js']];
        $this->js = ['activator.js'];
        $this->jsOptions = ['position' => View::POS_HEAD, 'id' => self::ID];

        parent::init();
    }
}
