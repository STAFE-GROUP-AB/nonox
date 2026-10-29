<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Feedback after saving, creating, updating or removing something. Every such
 * action must tell the user, in plain Swedish, what happened.
 *
 * - In a Livewire component: use the ShowsToasts trait, $this->toast(...).
 * - Before a redirect: Toast::flash(...) shows it on the next page.
 */
final class Toast
{
    public const string SESSION_KEY = 'toasts';

    public static function flash(string $message, string $type = 'success'): void
    {
        $toasts = session()->get(self::SESSION_KEY, []);
        $toasts[] = ['message' => $message, 'type' => $type];

        session()->flash(self::SESSION_KEY, $toasts);
    }

    /**
     * Toasts waiting to be shown on this page, including Jetstream's banners.
     *
     * @return list<array{message: string, type: string}>
     */
    public static function pending(): array
    {
        $toasts = array_values(array_filter(
            (array) session()->get(self::SESSION_KEY, []),
            fn (mixed $toast): bool => is_array($toast) && is_string($toast['message'] ?? null),
        ));

        $banner = session()->get('flash.banner');

        if (is_string($banner) && $banner !== '') {
            $toasts[] = [
                'message' => $banner,
                'type' => session()->get('flash.bannerStyle') === 'danger' ? 'error' : 'success',
            ];
        }

        return array_map(fn (array $toast): array => [
            'message' => (string) $toast['message'],
            'type' => ($toast['type'] ?? 'success') === 'error' ? 'error' : 'success',
        ], $toasts);
    }
}
