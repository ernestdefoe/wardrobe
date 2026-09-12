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
 * enabled is ignored rather than trusted.
 */
class ThemeRegistry
{
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

        $configured = json_decode((string) $this->settings->get('wardrobe.themes'), true);

        if (! is_array($configured)) {
            return $this->ids = [];
        }

        return $this->ids = array_values(array_filter(
            array_map('strval', $configured),
            fn (string $id) => $this->extensions->isEnabled($id)
        ));
    }

    /**
     * The theme a forum shows when a member has expressed no preference.
     */
    public function default(): ?string
    {
        $default = $this->settings->get('wardrobe.default');

        return in_array($default, $this->ids(), true) ? $default : null;
    }

    /**
     * The theme this actor should be served, or null to leave the shared
     * stylesheet alone.
     */
    public function resolve(User $actor): ?string
    {
        $chosen = $actor->isGuest() ? null : $actor->getPreference('wardrobeTheme');

        if (is_string($chosen) && in_array($chosen, $this->ids(), true)) {
            return $chosen;
        }

        return $this->default();
    }
}
