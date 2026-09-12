<?php

namespace Ernestdefoe\Wardrobe;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Which installed extensions the owner has declared to be themes, and which
 * one a given actor should see.
 *
 * Flarum has no concept of a theme — a theme is an extension that contributes
 * LESS — so the owner names them. Anything named here that is not currently
 * enabled is ignored rather than trusted, which is also what makes disabling a
 * theme in the AdminCP do the right thing without a second step.
 */
class ThemeRegistry
{
    public const THEMES = 'wardrobe.themes';
    public const DEFAULT = 'wardrobe.default';
    public const ALLOW_USER_CHOICE = 'wardrobe.allow_user_choice';
    public const PREFERENCE = 'wardrobeTheme';

    private ?array $ids = null;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions
    ) {
    }

    /**
     * @return string[] Extension ids, e.g. `ernestdefoe-cascade`.
     */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $configured = json_decode((string) $this->settings->get(self::THEMES), true);

        if (! is_array($configured)) {
            return $this->ids = [];
        }

        return $this->ids = array_values(array_filter(
            array_map('strval', $configured),
            fn (string $id) => $this->extensions->isEnabled($id)
        ));
    }

    /**
     * Every enabled extension, for working out which of them are companions of
     * a theme (an add-on that requires it).
     *
     * @return \Flarum\Extension\Extension[]
     */
    public function enabledExtensions(): array
    {
        return $this->extensions->getEnabledExtensions();
    }

    /**
     * The themes as the forum frontend needs them: an id and a name to show.
     *
     * The name comes from each extension's own `extra.flarum-extension.title`,
     * so a forum never has to retype what its themes are called — and the list
     * the member picks from can never drift from the list the owner chose.
     * `app.data.extensions` is an ADMIN-only payload, which is why this is
     * computed here rather than in the picker.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function listing(): array
    {
        return array_map(function (string $id) {
            $extension = $this->extensions->getExtension($id);

            return [
                'id' => $id,
                'title' => $extension?->getTitle() ?: $id,
            ];
        }, $this->ids());
    }

    /**
     * The theme a forum shows when a member has expressed no preference.
     *
     * Falls back to the first configured theme rather than to nothing: an owner
     * who adds themes and forgets to pick a default still gets a coherent
     * forum, not the shared stylesheet with every theme in it.
     */
    public function default(): ?string
    {
        $default = $this->settings->get(self::DEFAULT);

        if (in_array($default, $this->ids(), true)) {
            return $default;
        }

        return $this->ids()[0] ?? null;
    }

    public function allowsUserChoice(): bool
    {
        $value = $this->settings->get(self::ALLOW_USER_CHOICE);

        // Unset means yes. A forum that installs this extension wants members
        // choosing; an owner who wants one enforced look turns it off.
        return $value === null || (bool) $value;
    }

    /**
     * The theme this actor should be served, or null to leave the shared
     * stylesheet alone.
     *
     * 🚨 The "let members choose" setting is enforced HERE, not only by hiding
     * the picker. A preference already stored before the owner turned it off —
     * or written straight to the API — must stop taking effect the moment they
     * do.
     */
    public function resolve(User $actor, ?string $guestChoice = null): ?string
    {
        if (! $this->allowsUserChoice()) {
            return $this->default();
        }

        // A guest has nowhere to store a preference, so the switcher writes a
        // cookie instead and it is read here — the page is themed server-side
        // for a guest exactly as it is for a member, rather than repainted
        // after boot. The value is checked against the configured themes like
        // any other client-supplied string.
        $chosen = $actor->isGuest() ? $guestChoice : $actor->getPreference(self::PREFERENCE);

        if (is_string($chosen) && in_array($chosen, $this->ids(), true)) {
            return $chosen;
        }

        return $this->default();
    }

    /**
     * Sanitises what a member may store in their preference.
     *
     * '' is a real, kept value: it means "follow the forum's default", which is
     * the picker's first option. Anything else that is not a configured theme
     * becomes '' rather than being stored, so a hand-written API call cannot
     * park a member on a theme the owner has not offered.
     */
    public function validPreference(mixed $value): string
    {
        return is_string($value) && in_array($value, $this->ids(), true) ? $value : '';
    }
}
