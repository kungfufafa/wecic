<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AnthropicDriver;
use App\Services\Ai\GeminiDriver;
use App\Services\NotebookAssistant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotebookSchemaTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function structuredKinds(): array
    {
        return ['glossary' => ['glossary'], 'quiz' => ['quiz'], 'flashcards' => ['flashcards'], 'mind map' => ['mind_map']];
    }

    #[DataProvider('structuredKinds')]
    public function test_a_structured_output_has_a_strict_lowercase_object_schema(string $kind): void
    {
        $schema = NotebookAssistant::schema($kind);

        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type']);

        foreach ($this->schemas($schema) as $path => $node) {
            $this->assertSame(strtolower($node['type']), $node['type'], $path);

            if ($node['type'] === 'object') {
                $this->assertFalse($node['additionalProperties'], $path);
                $this->assertSame(array_keys($node['properties']), $node['required'], $path);
            }
        }
    }

    public function test_lists_come_wrapped_in_items_and_citations_are_required(): void
    {
        foreach (['glossary', 'quiz', 'flashcards'] as $kind) {
            $schema = NotebookAssistant::schema($kind);

            $this->assertSame(['items'], $schema['required'], $kind);
            $this->assertSame('array', $schema['properties']['items']['type'], $kind);
            $this->assertContains('citations', $schema['properties']['items']['items']['required'], $kind);
            $this->assertSame(['type' => 'integer'], $schema['properties']['items']['items']['properties']['citations']['items'], $kind);
        }

        $this->assertSame(
            ['question', 'options', 'answer_index', 'explanation', 'topic', 'citations'],
            NotebookAssistant::schema('quiz')['properties']['items']['items']['required']
        );
    }

    public function test_the_mind_map_keeps_three_levels_below_its_root(): void
    {
        $node = NotebookAssistant::schema('mind_map');
        $depth = 0;

        while (isset($node['properties']['children'])) {
            $this->assertSame(['title', 'citations', 'children'], $node['required']);
            $node = $node['properties']['children']['items'];
            $depth++;
        }

        $this->assertSame(3, $depth);
        $this->assertSame(['title', 'citations'], $node['required']);
    }

    public function test_every_mind_map_node_cites_its_sources_without_a_recursive_ref(): void
    {
        $schema = NotebookAssistant::schema('mind_map');
        $node = $schema;

        for ($level = 0; $level <= 3; $level++) {
            $this->assertSame(['type' => 'array', 'items' => ['type' => 'integer']], array_diff_key($node['properties']['citations'], ['description' => true]));
            $node = $node['properties']['children']['items'] ?? null;
        }

        $this->assertNull($node);
        $this->assertStringNotContainsString('$ref', (string) json_encode($schema));
        $this->assertStringNotContainsString('$defs', (string) json_encode($schema));
    }

    public function test_prose_outputs_have_no_schema(): void
    {
        $this->assertNull(NotebookAssistant::schema('summary'));
        $this->assertNull(NotebookAssistant::schema('assignment_plan'));
    }

    #[DataProvider('structuredKinds')]
    public function test_gemini_receives_the_same_schema_in_uppercase_without_additional_properties(string $kind): void
    {
        $schema = NotebookAssistant::schema($kind);
        $gemini = GeminiDriver::geminiSchema($schema);
        $lowercase = iterator_to_array($this->schemas($schema));
        $uppercase = iterator_to_array($this->schemas($gemini));

        $this->assertSame(array_keys($lowercase), array_keys($uppercase));

        foreach ($uppercase as $path => $node) {
            $this->assertSame(strtoupper($lowercase[$path]['type']), $node['type'], $path);
            $this->assertArrayNotHasKey('additionalProperties', $node, $path);
            $this->assertSame($lowercase[$path]['required'] ?? null, $node['required'] ?? null, $path);
            $this->assertSame($lowercase[$path]['description'] ?? null, $node['description'] ?? null, $path);
        }
    }

    public function test_the_gemini_mapper_leaves_property_names_and_values_alone(): void
    {
        $this->assertSame([
            'type' => 'OBJECT',
            'properties' => [
                'type' => ['type' => 'STRING', 'enum' => ['a', 'b']],
                'items' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
            ],
            'required' => ['type', 'items'],
        ], GeminiDriver::geminiSchema([
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['a', 'b']],
                'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['type', 'items'],
            'additionalProperties' => false,
        ]));
    }

    public function test_a_count_asks_for_exactly_that_many_items_and_none_leaves_the_list_open(): void
    {
        $items = NotebookAssistant::schema('quiz', 5)['properties']['items'];

        $this->assertSame([5, 5], [$items['minItems'], $items['maxItems']]);
        $this->assertArrayNotHasKey('maxItems', NotebookAssistant::schema('quiz')['properties']['items']);
        $this->assertSame(20, NotebookAssistant::schema('flashcards', 20)['properties']['items']['maxItems']);
        $this->assertSame(20, GeminiDriver::geminiSchema(NotebookAssistant::schema('flashcards', 20))['properties']['items']['maxItems']);
    }

    public function test_claude_receives_the_schema_without_item_counts_at_any_level(): void
    {
        $schema = AnthropicDriver::anthropicSchema([
            'type' => 'object',
            'properties' => [
                'minItems' => ['type' => 'integer'],
                'items' => ['type' => 'array', 'minItems' => 5, 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']]]]],
            ],
            'required' => ['minItems', 'items'],
            'additionalProperties' => false,
        ]);

        $this->assertSame(['type' => 'integer'], $schema['properties']['minItems'], 'a property named like the keyword stays');
        $this->assertSame(['type', 'items'], array_keys($schema['properties']['items']));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['items']['items']['properties']['tags']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(NotebookAssistant::schema('quiz'), AnthropicDriver::anthropicSchema(NotebookAssistant::schema('quiz', 5)));
    }

    /**
     * Every schema node in a schema, by its path.
     *
     * @param  array<string, mixed>  $schema
     * @return \Generator<string, array<string, mixed>>
     */
    private function schemas(array $schema, string $path = '$'): \Generator
    {
        yield $path => $schema;

        foreach ($schema['properties'] ?? [] as $name => $property) {
            yield from $this->schemas($property, "{$path}.{$name}");
        }

        if (isset($schema['items'])) {
            yield from $this->schemas($schema['items'], "{$path}[]");
        }
    }
}
