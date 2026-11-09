<?php

namespace Tests\Support;

use DateTimeImmutable;

trait ContractAssertions
{
    protected function assertContract(array $value, string $name): void
    {
        $document = json_decode(file_get_contents(dirname(base_path(), 2).'/docs/openapi.yaml'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSchemaValue($value, $document['components']['schemas'][$name], $document, $name);
    }

    private function assertSchemaValue(mixed $value, array $schema, array $document, string $path): void
    {
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);
            $schema = $document['components']['schemas'][$name];
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (array_key_exists('enum', $schema)) {
            $this->assertTrue(in_array($value, $schema['enum'], true), $path.' must match its enum');
        }
        switch ($schema['type'] ?? null) {
            case 'object':
                $this->assertIsArray($value, $path);
                foreach ($schema['required'] ?? [] as $field) {
                    $this->assertArrayHasKey($field, $value, $path);
                }
                if (($schema['additionalProperties'] ?? null) === false) {
                    $this->assertSame([], array_values(array_diff(array_keys($value), array_keys($schema['properties'] ?? []))), $path.' has unexpected fields');
                }
                foreach ($value as $field => $child) {
                    if (isset($schema['properties'][$field])) {
                        $this->assertSchemaValue($child, $schema['properties'][$field], $document, $path.'.'.$field);
                    }
                }
                break;
            case 'array':
                $this->assertIsArray($value, $path);
                $this->assertTrue(array_is_list($value), $path.' must be a list');
                foreach ($value as $index => $child) {
                    $this->assertSchemaValue($child, $schema['items'], $document, $path.'.'.$index);
                }
                break;
            case 'integer':
                $this->assertIsInt($value, $path);
                if (isset($schema['minimum'])) {
                    $this->assertGreaterThanOrEqual($schema['minimum'], $value, $path);
                }
                if (isset($schema['maximum'])) {
                    $this->assertLessThanOrEqual($schema['maximum'], $value, $path);
                }
                break;
            case 'boolean':
                $this->assertIsBool($value, $path);
                break;
            case 'string':
                $this->assertIsString($value, $path);
                if (isset($schema['pattern'])) {
                    $this->assertMatchesRegularExpression('~'.str_replace('~', '\~', $schema['pattern']).'~D', $value, $path);
                }
                if (($schema['format'] ?? null) === 'uuid') {
                    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value, $path);
                }
                if (($schema['format'] ?? null) === 'date-time') {
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value, $path);
                    $this->assertInstanceOf(DateTimeImmutable::class, new DateTimeImmutable($value));
                }
                break;
        }
    }
}
