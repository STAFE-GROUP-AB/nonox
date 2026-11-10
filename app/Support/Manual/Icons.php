<?php

declare(strict_types=1);

namespace App\Support\Manual;

/**
 * The manual's icons as inline SVG (no icon font, nothing loaded from
 * elsewhere): one per section, by the `ikon` name in avsnitt.yaml, and one
 * per kind of notice. Simple 24×24 line drawings in the text colour.
 */
final class Icons
{
    private const array PATHS = [
        'rocket' => 'M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 15l-3-3c1-4 5-9 13-9 0 8-5 12-9 13zM14.5 9.5h.01M9 12H5l2-4h4M12 15v4l4-2v-4',
        'book' => 'M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5zM4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5M8 7h8M8 11h6',
        'invoice' => 'M6 2h9l5 5v15H6zM14 2v6h6M9 12h6M9 16h6M9 8h2',
        'inbox' => 'M3 13l3-9h12l3 9v7H3zM3 13h5l1 3h6l1-3h5',
        'bank' => 'M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18',
        'percent' => 'M19 5L5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'receipt' => 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
        'scale' => 'M12 3v18M7 21h10M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z',
        'checklist' => 'M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2',
        'calendar' => 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4M8 14h3M8 17h6',
        'wallet' => 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2zM16.5 14.5h.01',
        'handshake' => 'M8 12l3 3c.8.8 2 .8 2.8 0l4.2-4.2M2 10l4-4 4 2 3-2 3 1 6 5M2 10l6 7 2 1M22 12l-4 4',
        'clipboard' => 'M9 3h6v3H9zM9 4.5H6v16.5h12V4.5h-3M9 12l2 2 4-4M9 17h6',
        'building' => 'M4 21V5l8-3v19M12 8h8v13M2 21h20M8 8h.01M8 12h.01M8 16h.01M16 12h.01M16 16h.01',
        'settings' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
        'truck' => 'M2 6h12v10H2zM14 10h4l3 3v3h-7M6.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
        'person' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5',
        'glossary' => 'M4 4h11a3 3 0 0 1 3 3v14H7a3 3 0 0 1-3-3zM18 7h2v14h-2M8 9h6M8 13h4',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
        'users' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c0-3.5 3-6 7-6s7 2.5 7 6M16 3.5a4 4 0 0 1 0 7.5M18 15c2.4.6 4 2.8 4 6',
        'module' => 'M12 2l9 5v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10',
        'factory' => 'M2 21V11l5 3V11l5 3V11l5 3V4h4v17zM2 21h20M6 17h2M11 17h2M16 17h2',
        'box' => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10M7.5 5l9 4',
        'arrow' => 'M5 12h14M13 6l6 6-6 6',
        'code' => 'M8 7l-5 5 5 5M16 7l5 5-5 5',
        'alert-tip' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z',
        'alert-note' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 7.5h.01',
        'alert-important' => 'M4 4h16v13H8l-4 4zM12 8v4M12 14.5h.01',
        'alert-warning' => 'M12 3l10 18H2zM12 10v4M12 17.5h.01',
        'help' => 'M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.5V14M12 17.5h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    ];

    public static function svg(string $name, int $size = 22): string
    {
        $path = self::PATHS[$name] ?? self::PATHS['book'];

        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="'.$path.'"></path></svg>';
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }
}
