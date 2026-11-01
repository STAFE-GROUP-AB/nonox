<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Domain\Firm\Memberships;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notiser (/notiser): the signed-in user's own notices, newest first. A
 * notice about a task opens it through the company switch, which refuses a
 * company the user no longer belongs to.
 */
class NotificationList extends Component implements SpansCompanies
{
    use ShowsToasts;
    use WithPagination;

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->toast(__('Alla notiser är markerade som lästa.'));
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.notifications.notification-list', [
            'notifications' => $user->notifications()->latest()->paginate(30),
            'unread' => $user->unreadNotifications()->count(),
            'memberOf' => Memberships::of($user)->ids(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
