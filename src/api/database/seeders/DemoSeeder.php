<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['00000000-0000-4000-8000-000000000001', 'DEMO-ARCTIC', 'DEMO-ACCOUNT-ARCTIC'],
                ['00000000-0000-4000-8000-000000000002', 'DEMO-BOREAL', 'DEMO-ACCOUNT-BOREAL'],
            ] as [$id, $name, $account]) {
                DB::table('merchants')->insertOrIgnore(['id' => $id, 'name' => $name, 'virtual_account_id' => $account, 'created_at' => now('UTC')]);
            }
            DB::table('fiscal_documents')->insertOrIgnore([
                'id' => '00000000-0000-4000-8000-000000000101', 'merchant_id' => '00000000-0000-4000-8000-000000000001',
                'reference' => 'DEMO-DOC-SEED-001', 'gross_amount_cents' => 100000, 'cbs_amount_cents' => 8000,
                'ibs_amount_cents' => 12000, 'status' => 'ISSUED', 'created_at' => now('UTC'),
            ]);
        });
    }
}
