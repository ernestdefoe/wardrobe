import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import FieldSet from 'flarum/common/components/FieldSet';
import Select from 'flarum/common/components/Select';

import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import { wardrobe, canChoose, chooseTheme } from './theme';

/**
 * Let a member pick which theme they read the forum in.
 *
 * Added to their own settings page, not the admin area: the admin setting
 * chooses the forum's default, this chooses the reader's. The empty option is a
 * real stored value meaning "follow the forum", so a member who has never
 * touched this — or who goes back to it — tracks the owner's choice rather
 * than being pinned to whatever it happened to be that day.
 */
export default function addThemePicker(): void {
  /*
   * 🚨 The first argument is a module PATH, not an imported component.
   *
   * SettingsPage is code-split: it is not in the registry until someone
   * navigates to /settings. `import SettingsPage from '...'` compiles to a
   * registry lookup that runs at boot, returns undefined, and
   * `extend(undefined.prototype, …)` throws inside the initializer — taking the
   * whole forum down on every page, not just the settings page.
   */
  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (this: any, items: ItemList<Mithril.Children>) {
    // One theme is not a choice, and a forum that has turned member choice off
    // should show nothing at all rather than a disabled control.
    if (!canChoose()) return;

    const data = wardrobe();

    const user = this.user;

    if (!user) return;

    const options: Record<string, Mithril.Children> = { '': app.translator.trans('ernestdefoe-wardrobe.forum.theme.follow_forum') };

    data.themes.forEach((theme) => {
      options[theme.id] = theme.title;
    });

    items.add(
      'wardrobeTheme',
      // Same shape core gives its own sections, so this reads as one of them
      // rather than as something bolted on underneath.
      <FieldSet className="Settings-wardrobeTheme FieldSet--min" label={app.translator.trans('ernestdefoe-wardrobe.forum.theme.heading')}>
        <Select value={user.preferences()?.wardrobeTheme ?? ''} options={options} onchange={(value: string) => chooseTheme(value)} />
        <span className="helpText">{app.translator.trans('ernestdefoe-wardrobe.forum.theme.help')}</span>
      </FieldSet>,
      // Core spaces its sections ten apart from 100 (account) down to 60
      // (colour scheme). 64 lands this with the other "how it looks to me"
      // settings, just below Cascade's preset picker and above colour scheme.
      64
    );
  });
}
