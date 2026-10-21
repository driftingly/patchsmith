<?php

use App\Services\Audit\AuditSummary;

test('it parses the summary line of the audit script', function () {
    $summary = AuditSummary::fromOutput(
        "-- OS --\nUbuntu 24.04.1 LTS\n"
        ."SUMMARY | web-1 | Ubuntu 24.04.1 LTS | php:8.3.14 8.4.5 | apt_update:yes | upgradable:3 | stale_procs:1\n"
    );

    expect($summary)
        ->host->toBe('web-1')
        ->os->toBe('Ubuntu 24.04.1 LTS')
        ->phpVersions->toBe(['8.3.14', '8.4.5'])
        ->packageListsRefreshed->toBeTrue()
        ->upgradablePackages->toBe(3)
        ->staleProcesses->toBe(1);
});

test('a server with no updates, no stale processes and fresh package lists has no problems', function () {
    $summary = AuditSummary::fromOutput('SUMMARY | web-1 | Ubuntu | php:8.4.5 | apt_update:yes | upgradable:0 | stale_procs:0');

    expect($summary->problems())->toBe([]);
});

test('each problem is described', function () {
    $summary = AuditSummary::fromOutput('SUMMARY | web-1 | Ubuntu | php:8.4.5 | apt_update:no | upgradable:1 | stale_procs:2');

    expect($summary->problems())->toBe(['1 PHP package to update', '2 stale PHP processes', 'apt-get update failed']);
});

test('a server with no PHP installed parses to an empty version list', function () {
    $summary = AuditSummary::fromOutput('SUMMARY | db-1 | Ubuntu | php: | apt_update:yes | upgradable:0 | stale_procs:0');

    expect($summary->phpVersions)->toBe([]);
});

test('output without a summary line does not parse', function (?string $output) {
    expect(AuditSummary::fromOutput($output))->toBeNull();
})->with([
    'no output' => [null],
    'no summary line' => ["-- OS --\nUbuntu\n"],
    'truncated summary' => ['SUMMARY | web-1 | Ubuntu'],
]);
