<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Event\AssetsRecompiled;
use Flarum\Queue\RoutingQueue;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

/**
 * Marks every per-theme stylesheet stale when core rebuilds the shared one,
 * and gets them rebuilt in the background if this forum can.
 *
 * 🚨 Without this the theme sheets go stale and stay stale. Core's
 * `RecompileFrontendAssets` only rebuilds the asset sets registered with the
 * `AssetManager` — forum, admin, common — so a theme stylesheet built here is
 * invisible to it: enabling an extension rebuilds `forum.css` and leaves every
 * theme sheet on its old revision, indefinitely. Proved on the dev forum —
 * enabling Armory put 42 of its rules into forum.css and none into either
 * theme sheet.
 *
 * Marking rather than deleting is deliberate: `RevisionCompiler::commit()`
 * hashes the compiled output, so a rebuild that changes nothing leaves the
 * revision alone and every member keeps the copy already in their browser.
 * Deleting would hand all of them a new URL and a fresh ~70 KB download for
 * byte-identical CSS.
 */
class InvalidateThemeStylesheets
{
    public function __construct(
        private ThemeStylesheets $stylesheets,
        private Queue $queue
    ) {
    }

    public function handle(AssetsRecompiled $event): void
    {
        $this->stylesheets->markStale();

        // 🚨 Never dispatch onto a sync queue. This listener runs inside a
        // member's page request, and a sync dispatch would compile every theme
        // — several seconds — before that page was sent. Forums without a
        // worker simply rebuild lazily on first use instead.
        // The bound connection is wrapped for per-job queue routing, so the
        // driver underneath is what decides whether a push is really deferred.
        $driver = $this->queue instanceof RoutingQueue ? $this->queue->getDriver() : $this->queue;

        if ($driver instanceof SyncQueue) {
            return;
        }

        $this->queue->push(new WarmThemeStylesheets());
    }
}
