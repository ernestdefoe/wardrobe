import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';

import addThemePicker from './addThemePicker';
import ThemeSwitcher from './components/ThemeSwitcher';
import { canChoose } from './theme';

// NOTE: the Admin extender lives in js/src/admin/index.js and NOWHERE else. It
// touches admin-only globals; re-exporting it here would run it during forum
// boot and take the whole forum down.

app.initializers.add('ernestdefoe-wardrobe', () => {
  addThemePicker();

  extend(HeaderSecondary.prototype, 'items', function (items) {
    // Checked at render time, not at boot: `app.data.wardrobe` is in the page
    // payload, which is populated before initializers run, but the forum can
    // also turn member choice off while a tab is open.
    if (!canChoose()) return;

    // 25 puts it left of Search (30) and right of the session menu, matching
    // where theme-toggle sits on the forums that run both.
    items.add('wardrobe-switcher', ThemeSwitcher.component(), 25);
  });
});
