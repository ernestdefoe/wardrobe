<?php

namespace Ernestdefoe\Wardrobe\Tests\integration\api;

use Ernestdefoe\Wardrobe\CoreAssetSeam;
use Flarum\Frontend\AssetManager;
use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four facts about core's asset pipeline that CoreAssetSeam relies on.
 * None is documented extension API, so a core upgrade that changes one must
 * fail here, before it reaches a forum.
 */
class CoreAssetsContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-wardrobe');
    }

    #[Test]
    public function the_asset_factory_builds_an_asset_set_for_any_name()
    {
        $factory = $this->app()->getContainer()->make('flarum.assets.factory');

        $this->assertIsCallable($factory);
        $this->assertInstanceOf(Assets::class, $factory('forum-theme-contract'));
    }

    #[Test]
    public function an_asset_set_exposes_its_source_callbacks()
    {
        $forum = $this->app()->getContainer()->make(AssetManager::class)->frontend('forum');

        $this->assertTrue((new \ReflectionProperty(Assets::class, 'sources'))->isPublic());

        foreach (['css', 'js'] as $type) {
            $this->assertIsArray($forum->sources[$type] ?? null, "Assets::\$sources['$type']");
            $this->assertNotEmpty($forum->sources[$type]);

            foreach ($forum->sources[$type] as $callback) {
                $this->assertIsCallable($callback);
            }
        }
    }

    #[Test]
    public function a_source_callback_takes_a_collector_and_a_locale()
    {
        $forum = $this->app()->getContainer()->make(AssetManager::class)->frontend('forum');

        $collected = new SourceCollector();
        ($forum->sources['css'][0])($collected, null);

        $this->assertNotEmpty($collected->getSources());
    }

    #[Test]
    public function each_extensions_js_names_the_extension_it_belongs_to()
    {
        $seam = $this->app()->getContainer()->make(CoreAssetSeam::class);

        $owners = array_map(fn (array $sources) => $seam->jsOwner($sources), $seam->forumSources('js'));

        $this->assertContains('flarum-tags', $owners);
        $this->assertContains('ernestdefoe-wardrobe', $owners);
    }

    #[Test]
    public function an_empty_theme_set_starts_without_the_factorys_own_sources()
    {
        $assets = $this->app()->getContainer()->make(CoreAssetSeam::class)->emptyAssets('forum-theme-contract');

        $this->assertSame([], $assets->sources['css']);
        $this->assertSame([], $assets->sources['js']);
    }
}
