<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\LandingPage;
use App\Support\Landing\SecurityReview;
use Illuminate\View\View;

/**
 * The public security and compliance page at /sakerhet: the latest review,
 * its method and a grade per area, with what is still open (SecurityReview).
 */
class SecurityPageController
{
    public function __invoke(): View
    {
        return view('start.security', [
            ...LandingPage::data(),
            'review' => SecurityReview::data(),
            'security' => SecurityReview::areas('sakerhet'),
            'regulations' => SecurityReview::areas('regelverk'),
            'totals' => SecurityReview::totals(),
            'securityEmail' => (string) (config('app.landing.security_email') ?: config('app.landing.privacy_email') ?: 'andreas@gozero.se'),
            'pageTitle' => 'Säkerhet och regelverk – NONOX',
            'pageDescription' => 'Hur säkert NONOX är och hur det följer GDPR, Bokföringslagen, AI-förordningen och andra regelverk: senaste granskningen, betyg per område och det som återstår.',
        ]);
    }
}
