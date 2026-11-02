<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Livewire\Hq\Concerns\ReadsAsHqAdmin;
use App\Support\Audit\Audit;
use App\Support\Hq\CustomerDirectory;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kunder in /hq (ADR-038): the companies and the users of the instance,
 * read-only and with metadata only. Opening it is written to the audit log.
 */
class Customers extends Component implements SpansCompanies
{
    use ReadsAsHqAdmin;
    use WithPagination;

    public const string COMPANIES = 'foretag';

    public const string USERS = 'anvandare';

    #[Url(as: 'flik', except: self::COMPANIES)]
    public string $tab = self::COMPANIES;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'modul', except: '')]
    public string $module = '';

    #[Url(as: 'arkiverade', except: '')]
    public string $archived = '';

    #[Url(as: 'kalla', except: '')]
    public string $source = '';

    #[Url(as: 'fran', except: '')]
    public string $from = '';

    #[Url(as: 'till', except: '')]
    public string $to = '';

    #[Url(as: 'ordning', except: 'nyast')]
    public string $sort = 'nyast';

    public function mount(): void
    {
        if (! in_array($this->tab, [self::COMPANIES, self::USERS], true)) {
            $this->tab = self::COMPANIES;
        }

        Audit::instance('hq.customers_viewed', null, ['tab' => $this->tab], $this->admin());
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === self::USERS ? self::USERS : self::COMPANIES;
        $this->sort = 'nyast';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'module', 'archived', 'source', 'from', 'to', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function render(CustomerDirectory $directory): View
    {
        $filters = [
            'search' => $this->search,
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort,
        ];

        return view('livewire.hq.customers', [
            'companies' => $this->tab === self::COMPANIES ? $directory->companies([...$filters, 'module' => $this->module, 'archived' => $this->archived]) : null,
            'users' => $this->tab === self::USERS ? $directory->users([...$filters, 'source' => $this->source]) : null,
            'moduleOptions' => $directory->moduleOptions(),
            'sources' => CustomerDirectory::sources(),
            'directory' => $directory,
        ]);
    }
}
