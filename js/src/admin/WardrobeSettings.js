import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';

const THEMES = 'wardrobe.themes';
const DEFAULT = 'wardrobe.default';
const ALLOW = 'wardrobe.allow_user_choice';
const OFFER_FLARUM = 'wardrobe.offer_flarum';

// Matches ThemeRegistry::FLARUM — no extension id can be a bare word.
const FLARUM = 'flarum';

const SELF = 'ernestdefoe-wardrobe';

const t = (key, params) => app.translator.trans(`ernestdefoe-wardrobe.admin.settings.${key}`, params);

/**
 * Wardrobe's whole settings page.
 *
 * It is one custom component rather than three registered settings because the
 * default-theme dropdown's options depend on which themes are ticked *right
 * now*, including unsaved ticks. Core calls an object-form setting's callback
 * once, at `beforeMount` — its options would be frozen at boot, so the dropdown
 * would offer yesterday's themes. A custom setting is called on every render.
 */
export default class WardrobeSettings extends Component {
  view() {
    const page = this.attrs.page;
    const installed = installedThemes();
    const chosen = chosenThemes(page);
    const choices = choicesOf(page, chosen);

    return (
      <div className="WardrobeSettings">
        <div className="Form-group">
          <label>{t('themes_label')}</label>
          <div className="helpText">{t('themes_help')}</div>
          {installed.length === 0 ? (
            <div className="WardrobeSettings-empty helpText">{t('no_themes')}</div>
          ) : (
            installed.map((ext) => this.themeSwitch(page, ext, chosen))
          )}
        </div>

        {chosen.length > 0 ? (
          <div className="Form-group">
            <Switch state={offersFlarum(page)} onchange={(value) => toggleFlarum(page, value)}>
              {t('offer_flarum_label')}
            </Switch>
            <div className="helpText">{t('offer_flarum_help')}</div>
          </div>
        ) : null}

        {chosen.length > 0 ? (
          <div className="Form-group">
            <label>{t('default_label')}</label>
            <div className="helpText">{t('default_help')}</div>
            <Select
              value={defaultTheme(page, choices)}
              options={Object.fromEntries(choices.map((id) => [id, titleOf(id)]))}
              onchange={(value) => page.setting(DEFAULT)(value)}
            />
          </div>
        ) : null}

        <div className="Form-group">
          <Switch state={allowsUserChoice(page)} onchange={(value) => page.setting(ALLOW)(value ? '1' : '0')}>
            {t('allow_user_choice_label')}
          </Switch>
          <div className="helpText">{t('allow_user_choice_help')}</div>
        </div>
      </div>
    );
  }

  themeSwitch(page, extension, chosen) {
    const on = chosen.includes(extension.id);

    return (
      <div className="WardrobeSettings-theme" key={extension.id}>
        <Switch state={on} onchange={() => toggleTheme(page, extension.id, !on)}>
          {extension.extra['flarum-extension'].title || extension.id}
        </Switch>
      </div>
    );
  }
}

/**
 * Every enabled extension that says it is a theme.
 *
 * Flarum extensions declare `extra.flarum-extension.category`, and every theme
 * in the ecosystem sets it to `theme` — so the owner never types an extension
 * id, they tick the themes they already installed. A theme that fails to
 * declare its category simply will not appear, which is the right pressure: the
 * fix belongs in that theme's composer.json.
 */
function installedThemes() {
  return Object.values(app.data.extensions || {})
    .filter((extension) => extension.id !== SELF)
    .filter((extension) => extension.extra?.['flarum-extension']?.category === 'theme')
    .filter((extension) => isExtensionEnabled(extension.id))
    .sort((a, b) => (a.extra['flarum-extension'].title || a.id).localeCompare(b.extra['flarum-extension'].title || b.id));
}

function chosenThemes(page) {
  try {
    const parsed = JSON.parse(page.setting(THEMES)() || '[]');

    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    // A hand-edited settings row should not take the admin page down with it.
    return [];
  }
}

/** Matches ThemeRegistry::choices(): plain Flarum only alongside real themes. */
function choicesOf(page, chosen) {
  return chosen.length && offersFlarum(page) ? [...chosen, FLARUM] : chosen;
}

function offersFlarum(page) {
  return !!Number(page.setting(OFFER_FLARUM)() || 0);
}

function toggleTheme(page, id, on) {
  const next = on ? [...chosenThemes(page), id] : chosenThemes(page).filter((existing) => existing !== id);

  page.setting(THEMES)(JSON.stringify(next));
  keepDefaultReal(page);
}

function toggleFlarum(page, on) {
  page.setting(OFFER_FLARUM)(on ? '1' : '0');
  keepDefaultReal(page);
}

// Untick whatever was the default and the stored default is dangling. Point it
// at something real so the dropdown never shows an empty selection and the
// forum never falls back to the shared stylesheet by accident.
function keepDefaultReal(page) {
  const choices = choicesOf(page, chosenThemes(page));

  if (!choices.includes(page.setting(DEFAULT)())) {
    page.setting(DEFAULT)(choices[0] ?? '');
  }
}

function defaultTheme(page, choices) {
  const stored = page.setting(DEFAULT)();

  return choices.includes(stored) ? stored : choices[0];
}

/** Unset means yes — matching ThemeRegistry::allowsUserChoice(). */
function allowsUserChoice(page) {
  const value = page.setting(ALLOW)();

  return value === '' || value === null || value === undefined ? true : !!Number(value);
}

function titleOf(id) {
  if (id === FLARUM) return t('flarum_title');

  const extension = app.data.extensions?.[id];

  return extension?.extra?.['flarum-extension']?.title || id;
}
