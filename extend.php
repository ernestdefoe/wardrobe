<?php

/*
 * Wardrobe — several themes installed, one chosen per member.
 */

use Flarum\Extend;
use Flarum\Frontend\Event\AssetsRecompiled;
use Ernestdefoe\Wardrobe\InvalidateThemeStylesheets;
use Ernestdefoe\Wardrobe\SwapStylesheet;

return [
    // Priority 0, so this runs after core's Content\Assets (190) has already
    // put the shared forum.css URL into the document.
    (new Extend\Frontend('forum'))
        ->content(SwapStylesheet::class),

    (new Extend\User())
        ->registerPreference('wardrobeTheme', null, null),

    // Core only rebuilds the asset sets it knows about, so the theme
    // stylesheets must be thrown away whenever the shared one is rebuilt.
    (new Extend\Event())
        ->listen(AssetsRecompiled::class, InvalidateThemeStylesheets::class),

    (new Extend\Settings())
        ->serializeToForum('wardrobe.themes', 'wardrobe.themes')
        ->serializeToForum('wardrobe.default', 'wardrobe.default'),
];
