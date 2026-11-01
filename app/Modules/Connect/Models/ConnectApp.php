<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use Laravel\Passport\Client;

/**
 * An app registered on this installation that companies can connect
 * (ADR-032): Passport's OAuth client, registered with
 * `php artisan connect:app`. Not company data: the same app can be
 * connected to many companies, one grant each.
 *
 * @property string $id
 * @property string $name
 * @property bool $revoked
 */
class ConnectApp extends Client {}
