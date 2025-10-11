<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Http\Requests\ClassDetailRequest;
use Illuminate\Http\Request;

// Create Laravel app instance
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test validation
$requestData = [
    'id' => 2265,
    'dosen_id' => 5240,
    'course_id' => 8810,
];

echo "Testing validation for parameters:\n";
print_r($requestData);

// Create form request and validate
$formRequest = new ClassDetailRequest;
$request = Request::create('/test', 'GET', $requestData);

// Manually validate
$validator = validator($requestData, $formRequest->rules());

if ($validator->fails()) {
    echo "Validation FAILED:\n";
    print_r($validator->errors()->toArray());
} else {
    echo "Validation PASSED!\n";
    echo "Validated data:\n";
    print_r($validator->validated());
}

// Test with course_id yang bermasalah
echo "\n\nTesting with invalid course_id:\n";
$invalidData = [
    'id' => 2265,
    'dosen_id' => 5240,
    'course_id' => 7777, // Invalid
];

$validator2 = validator($invalidData, $formRequest->rules());

if ($validator2->fails()) {
    echo "Validation FAILED as expected:\n";
    print_r($validator2->errors()->toArray());
} else {
    echo "Validation unexpectedly passed!\n";
}
