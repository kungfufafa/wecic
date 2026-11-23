<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's own Notebook AI settings: which provider answers them, and
 * their own API keys (encrypted at rest, never sent back in full). Any
 * setting left empty falls back to the server's (`services.*`,
 * `notebook.ai.provider`). NotebookAiOwnerSettings applies them.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string|null $provider gemini, anthropic or openai; null follows the server.
 * @property string|null $gemini_api_key
 * @property string|null $anthropic_api_key
 * @property string|null $openai_api_key
 * @property string|null $openai_base_url
 * @property string|null $openai_model
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookAiSetting extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookAiSettingFactory> */
    use HasFactory;

    /**
     * The providers a student may choose.
     */
    public const PROVIDERS = ['gemini', 'anthropic', 'openai'];

    /**
     * The key column of each provider.
     */
    public const KEY_COLUMNS = [
        'gemini' => 'gemini_api_key',
        'anthropic' => 'anthropic_api_key',
        'openai' => 'openai_api_key',
    ];

    protected $fillable = [
        'owner_hash',
        'provider',
        'gemini_api_key',
        'anthropic_api_key',
        'openai_api_key',
        'openai_base_url',
        'openai_model',
    ];

    protected $hidden = [
        'gemini_api_key',
        'anthropic_api_key',
        'openai_api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gemini_api_key' => 'encrypted',
            'anthropic_api_key' => 'encrypted',
            'openai_api_key' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $ownerHash): Builder
    {
        return $query->where('owner_hash', $ownerHash);
    }

    /**
     * The student's key for a provider, or null when none is saved.
     */
    public function apiKey(string $provider): ?string
    {
        $column = self::KEY_COLUMNS[$provider] ?? null;
        $key = $column !== null ? $this->getAttribute($column) : null;

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Whether the student points the OpenAI-compatible provider at their
     * own endpoint, which then never receives the server's key.
     */
    public function hasOwnOpenAiEndpoint(): bool
    {
        return is_string($this->openai_base_url) && trim($this->openai_base_url) !== '';
    }

    /**
     * A key as the settings page shows it: its last four characters only.
     */
    public static function mask(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return str_repeat('•', 8).(mb_strlen($key) > 8 ? mb_substr($key, -4) : '');
    }
}
