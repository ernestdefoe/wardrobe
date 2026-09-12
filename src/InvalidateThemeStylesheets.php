<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Event\AssetsRecompiled;

/**
 * Throws away every per-theme stylesheet when core rebuilds the shared one.
 *
 * 🚨 Without this the theme sheets go stale and stay stale. Core's
 * `RecompileFrontendAssets` only rebuilds the asset sets registered with the
 * `AssetManager` — forum, admin, common — so a theme stylesheet built here is
 * invisible to it: enabling an extension rebuilds `forum.css` and leaves every
 * theme sheet on its old revision, indefinitely. Proved on the dev forum —
 * enabling Armory put 42 of its rules into forum.css and none into either
 * theme sheet.
 *
 * Flushing rather than recompiling keeps the laziness: a theme nobody is
 * using is never built, and the next member to ask for one pays a single
 * compile.
 */
class InvalidateThemeStylesheets
{
    public function __construct(
        private ThemeStylesheets $stylesheets
    ) {
    }

    public function handle(AssetsRecompiled $event): void
    {
        $this->stylesheets->flushAll();
    }
}
