<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\GeminiDriver;

/**
 * The Gemini client under its old name.
 *
 * @deprecated Type-hint App\Services\Ai\NotebookAiClient (bound to the
 *             configured provider) or use App\Services\Ai\GeminiDriver.
 *             This alias is removed in the next release.
 */
class GeminiClient extends GeminiDriver {}
