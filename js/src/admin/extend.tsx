import Admin from 'flarum/common/extenders/Admin';

import type ExtensionPage from 'flarum/admin/components/ExtensionPage';

import WardrobeSettings from './WardrobeSettings';

/**
 * Wardrobe's admin settings.
 *
 * Registered with the Admin extender, Flarum 2's declarative way to add
 * extension settings.
 *
 * `customSetting`, not `setting`: core calls an object-form setting's callback
 * once at beforeMount, which would freeze the default-theme dropdown's options
 * at boot. A custom setting is called on every render, with the ExtensionPage
 * as `this` — which is how the component reaches `setting()` and gets saved by
 * the page's own Save button.
 */
export default [
  new Admin().customSetting(function (this: ExtensionPage) {
    return <WardrobeSettings page={this} />;
  }),
];
