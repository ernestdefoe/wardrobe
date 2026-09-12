import app from 'flarum/forum/app';

export const COOKIE = 'wardrobe_theme';

/**
 * Everything the forum frontend knows about themes, put there by ApplyTheme.
 *
 * It is in the page payload rather than on the forum resource because the list
 * needs each theme's own title, and `app.data.extensions` — where titles live —
 * is an admin-only payload.
 */
export function wardrobe() {
  return app.data.wardrobe || { themes: [], active: null, default: null, allowUserChoice: true };
}

/** Whether to offer a choice at all: one theme is not a choice. */
export function canChoose() {
  const data = wardrobe();

  return data.allowUserChoice && data.themes.length > 1;
}

/**
 * Switch theme, for members and guests alike.
 *
 * A member's choice is a stored preference, so it follows them to every device
 * they sign in on. A guest has nowhere to store a preference, so their choice
 * is a cookie — which the server reads on the next request, exactly like the
 * preference, and which therefore produces a properly themed page rather than
 * one repainted after boot.
 *
 * 🚨 The cookie is only ever written when a guest actually picks something. A
 * guest who never touches this sends no cookie, so their HTML is identical to
 * every other guest's and stays cacheable — which is the whole point of not
 * setting it by default.
 *
 * 🚨 The reload is not laziness, it is correctness. Wardrobe serves each theme
 * its own compiled JS bundle as well as its own stylesheet, so the running page
 * is literally the other theme's code. Swapping the stylesheet href in place
 * would repaint the colours over components that belong to the theme being left
 * behind.
 */
export function chooseTheme(themeId) {
  const user = app.session.user;

  if (!user) {
    writeCookie(themeId);
    window.location.reload();

    return;
  }

  user
    .savePreferences({ wardrobeTheme: themeId })
    .then(() => window.location.reload())
    .catch(() => {
      app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-wardrobe.forum.theme.save_failed'));
    });
}

function writeCookie(themeId) {
  const year = 60 * 60 * 24 * 365;
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';

  document.cookie = `${COOKIE}=${encodeURIComponent(themeId)}; Path=/; Max-Age=${year}; SameSite=Lax${secure}`;
}
