<?php

declare(strict_types=1);

namespace App\Support\Guide;

use App\Models\Company;
use Illuminate\Support\Facades\Route;

/**
 * One step of the start plan (docs/foretagsguiden/startplan.json). The key
 * is stable forever: a company's progress is stored against it.
 *
 * @phpstan-type StepData array{key: string, title: string, text: string, legalForms: list<string>, industries: list<string>, condition: ?string, article: ?string, route: ?string, sources: list<array{title: string, url: string}>}
 */
final readonly class StartPlanStep
{
    /** The conditions a step can have, besides none. */
    public const array CONDITIONS = ['vat_registered', 'employer_registered', 'not_vat_registered'];

    /**
     * @param  list<string>  $legalForms
     * @param  list<string>  $industries
     * @param  list<array{title: string, url: string}>  $sources
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $text,
        public array $legalForms,
        public array $industries,
        public ?string $condition,
        public ?string $article,
        public ?string $route,
        public array $sources,
    ) {}

    /** @param  StepData  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['title'], $data['text'], $data['legalForms'], $data['industries'], $data['condition'], $data['article'], $data['route'], $data['sources']);
    }

    public function appliesTo(Company $company): bool
    {
        if (! Guide::matches($this->legalForms, $this->industries, $company)) {
            return false;
        }

        return match ($this->condition) {
            'vat_registered' => $company->isVatRegistered(),
            'not_vat_registered' => ! $company->isVatRegistered(),
            'employer_registered' => $company->employer_registered,
            default => true,
        };
    }

    /** The article that explains the step, when it is written. */
    public function articleUrl(): ?string
    {
        if ($this->article === null || ! str_contains($this->article, '/')) {
            return null;
        }

        [$section, $slug] = explode('/', $this->article, 2);

        return Guide::article($section, $slug, false) === null ? null : Guide::url($section, $slug);
    }

    /** Where the step is done in NONOX, when it is. */
    public function routeUrl(): ?string
    {
        return $this->route !== null && Route::has($this->route) ? route($this->route) : null;
    }
}
