<?php

use App\Audit\AuditLog;
use App\Jobs\PruneAuditLogs;
use Illuminate\Support\Facades\DB;

it('prunes audit logs older than retain days', function () {
    DB::table('audit_logs')->insert([
        'action' => 'test',
        'actor_kind' => 'system',
        'created_at' => now()->subDays(400),
    ]);
    DB::table('audit_logs')->insert([
        'action' => 'recent',
        'actor_kind' => 'system',
        'created_at' => now()->subDays(10),
    ]);

    (new PruneAuditLogs(365))->handle();

    expect(AuditLog::count())->toBe(1)
        ->and(AuditLog::first()->action)->toBe('recent');
});
