<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Desk;
use App\Models\OrderIntent;
use Illuminate\Console\Command;

/**
 * Operator escape hatch for a live order the desk cannot settle on its own. Goes through
 * Desk::resolveIntent: a fill is booked from the venue's record, and an order is only released as
 * abandoned/rejected once the venue confirms it is absent (or --force says the operator checked).
 */
class DeskIntentsResolve extends Command
{
    protected $signature = 'desk:intents:resolve
        {client_order_id? : the pending intent to resolve (omit to list pending intents)}
        {outcome? : filled | rejected | abandoned}
        {--force : resolve rejected/abandoned without the venue confirming the order is absent}';

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

        $force = (bool) $this->option('force');
        if ($force) {
            $this->warn('--force: the venue check is skipped. If the order actually filled, nothing books it and the next cycle can buy again.');
        }

        try {
            $desk = app(Desk::class);
            [$done, $message] = $desk->resolveIntent($desk->executorForMode('live'), $intent, $outcome, $force);
        } catch (\Throwable $e) {
            $this->error('could not resolve: '.$e->getMessage());

            return self::FAILURE;
        }

        $done ? $this->info("{$id}: {$message}") : $this->error("{$id}: {$message}");

        return $done ? self::SUCCESS : self::FAILURE;
    }
}
