<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\API\PortalDataController;
use App\Services\DashboardMetricsBuilder;
use Dedoc\Scramble\Attributes\Response;
use Dedoc\Scramble\Support\PhpDoc;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Scramble response shapes of PortalDataController follow the builders
 * that produce the payload.
 */
class PortalDataControllerResponseDocsTest extends TestCase
{
    public function test_the_documented_ipk_statuses_match_the_metrics_builder(): void
    {
        $builderDoc = (string) (new ReflectionClass(DashboardMetricsBuilder::class))->getDocComment();
        $this->assertSame(1, preg_match('/@phpstan-type IpkStatus (\S+)/', $builderDoc, $builderMatch));

        $dashboardResponse = (string) (new ReflectionClassConstant(PortalDataController::class, 'DASHBOARD_RESPONSE'))->getValue();
        $this->assertSame(1, preg_match('/ipk: array\{[^}]*status: ([^},]+)\}/', $dashboardResponse, $docMatch));

        $this->assertEqualsCanonicalizing(explode('|', $builderMatch[1]), explode('|', trim($docMatch[1])));
    }

    public function test_the_meeting_response_documents_the_full_notebook_flag_the_repository_adds(): void
    {
        $meetingMeta = $this->successMeta('meetingDetail');

        $this->assertArrayHasKey('notebook_limit_reached', $meetingMeta);
        $this->assertTrue($meetingMeta['notebook_limit_reached']->optional, 'The flag is only sent when the notebook is full.');
        $this->assertSame('true', (string) $meetingMeta['notebook_limit_reached']->valueType);
        $this->assertSame('string', (string) $meetingMeta['meeting_id']->valueType);
        $this->assertSame('string', (string) $meetingMeta['courses_id']->valueType);
        $this->assertArrayNotHasKey('parameters', $meetingMeta);
    }

    public function test_the_class_detail_response_documents_its_resolved_parameters_without_the_meeting_keys(): void
    {
        $classMeta = $this->successMeta('classDetail');

        $this->assertFalse($classMeta['id']->optional);
        $this->assertFalse($classMeta['parameters']->optional);
        $this->assertArrayNotHasKey('notebook_limit_reached', $classMeta);
        $this->assertArrayNotHasKey('meeting_id', $classMeta);
    }

    /**
     * The `meta` shape items of the documented 200 response of an action, by key
     *
     * @return array<string, ArrayShapeItemNode>
     */
    private function successMeta(string $action): array
    {
        $responses = array_map(
            static fn (\ReflectionAttribute $attribute): Response => $attribute->newInstance(),
            (new ReflectionMethod(PortalDataController::class, $action))->getAttributes(Response::class)
        );
        $success = array_values(array_filter($responses, static fn (Response $response): bool => (int) $response->status === 200));
        $this->assertCount(1, $success, $action);

        $shape = PhpDoc::parse("/** @return {$success[0]->type} */")->getReturnTagValues()[0]->type ?? null;
        $this->assertInstanceOf(ArrayShapeNode::class, $shape, $action);

        $meta = null;

        foreach ($shape->items as $item) {
            if ((string) $item->keyName === 'meta') {
                $meta = $item->valueType;
            }
        }

        $this->assertInstanceOf(ArrayShapeNode::class, $meta, $action);

        $items = [];

        foreach ($meta->items as $item) {
            $items[(string) $item->keyName] = $item;
        }

        return $items;
    }
}
