<?php

declare(strict_types=1);

namespace App\Support\Guide;

use App\Models\Company;

/**
 * One article of Företagsguiden (docs/foretagsguiden/SKRIVREGLER.md), read
 * from its front matter. The cache keeps arrays only, so the guide stores
 * toArray() and builds the object again when it is read. The HTML is only
 * set on the article a page shows.
 *
 * @phpstan-type Source array{title: string, url: string}
 * @phpstan-type ArticleData array{path: string, section: string, slug: string, title: string, description: string, order: int, legalForms: list<string>, industries: list<string>, routes: list<string>, keywords: list<string>, sources: list<Source>, reviewedOn: ?string, humanReviewOn: ?string, legalVerify: list<string>, minutes: int}
 */
final readonly class Article
{
    /**
     * @param  list<string>  $legalForms  LegalForm values; empty when it applies to every form
     * @param  list<string>  $industries  Industry values; empty when it applies to every industry
     * @param  list<string>  $routes  named routes where the tip panel shows the article
     * @param  list<string>  $keywords
     * @param  list<Source>  $sources
     * @param  list<string>  $legalVerify  open questions still being checked
     */
    public function __construct(
        public string $path,
        public string $section,
        public string $slug,
        public string $title,
        public string $description,
        public int $order,
        public array $legalForms,
        public array $industries,
        public array $routes,
        public array $keywords,
        public array $sources,
        public ?string $reviewedOn,
        public ?string $humanReviewOn,
        public array $legalVerify,
        public int $minutes,
        public ?string $html = null,
    ) {}

    /** @param  ArticleData  $data */
    public static function fromArray(array $data, ?string $html = null): self
    {
        return new self(
            $data['path'],
            $data['section'],
            $data['slug'],
            $data['title'],
            $data['description'],
            $data['order'],
            $data['legalForms'],
            $data['industries'],
            $data['routes'],
            $data['keywords'],
            $data['sources'],
            $data['reviewedOn'],
            $data['humanReviewOn'],
            $data['legalVerify'],
            $data['minutes'],
            $html,
        );
    }

    /** @return ArticleData */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'section' => $this->section,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'order' => $this->order,
            'legalForms' => $this->legalForms,
            'industries' => $this->industries,
            'routes' => $this->routes,
            'keywords' => $this->keywords,
            'sources' => $this->sources,
            'reviewedOn' => $this->reviewedOn,
            'humanReviewOn' => $this->humanReviewOn,
            'legalVerify' => $this->legalVerify,
            'minutes' => $this->minutes,
        ];
    }

    public function isOverview(): bool
    {
        return $this->slug === Guide::OVERVIEW;
    }

    public function url(): string
    {
        return Guide::url($this->section, $this->slug);
    }

    /** Whether the article is written for the company's legal form and industry. */
    public function appliesTo(Company $company): bool
    {
        return Guide::matches($this->legalForms, $this->industries, $company);
    }
}
