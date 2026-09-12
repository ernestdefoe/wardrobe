<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Queue\AbstractJob;

/**
 * Recompiles every configured theme’s assets off the request path.
 *
 * A theme costs ~450 ms of LESS plus a JS concatenation to build. Without this, the first member
 * to arrive on each theme after an extension toggle pays that on their page
 * load; with it, the queue has usually finished before anyone asks.
 *
 * Only dispatched when there is a real queue worker — see
 * {@see InvalidateThemeAssets}. If the job never runs (a forum whose
 * worker is down), nothing breaks: `ThemeAssets` still rebuilds
 * on demand.
 */
class WarmThemeAssets extends AbstractJob
{
    public function handle(ThemeAssets $assets): void
    {
        $assets->warmAll();
    }
}
