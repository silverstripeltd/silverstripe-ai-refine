# AI Providers

## Provider abstraction

Provider calls go through the shared `silverstripeltd/silverstripe-ai-core` package, which ships the Gemini (default here), OpenAI and Anthropic providers. One provider is active at a time, selected via environment variable. Custom providers are registered in the ai-core `ProviderFactory.providers` YAML map.

## Provider interface

`RefineCompletionService` builds the evaluation prompts, sends them through ai-core's `JsonCompletion` with `EnvProviderSettings::forModule('REFINE')`, and parses the reply (with recovery of JSON wrapped in prose or code fences). HTTP requests respect the configured timeout and are never retried.

```php
class RefineCompletionService
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

All configuration via environment variables. Each provider variable falls back to the shared `AI_*` variable of the same name (for example `AI_API_KEY`), then to the module defaults in `_config/config.yml` (`EnvProviderSettings.modules.REFINE`):

| Environment variable | Description | Default |
|---|---|---|
| `AI_REFINE_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_REFINE_API_KEY` | API key for the active provider | (required) |
| `AI_REFINE_MODEL` | Model to use | `gemini-3.1-flash-lite`, `gpt-5-mini` or `claude-haiku-4-5` |
| `AI_REFINE_THINKING_LEVEL` | Thinking level, sent to whichever provider is active | `low` for Gemini, unset otherwise |
| `AI_REFINE_TEMPERATURE` | Temperature for generation | `0.0` (`1.0` for OpenAI, whose GPT-5 models accept no other value) |
| `AI_REFINE_MAX_TOKENS` | Max tokens in response for the shared evaluation prompt | `20000` |
| `AI_REFINE_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |
| `AI_REFINE_RATE_LIMIT_DELAY` | Delay between API calls (background job) | `6` |

**Note:** Both background and on-demand evaluation use the same rewrite-aware prompt. This is a conscious tradeoff: asking the model to rewrite the page exposes weaknesses and omissions that can be missed by a score-only prompt, which produces better audit behaviour even though it uses more tokens. The background job still discards the returned `suggestions` payload after persisting rating and reasoning.

**Compatibility:** `AI_REFINE_REWRITE_MAX_TOKENS` is still honoured as a fallback alias if it is already configured in a project, but `AI_REFINE_MAX_TOKENS` is the primary setting going forward.

**Note:** `AI_REFINE_TEMPERATURE` defaults to `0.0` because this module is primarily used for auditing and compliance checks. More deterministic ratings are preferred over creative variation, so repeated evaluations of the same content are less likely to drift unless a project intentionally overrides the setting.

## Error handling

Every failure is an ai-core `SilverstripeLtd\AiCore\Provider\ProviderException`, thrown immediately (no retry):

- **Transient failures** (network timeout, rate limit, 5xx): `isTransient()` is true
- **Permanent failures** (4xx non-rate-limit, malformed JSON, missing required keys): neither flag is set

### Error classification

`ProviderException::isBlocking()` distinguishes configuration errors from per-page errors:

- **Blocking**: Missing or invalid API key, authentication failure (401/403 from the provider), an unknown provider name or an invalid setting. These indicate broken configuration that will affect every page - there is no point continuing.
- **Not blocking**: Network timeouts, rate limits, 5xx errors, malformed responses. These are transient or page-specific and the caller can skip and continue.

### Caller behaviour

- **CMS modal:** Shows a toast notification for any `ProviderException`
- **Background job:** Checks `isBlocking()`. Blocking exceptions stop the job immediately and trigger re-queue (see `specs/05_background-job.md`). Other exceptions are logged, the page is skipped, and processing continues.
