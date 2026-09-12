<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the actor their chosen theme.
 *
 * Three things happen here, all server-side and all in the first byte of HTML,
 * so nothing flashes and nothing waits for JavaScript:
 *
 *  1. the shared `forum.css` link is replaced with the theme's own stylesheet;
 *  2. `<html>` is stamped with the active theme id, which is the hook a theme
 *     gates its own JavaScript on (see the README — theme JS is still one
 *     bundle for every member, so a theme that ships behaviour must check);
 *  3. the theme list and the active choice go into the page payload, which is
 *     what the member's picker reads.
 *
 * Registered at priority 0 so it runs after core's `Content\Assets` (190) has
 * filled `Document::$css`.
 */
class ApplyTheme
{
    public function __construct(
        private ThemeRegistry $registry,
        private ThemeAssets $assets
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $chosen = $this->registry->resolve(
            RequestUtil::getActor($request),
            $this->guestChoice($request)
        );

        $document->payload['wardrobe'] = [
            'themes' => $this->registry->listing(),
            'active' => $chosen,
            'default' => $this->registry->default(),
            'allowUserChoice' => $this->registry->allowsUserChoice(),
        ];

        if ($chosen === null) {
            return;
        }

        $document->extraAttributes['data-wardrobe-theme'] = $chosen;

        $this->swap($document->css, '~/forum\.css(\?|$)~', $this->assets->cssUrlFor($chosen));

        $js = $this->assets->jsUrlFor($chosen);

        $this->swap($document->js, '~/forum\.js(\?|$)~', $js);

        // 🚨 The preload has to move with the script. Core builds its JS
        // preloads from $document->js at a higher priority than this callback,
        // so a swap that ignored them would preload the shared bundle AND load
        // the theme's — two copies of ~1.6 MB for every page view.
        foreach ($document->preloads as $index => $preload) {
            if (isset($preload['href']) && $js !== null && preg_match('~/forum\.js(\?|$)~', (string) $preload['href'])) {
                $document->preloads[$index]['href'] = $js;
            }
        }
    }

    /**
     * The theme a signed-out visitor picked from the header switcher.
     *
     * Only present once they have actually chosen: a guest who never touches
     * the switcher sends no cookie, so their page is byte-identical to every
     * other guest's and stays cacheable. 🚨 A forum that puts a full-page cache
     * in front of Flarum must add this cookie to its cache key, or one guest's
     * choice would be served to the next.
     */
    private function guestChoice(ServerRequestInterface $request): ?string
    {
        $cookie = $request->getCookieParams()['wardrobe_theme'] ?? null;

        return is_string($cookie) ? $cookie : null;
    }

    /**
     * Replace the one entry matching $pattern, leaving locale bundles and
     * anything an extension has added untouched.
     *
     * @param string[] $urls
     */
    private function swap(array &$urls, string $pattern, ?string $replacement): void
    {
        if ($replacement === null) {
            return;
        }

        foreach ($urls as $index => $existing) {
            if (preg_match($pattern, $existing)) {
                $urls[$index] = $replacement;

                return;
            }
        }
    }
}
