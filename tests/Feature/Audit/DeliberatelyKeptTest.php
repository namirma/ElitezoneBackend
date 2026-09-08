<?php

namespace Tests\Feature\Audit;

use App\Enums\Survey\SurveyStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the things this codebase keeps ON PURPOSE even though nothing
 * references them.
 *
 * Every entry here has already survived one deletion attempt. An over-engineering
 * audit greps for call sites, finds none, and cuts — which is correct for real rot
 * and wrong for a parked flow. On 2026-08-08 exactly that happened to
 * artisaninweb/laravel-soap: it was removed, committed and pushed before anyone
 * noticed CLAUDE.md line 2172 says to keep it. The reasoning lived only in a
 * 2535-line document, so it was only as strong as the reader's patience.
 *
 * This test moves that reasoning somewhere a grep cannot miss. When one of these
 * assertions fails, the message tells you what you deleted and why it was there —
 * then it is your call whether the reason still holds.
 *
 * Adding to this list is cheap. Removing from it should mean the parked flow was
 * actually cancelled, not that it still looks unused.
 *
 * @group audit-fixes
 */
class DeliberatelyKeptTest extends TestCase
{
    #[Test]
    public function the_soap_client_stays_in_composer_until_saze90_creds_are_replaced(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertArrayHasKey(
            'artisaninweb/laravel-soap',
            $composer['require'],
            <<<'WHY'

            artisaninweb/laravel-soap was removed from composer.json.

            It has no call sites, and that is the point. Badge data lives on the
            company's legacy site saze90.com. The tier (gold/silver/bronze) already
            arrives over HTTP via Saze90BadgeService, but the badge PURCHASE AMOUNT
            (users.badge_purchase_amount) does not — that only exists behind the SOAP
            op getUser at https://www.saze90.com/api/?wsdl, whose historical
            credentials are stale, so the call currently returns empty.

            Until fresh creds arrive, users.badge_purchase_amount stays NULL and the
            elite yar lifetime cap in WeighbridgeVatRouter::applyEliteYARLifetimeCap()
            is dormant (uncapped) — intended interim behaviour, not a bug. The
            dependency is parked against that work, per CLAUDE.md line ~2172.

            Delete it only once product confirms the saze90 SOAP path is abandoned.
            That is also the moment to decide whether the lifetime cap is dropped for
            good, because nothing else can fill that column.
            WHY
        );
    }

    #[Test]
    public function the_parked_invoice_blades_are_not_cleaned_up_on_sight(): void
    {
        // Each maps to a document flow that was parked, not cancelled. FinancialCodes
        // documents the surplus/over-delivery path (docs J/K/M) as removed per spec,
        // and invoice_default was the old silent fallback that InvoiceGenerator::
        // getViewName() now throws instead of using. They are formatted Persian
        // templates and the starting point if any of those flows returns.
        $parked = [
            'invoice_default' => 'old silent fallback, removed from getViewName() but kept as a template',
            'invoice' => 'generic invoice template',
            'refund_report' => 'surplus/over-delivery path (spec docs J/K/M)',
            'supplementary_receipt' => 'surplus/over-delivery path (spec docs J/K/M)',
            'supplementary_report' => 'surplus/over-delivery path (spec docs J/K/M)',
        ];

        foreach ($parked as $view => $why) {
            $this->assertFileExists(
                resource_path("views/pdf/{$view}.blade.php"),
                "resources/views/pdf/{$view}.blade.php was deleted. It is unreferenced on "
                ."purpose — {$why}. These are parked document flows, not rot; CLAUDE.md "
                ."says to leave them and not 'fix' the missing references."
            );
        }
    }

    #[Test]
    public function the_survey_enum_survives_the_feature_being_switched_off(): void
    {
        $this->assertTrue(
            enum_exists(SurveyStatus::class),
            <<<'WHY'

            App\Enums\Survey\SurveyStatus was deleted.

            It has zero references because the surveys feature is switched off, not
            because it is dead. config/features.php → order_surveys defaults to false
            (product decision, 2026-07-25), so the routes are never registered and
            nothing reaches the enum. The rest of the module — the order_surveys
            table, model, controller, request, resource and both PDF Blades — is
            deliberately intact so re-enabling is one env var.

            Deleting the enum makes that one-line revival a code change instead.
            WHY
        );
    }
}
