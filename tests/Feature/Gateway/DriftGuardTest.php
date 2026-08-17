<?php

use Composer\InstalledVersions;
use Laravel\Ai\Ai;

/**
 * Pins the vendor sources ReasoningOpenRouterGateway copies from or wraps.
 * When laravel/ai changes any of them, this fails on purpose: upstream may
 * have absorbed one of our fixes (drop the delta), changed the surface we
 * patch (re-diff processTextStream against the new stock body), or shipped
 * behavior our copy now misses (port it). After reconciling, refresh the
 * hash here. Recorded against laravel/ai v0.10.3.
 *
 * Versions BELOW the rebase version (the prefer-lowest CI cells) skip: their
 * sources are known to differ and there is nothing to reconcile — functional
 * tests carry compatibility there. At or above, the pins are enforced; a new
 * patch release changing these files is exactly the alarm this test exists
 * to raise.
 */
const DRIFT_GUARD_REBASED_ON = '0.10.3';

it('vendor gateway sources are unchanged since the fork was rebased', function () {
    $installed = ltrim((string) InstalledVersions::getPrettyVersion('laravel/ai'), 'v');

    if (version_compare($installed, DRIFT_GUARD_REBASED_ON, '<')) {
        $this->markTestSkipped(sprintf(
            'Drift guard pins laravel/ai v%s sources; v%s is older (prefer-lowest).',
            DRIFT_GUARD_REBASED_ON,
            $installed,
        ));
    }

    $pinned = [
        'Gateway/OpenRouter/Concerns/HandlesTextStreaming.php' => 'cb63bf8302651b5beddfcd7b0926f8f6e4dd2385e9bfd4e8511c6bb3e681a317',
        'Gateway/OpenRouter/Concerns/ParsesTextResponses.php' => '42dc89bb18f4bbd592dd4ba1153991427af54046c1809680dc2ae021e928dc2b',
        'Gateway/OpenRouter/Concerns/BuildsTextRequests.php' => '3519c0fb0069e3b96d75c8f81974fd5044cdb22771f6ffe343e03cbc4ccb16c5',
        'Gateway/OpenRouter/Concerns/CreatesOpenRouterClient.php' => '0eb8db8712cf6fe41c50431047a3da6fdbc5b331268d63e60d9591a0b12af375',
        'Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php' => 'b0a6c3786124f9cca8898f424d8efb0fdddaccf280e3bec17aaeb96bc9735167',
        'Gateway/Concerns/ParsesServerSentEvents.php' => '6429c2393b9f9d3d1d6e6cee92d356bda84067151c3bca050635a6c06de7b649',
        // M2 additions — failover semantics the fallback chains and circuit
        // breaker ride on, and the event dispatch points metering listens to.
        'Promptable.php' => '678556e53a2d4400caf75805b90708ff114ffde061c95b50153d05f3fd0994ab',
        'Gateway/Concerns/HandlesFailoverErrors.php' => '3e6a4b5abfa72b9ff56963987933e45f5280db8b37609f2bcf65eac1895fc079',
        'Providers/Concerns/GeneratesText.php' => '4759993fe6fd0352a054743cceac90378f61662bd8190bf16b2fa12d270fc9dd',
        'Providers/Concerns/StreamsText.php' => '3f8a298dd9a6a20584ea9e5672d7096215b4103ed327d02c1efe8e67640852c1',
        'Models/Conversation.php' => '0e51a0f5a3a8b8cfba83fd72dfb3dc30040071f124713420c96730706e0107ff',
        // M3 addition — EncryptedConversationStore extends this and rides its
        // messageAttributes() seam plus the reconstruction in
        // getLatestConversationMessages(); re-diff on any change.
        'Storage/DatabaseConversationStore.php' => '915f37bec63d68e68f5d70fd99fcf7d01f2fdbf64c76cd5307e9291f4136df6a',
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
