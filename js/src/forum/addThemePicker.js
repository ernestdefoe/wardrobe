import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import FieldSet from 'flarum/common/components/FieldSet';
import Select from 'flarum/common/components/Select';

import { wardrobe, canChoose } from './theme';

/**
 * Let a member pick which theme they read the forum in.
 *
 * Added to their own settings page, not the admin area: the admin setting
 * chooses the forum's default, this chooses the reader's. The empty option is a
 * real stored value meaning "follow the forum", so a member who has never
 * touched this — or who goes back to it — tracks the owner's choice rather
 * than being pinned to whatever it happened to be that day.
 */
export default function addThemePicker() {
  /*
   * 🚨 The first argument is a module PATH, not an imported component.
   *
   * SettingsPage is code-split: it is not in the registry until someone
   * navigates to /settings. `import SettingsPage from '...'` compiles to a
   * registry lookup that runs at boot, returns undefined, and
   * `extend(undefined.prototype, …)` throws inside the initializer — taking the
   * whole forum down on every page, not just the settings page.
   */
  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (items) {
    // One theme is not a choice, and a forum that has turned member choice off
    // should show nothing at all rather than a disabled control.
    if (!canChoose()) return;

    const data = wardrobe();

    const user = this.user;

    if (!user) return;

    const options = { '': app.translator.trans('ernestdefoe-wardrobe.forum.theme.follow_forum') };

    data.themes.forEach((theme) => {
      options[theme.id] = theme.title;
    });

    items.add(
      'wardrobeTheme',
      // Same shape core gives its own sections, so this reads as one of them
      // rather than as something bolted on underneath.
      <FieldSet className="Settings-wardrobeTheme FieldSet--min" label={app.translator.trans('ernestdefoe-wardrobe.forum.theme.heading')}>
        <Select value={user.preferences()?.wardrobeTheme ?? ''} options={options} onchange={(value) => apply(user, value)} />
        <span className="helpText">{app.translator.trans('ernestdefoe-wardrobe.forum.theme.help')}</span>
      </FieldSet>,
      // Core spaces its sections ten apart from 100 (account) down to 60
      // (colour scheme). 64 lands this with the other "how it looks to me"
      // settings, just below Cascade's preset picker and above colour scheme.
      64
    );
  });
}

/**
 * Store the choice, then reload.
 *
 * 🚨 The reload is not laziness, it is correctness. Swapping the stylesheet
 * href in place would repaint the colours instantly — but theme JavaScript is
 * one bundle for every member and is gated on the `data-wardrobe-theme`
 * attribute at boot, so a live swap leaves the new theme's components
 * unrendered and the old theme's still on screen: the new colours over the old
 * layout. A reload is a second, and it is the whole theme.
 *
 * When themes can re-gate themselves at runtime this can become a live swap —
 * the server already exposes each theme's stylesheet through its own
 * revisioned URL.
 */
function apply(user, value) {
  user
    .savePreferences({ wardrobeTheme: value })
    .then(() => window.location.reload())
    .catch(() => {
      app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-wardrobe.forum.theme.save_failed'));
    });
}
