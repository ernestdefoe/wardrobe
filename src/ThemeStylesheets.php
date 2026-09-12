<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\LessCompiler;
use Flarum\Frontend\Compiler\Source\FileSource;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Frontend\Compiler\Source\SourceInterface;
use Illuminate\Contracts\Container\Container;

/**
 * One compiled stylesheet per theme.
 *
 * Flarum compiles every enabled extension's LESS into a single `forum.css`, so
 * two themes enabled at once means two themes fighting in one file. The fix is
 * to compile the same sources once per theme, each time with every *other*
 * theme left out.
 *
 * Two facts in core make this possible without patching it:
 *
 *  - `Extend\Frontend` registers extension LESS as
 *    `$sources->addFile($path, $moduleName)`, so every CSS source knows which
 *    extension it came from (`FileSource::getExtensionId()`).
 *  - `Assets::$sources` is public, and `flarum.assets.factory` builds a
 *    correctly-configured Assets for any name — including the LESS import
 *    overrides and custom functions that `Extend\Theme` decorates the factory
 *    with, so themes that override core LESS files keep working.
 *
 * Compilation is lazy: `RevisionCompiler::getUrl()` commits when there is no
 * revision yet, so a theme nobody has chosen is never compiled, and a cache
 * clear costs one compile per theme *actually in use*, on first request.
 */
class ThemeStylesheets
{
    /** @var array<string, LessCompiler|null> */
    private array $compilers = [];

    public function __construct(
        private Container $container,
        private ThemeRegistry $registry
    ) {
    }

    public function urlFor(string $themeId): ?string
    {
        return $this->compilerFor($themeId)?->getUrl();
    }

    /**
     * Delete every theme stylesheet and clear its revision, so each is rebuilt
     * on first use. See {@see InvalidateThemeStylesheets} for why this is not
     * optional.
     */
    public function flushAll(): void
    {
        foreach ($this->registry->ids() as $themeId) {
            $this->compilerFor($themeId)?->flush();
        }

        $this->compilers = [];
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

    private function name(string $themeId): string
    {
        return 'forum-theme-'.preg_replace('/[^a-z0-9]+/i', '-', $themeId);
    }
}
