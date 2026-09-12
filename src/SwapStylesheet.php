<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Swaps the shared forum stylesheet for the actor's chosen theme.
 *
 * Runs at priority 0 — after core's `Content\Assets` (190) has filled
 * `Document::$css` — and replaces exactly one entry, leaving the locale
 * stylesheet and anything an extension has added untouched.
 */
class SwapStylesheet
{
    public function __construct(
        private ThemeRegistry $registry,
        private ThemeStylesheets $stylesheets
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $chosen = $this->registry->resolve(RequestUtil::getActor($request));

        if ($chosen === null) {
            return;
        }

        $url = $this->stylesheets->urlFor($chosen);

        if ($url === null) {
            return;
        }

        foreach ($document->css as $index => $existing) {
            if (preg_match('~/forum\.css(\?|$)~', $existing)) {
                $document->css[$index] = $url;

                return;
            }
        }
    }
}
