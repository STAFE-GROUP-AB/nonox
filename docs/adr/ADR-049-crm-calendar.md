# ADR-049: CRM calendar (stage 6)

- Status: **Accepted, implemented**; the two-way sync is built but **dormant**
  until the owner has registered the apps with Google and Microsoft (see
  "Owner actions") and a company switches it on
- Date: 2026-11-04
- Builds on: ADR-043 (CRM, stage 6 of its plan), ADR-037 (Planering's
  calendar link, whose token handling is reused), ADR-017 (modules),
  ADR-005 (tenancy), ADR-006 (audit log)

## Context

The owner's brief (translated): "Tasks, calls and meetings with calendar sync
to Google and Outlook." ADR-043's plan for stage 6: "Calendar export (ICS)
first, then two-way calendar and mail sync with Google and Microsoft once app
registrations exist." No app is registered with Google or Microsoft yet.

CRM activities (ADR-043) are notes, calls, meetings, e-mails and tasks with an
optional due time and an assigned user; a deal's next step is such an
activity. Until now they existed only inside NONOX.

## Decision

Three ways into a calendar, one rule set (`CalendarEntries`) for what may be
in a calendar and with how much, and one iCalendar writer (`App\Support\Calendar\Ics`,
`CalendarDocument`).

### What may be in a calendar

- Open calls, meetings and tasks with a time, assigned to the user, from 30
  days back to a year ahead. A deal's next step is one of them. Done
  activities, notes and e-mails are not.
- **Never**: activities of a person whose processing is restricted (art. 18)
  or of a customer with restricted processing. A calendar copy is processing
  beyond storage.
- **How much.** By default an event says only `<Typ>: <företag>` (for
  example "Möte: Bygg AB") with a link back to the account card; a private
  person's account is not named at all ("Samtal: privatkund"). With details,
  which the user turns on after a warning: the subject, place, deal, the
  activity's text and the person's name. A person marked Kontakta inte is
  never named and the text is never copied, whatever the choice. Nothing from
  the ledger is ever in a calendar, so a seller's limits on a private
  person's ledger (ADR-043) hold.
- **The company decides** (CRM settings, `crm:settings`): `calendar_details`
  (default on) allows users to choose details; off forces the minimal form
  everywhere. `calendar_sync` (default **off**) allows connecting Google or
  Microsoft; it is only shown while a provider is configured. Taking it back
  disconnects every connection within one sync round.

### 1. Subscription link (`/kunder/kalender/{token}.ics`)

- **Per user and company**, not across companies. A user in several companies
  (an accounting firm) makes one link per company. Reasons: tenancy stays
  trivial (the token names one company and one user), the link dies with the
  membership, and the company's details setting applies cleanly.
- The token is 48 random letters and digits; only its SHA-256 is stored
  (`crm_calendar_feeds`), so the link is shown once. At most one active link
  per user and company (partial unique index). A new link revokes the old.
  "Stäng av länken" revokes it at once. Same pattern as Planering's link.
- No login (calendar programs cannot sign in). Every request checks: token
  active, company not archived, CRM on, the user still a member with
  `crm:view`. Every failure is the same 404. `last_used_at` is written at
  most once an hour.
- Throttled 30 per minute per IP (`crm-calendar`), `SecurityHeaders`,
  `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: private,
  no-store`.
- RFC 5545: CRLF, folding at 75 octets without splitting UTF-8, TEXT
  escaping, `VTIMEZONE` for Europe/Stockholm (EU rule), local `DTSTART;TZID`,
  stable `UID` `crm-activity-{id}@{host}`, `SEQUENCE` = the activity's
  `calendar_sequence`, `LAST-MODIFIED`, `REFRESH-INTERVAL`/`X-PUBLISHED-TTL`
  30 minutes, `CLASS:PRIVATE`. Done or cancelled activities are simply left
  out, so subscribing calendars drop them on the next fetch.
- Leaving the company (`TeamMemberRemoved`) revokes the link; deleting the
  account (`DeleteAccount` now dispatches `UserAccountDeleting`) deletes it.
- The Kalender page (`/kunder/kalender`, `crm:view`, in the CRM menu) makes,
  replaces and switches off the link, sets the details choice, and explains
  subscribing in Google Calendar, Outlook and Apple Calendar.

### 2. One event, and an invitation

- **Lägg till i kalendern** on a planned activity downloads it as an `.ics`
  file (`/kunder/aktiviteter/{id}/kalenderfil`, signed in, `crm:view`).
  The user downloads it on purpose, so it carries the details, still without
  a person marked Kontakta inte.
- **Skicka inbjudan** (a checkbox when planning a meeting, and a button on a
  planned meeting) e-mails the meeting to its CRM person with an `.ics`
  attachment (`METHOD:REQUEST`, the user as `ORGANIZER` and Reply-To, the
  person as `ATTENDEE`). Opt-in per meeting. The invitation carries the
  subject (or "Möte med <företag>"), time and place, **never the activity's
  text** (an internal note). Refused for a person marked Kontakta inte, an
  address or number on the suppression list, and everything `WinBackBlock`
  refuses (restricted, anonymised or inactive customer; a private person's
  account with someone marked Kontakta inte). Logged as a done e-mail
  activity on the account and audited with ids. Sending again after a move
  sends the higher `SEQUENCE`, which updates the receiver's copy.
- An activity now has an optional length (`duration_minutes`, 5–1440;
  default 60 for a meeting, 15 otherwise) and place (`location`).

### 3. Two-way sync with Google and Microsoft (dormant)

- **Driver interface** `CalendarProvider` with `GoogleCalendarProvider`
  (Calendar API v3) and `MicrosoftGraphProvider` (Graph v1.0), plain HTTP
  through Laravel's client; **no SDK** was added. Configured by
  `services.google_calendar` and `services.microsoft_graph` (client id,
  secret, optional redirect, Microsoft tenant, default `common`). Without
  a client id and secret a provider is invisible: no screen, the connect
  routes answer 404, the observer queues nothing, `crm:calendar-sync` does
  nothing. All tests use `Http::fake` with `preventStrayRequests`.
- **OAuth 2.0** authorization code with **PKCE (S256)** and a random state,
  both in the session with the user and company; the callback checks all of
  them before the code is exchanged. Redirect URIs (Swedish paths):
  `https://nonox.app/kunder/kalender/koppla/google/svar` and
  `https://nonox.app/kunder/kalender/koppla/microsoft/svar`.
- **Per user and company**, one connection (`crm_calendar_connections`).
  Access token, refresh token and the account address are `encrypted` casts
  (application key); hidden from serialisation; never logged or audited.
  The user picks one writable calendar (default the primary one).
- **NONOX → calendar**: an observer raises `calendar_sequence` on every change
  a calendar must see and, while a provider is configured, queues
  `SyncCalendarActivity` after commit for the assignee and a former assignee.
  `crm:calendar-sync` (every 15 minutes) queues a full round per active
  connection. Events are written with the same minimal/detailed rules,
  `visibility`/`sensitivity` private; done, reassigned or no longer allowed
  activities are removed. `crm_calendar_events` maps each event to its
  activity with the sequence last written.
- **Calendar → NONOX, only for events NONOX wrote**: Google's incremental
  `syncToken` (410 → read everything again) and Graph's
  `calendarView/delta` (`deltaLink` as the cursor; 410 → again). Every
  other event in the user's calendar is read past and **never stored**.
  A moved event moves the activity (time and length). A cancelled or deleted
  event closes the activity with the note "Inställd i kalendern", except the
  last open step of an open deal, which stays open (a deal never loses its
  next step from outside; audited `crm.calendar_cancel_kept`).
- **Conflict policy**: NONOX owns the content (subject, text, person, deal);
  the calendar may change only time, length and cancellation. When both
  changed since the last write (`calendar_sequence` > the mapping's
  `synced_sequence`), **NONOX wins** and the next push overwrites the
  calendar. A change read from the calendar sets the mapping's sequence to
  the new one, so it is not written back.
- **Tokens**: refreshed two minutes before expiry and once on a 401. A
  refused refresh (`invalid_grant`) puts the connection in ERROR, audited,
  and the page asks the user to connect again.
- **Revocation**: "Koppla bort" stops the connection at once and a job
  removes NONOX's events, revokes the grant (Google: `oauth2.googleapis.com/revoke`)
  and deletes the row. Graph has no call that revokes one delegated grant
  (only all the user's sessions), so the page tells the user to remove the
  app under their Microsoft account. Leaving the company and the company
  taking back `calendar_sync` disconnect the same way. Deleting the account
  deletes the tokens in the same transaction and revokes after commit,
  without removing events (the calendar is the user's own).
- **Mail sync** is not built. `MailSyncProvider` fixes the interface: the
  same connection with added scopes by a separate consent, only messages
  to or from CRM people and accounts, headers until opened, Kontakta inte
  and the suppression list respected, sending from the user's own mailbox.
  Stage 5 (BCC logging) is separate.

### Audit (ids, providers and choices only)

`crm.calendar_feed_issued`, `crm.calendar_feed_revoked`,
`crm.calendar_details_set`, `crm.meeting_invitation_sent`,
`crm.calendar_connected`, `crm.calendar_reconnected`, `crm.calendar_chosen`,
`crm.calendar_connection_failed`, `crm.calendar_disconnected`,
`crm.calendar_moved`, `crm.calendar_cancelled`, `crm.calendar_cancel_kept`.

## Owner actions to switch the sync on

Nothing here is needed for the link, the file or the invitation.

### Google (Google Cloud console)

1. Create a project (for example "NONOX") and enable the **Google Calendar API**.
2. **Google Auth Platform → Branding**: app name NONOX, support e-mail, logo,
   home page `https://nonox.app`, privacy policy `https://nonox.app/integritetspolicy`,
   terms `https://nonox.app/villkor`, authorised domain `nonox.app` (verified
   in Google Search Console).
3. **Audience**: External. **Data access**: the scopes `openid`, `email`
   (`.../auth/userinfo.email`), `https://www.googleapis.com/auth/calendar.events`
   and `https://www.googleapis.com/auth/calendar.calendarlist.readonly`.
4. **Verification**: both calendar scopes are *sensitive*. Before
   verification only listed test users (at most 100) can connect, they see
   an "unverified app" warning, and refresh tokens of an app in *Testing*
   expire after 7 days. Submit for verification with a justification per
   scope and a demo video of the connect flow and how the scopes are used;
   no CASA security assessment is needed for these scopes (it is for
   *restricted* ones such as Gmail, which mail sync would need).
   TODO(verify-at-registration): Google's current review requirements and
   timelines, and that `calendar.calendarlist.readonly` is still offered as
   its own scope (otherwise use `calendar.readonly` for the list).
5. **Clients → Create client**: type *Web application*, authorised redirect
   URI `https://nonox.app/kunder/kalender/koppla/google/svar` (plus
   `http://nonox.test/kunder/kalender/koppla/google/svar` for local tests).
6. Put `GOOGLE_CALENDAR_CLIENT_ID` and `GOOGLE_CALENDAR_CLIENT_SECRET` in the
   site's environment in Forge.

### Microsoft (Microsoft Entra admin center)

1. **App registrations → New registration**: name NONOX; supported account
   types *Accounts in any organizational directory and personal Microsoft
   accounts*; redirect URI (platform *Web*)
   `https://nonox.app/kunder/kalender/koppla/microsoft/svar`.
2. **Certificates & secrets**: a client secret (at most 24 months; put the
   renewal in the calendar).
3. **API permissions → Microsoft Graph → Delegated**: `Calendars.ReadWrite`,
   `User.Read`, `offline_access`, `openid`, `email`. No admin consent is
   required for these, but an organisation may have turned user consent off,
   and then its admin must approve NONOX.
4. **Branding & properties**: publisher domain `nonox.app`, and **publisher
   verification** (needs a Microsoft AI Cloud Partner Program ID). Without it
   users in many organisations cannot consent on their own.
   TODO(verify-at-registration): Microsoft's current consent rules for
   unverified multitenant apps.
5. Put `MICROSOFT_GRAPH_CLIENT_ID`, `MICROSOFT_GRAPH_CLIENT_SECRET` (and
   `MICROSOFT_GRAPH_TENANT=common`) in the environment.

Then a company switches on **Användarna får koppla sin Google- eller
Microsoft-kalender** under CRM → Inställningar, and each user connects on the
Kalender page.

## GDPR

- **The link and the connection are personal data of the user** (and the
  activities in them may be personal data of the CRM's contacts). NONOX
  processes them as the company's processor (DPA), like the rest of the CRM.
- **The subscription link** sends data to whatever calendar service the user
  subscribes with. Minimal by default; details only by the user's choice
  within the company's setting, after a warning that the link works without
  sign-in and that the calendar service stores what it fetches.
- **Google and Microsoft are not NONOX's sub-processors**: NONOX never stores
  CRM data with them on its own account. Writing to a connected calendar is a
  disclosure, made on the company's instruction (the `calendar_sync` setting)
  by the user, to the user's own calendar provider. For a Google Workspace or
  Microsoft 365 account of the company, the company's own agreement with that
  provider governs (the company is controller, the provider its processor).
  For a personal account, the provider acts under the user's own terms; the
  company decides whether that is acceptable, which is why `calendar_sync` is
  off by default. Third-country transfer: Google LLC and Microsoft Corporation
  are certified under the EU–US Data Privacy Framework; data may be stored
  outside the EU. The sub-processor list says this under "Not sub-processors".
- Retention: the link until revoked, replaced, the membership ends or the
  account is deleted; the connection and its tokens until disconnected, the
  membership ends, the company takes back the setting, or the account is
  deleted. The event map follows the connection.
- `TODO(legal-verify)`: (1) whether a company that allows `calendar_sync` must
  name Google/Microsoft as recipients in its own information to contact
  persons (art. 13/14) and record the transfer basis in its own register;
  (2) whether a personal Google/Microsoft account of an employee is an
  acceptable recipient at all for a company's CRM data, or should be refused
  (only organisation accounts); (3) the default of `calendar_details`
  (currently on, the user still opts in); (4) whether sending a meeting
  invitation to a contact person under legitimate interest needs anything
  beyond the existing information duty.

## Consequences

- `crm_activities` gains `duration_minutes`, `location`,
  `calendar_sequence` and `invited_at`; `LogActivity` accepts the first two.
  The public API does not expose them yet.
- `DeleteAccount` dispatches `UserAccountDeleting` inside its transaction.
- The stage 1 settings test compares with `toMatchArray`, since CRM's
  settings now include the two calendar switches.
- Open items: push notifications (Google `watch`, Graph subscriptions) are
  not built (15-minute polling); a meeting deleted in NONOX through a
  cascade (prospect erased) leaves its event in a connected calendar until
  the user removes it; a cancellation is not sent to an invited person
  (`METHOD:CANCEL`); the API does not take length or place.
