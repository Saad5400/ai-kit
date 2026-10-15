<?php

namespace Saad\AiKit\Rag;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Saad\AiKit\Rag\Embeddings\Embedder;
use Saad\AiKit\Rag\Embeddings\FakeEmbedder;
use Saad\AiKit\Rag\Embeddings\OpenRouterEmbedder;
use Saad\AiKit\Rag\Events\RetrievalSpend;
use Saad\AiKit\Rag\Listeners\RecordRetrievalBudget;
use Saad\AiKit\Rag\Rerank\FakeReranker;
use Saad\AiKit\Rag\Rerank\GuardedReranker;
use Saad\AiKit\Rag\Rerank\NullReranker;
use Saad\AiKit\Rag\Rerank\OpenRouterReranker;
use Saad\AiKit\Rag\Rerank\Reranker;
use Saad\AiKit\Safety\BudgetGuard;

/**
 * The `rag` module (DECISIONS.md #29): binds the `embeddings` and
 * `rerank` lanes to their contracts. The pure pieces — Text, Fusion,
 * Glossary, Tools, Eval — need no module and no binding.
 *
 * Both bindings are resolved fresh on every `make()` (not singletons), so a
 * config change made at runtime — a test, an eval variant swapping
 * `ai-kit.rerank.model` — is honoured by the next resolution. An app that
 * brings its own backend simply binds its class to the contract in its own
 * provider, which registers after this one.
 */
class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Embedder::class, function (Application $app): Embedder {
            $driver = (string) $app['config']->get('ai-kit.embeddings.driver', 'openrouter');

            return match ($driver) {
                'openrouter' => OpenRouterEmbedder::fromConfig(),
                'fake' => new FakeEmbedder(
                    (int) $app['config']->get('ai-kit.embeddings.dimensions', 1536),
                    (string) $app['config']->get('ai-kit.embeddings.model', 'fake'),
                ),
                default => throw new InvalidArgumentException("Unknown ai-kit.embeddings.driver [{$driver}] (openrouter|fake)."),
            };
        });

        $this->app->bind(Reranker::class, function (Application $app): Reranker {
            $driver = (string) $app['config']->get('ai-kit.rerank.driver', 'openrouter');

            $reranker = match ($driver) {
                'openrouter' => OpenRouterReranker::fromConfig(),
                'fake' => new FakeReranker(model: (string) $app['config']->get('ai-kit.rerank.model', 'fake')),
                'null', 'none' => new NullReranker,
                default => throw new InvalidArgumentException("Unknown ai-kit.rerank.driver [{$driver}] (openrouter|fake|null)."),
            };

            // #29a/c: off until the app's eval enables it, and never past the
            // per-search ceiling or an exhausted daily budget — in every case
            // the caller gets a failed response and keeps its fused order.
            $ceiling = $app['config']->get('ai-kit.rerank.max_usd_per_search');

            return new GuardedReranker(
                $reranker,
                enabled: (bool) $app['config']->get('ai-kit.rerank.enabled', false),
                maxUsdPerSearch: is_numeric($ceiling) ? (float) $ceiling : null,
                budget: $app->bound(BudgetGuard::class) ? $app->make(BudgetGuard::class) : null,
            );
        });
    }

    public function boot(): void
    {
        // Retrieval spends the same provider key the chat turns do, so it
        // counts against the same daily budget. The toggle is read at handle
        // time; the listener is inert without the safety module.
        if ($this->app['config']->get('ai-kit.modules.safety') === true) {
            Event::listen(RetrievalSpend::class, RecordRetrievalBudget::class);
        }

        Event::listen(RetrievalSpend::class, static function (RetrievalSpend $event): void {
            if ($event->lane === 'rerank') {
                GuardedReranker::auditReportedCost($event->model, $event->requests, $event->costUsd);
            }
        });
    }
}
