<?php

/*
 * Wardrobe — several themes installed, one chosen per member.
 */

use Ernestdefoe\Wardrobe\ApplyTheme;
use Ernestdefoe\Wardrobe\InvalidateThemeAssets;
use Ernestdefoe\Wardrobe\ThemeRegistry;
use Flarum\Extend;
use Flarum\Frontend\Event\AssetsRecompiled;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        // Priority 0, so this runs after core's Content\Assets (190) has
        // already put the shared forum.css URL into the document.
        ->content(ApplyTheme::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * The member's own choice.
     *
     * 🚨 The transformer is what keeps this honest: a preference is written
     * from the client, so without it a member could store any string at all.
     * `validPreference()` keeps '' (which means "follow the forum") and turns
     * anything that is not a configured theme into '' as well — so a
     * hand-written API call cannot park someone on a theme the owner has not
     * offered.
     */
    (new Extend\User())
        ->registerPreference(
            ThemeRegistry::PREFERENCE,
            fn ($value) => resolve(ThemeRegistry::class)->validPreference($value),
            ''
        ),

    // Core only rebuilds the asset sets it knows about, so the theme
    // stylesheets must be marked stale whenever the shared one is rebuilt.
    (new Extend\Event())
        ->listen(AssetsRecompiled::class, InvalidateThemeAssets::class),
];
