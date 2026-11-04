<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Company;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\ModuleManager;

/**
 * The address a company's people BCC or forward e-mail to, to log it in the
 * CRM (ADR-048): the inbox's address with +crm (ADR-023). In the cloud
 * that is {slug}+crm@in.nonox.app; a self-hosted NONOX that reads its inbox
 * over IMAP uses the mailbox's own address with +crm, delivered to the same
 * mailbox by the company's mail server.
 */
final class CrmMailAddress
{
    public static function for(Company $company): string
    {
        $modules = app(ModuleManager::class);
        $imap = $modules->isEnabled(InboxModule::KEY, $company)
            ? MailboxSettings::fromModuleSettings($modules->settings(InboxModule::KEY, $company))
            : null;

        if ($imap !== null && str_contains($imap->username, '@')) {
            [$local, $domain] = explode('@', mb_strtolower($imap->username), 2);

            return "{$local}+".InboxAddress::CRM_ALIAS."@{$domain}";
        }

        return InboxAddress::forSlug(InboxAddress::mailbox($company)->slug, InboxAddress::CRM_ALIAS);
    }

    /** Whether this NONOX reads its mail over IMAP (self-hosted) rather than from the cloud's receiving domain. */
    public static function viaImap(Company $company): bool
    {
        $modules = app(ModuleManager::class);

        return $modules->isEnabled(InboxModule::KEY, $company)
            && MailboxSettings::fromModuleSettings($modules->settings(InboxModule::KEY, $company)) !== null;
    }
}
