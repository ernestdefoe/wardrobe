# Wardrobe

> **Status: spike.** The architecture is proved end to end on dev.ernestdefoe.online
> (Flarum 2.0.0-rc.8). There is no admin UI, no member-facing picker and no JS
> yet — configuration is two settings rows and a user preference.

Install several themes on one Flarum 2 forum and let every member choose the one
they see — the thing every other forum platform has had for twenty years.

## Why Flarum can't do this today

Flarum compiles **every** enabled extension's LESS into a single revisioned
`forum.css`. Two themes enabled means two themes in one file, fighting.

Wardrobe compiles that same source list once per theme, each time with every
*other* theme left out, and swaps the stylesheet link per request.

## How it works

| Piece | What it does |
|---|---|
| `ThemeRegistry` | which enabled extensions the owner has declared to be themes, and which one an actor should see |
| `ThemeStylesheets` | builds one `forum-theme-<id>.css` per theme by replaying the forum's own CSS sources and filtering by extension id |
| `SwapStylesheet` | a frontend content callback (priority 0) that replaces the `forum.css` entry in `Document::$css` |
| `InvalidateThemeStylesheets` | throws the theme sheets away whenever core rebuilds the shared one — **without this they go stale and stay stale** |

Three facts in core make it possible without patching anything:

1. `Extend\Frontend` registers extension LESS as
   `$sources->addFile($path, $moduleName)`, so every CSS source knows which
   extension it came from.
2. `Assets::$sources` is public and `flarum.assets.factory` builds a properly
   configured `Assets` for any name — including the LESS import overrides that
   `Extend\Theme` decorates the factory with, so themes that override core LESS
   files keep working.
3. `RevisionCompiler::getUrl()` commits when there is no revision, so a theme
   nobody has chosen is never compiled.

## Cost

Measured on dev.ernestdefoe.online (rc.8, 60+ extensions, Valkey cache, Horizon):

| | |
|---|---|
| Added to a page request | **0.11 ms** p50, 0.16 ms p90 — against a ~70 ms page |
| Page latency with vs without | 70.2 ms vs 70.4 ms p50 over 50 requests — indistinguishable |
| Compile, per theme | ~450 ms, off the request path on the queue |
| Rebuild that changes nothing | revision unchanged, so **no member re-downloads anything** |
| 20 concurrent requests on a stale theme | **1 compile per theme** (20 without the guard) |

The hot path is all closures — `Assets::css()` appends a callback, `makeCss()`
defers collection to compile time, `getUrl()` reads a revision — so nothing
walks the filesystem to render a page. There is deliberately no URL cache on
top: it would cost more to look up than it saves.

Guests all resolve to the forum default, so guest HTML keeps one stylesheet URL
and stays cacheable. Per-member choice is for members.

## Measured on the dev forum

Cascade and Mosaic enabled together, plus 60 other extensions:

| stylesheet | bytes | Armory rules | Cascade rules | Mosaic rules |
|---|---|---|---|---|
| `forum.css` (what Flarum serves today) | 581,174 | 42 | 324 | 227 |
| `forum-theme-ernestdefoe-cascade.css` | 519,361 | 42 | 324 | **0** |
| `forum-theme-ernestdefoe-mosaic.css` | 546,423 | 42 | **0** | 227 |

Every member gets a *smaller* stylesheet than the shared one, with no foreign
theme rules in it — and, because
themes are compiled whole — a theme that overrides core LESS variables still
works. Mosaic overrides seven of them, which is why a shared "common" sheet
plus a per-theme delta is **not** a viable optimisation: the overrides would
never reach the rules they are meant to change.

## Not solved yet

**Theme JS is still one bundle for everyone.** A theme that ships JS will run
its JS for every member whatever stylesheet they got. Themes have to gate their
own behaviour — Cascade already does exactly this for its presets, via an
attribute on `<html>`. Per-theme JS bundles are possible by the same seam as the
CSS one, but the JS file source is not tagged with its module name, so it is a
uglier job. Deliberately out of scope for v1.

## Configuration (spike)

```sql
REPLACE INTO settings (`key`,`value`) VALUES
  ('wardrobe.themes','["ernestdefoe-cascade","ernestdefoe-mosaic"]'),
  ('wardrobe.default','ernestdefoe-cascade');
```

A member's choice is the `wardrobeTheme` user preference, holding an extension
id. Guests and members with no choice get `wardrobe.default`.

## Licence

MIT
