<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Subscriber choices for "new article" emails.
 *
 * Stored as JSON on users.notification_preferences. Missing/null column means
 * the defaults below — so existing accounts get digests without a migration
 * backfill.
 */
class NotificationPreferences
{
    public const EACH = 'each';

    public const DAILY = 'daily';

    public const NONE = 'none';

    public const DEFAULT_FREQUENCY = self::DAILY;

    /**
     * @return array{newArticles: string, components: list<string>|null}
     */
    public function for(User $user): array
    {
        $stored = $user->notification_preferences ?? [];

        $frequency = $stored['new_articles'] ?? self::DEFAULT_FREQUENCY;
        if (! in_array($frequency, [self::EACH, self::DAILY, self::NONE], true)) {
            $frequency = self::DEFAULT_FREQUENCY;
        }

        $components = $stored['components'] ?? null;
        if (is_array($components) && $components === []) {
            $components = null;
        }

        return [
            'newArticles' => $frequency,
            'components' => is_array($components) ? array_values($components) : null,
        ];
    }

    /**
     * @param  array{new_articles?: string, newArticles?: string, components?: list<string>|null}  $input
     * @return array{newArticles: string, components: list<string>|null}
     */
    public function update(User $user, array $input): array
    {
        $current = $this->for($user);

        $frequency = $input['new_articles'] ?? $input['newArticles'] ?? $current['newArticles'];
        $components = array_key_exists('components', $input)
            ? $input['components']
            : $current['components'];

        if (is_array($components) && $components === []) {
            $components = null;
        }

        $user->update([
            'notification_preferences' => [
                'new_articles' => $frequency,
                'components' => $components,
            ],
        ]);

        return $this->for($user->refresh());
    }

    public function unsubscribe(User $user): array
    {
        return $this->update($user, ['new_articles' => self::NONE]);
    }

    /**
     * Whether this subscriber wants mail for a product in the given component.
     *
     * @param  array{newArticles: string, components: list<string>|null}  $prefs
     */
    public function wantsComponent(array $prefs, string $componentCode): bool
    {
        if ($prefs['newArticles'] === self::NONE) {
            return false;
        }

        if ($prefs['components'] === null) {
            return true;
        }

        return in_array($componentCode, $prefs['components'], true);
    }
}
