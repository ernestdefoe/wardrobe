<?php

namespace Ernestdefoe\Wardrobe\Tests\integration\api;

use Flarum\Frontend\Event\AssetsRecompiled;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

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
    public function rebuilding_the_shared_assets_marks_every_theme_stale()
    {
        $this->themes();
        $this->app();

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new AssetsRecompiled());

        $this->assertGreaterThan(0, (int) $this->database()->table('settings')->where('key', 'wardrobe.stale_at')->value('value'));
    }
}
