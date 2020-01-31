<?php

namespace App\Jobs\Stripe;

use App\Contracts\StripeServiceContract;
use App\Models\Enrollment;
use App\Models\States\Enrollment\Cancelled;
use App\Models\States\Enrollment\Paid;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Source;

/**
 * Handles paid invoices, in case people pay out-of-band (via SEPA transfer or something).
 *
 * Called on source.chargeable
 * @author Roelof Roos <github@roelof.io>
 * @license MPL-2.0
 */
class HandleSourceChargeable extends StripeWebhookJob
{
    /**
     * Execute the job.
     *
     * @param Event $event
     * @param Invoice $invoice
     * @return void
     */
    public function process(Event $event, Source $source = null): void
    {
        // Check if the payment intent exists
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::wherePaymentSource($source->id)->first();

        // Skip if not found
        if ($enrollment === null) {
            logger()->info(
                'Recieved chargeable {source} for unknown enrollment',
                compact('source')
            );
            return;
        }

        // If the enrollment is already cancelled, don't do anything
        if ($enrollment->state instanceof Cancelled) {
            logger()->info(
                'Recieved chargeable {source} for cancelled enrollment',
                compact('source', 'enrollment')
            );

            // Stop
            return;
        }

        // Don't act on already paid invoices.
        if ($enrollment->state instanceof Paid) {
            logger()->info(
                'Recieved chargeable {source} for already paid enrollment',
                compact('source', 'enrollment')
            );

            // noop
            return;
        }

        /** @var StripeServiceContract $service */
        $service = app(StripeServiceContract::class);

        /** @var \Stripe\Invoice $invoice */
        $invoice = $service->getInvoice($enrollment);
        if (!$invoice) {
            logger()->notice(
                'Recieved chargeable {source} for enrollment without invoice',
                compact('source', 'enrollment')
            );

            // noop
            return;
        }

        if ($invoice->amount_remaining > $source->amount) {
            logger()->notice(
                'Recieved chargeable {source} for {invoice} of insufficient amount',
                compact('source', 'enrollment', 'invoice')
            );

            // noop
            return;
        }

        // Log result
        logger()->info(
            'Paying {invoice} with {source}.',
            compact('enrollment', 'invoice', 'source')
        );

        // Try to pay
        $service->payInvoice($enrollment, $source);
    }
}
