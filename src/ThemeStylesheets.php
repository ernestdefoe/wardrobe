<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\LessCompiler;
use Flarum\Frontend\Compiler\Source\FileSource;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Frontend\Compiler\Source\SourceInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * One compiled stylesheet per theme.
 *
 * Flarum compiles every enabled extension's LESS into a single `forum.css`, so
 * two themes enabled at once means two themes fighting in one file. The fix is
 * to compile the same sources once per theme, each time with every *other*
 * theme left out.
 *
 * Three facts in core make this possible without patching it:
 *
 *  - `Extend\Frontend` registers extension LESS as
 *    `$sources->addFile($path, $moduleName)`, so every CSS source knows which
 *    extension it came from (`FileSource::getExtensionId()`).
 *  - `Assets::$sources` is public, and `flarum.assets.factory` builds a
 *    correctly-configured Assets for any name — including the LESS import
 *    overrides and custom functions that `Extend\Theme` decorates the factory
 *    with, so themes that override core LESS files keep working.
 *  - `RevisionCompiler::commit()` hashes the compiled *output*, so a rebuild
 *    that changes nothing a client would download leaves the revision — and
 *    therefore every member's cached copy — alone.
 *
 * ## The hot path
 *
 * Building the compiler is all closures: `Assets::css()` appends a callback,
 * `makeCss()` defers source collection to compile time, and `getUrl()` only
 * reads the revision. Nothing walks the filesystem. Measured on the dev forum
 * with 60+ extensions installed: **0.07 ms** per request, against a ~70 ms
 * page. There is deliberately no URL cache on top of that — it would cost more
 * to look up than the work it saves.
 *
 * ## Staleness
 *
 * 🚨 Core's `RecompileFrontendAssets` only rebuilds the asset sets registered
 * with the `AssetManager` — forum, admin, common — so these sheets are
 * invisible to it and will never be rebuilt by it. Rather than deleting them
 * (which changes every member's URL and forces a ~70 KB re-download even when
 * their theme is byte-identical), a rebuild marks them stale and the next
 * `commit()` decides: identical output keeps the old revision, changed output
 * moves it.
 */
class ThemeStylesheets
{
    /** @var array<string, LessCompiler|null> */
    private array $compilers = [];

    /** @var array<string, true> Themes already brought up to date this request. */
    private array $fresh = [];

    public function __construct(
        private Container $container,
        private ThemeRegistry $registry,
        private SettingsRepositoryInterface $settings,
        private Cache $cache
    ) {
    }

    public function urlFor(string $themeId): ?string
    {
        $compiler = $this->compilerFor($themeId);

        if ($compiler === null) {
            return null;
        }

        if ($this->isStale($themeId)) {
            $this->rebuild($themeId, $compiler);
        }

        return $compiler->getUrl();
    }

    /**
     * Bring every configured theme up to date — the background half of
     * {@see WarmThemeStylesheets}, so no member is the one who pays for a
     * compile.
     */
    public function warmAll(): void
    {
        foreach ($this->registry->ids() as $themeId) {
            if (! $this->isStale($themeId)) {
                continue;
            }

            if ($compiler = $this->compilerFor($themeId)) {
                $this->rebuild($themeId, $compiler);
            }
        }
    }

    /**
     * Record that core has rebuilt the shared stylesheet, so every theme sheet
     * is now built from sources that have moved on.
     */
    public function markStale(): void
    {
        $this->settings->set('wardrobe.stale_at', time());

        $this->fresh = [];
    }

    /**
     * 🚨 `built_at` lives in the cache, not in settings, and that is load-bearing.
     * Flarum's settings repository memoises for the whole request, so a burst of
     * requests that booted before a rebuild would each read their own stale copy,
     * walk straight past the in-lock recheck, and compile the same stylesheet
     * again. Measured on the dev forum: 20 concurrent requests produced 20
     * compiles unlocked, 9 with the lock and a settings-based recheck, and 1
     * once the recheck read the cache instead.
     */
    private function isStale(string $themeId): bool
    {
        if (isset($this->fresh[$themeId])) {
            return false;
        }

        $staleAt = (int) $this->settings->get('wardrobe.stale_at');
        $builtAt = (int) $this->cache->get($this->builtKey($themeId), 0);

        return $builtAt <= $staleAt;
    }

    /**
     * Recompile one theme, under a lock so that a burst of traffic arriving
     * straight after a rebuild produces one compile rather than one per
     * request — each of which would be ~450 ms of LESS for identical bytes.
     *
     * If the lock can't be taken in time, the sheet already on disk is served.
     * It is one rebuild behind for a few seconds, which is invisible next to
     * blocking the request.
     */
    private function rebuild(string $themeId, LessCompiler $compiler): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $this->commit($themeId, $compiler);

            return;
        }

        try {
            $this->cache->lock('wardrobe.build.'.$themeId, 60)->block(5, function () use ($themeId, $compiler) {
                // Another process may have finished while we waited.
                if ($this->isStale($themeId)) {
                    $this->commit($themeId, $compiler);
                }
            });
        } catch (LockTimeoutException) {
            $this->fresh[$themeId] = true;
        }
    }

    private function commit(string $themeId, LessCompiler $compiler): void
    {
        // Not force: identical output keeps its revision, so members who were
        // already carrying this stylesheet keep using the copy in their browser.
        $compiler->commit();

        $this->cache->forever($this->builtKey($themeId), time());

        $this->fresh[$themeId] = true;
    }

    private function compilerFor(string $themeId): ?LessCompiler
    {
        if (array_key_exists($themeId, $this->compilers)) {
            return $this->compilers[$themeId];
        }

        $themes = $this->registry->ids();

        if (! in_array($themeId, $themes, true)) {
            return $this->compilers[$themeId] = null;
        }

        /** @var Assets $forum */
        $forum = $this->container->make('flarum.assets.forum');

        /** @var callable $factory */
        $factory = $this->container->make('flarum.assets.factory');

        /** @var Assets $assets */
        $assets = $factory($this->name($themeId));

        // The factory seeds its own base CSS. We want an exact replay of the
        // forum's own source list instead — which already includes that base —
        // so start from empty rather than compiling variables.less twice.
        $assets->sources['css'] = [];

        $assets->css(function (SourceCollector $out) use ($forum, $themes, $themeId) {
            foreach ($forum->sources['css'] as $callback) {
                $collected = new SourceCollector();
                $callback($collected, null);

                foreach ($collected->getSources() as $source) {
                    $owner = $source instanceof FileSource ? $source->getExtensionId() : null;

                    // Every theme except the chosen one is left on the rail.
                    if ($owner !== null && $owner !== $themeId && in_array($owner, $themes, true)) {
                        continue;
                    }

                    $this->reAdd($out, $source, $owner);
                }
            }
        });

        return $this->compilers[$themeId] = $assets->makeCss();
    }

    private function reAdd(SourceCollector $out, SourceInterface $source, ?string $owner): void
    {
        if ($source instanceof FileSource) {
            $out->addFile($source->getPath(), $owner);

            return;
        }

        // A StringSource (the forum's custom LESS, the settings-driven
        // variables) does not expose its closure, so wrap the read instead of
        // copying it — this stays lazy, evaluated at compile time as before.
        $out->addString(fn () => $source->getContent());
    }

    private function builtKey(string $themeId): string
    {
        return 'wardrobe.built.'.$themeId;
    }

    private function name(string $themeId): string
    {
        return 'forum-theme-'.preg_replace('/[^a-z0-9]+/i', '-', $themeId);
    }
}
