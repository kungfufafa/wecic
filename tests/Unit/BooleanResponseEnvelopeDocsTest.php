<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\API\CampusApiController;
use App\Http\Controllers\API\PortalActionController;
use App\Http\Controllers\API\PortalOperationController;
use Dedoc\Scramble\Support\PhpDoc;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use ReflectionClass;
use Tests\TestCase;

/**
 * The `{success, message, data}` endpoints also answer with `status` and
 * `meta.timestamp`; the Scramble response shapes document both.
 */
class BooleanResponseEnvelopeDocsTest extends TestCase
{
    public function test_every_documented_boolean_response_carries_status_and_meta(): void
    {
        $documented = 0;

        foreach ([CampusApiController::class, PortalActionController::class, PortalOperationController::class] as $controller) {
            foreach ((new ReflectionClass($controller))->getReflectionConstants() as $constant) {
                $type = $constant->getValue();

                if ($constant->getDeclaringClass()->getName() !== $controller || ! is_string($type) || ! str_starts_with($type, 'array{success:')) {
                    continue;
                }

                $shape = PhpDoc::parse("/** @return {$type} */")->getReturnTagValues()[0]->type ?? null;
                $this->assertInstanceOf(ArrayShapeNode::class, $shape, $controller.'::'.$constant->getName());

                $keys = array_map(fn (ArrayShapeItemNode $item): string => (string) $item->keyName, $shape->items);
                $this->assertSame(['success', 'status', 'message', 'data', 'meta'], $keys, $controller.'::'.$constant->getName());
                $this->assertMatchesRegularExpression("/status: '(success|error)'/", $type);
                $this->assertStringContainsString('meta: array{timestamp: string}', $type);

                $documented++;
            }
        }

        $this->assertGreaterThanOrEqual(7, $documented);
    }

    public function test_the_boolean_envelope_carries_status_and_timestamp(): void
    {
        $controller = new class extends CampusApiController
        {
            /**
             * @param  array<string, mixed>  $meta
             */
            public function respond(bool $success, array $meta = []): array
            {
                return $this->booleanResponse($success, 'Pesan.', ['id' => 1], $success ? 200 : 422, $meta)->getData(true);
            }
        };

        $succeeded = $controller->respond(true);
        $this->assertTrue($succeeded['success']);
        $this->assertSame('success', $succeeded['status']);
        $this->assertSame('Pesan.', $succeeded['message']);
        $this->assertSame(['id' => 1], $succeeded['data']);
        $this->assertIsString($succeeded['meta']['timestamp']);

        $failed = $controller->respond(false, ['offline' => true]);
        $this->assertFalse($failed['success']);
        $this->assertSame('error', $failed['status']);
        $this->assertTrue($failed['meta']['offline']);
        $this->assertIsString($failed['meta']['timestamp']);
    }
}
