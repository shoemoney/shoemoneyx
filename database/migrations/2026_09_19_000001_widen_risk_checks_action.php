<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * risk_checks.action was string(8) — enough for HOLD|CLOSE|TRIM|ADD, not for the
     * 20-char 'force_close_terminal' the terminal force-close sweep now writes
     * (Desk.php). MariaDB strict mode (the default since 10.2.4, and what .env's
     * DB_CONNECTION=mysql runs) throws SQLSTATE[22001] on that insert; sqlite ignores
     * varchar widths, which is why the test suite never caught it.
     */
    public function up(): void
    {
        Schema::table('risk_checks', function (Blueprint $table) {
            $table->string('action', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('risk_checks', function (Blueprint $table) {
            $table->string('action', 8)->change();
        });
    }
};
