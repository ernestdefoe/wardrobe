<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Queue\AbstractJob;

/**
 * Recompiles every configured theme stylesheet off the request path.
 *
 * A theme sheet costs ~450 ms of LESS to build. Without this, the first member
 * to arrive on each theme after an extension toggle pays that on their page
 * load; with it, the queue has usually finished before anyone asks.
 *
 * Only dispatched when there is a real queue worker — see
 * {@see InvalidateThemeStylesheets}. If the job never runs (a forum whose
 * worker is down), nothing breaks: `ThemeStylesheets::urlFor()` still rebuilds
 * on demand.
 */
class WarmThemeStylesheets extends AbstractJob
{
    public function handle(ThemeStylesheets $stylesheets): void
    {
        $stylesheets->warmAll();
    }
}
