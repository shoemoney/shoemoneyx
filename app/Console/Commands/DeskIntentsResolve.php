<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OrderIntent;
use Illuminate\Console\Command;

/**
 * Operator escape hatch for a live order the desk cannot verify on its own (a venue with no order
 * listing, a lookup that never succeeds). Only closes the intent so the product stops being held; the
 * operator is asserting what happened at the venue and reconciling any position by hand.
 */
class DeskIntentsResolve extends Command
{
    protected $signature = 'desk:intents:resolve
        {client_order_id? : the pending intent to resolve (omit to list pending intents)}
        {outcome? : filled | rejected | abandoned}';

    protected $description = 'List pending live order intents, or manually resolve one';

    public function handle(): int
    {
        $id = $this->argument('client_order_id');

        if ($id === null) {
            $rows = OrderIntent::pending()->orderBy('id')->get()->map(fn (OrderIntent $i) => [
                $i->client_order_id, $i->venue, $i->method, $i->desk_product_id, $i->venue_order_id ?? '-', $i->ageSeconds().'s',
            ])->all();
            $this->table(['client_order_id', 'venue', 'method', 'product', 'venue order', 'age'], $rows);

            return self::SUCCESS;
        }

        $outcome = (string) $this->argument('outcome');
        if (! in_array($outcome, ['filled', 'rejected', 'abandoned'], true)) {
            $this->error('outcome must be filled, rejected or abandoned');

            return self::INVALID;
        }

        $intent = OrderIntent::pending()->where('client_order_id', $id)->first();
        if ($intent === null) {
            $this->error("no pending intent {$id}");

            return self::FAILURE;
        }

        $intent->resolve($outcome, 'resolved manually by an operator');
        $this->info("resolved {$id} ({$intent->method} {$intent->desk_product_id}) as {$outcome}");
        if ($outcome === 'filled') {
            $this->warn('nothing was booked: record the position/fill yourself.');
        }

        return self::SUCCESS;
    }
}
