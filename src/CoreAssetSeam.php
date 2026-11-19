<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\AssetManager;
use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Frontend\Compiler\Source\SourceInterface;
use Flarum\Frontend\Compiler\Source\StringSource;
use Illuminate\Contracts\Container\Container;

/**
 * Everything Wardrobe needs from core's asset pipeline that is not documented
 * extension API, in one class.
 *
 * Core has no extender for "the forum's assets, built again without some
 * extensions", so a theme build replays the forum's own asset sources. That
 * rests on four facts about core, each used once below:
 *
 *  1. `flarum.assets.factory` builds a configured Assets set for any name (the
 *     binding core's Extend\Frontend and Extend\Theme use);
 *  2. `Assets::$sources` holds each frontend's source callbacks;
 *  3. a source callback takes `(SourceCollector $sources, ?string $locale)`;
 *  4. Extend\Frontend ends each extension's JS with
 *     `flarum.extensions['<id>']=module.exports`.
 *
 * `tests/integration/api/CoreAssetsContractTest.php` checks all four against
 * the installed core, so an upgrade that changes one fails there first. In
 * production ApplyTheme catches the failure, logs it and serves the shared
 * assets.
 */
class CoreAssetSeam
{
    public function __construct(
        private Container $container,
        private AssetManager $assets
    ) {
    }

    /**
     * An empty asset set to build one theme into.
     *
     * The factory seeds its own base CSS, but the forum's sources already
     * include that base, so the set starts empty rather than compiling
     * variables.less twice.
     */
    public function emptyAssets(string $name): Assets
    {
        /** @var Assets $assets */
        $assets = $this->container->make('flarum.assets.factory')($name);
        $assets->sources = ['css' => [], 'js' => []] + $assets->sources;

        return $assets;
    }

    /**
     * The forum's sources of one type, grouped by the callback (and so, for
     * JS, the extension) that contributed them.
     *
     * @return list<SourceInterface[]>
     */
    public function forumSources(string $type): array
    {
        $groups = [];

        foreach ($this->assets->frontend('forum')->sources[$type] as $callback) {
            $collected = new SourceCollector();
            $callback($collected, null);
            $groups[] = $collected->getSources();
        }

        return $groups;
    }

    /**
     * The extension one group of JS sources belongs to, read from the line
     * Extend\Frontend appends to it.
     *
     * @param SourceInterface[] $sources
     */
    public function jsOwner(array $sources): ?string
    {
        foreach ($sources as $source) {
            if ($source instanceof StringSource && preg_match("/flarum\.extensions\['([^']+)'\]/", $source->getContent(), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
