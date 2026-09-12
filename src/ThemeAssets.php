<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\CompilerInterface;
use Flarum\Frontend\Compiler\Source\FileSource;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Frontend\Compiler\Source\SourceInterface;
use Flarum\Frontend\Compiler\Source\StringSource;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;

/**
 * One compiled stylesheet AND one compiled script per theme.
 *
 * Flarum compiles every enabled extension's LESS into a single `forum.css` and
 * every extension's JS into a single `forum.js`, so two themes enabled at once
 * means two themes fighting in one file — and, worse, both themes' JavaScript
 * running for everyone. The fix is to compile the same sources once per theme,
 * each time with every *other* theme left out of both bundles.
 *
 * Three facts in core make this possible without patching it:
 *
 *  - `Extend\Frontend` registers extension LESS as
 *    `$sources->addFile($path, $moduleName)`, so every CSS source knows which
 *    extension it came from (`FileSource::getExtensionId()`).
 *  - Each extension contributes exactly ONE callback per asset type, and its JS
 *    callback emits `flarum.extensions['<id>']=module.exports` — so JS can be
 *    attributed at callback granularity even though the file source itself
 *    carries no id.
 *  - `Assets::$sources` is public, and `flarum.assets.factory` builds a
 *    correctly-configured Assets for any name — including the LESS import
 *    overrides and custom functions that `Extend\Theme` decorates the factory
 *    with, so themes that override core LESS files keep working.
 *
 * ## The hot path
 *
 * Building the compilers is all closures: `Assets::css()` appends a callback,
 * `makeCss()` defers source collection to compile time, and `getUrl()` only
 * reads a revision. Nothing walks the filesystem to render a page. Measured on
 * a forum with 60+ extensions: ~0.1 ms per request against a ~70 ms page.
 *
 * ## Staleness
 *
 * 🚨 Core's `RecompileFrontendAssets` only rebuilds the asset sets registered
 * with the `AssetManager` — forum, admin, common — so these are invisible to it
 * and will never be rebuilt by it. Rather than deleting them (which changes
 * every member's URL and forces a fresh download of byte-identical assets), a
 * rebuild marks them stale and the next `commit()` decides: identical output
 * keeps the old revision, changed output moves it.
 */
class ThemeAssets
{
    /** @var array<string, array{css: CompilerInterface, js: CompilerInterface}|null> */
    private array $compilers = [];

    /** @var array<string, true> Themes already brought up to date this request. */
    private array $fresh = [];

    /** @var array<string, string[]>|null theme id => ids excluded with it */
    private ?array $companions = null;

    public function __construct(
        private Container $container,
        private ThemeRegistry $registry,
        private SettingsRepositoryInterface $settings,
        private Cache $cache
    ) {
    }

    public function cssUrlFor(string $themeId): ?string
    {
        return $this->urlFor($themeId, 'css');
    }

    public function jsUrlFor(string $themeId): ?string
    {
        return $this->urlFor($themeId, 'js');
    }

    private function urlFor(string $themeId, string $type): ?string
    {
        $compilers = $this->compilersFor($themeId);

        if ($compilers === null) {
            return null;
        }

        if ($this->isStale($themeId)) {
            $this->rebuild($themeId, $compilers);
        }

        return $compilers[$type]->getUrl();
    }

    /**
     * Bring every configured theme up to date — the background half of
     * {@see WarmThemeAssets}, so no member is the one who pays for a
     * compile.
     */
    public function warmAll(): void
    {
        foreach ($this->registry->ids() as $themeId) {
            if (! $this->isStale($themeId)) {
                continue;
            }

            if ($compilers = $this->compilersFor($themeId)) {
                $this->rebuild($themeId, $compilers);
            }
        }
    }

    /**
     * Record that core has rebuilt the shared assets, so every theme's are now
     * built from sources that have moved on.
     */
    public function markStale(): void
    {
        $this->settings->set('wardrobe.stale_at', time());

        $this->fresh = [];
    }

    /**
     * 🚨 `built_at` lives in the cache, not in settings, and that is
     * load-bearing. Flarum's settings repository memoises for the whole
     * request, so a burst of requests that booted before a rebuild would each
     * read their own stale copy, walk straight past the in-lock recheck, and
     * compile the same assets again. Measured: 20 concurrent requests produced
     * 20 compiles unlocked, 9 with the lock and a settings-based recheck, and 1
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
     * request.
     *
     * If the lock can't be taken in time, what is already on disk is served: one
     * rebuild behind for a few seconds, which is invisible next to blocking the
     * request.
     *
     * @param array{css: CompilerInterface, js: CompilerInterface} $compilers
     */
    private function rebuild(string $themeId, array $compilers): void
    {
        if (! $this->cache->getStore() instanceof LockProvider) {
            $this->commit($themeId, $compilers);

            return;
        }

        try {
            $this->cache->lock('wardrobe.build.'.$themeId, 60)->block(5, function () use ($themeId, $compilers) {
                // Another process may have finished while we waited.
                if ($this->isStale($themeId)) {
                    $this->commit($themeId, $compilers);
                }
            });
        } catch (LockTimeoutException) {
            $this->fresh[$themeId] = true;
        }
    }

    /** @param array{css: CompilerInterface, js: CompilerInterface} $compilers */
    private function commit(string $themeId, array $compilers): void
    {
        // Not force: identical output keeps its revision, so members already
        // carrying these assets keep the copies in their browser.
        foreach ($compilers as $compiler) {
            $compiler->commit();
        }

        $this->cache->forever($this->builtKey($themeId), time());

        $this->fresh[$themeId] = true;
    }

    /** @return array{css: CompilerInterface, js: CompilerInterface}|null */
    private function compilersFor(string $themeId): ?array
    {
        if (array_key_exists($themeId, $this->compilers)) {
            return $this->compilers[$themeId];
        }

        if (! in_array($themeId, $this->registry->ids(), true)) {
            return $this->compilers[$themeId] = null;
        }

        /** @var Assets $forum */
        $forum = $this->container->make('flarum.assets.forum');

        /** @var callable $factory */
        $factory = $this->container->make('flarum.assets.factory');

        /** @var Assets $assets */
        $assets = $factory('forum-theme-'.preg_replace('/[^a-z0-9]+/i', '-', $themeId));

        // The factory seeds its own base CSS. We want an exact replay of the
        // forum's own source list instead — which already includes that base —
        // so start from empty rather than compiling variables.less twice.
        $assets->sources['css'] = [];
        $assets->sources['js'] = [];

        $excluded = $this->excludedFor($themeId);

        $assets->css(fn (SourceCollector $out) => $this->replayCss($out, $forum, $excluded));
        $assets->js(fn (SourceCollector $out) => $this->replayJs($out, $forum, $excluded));

        return $this->compilers[$themeId] = [
            'css' => $assets->makeCss(),
            'js' => $assets->makeJs(),
        ];
    }

    /**
     * CSS is attributed per source, because one callback — core's — contributes
     * files that belong to no extension at all.
     *
     * @param string[] $excluded
     */
    private function replayCss(SourceCollector $out, Assets $forum, array $excluded): void
    {
        foreach ($forum->sources['css'] as $callback) {
            $collected = new SourceCollector();
            $callback($collected, null);

            foreach ($collected->getSources() as $source) {
                $owner = $source instanceof FileSource ? $source->getExtensionId() : null;

                if ($owner !== null && in_array($owner, $excluded, true)) {
                    continue;
                }

                $this->reAdd($out, $source, $owner);
            }
        }
    }

    /**
     * JS is attributed per callback, because `Extend\Frontend` adds an
     * extension's JS as three sources — a `var module={}` preamble, the file,
     * and the `flarum.extensions['id']=module.exports` line — and only the last
     * carries the id. Each callback belongs to exactly one extension, so the
     * whole group lives or dies together.
     *
     * @param string[] $excluded
     */
    private function replayJs(SourceCollector $out, Assets $forum, array $excluded): void
    {
        foreach ($forum->sources['js'] as $callback) {
            $collected = new SourceCollector();
            $callback($collected, null);

            $sources = $collected->getSources();

            if (in_array($this->ownerOfJs($sources), $excluded, true)) {
                continue;
            }

            foreach ($sources as $source) {
                $this->reAdd($out, $source, null);
            }
        }
    }

    /** @param SourceInterface[] $sources */
    private function ownerOfJs(array $sources): ?string
    {
        foreach ($sources as $source) {
            if (! $source instanceof StringSource) {
                continue;
            }

            if (preg_match("/flarum\.extensions\['([^']+)'\]/", $source->getContent(), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function reAdd(SourceCollector $out, SourceInterface $source, ?string $owner): void
    {
        if ($source instanceof FileSource) {
            $out->addFile($source->getPath(), $owner);

            return;
        }

        // A StringSource does not expose its closure, so wrap the read rather
        // than copying it — this stays lazy, evaluated at compile time as
        // before.
        $out->addString(fn () => $source->getContent());
    }

    /**
     * Everything to leave out when building for one theme: every other theme,
     * plus anything that depends on one of them.
     *
     * 🚨 The dependents matter. A theme addon — Shattered Pact is built on
     * Bespoke — is not itself a theme, so it is never in the registry, and
     * without this its JS would load while the theme it extends was excluded:
     * an add-on reaching for exports that are not there, which is a broken
     * forum for whoever picked the other theme.
     *
     * @return string[]
     */
    private function excludedFor(string $themeId): array
    {
        $excluded = [];

        foreach ($this->registry->ids() as $id) {
            if ($id === $themeId) {
                continue;
            }

            $excluded[] = $id;
            $excluded = array_merge($excluded, $this->companionsOf($id));
        }

        // A companion of the chosen theme stays, even if it also lists another
        // theme as a dependency.
        return array_values(array_diff(
            array_unique($excluded),
            [$themeId],
            $this->companionsOf($themeId)
        ));
    }

    /** @return string[] ids of enabled extensions that depend on this theme */
    private function companionsOf(string $themeId): array
    {
        if ($this->companions === null) {
            $this->companions = [];

            foreach ($this->registry->enabledExtensions() as $extension) {
                foreach ($extension->getExtensionDependencyIds() as $dependency) {
                    $this->companions[$dependency][] = $extension->getId();
                }
            }
        }

        return $this->companions[$themeId] ?? [];
    }

    private function builtKey(string $themeId): string
    {
        return 'wardrobe.built.'.$themeId;
    }
}
