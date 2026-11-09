<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Boardroom\Domain\NoticeWindow;
use App\Modules\Boardroom\Domain\RegisterState;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Models\ShareClass;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The boardroom's documents as PDF, in NONOX's own layout:
 *
 * - the share register at a date, in share number order (ABL 5 kap. 5 §).
 *   The full version is the one the company shows anyone who asks to
 *   inspect it (5 kap. 10 §) and at a general meeting (7 kap. 28 §). The
 *   version without personal numbers is a voluntary courtesy copy, labelled
 *   as such on every page; it does not fulfil the duty to keep the register
 *   available (ADR-070 addendum). TODO(legal-verify): L1.
 * - minutes approved in NONOX, with who approved and when;
 * - the notice of a general meeting with its numbered agenda (7 kap. 24 §).
 */
final class BoardroomPdf
{
    public static function register(Company $company, RegisterState $state, CarbonImmutable $date, bool $masked, User $user): string
    {
        return self::render(self::registerHtml($company, $state, $date, $masked, $user));
    }

    /** The register's HTML, also read by the tests that check no personal number reaches the courtesy copy. */
    public static function registerHtml(Company $company, RegisterState $state, CarbonImmutable $date, bool $masked, User $user): string
    {
        $classes = ShareClass::query()->orderBy('code')->get()->keyBy('id');

        return view('boardroom::pdf.register', [
            'company' => $company,
            'state' => $state,
            'date' => $date,
            'masked' => $masked,
            'classes' => $classes,
            'articles' => ArticlesVersion::applicable($date),
            'user' => $user,
            'createdAt' => CarbonImmutable::now(),
        ])->render();
    }

    public static function minutes(Company $company, Meeting $meeting, Minutes $minutes, User $user): string
    {
        $corrects = $minutes->corrects_minutes_id === null ? null : Minutes::query()->find($minutes->corrects_minutes_id);

        return self::render(view('boardroom::pdf.minutes', [
            'company' => $company,
            'meeting' => $meeting,
            'minutes' => $minutes,
            'body' => $minutes->body,
            'approver' => $user,
            'checker' => $minutes->checked_by === null ? null : User::query()->find($minutes->checked_by),
            'corrects' => $corrects,
        ])->render());
    }

    public static function notice(Company $company, Meeting $meeting): string
    {
        $items = AgendaItem::query()->where('meeting_id', $meeting->id)->orderBy('position')->get();
        $articles = ArticlesVersion::applicable($meeting->meeting_date);

        return self::render(view('boardroom::pdf.notice', [
            'company' => $company,
            'meeting' => $meeting,
            'items' => $items,
            'window' => NoticeWindow::for($meeting, $items, $articles),
            'articles' => $articles,
        ])->render());
    }

    private static function render(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
