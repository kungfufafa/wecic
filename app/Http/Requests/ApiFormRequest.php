<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $validator->errors()->first() ?: 'Validation failed.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * Portal field names carry brackets (`data[tipe]`). PHP reads a multipart
     * key `form_values[data[tipe]]` as `data[tipe` and `form_values[data][tipe]`
     * as a nested array, so both are put back to the portal's own field name.
     *
     * @return array<string, mixed>|mixed
     */
    protected function normalizedPortalFormValues(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }

        $normalized = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (is_array($value) && ! array_is_list($value)) {
                foreach ($this->normalizedPortalFormValues($value) as $childName => $childValue) {
                    $normalized[$this->nestedPortalFieldName($name, (string) $childName)] = $childValue;
                }

                continue;
            }

            $missingBrackets = substr_count($name, '[') - substr_count($name, ']');
            $normalized[$name.str_repeat(']', max(0, $missingBrackets))] = $value;
        }

        return $normalized;
    }

    private function nestedPortalFieldName(string $parent, string $child): string
    {
        $bracketPosition = strpos($child, '[');

        if ($bracketPosition === false) {
            return $parent.'['.$child.']';
        }

        return $parent.'['.substr($child, 0, $bracketPosition).']'.substr($child, $bracketPosition);
    }
}
