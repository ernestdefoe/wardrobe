<?php

namespace Ernestdefoe\Wardrobe\Tests\integration\api;

use Ernestdefoe\Wardrobe\ThemeAssets;
use Ernestdefoe\Wardrobe\ThemeRegistry;
use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Frontend\Event\AssetsRecompiled;
use Flarum\Settings\DatabaseSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * No real themes are installed here, so two ordinary extensions stand in for
 * them: Wardrobe treats any enabled extension it is told about as a theme,
 * and builds each one's stylesheet without the others'.
 */
class WardrobeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const TAGS = 'flarum-tags';
    private const FLAGS = 'flarum-flags';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-flags', 'ernestdefoe-wardrobe');

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    private function themes(array $ids = [self::TAGS, self::FLAGS], ?string $default = null): void
    {
        $this->setting('wardrobe.themes', json_encode($ids));
        if ($default) {
            $this->setting('wardrobe.default', $default);
        }
    }

    /** setting() only seeds the database before the app boots; this changes it after. */
    private function liveSetting(string $key, string $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set($key, $value);
    }

    private function page(?int $actor = null, array $cookies = []): string
    {
        $request = $actor
            ? $this->request('GET', '/', ['authenticatedAs' => $actor])
            : new ServerRequest([], [], '/', 'GET');

        if ($cookies) {
            $request = $request->withCookieParams($request->getCookieParams() + $cookies);
        }

        return (string) $this->send($request)->getBody();
    }

    /** Wardrobe as a newly booted request sees it: nothing remembered from this one. */
    private function nextRequest(): ThemeAssets
    {
        $container = $this->app()->getContainer();

        return new ThemeAssets(
            $container,
            $container->make(ThemeRegistry::class),
            $container->make(SettingsRepositoryInterface::class),
            $container->make(Cache::class),
            $container->make(VersionerInterface::class)
        );
    }

    private function payload(string $html): array
    {
        $this->assertSame(1, preg_match('#"wardrobe":(\{"themes".*?"allowUserChoice":(?:true|false)\})#', $html, $m), 'The payload carries the themes');

        return json_decode($m[1], true);
    }

    private function active(string $html): ?string
    {
        return $this->payload($html)['active'];
    }

    private function preference(string $value): void
    {
        $this->send($this->request('PATCH', '/api/users/2', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'users', 'id' => '2', 'attributes' => ['preferences' => ['wardrobeTheme' => $value]]]],
        ]));
    }

    private function stored(): mixed
    {
        $preferences = json_decode((string) $this->database()->table('users')->where('id', 2)->value('preferences'), true);

        return $preferences['wardrobeTheme'] ?? null;
    }

    #[Test]
    public function without_themes_the_shared_stylesheet_is_left_alone()
    {
        $html = $this->page();

        $this->assertNull($this->active($html));
        $this->assertSame([], $this->payload($html)['themes']);
        $this->assertDoesNotMatchRegularExpression('#(href|src)="[^"]*/assets/forum-theme-#', $html);
        $this->assertStringNotContainsString('data-wardrobe-theme', $html);
    }

    #[Test]
    public function a_theme_that_is_not_enabled_is_never_offered()
    {
        $this->themes([self::TAGS, 'acme-not-installed']);

        $this->assertSame([self::TAGS], array_column($this->payload($this->page())['themes'], 'id'));
    }

    #[Test]
    public function a_visitor_gets_the_default_theme_in_place_of_the_shared_assets()
    {
        $this->themes(default: self::FLAGS);

        $html = $this->page();

        $this->assertSame(self::FLAGS, $this->active($html));
        $this->assertStringContainsString('data-wardrobe-theme="flarum-flags"', $html);
        $this->assertMatchesRegularExpression('#/assets/forum-theme-flarum-flags\.css#', $html);
        $this->assertMatchesRegularExpression('#/assets/forum-theme-flarum-flags\.js#', $html);
        $this->assertDoesNotMatchRegularExpression('#(href|src)="[^"]*/assets/forum\.(css|js)\b#', $html, 'Never the shared bundle as well');
        $this->assertSame(['Tags', 'Flags'], array_column($this->payload($html)['themes'], 'title'), 'Each theme by its own extension title');
    }

    #[Test]
    public function the_first_theme_is_the_default_until_one_is_chosen()
    {
        $this->themes(default: 'acme-not-installed');

        $this->assertSame(self::TAGS, $this->active($this->page()));
    }

    #[Test]
    public function each_theme_is_built_without_the_others()
    {
        $this->themes();

        $this->page();
        $this->page(null, ['wardrobe_theme' => self::FLAGS]);
        $assets = $this->app()->getContainer()->make('flarum.paths')->public.'/assets/';
        $tags = (string) file_get_contents($assets.'forum-theme-flarum-tags.css');
        $flags = (string) file_get_contents($assets.'forum-theme-flarum-flags.css');

        $this->assertStringContainsString('.TagLabel', $tags);
        $this->assertStringNotContainsString('.Post--flagged', $tags, "Another theme's styles never leak in");
        $this->assertStringContainsString('.Post--flagged', $flags);
        $this->assertStringNotContainsString('.TagLabel', $flags);
    }

    #[Test]
    public function a_member_is_served_the_theme_they_chose()
    {
        $this->themes();

        $this->preference(self::FLAGS);
        $this->assertSame(self::FLAGS, $this->stored());
        $this->assertSame(self::FLAGS, $this->active($this->page(2)));
        $this->assertSame(self::TAGS, $this->active($this->page(1)), 'Everyone else gets the default');
    }

    #[Test]
    public function a_preference_can_only_name_an_offered_theme()
    {
        $this->themes();

        $this->preference('acme-not-installed');
        $this->assertSame('', $this->stored(), 'Stored as "follow the forum"');
        $this->assertSame(self::TAGS, $this->active($this->page(2)));
    }

    #[Test]
    public function turning_member_choice_off_overrides_stored_choices()
    {
        $this->themes();
        $this->setting('wardrobe.allow_user_choice', '0');

        $this->preference(self::FLAGS);

        $this->assertSame(self::TAGS, $this->active($this->page(2)));
        $this->assertSame(self::TAGS, $this->active($this->page(null, ['wardrobe_theme' => self::FLAGS])));
        $this->assertFalse($this->payload($this->page(2))['allowUserChoice']);
    }

    #[Test]
    public function a_guest_is_served_the_theme_in_their_cookie_if_it_is_offered()
    {
        $this->themes();

        $this->assertSame(self::FLAGS, $this->active($this->page(null, ['wardrobe_theme' => self::FLAGS])));
        $this->assertSame(self::TAGS, $this->active($this->page(null, ['wardrobe_theme' => 'acme-not-installed'])));
    }

    #[Test]
    public function plain_flarum_is_offered_only_when_switched_on()
    {
        $this->themes();

        $this->assertSame([self::TAGS, self::FLAGS], array_column($this->payload($this->page())['themes'], 'id'));
        $this->assertSame(self::TAGS, $this->active($this->page(null, ['wardrobe_theme' => 'flarum'])), 'A cookie cannot pick it while it is off');

        $this->preference('flarum');
        $this->assertSame('', $this->stored(), 'Nor can a preference');

        $this->liveSetting('wardrobe.offer_flarum', '1');

        $themes = $this->payload($this->page())['themes'];
        $this->assertSame([self::TAGS, self::FLAGS, 'flarum'], array_column($themes, 'id'));
        // Extension locales are not loaded in the test app, so this proves the
        // key; the English string is checked on a real forum.
        $translator = $this->app()->getContainer()->make(TranslatorInterface::class);
        $this->assertSame($translator->trans('ernestdefoe-wardrobe.forum.theme.flarum'), $themes[2]['title']);
    }

    #[Test]
    public function plain_flarum_is_built_with_every_theme_left_out()
    {
        $this->themes();
        $this->setting('wardrobe.offer_flarum', '1');

        $html = $this->page(null, ['wardrobe_theme' => 'flarum']);

        $this->assertSame('flarum', $this->active($html));
        $this->assertMatchesRegularExpression('#/assets/forum-theme-flarum\.css#', $html);
        $this->assertMatchesRegularExpression('#/assets/forum-theme-flarum\.js#', $html);
        $this->assertDoesNotMatchRegularExpression('#(href|src)="[^"]*/assets/forum\.(css|js)\b#', $html, 'Never the shared bundle, which carries every theme');

        $assets = $this->app()->getContainer()->make('flarum.paths')->public.'/assets/';
        $css = (string) file_get_contents($assets.'forum-theme-flarum.css');
        $js = (string) file_get_contents($assets.'forum-theme-flarum.js');

        $this->assertStringContainsString('.Header', $css, "Core's own styles stay");
        $this->assertStringNotContainsString('.TagLabel', $css);
        $this->assertStringNotContainsString('.Post--flagged', $css);
        $this->assertStringNotContainsString("flarum.extensions['flarum-tags']", $js);
        $this->assertStringNotContainsString("flarum.extensions['flarum-flags']", $js);
    }

    #[Test]
    public function plain_flarum_can_be_chosen_and_be_the_default()
    {
        $this->themes(default: 'flarum');
        $this->setting('wardrobe.offer_flarum', '1');

        $this->assertSame('flarum', $this->active($this->page()));

        $this->preference(self::FLAGS);
        $this->assertSame(self::FLAGS, $this->active($this->page(2)));

        $this->preference('flarum');
        $this->assertSame('flarum', $this->stored());
        $this->assertSame('flarum', $this->active($this->page(2)));

        $this->liveSetting('wardrobe.offer_flarum', '0');
        $this->assertSame(self::TAGS, $this->active($this->page(2)), 'Switching it off moves everyone on it to a real theme');
    }

    #[Test]
    public function plain_flarum_is_not_a_choice_without_themes()
    {
        $this->setting('wardrobe.offer_flarum', '1');

        $html = $this->page(null, ['wardrobe_theme' => 'flarum']);

        $this->assertNull($this->active($html));
        $this->assertSame([], $this->payload($html)['themes']);
    }

    #[Test]
    public function rebuilding_the_shared_assets_marks_every_theme_stale()
    {
        $this->themes();
        $this->app();

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new AssetsRecompiled());

        $this->assertGreaterThan(0, (int) $this->database()->table('settings')->where('key', 'wardrobe.stale_at')->value('value'));
    }

    /**
     * 🚨 Seen on dev: the queue worker booted before an extension update, so
     * it compiled the theme from its boot-time sources — and stamped the
     * result as built *now*, after the rebuild was marked. Every page request
     * then took the old bundle as current until the worker was restarted.
     */
    #[Test]
    public function a_build_by_a_process_that_booted_before_the_change_is_not_current()
    {
        $this->themes();
        $container = $this->app()->getContainer();

        // This process has read its settings; another one now marks a rebuild.
        $container->make(SettingsRepositoryInterface::class)->get('wardrobe.stale_at');
        $this->database()->table('settings')->insert(['key' => 'wardrobe.stale_at', 'value' => (string) (time() - 5)]);

        $container->make(ThemeAssets::class)->warmAll();

        // A process booted after the change must still see the theme as stale.
        $fresh = new ThemeAssets(
            $container,
            $container->make(ThemeRegistry::class),
            new DatabaseSettingsRepository($this->database()),
            $container->make(Cache::class),
            $container->make(VersionerInterface::class)
        );

        $theme = self::TAGS;
        $this->assertTrue((fn () => $this->isStale($theme))->call($fresh), "The theme was built from sources older than the mark");
    }

    #[Test]
    public function two_rebuilds_in_the_same_second_are_two_marks()
    {
        $this->themes();
        $assets = $this->app()->getContainer()->make(ThemeAssets::class);
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);

        $assets->markStale();
        $first = $settings->get('wardrobe.stale_at');
        $assets->markStale();

        $this->assertGreaterThan((float) $first, (float) $settings->get('wardrobe.stale_at'));
    }

    /**
     * 🚨 Seen on the showcase: phpredis writes a float at 14 significant
     * digits, so a build stamped with the mark as a float read back as below
     * it — 1792527414.066511 became 1792527414.0665 — and every page view
     * recompiled its theme.
     */
    #[Test]
    public function a_build_is_current_when_its_cache_rounds_numbers_like_redis()
    {
        $this->themes();
        // The mark seen there: one that rounds DOWN at 14 digits.
        $this->setting('wardrobe.stale_at', '1792527414.066511');
        $container = $this->app()->getContainer();

        $store = new class extends ArrayStore {
            public function forever($key, $value)
            {
                return parent::forever($key, is_float($value) ? sprintf('%.14G', $value) : $value);
            }
        };

        $assets = new ThemeAssets(
            $container,
            $container->make(ThemeRegistry::class),
            $container->make(SettingsRepositoryInterface::class),
            new CacheRepository($store),
            $container->make(VersionerInterface::class)
        );
        $assets->warmAll();

        // As the next request sees it: nothing remembered from this one.
        $theme = self::TAGS;
        $staleNextRequest = (function () use ($theme) {
            $this->fresh = [];

            return $this->isStale($theme);
        })->call($assets);

        $this->assertFalse($staleNextRequest, 'A theme just built is stale again on the next request');
    }

    /**
     * 🚨 Seen on dev, the showcase and live on 2026-10-25:
     * `millwright:rebuild-assets` rebuilds forum.js and forum.css by
     * committing them directly, which fires no event, so the theme bundles
     * kept the old code until someone rebuilt them by hand.
     */
    #[Test]
    public function a_rebuild_of_the_shared_assets_that_fires_no_event_still_reaches_every_theme()
    {
        $this->themes();
        $this->page();

        $container = $this->app()->getContainer();
        $themeJs = $container->make('flarum.paths')->public.'/assets/forum-theme-'.self::TAGS.'.js';
        $this->assertStringNotContainsString('wardrobeProbe', (string) file_get_contents($themeJs));

        // An extension's JS changes, and the shared bundle is rebuilt the way
        // millwright:rebuild-assets does it: commit() and nothing else.
        $container->extend('flarum.assets.forum', fn ($assets) => $assets->js(
            fn ($sources) => $sources->addString(fn () => 'window.wardrobeProbe=1;')
        ));
        $container->make('flarum.assets.forum')->makeJs()->commit();

        // The next request — a fresh process, so nothing remembered from this one.
        $this->nextRequest()->jsUrlFor(self::TAGS);

        $this->assertStringContainsString('wardrobeProbe', (string) file_get_contents($themeJs));
    }

    #[Test]
    public function a_theme_is_not_rebuilt_while_the_shared_assets_stay_the_same()
    {
        $this->themes();
        $this->page();

        $theme = self::TAGS;
        $stale = (fn () => $this->isStale($theme))->call($this->nextRequest());

        $this->assertFalse($stale, 'A theme just built is stale again on the next request');
    }
}
