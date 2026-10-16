<?php

namespace App\Console\Commands;

use App\Support\ProtocolIntakeIntegrity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AuditProtocolIntakes extends Command
{
    protected $signature = 'protocols:audit-intakes';

    protected $description = 'Read-only audit of protocol/intake relationships before enforcing their constraints';

    public function handle(ProtocolIntakeIntegrity $integrity): int
    {
        if (! Schema::hasColumn('intake_bookings', 'protocol_id')) {
            $this->error('Apply 2026_10_14_000001_link_intakes_to_protocols first (see docs/protocol-intake-model.md).');

            return self::FAILURE;
        }
        $issues = $integrity->issues();
        $this->line(json_encode($issues, JSON_PRETTY_PRINT));

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
