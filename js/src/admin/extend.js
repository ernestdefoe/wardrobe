import Admin from 'flarum/common/extenders/Admin';

import WardrobeSettings from './WardrobeSettings';

/**
 * Wardrobe's admin settings.
 *
 * These live in an Admin extender rather than inside `app.initializers.add`
 * because `app.extensionData` is registered by a core admin initializer that
 * may not have run when ours fires — the extender pipeline runs at a point in
 * the boot sequence where that ordering hazard does not exist.
 *
 * `customSetting`, not `setting`: core calls an object-form setting's callback
 * once at beforeMount, which would freeze the default-theme dropdown's options
 * at boot. A custom setting is called on every render, with the ExtensionPage
 * as `this` — which is how the component reaches `setting()` and gets saved by
 * the page's own Save button.
 */
export default [
  new Admin().customSetting(function () {
    return <WardrobeSettings page={this} />;
  }),
];
