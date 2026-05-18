# AI Providers

## Provider abstraction

The module includes a provider abstraction layer supporting multiple AI providers. One provider is active at a time, selected via environment variable. The module ships with three built-in providers:

- **Gemini** - primary provider (default). Calls the v1beta `generateContent` endpoint and includes `thinkingConfig.thinkingLevel` when `AI_REFINE_THINKING_LEVEL` is not `none`.
- **OpenAI** - Chat Completions API provider
- **Anthropic** - Messages API provider
- **Custom providers** - the built-in factory supports `gemini`, `openai`, and `anthropic` only. To use a custom provider, projects must override the factory via Silverstripe's Injector.

## Provider interface

All providers extend `AbstractAIProvider`, which supplies the evaluation methods and shared error handling. Concrete providers implement protected request hooks (`performRequest`, `extractResponseContent`, `isTransientStatus`, and `getDefaultModel`). HTTP requests are made with Guzzle (bundled with Silverstripe framework) and respect configured timeouts.

```php
abstract class AbstractAIProvider
{
    /**
     * Evaluate page content against a refine definition.
     */
    public function evaluateRefine(
        string $content,
        string $pageTitle,
        string $refineDefinition,
        array $rewriteTargets = []
    ): RefineFullResult;
}
```

### RefineRatingResult

Base value object carrying the rating and reasoning shared by all evaluation results:

```php
class RefineRatingResult
{
    public string $rating;             // One of: Excellent, Good, Adequate, NeedsWork, Poor
    public string $reasoningSummary;   // AI explanation of the rating
}
```

### RefineFullResult

Value object returned by the shared evaluation prompt (extends rating result):

```php
class RefineFullResult
{
    public string $rating;
    public string $reasoningSummary;
    public array $suggestions;  // list<RefineSuggestion>
}

class RefineSuggestion
{
    public string $targetKey;
    public string $targetType;
    public string $fieldName;
    public ?int $targetId;
    public string $sourceContent;
    public string $suggestedContent;
}
```

The provider parses `targetKey`, `targetType`, and `suggestedContent` directly from the model response. `RefineEvaluationService` then resolves each suggestion back onto a server-known rewrite target, filling in `fieldName`, `targetId`, and `sourceContent` before the result is returned to the modal.

## Configuration

All configuration via environment variables:

| Environment variable | Description | Default |
|---|---|---|
| `AI_REFINE_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_REFINE_API_KEY` | API key for the active provider | (required) |
| `AI_REFINE_MODEL` | Model to use | Provider-specific default |
| `AI_REFINE_THINKING_LEVEL` | Thinking level for Gemini | `low` |
| `AI_REFINE_TEMPERATURE` | Temperature for generation | `0.0` |
| `AI_REFINE_MAX_TOKENS` | Max tokens in response for the shared evaluation prompt | `20000` |
| `AI_REFINE_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |
| `AI_REFINE_RATE_LIMIT_DELAY` | Delay between API calls (background job) | `6` |

**Note:** Both background and on-demand evaluation use the same rewrite-aware prompt. This is a conscious tradeoff: asking the model to rewrite the page exposes weaknesses and omissions that can be missed by a score-only prompt, which produces better audit behaviour even though it uses more tokens. The background job still discards the returned `suggestions` payload after persisting rating and reasoning.

**Compatibility:** `AI_REFINE_REWRITE_MAX_TOKENS` is still honoured as a fallback alias if it is already configured in a project, but `AI_REFINE_MAX_TOKENS` is the primary setting going forward.

**Note:** `AI_REFINE_TEMPERATURE` defaults to `0.0` because this module is primarily used for auditing and compliance checks. More deterministic ratings are preferred over creative variation, so repeated evaluations of the same content are less likely to drift unless a project intentionally overrides the setting.

## Error handling

- **Transient failures** (network timeout, rate limit, 5xx): Throw `AIProviderException` immediately (no retry)
- **Permanent failures** (invalid API key, 4xx non-rate-limit): Throw `AIProviderException` immediately
- **Malformed response** (invalid JSON, missing required keys): Throw `AIProviderException`

### Error classification

`AIProviderException` carries a `fatal` flag to distinguish configuration errors from per-page errors:

- **Fatal** (`fatal = true`): Missing or invalid API key, authentication failure (401/403 from the provider). These indicate broken configuration that will affect every page - there is no point continuing.
- **Non-fatal** (`fatal = false`): Network timeouts, rate limits, 5xx errors, malformed responses. These are transient or page-specific and the caller can skip and continue.

### Caller behaviour

- **CMS modal:** Shows a toast notification for any `AIProviderException`
- **Background job:** Checks the `fatal` flag. Fatal exceptions stop the job immediately and trigger re-queue (see `specs/05_background-job.md`). Non-fatal exceptions are logged, the page is skipped, and processing continues.
