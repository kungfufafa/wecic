<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PortalParameterValidationRequest extends PortalClassParameterRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Parameter validation failed',
            'validation_result' => [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
                'provided_params' => [
                    'id' => $this->query('id'),
                    'dosen_id' => $this->query('dosen_id'),
                    'course_id' => $this->query('course_id'),
                ],
            ],
            'debug_info' => [
                'suggestion' => 'Check parameter mapping and keep names identical to the upstream portal URL.',
                'help_url' => '/api/support/parameter-help',
                'example_url' => '/api/support/validate-parameters?id=2265&dosen_id=5240&course_id=8810',
            ],
        ], 422));
    }
}
