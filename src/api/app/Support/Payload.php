<?php

namespace App\Support;

use App\Domain\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class Payload
{
    public static function validate(Request $request, array $rules, array $integerFields = []): array
    {
        $data = $request->json()->all();
        $unknown = array_diff(array_keys($data), array_keys($rules));
        if ($unknown) {
            throw new ApiProblem(422, 'VALIDATION_ERROR', 'Campos não permitidos.', array_map(
                fn ($field) => ['field' => (string) $field, 'code' => 'UNKNOWN_FIELD', 'message' => 'Campo não permitido.'],
                array_values($unknown),
            ));
        }
        foreach ($integerFields as $field) {
            if (array_key_exists($field, $data) && ! is_int($data[$field])) {
                throw new ApiProblem(422, 'VALIDATION_ERROR', 'Montantes devem ser inteiros em centavos.', [
                    ['field' => $field, 'code' => 'INTEGER_REQUIRED', 'message' => 'Use número inteiro JSON.'],
                ]);
            }
        }
        $validated = Validator::make($data, $rules)->validate();
        foreach ($validated as $field => &$value) {
            if (in_array($field, ['merchant_id', 'fiscal_document_id'], true) && is_string($value)) {
                $value = strtolower($value);
            }
        }

        return $validated;
    }

    public static function empty(Request $request): array
    {
        return self::validate($request, []);
    }

    public static function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[!-~]{1,128}$/D', $key)) {
            throw new ApiProblem(400, 'BAD_REQUEST', 'Idempotency-Key é obrigatória (1–128 caracteres ASCII visíveis).');
        }

        return $key;
    }

    public static function tenant(Request $request, string $merchant): void
    {
        if ($request->attributes->get('merchant_id') !== strtolower($merchant)) {
            throw new ApiProblem(404, 'RESOURCE_NOT_FOUND', 'Recurso não encontrado.');
        }
    }
}
