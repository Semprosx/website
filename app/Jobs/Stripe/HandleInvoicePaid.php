<?php

namespace App\Jobs\Stripe;

use App\Models\Enrollment;
use App\Models\States\Enrollment\Cancelled;
use App\Models\States\Enrollment\Paid;
use App\Notifications\EnrollmentPaid;
use Stripe\Event;
use Stripe\Invoice;

/**
 * Handles paid invoices, in case people pay out-of-band (via SEPA transfer or something).
 *
 * Called on payment_intent.succeeded
 * @author Roelof Roos <github@roelof.io>
 * @license MPL-2.0
 */
class HandleInvoicePaid extends StripeWebhookJob
{
    /**
     * Execute the job.
     *
     * @param Invoice $invoice
     * @return void
     */
    protected function process(?Invoice $invoice): void
    {
        // Check if the payment intent exists
        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::wherePaymentInvoice($invoice->id)->first();

        // Skip if not found
        if ($enrollment === null) {
            logger()->info(
                'Recieved invoice change for unknown invoice {invoice}',
                compact('invoice')
            );
            return;
        }

        // If the enrollment is already cancelled, don't do anything
        if ($enrollment->state instanceof Cancelled) {
            logger()->info(
                'Recieved invoice change for cancelled enrollment {invoice}',
                compact('invoice')
            );

            // Stop
            return;
        }

        // Don't act on already paid invoices.
        if ($enrollment->state instanceof Paid) {
            // noop
            return;
        }

        // Log result
        logger()->info(
            'Marking {enrollment} as paid.',
            compact('enrollment', 'invoice')
        );

        // Mark enrollment as paid
        $enrollment->state->transitionTo(Paid::class);
        $enrollment->save();

        // Send mail
        $enrollment->user->notify(new EnrollmentPaid($enrollment));
    }
}
