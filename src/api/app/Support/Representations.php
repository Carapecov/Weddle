<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Representations
{
    public static function time(string $time): string
    {
        return CarbonImmutable::parse($time)->utc()->toISOString();
    }

    public static function merchant(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'virtual_account_id' => $row->virtual_account_id,
            'created_at' => self::time($row->created_at), 'simulation' => true];
    }

    public static function document(object $row): array
    {
        return ['id' => $row->id, 'merchant_id' => $row->merchant_id, 'reference' => $row->reference,
            'gross_amount_cents' => (int) $row->gross_amount_cents, 'cbs_amount_cents' => (int) $row->cbs_amount_cents,
            'ibs_amount_cents' => (int) $row->ibs_amount_cents, 'status' => $row->status,
            'created_at' => self::time($row->created_at), 'simulation' => true];
    }

    public static function payment(object $row): array
    {
        $split = null;
        if (in_array($row->status, ['SETTLED', 'REFUNDED'], true)) {
            $document = DB::table('fiscal_documents')->where('id', $row->fiscal_document_id)->first();
            $split = ['merchant_net_cents' => (int) $row->amount_cents - (int) $document->cbs_amount_cents - (int) $document->ibs_amount_cents,
                'cbs_cents' => (int) $document->cbs_amount_cents, 'ibs_cents' => (int) $document->ibs_amount_cents];
        }

        return ['id' => $row->id, 'merchant_id' => $row->merchant_id, 'fiscal_document_id' => $row->fiscal_document_id,
            'status' => $row->status, 'scenario' => $row->scenario, 'currency' => $row->currency,
            'gross_amount_cents' => (int) $row->amount_cents, 'split' => $split,
            'failure_code' => $row->failure_code, 'version' => (int) $row->version, 'simulation' => true,
            'created_at' => self::time($row->created_at), 'updated_at' => self::time($row->updated_at)];
    }

    public static function ledger(object $row): array
    {
        return ['id' => $row->id, 'payment_id' => $row->payment_id, 'event_id' => $row->event_id,
            'account_code' => $row->account_code, 'direction' => $row->direction,
            'amount_cents' => (int) $row->amount_cents, 'reversal_of_entry_id' => $row->reversal_of_entry_id,
            'created_at' => self::time($row->created_at)];
    }

    public static function event(object $row): array
    {
        return ['id' => $row->id, 'payment_id' => $row->payment_id, 'event_type' => $row->event_type,
            'previous_status' => $row->previous_status, 'new_status' => $row->new_status,
            'occurred_at' => self::time($row->occurred_at), 'correlation_id' => $row->correlation_id,
            'metadata_safe' => (object) json_decode($row->metadata_safe, true, 32, JSON_THROW_ON_ERROR)];
    }
}
