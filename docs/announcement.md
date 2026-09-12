# Wardrobe — install several themes and let every member pick one

Flarum compiles **every** enabled extension's LESS into a single `forum.css`, and every extension's JavaScript into a single `forum.js`. Enable two themes at once and you get both stylesheets fighting in one file *and* both themes' components rendering for everyone — which is why a forum has always been one theme for everybody.

Wardrobe compiles those same sources **once per theme**, each time with every *other* theme left out of both bundles, and serves each visitor the pair that belongs to their choice.

No theme has to be modified, and core is not patched.

![Cascade, as one member sees it](https://raw.githubusercontent.com/ernestdefoe/wardrobe/main/screenshots/theme-cascade.png)

![Mosaic, as another member sees it at the same moment](https://raw.githubusercontent.com/ernestdefoe/wardrobe/main/screenshots/theme-mosaic.png)

Same forum, same moment, two different members. Not a recolour — each one gets that theme's stylesheet *and* its JavaScript, so its components render and the other theme's do not.

## What people get

A theme switcher in the header, one click, for members **and guests**:

![The header switcher, open](https://raw.githubusercontent.com/ernestdefoe/wardrobe/main/screenshots/header-switcher.png)

A picker on the member settings page, with "Use the forum's theme" as its first option:

![The picker on a member's settings page](https://raw.githubusercontent.com/ernestdefoe/wardrobe/main/screenshots/member-settings.png)

A member's choice is stored as a preference, so it follows them between devices. A guest's is a cookie that the server reads on the next request — so a guest gets a properly themed page rather than one repainted after boot. No cookie is written until a guest actually picks something, so untouched guest pages stay byte-identical and cacheable.

## What admins get

Tick which installed themes the forum offers, pick the default, or turn member choice off entirely to hold one look:

![Wardrobe's admin settings](https://raw.githubusercontent.com/ernestdefoe/wardrobe/main/screenshots/admin-settings.png)

Themes are detected from the `flarum-extension.category` each extension declares, so you never type an extension id.

Theme add-ons travel with their theme: an extension that *requires* a theme is left out wherever that theme is, so an add-on never loads reaching for exports that aren't there.

## What it costs

Measured on a forum with 60+ extensions, a Valkey cache and a queue worker:

- **0.11 ms** added to a page request at p50 (0.16 ms p90) — against a ~70 ms page. With and without the extension enabled, page latency over 50 requests was 70.2 ms vs 70.4 ms: indistinguishable.
- Compiling a theme costs ~450 ms of LESS, and it happens **on the queue**, off the request path. Forums with no worker fall back to compiling on first use; nothing breaks.
- A rebuild that changes nothing leaves every member's cached stylesheet alone — the revision only moves when the bytes a client would download actually change.
- A burst of traffic arriving on a stale theme produces **one compile per theme**, not one per request.
- Each member's stylesheet is *smaller* than today's shared one, because it carries one theme instead of all of them. On the test forum: 581 KB shared, versus 519 KB and 546 KB for the two per-theme sheets — each with zero rules from the other theme.

Themes are compiled whole, never as a shared sheet plus a per-theme delta. A theme that overrides core LESS variables (one of the two test themes overrides seven) would silently stop working if its overrides were compiled apart from the rules they target.

## Notes

- Switching theme reloads the page. It has to — the running page is the other theme's JavaScript, not just its colours.
- If you run a full-page cache in front of Flarum, add the `wardrobe_theme` cookie to its cache key.
- English and Spanish included.

## Install

```
composer require ernestdefoe/wardrobe
```

- **GitHub:** https://github.com/ernestdefoe/wardrobe
- **Packagist:** https://packagist.org/packages/ernestdefoe/wardrobe
- **Support:** https://ernestdefoe.online/d/91-wardrobe
- **Licence:** MIT

Feedback and bug reports welcome — especially from anyone running themes other than mine, since the whole point is that a theme shouldn't need to know Wardrobe exists.
