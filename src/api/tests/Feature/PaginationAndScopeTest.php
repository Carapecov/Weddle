<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestCase;

final class PaginationAndScopeTest extends PostgresTestCase
{
    public function test_ledger_and_event_cursors_visit_each_entry_once_and_validate_limits(): void
    {
        $actor = $this->merchant();
        $payment = $this->settled($actor);
        foreach (['ledger' => ['LedgerPage', 4], 'events' => ['EventPage', 3]] as $endpoint => [$schema, $expected]) {
            $base = '/api/v1/payments/'.$payment['id'].'/'.$endpoint;
            $seen = [];
            $cursor = null;
            $pages = 0;
            do {
                $uri = $base.'?limit=1'.($cursor === null ? '' : '&cursor='.rawurlencode($cursor));
                $response = $this->api($actor, 'GET', $uri)->assertOk();
                $this->assertContract($response->json(), $schema);
                foreach ($response->json('data') as $entry) {
                    $this->assertNotContains($entry['id'], $seen, 'Cursor repeated an entry');
                    $seen[] = $entry['id'];
                }
                $cursor = $response->json('next_cursor');
                $this->assertLessThanOrEqual($expected, ++$pages, 'Cursor failed to terminate');
            } while ($cursor !== null);
            $this->assertCount($expected, $seen);
            $this->api($actor, 'GET', $base)->assertOk()->assertJsonCount($expected, 'data')->assertJsonPath('next_cursor', null);
            foreach (['0', '101', 'not-a-number'] as $limit) {
                $this->assertError($this->api($actor, 'GET', $base.'?limit='.$limit), 422);
            }
            $this->assertError($this->api($actor, 'GET', $base.'?cursor=not-a-valid-cursor'), 422);
        }
    }

    public function test_cursors_are_bound_to_the_payment_and_collection(): void
    {
        $actor = $this->merchant();
        $first = $this->settled($actor);
        $second = $this->settled($actor);
        $cursor = $this->api($actor, 'GET', '/api/v1/payments/'.$first['id'].'/ledger?limit=1')->assertOk()->json('next_cursor');
        $this->assertIsString($cursor);
        $this->assertError($this->api($actor, 'GET', '/api/v1/payments/'.$second['id'].'/ledger?cursor='.rawurlencode($cursor)), 422);
        $this->assertError($this->api($actor, 'GET', '/api/v1/payments/'.$first['id'].'/events?cursor='.rawurlencode($cursor)), 422);
    }

    public function test_idempotency_scope_separates_merchants_operations_and_resources(): void
    {
        $a = $this->merchant();
        $b = $this->merchant();
        $key = 'same-demo-key';
        $first = $this->payment($a, $this->document($a), key: $key)['payment'];
        $second = $this->payment($b, $this->document($b), key: $key)['payment'];
        $third = $this->payment($a, $this->document($a), key: (string) Str::uuid())['payment'];
        foreach ([[$a, $first], [$b, $second], [$a, $third]] as [$actor, $payment]) {
            $this->command($actor, $payment['id'], 'authorize', $key)->assertOk()->assertJsonPath('status', 'AUTHORIZED');
            $this->command($actor, $payment['id'], 'settle', $key)->assertOk()->assertJsonPath('status', 'SETTLED');
        }
        $this->assertSame(3, DB::table('payments')->count());
        $this->assertSame(3, DB::table('document_settlements')->count());
        $this->assertSame(12, DB::table('ledger_entries')->count());
    }
}
