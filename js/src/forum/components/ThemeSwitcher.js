import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';
import Button from 'flarum/common/components/Button';

import { wardrobe, chooseTheme } from '../theme';

/**
 * A theme picker in the header, for everyone — including guests.
 *
 * The settings-page picker is where a member goes to change something on
 * purpose. This is where anyone *discovers* that they can: a forum offering
 * three themes should say so on the page, not in a preferences screen a guest
 * cannot even open.
 */
export default class ThemeSwitcher extends Component {
  view() {
    const { themes, active } = wardrobe();
    const tr = (key) => app.translator.trans(`ernestdefoe-wardrobe.forum.switcher.${key}`);

    return (
      <Dropdown
        className="WardrobeSwitcher-dropdown"
        buttonClassName="Button Button--icon WardrobeSwitcher"
        menuClassName="Dropdown-menu WardrobeSwitcher-menu"
        icon="fas fa-palette"
        caretIcon={null}
        // `label` fills the button's <span.Button-labelText> so the mobile
        // slide-out drawer can render this as a labelled row next to the other
        // menu items; CSS hides it on the desktop header, where it stays an
        // icon.
        label={tr('label')}
        accessibleToggleLabel={tr('label')}
        title={tr('label')}
      >
        {themes.map((theme) => (
          <Button
            key={theme.id}
            className="WardrobeSwitcher-option"
            icon={theme.id === active ? 'fas fa-check' : 'far fa-circle'}
            active={theme.id === active}
            onclick={() => chooseTheme(theme.id)}
          >
            {theme.title}
          </Button>
        ))}
      </Dropdown>
    );
  }
}
