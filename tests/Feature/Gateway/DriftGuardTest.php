<?php

use Laravel\Ai\Ai;

/**
 * Pins the vendor sources ReasoningOpenRouterGateway copies from or wraps.
 * When laravel/ai changes any of them, this fails on purpose: upstream may
 * have absorbed one of our fixes (drop the delta), changed the surface we
 * patch (re-diff processTextStream against the new stock body), or shipped
 * behavior our copy now misses (port it). After reconciling, refresh the
 * hash here. Recorded against laravel/ai v0.10.3.
 */
it('vendor gateway sources are unchanged since the fork was rebased', function () {
    $pinned = [
        'Gateway/OpenRouter/Concerns/HandlesTextStreaming.php' => 'cb63bf8302651b5beddfcd7b0926f8f6e4dd2385e9bfd4e8511c6bb3e681a317',
        'Gateway/OpenRouter/Concerns/ParsesTextResponses.php' => '42dc89bb18f4bbd592dd4ba1153991427af54046c1809680dc2ae021e928dc2b',
        'Gateway/OpenRouter/Concerns/BuildsTextRequests.php' => '3519c0fb0069e3b96d75c8f81974fd5044cdb22771f6ffe343e03cbc4ccb16c5',
        'Gateway/OpenRouter/Concerns/CreatesOpenRouterClient.php' => '0eb8db8712cf6fe41c50431047a3da6fdbc5b331268d63e60d9591a0b12af375',
        'Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php' => 'b0a6c3786124f9cca8898f424d8efb0fdddaccf280e3bec17aaeb96bc9735167',
        'Gateway/Concerns/ParsesServerSentEvents.php' => '6429c2393b9f9d3d1d6e6cee92d356bda84067151c3bca050635a6c06de7b649',
    ];

    $sourceRoot = dirname((new ReflectionClass(Ai::class))->getFileName());

    $drifted = [];

    foreach ($pinned as $file => $expected) {
        $path = "{$sourceRoot}/{$file}";

        if (! is_file($path)) {
            $drifted[] = "{$file} no longer exists";

            continue;
        }

        if (hash_file('sha256', $path) !== $expected) {
            $drifted[] = "{$file} changed";
        }
    }

    expect($drifted)->toBe([], sprintf(
        "laravel/ai gateway sources drifted since the fork was rebased:\n  - %s\n\n".
        'Re-diff ReasoningOpenRouterGateway against the new stock sources (did upstream '.
        'absorb a delta? change the copied stream loop? add behavior we must port?), '.
        'reconcile, then refresh the pinned hashes in %s.',
        implode("\n  - ", $drifted),
        __FILE__,
    ));
});
