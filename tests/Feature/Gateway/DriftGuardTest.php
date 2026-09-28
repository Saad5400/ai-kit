<?php

use Composer\InstalledVersions;
use Laravel\Ai\Ai;

/**
 * Pins the vendor sources ReasoningOpenRouterGateway hooks into. It copies
 * no vendor logic any more; it rewrites stream chunks before stock's loop
 * reads them and overrides a few seams. When laravel/ai changes any of
 * these files, this fails on purpose: upstream may have absorbed one of our
 * deltas (drop it), or changed a surface a hook leans on (re-check the hook).
 * After reconciling, refresh the hash here. Recorded against laravel/ai v1.0.0.
 *
 * Versions BELOW the rebase version (the prefer-lowest CI cells) skip: their
 * sources are known to differ and there is nothing to reconcile — functional
 * tests carry compatibility there. At or above, the pins are enforced; a new
 * patch release changing these files is exactly the alarm this test exists
 * to raise.
 */
const DRIFT_GUARD_REBASED_ON = '1.0.0';

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
        // processTextStream() runs this stock loop over chunks tapChunk()
        // rewrote first. It leans on: reasoning read from `reasoning` then
        // `reasoning_details` (DeepSeek `reasoning_content` is renamed to
        // `reasoning`); empty `content` skipped; the reasoning block closed
        // on non-empty content OR a `tool_calls` key (a fully held/swallowed
        // content chunk gets `tool_calls: []` to close it); every choice
        // chunk processed until the parser ends (the held leak tail is a
        // synthetic final chunk, emitted before TextEnd and tool calls);
        // and an Error frame returning null.
        'Gateway/OpenRouter/Concerns/HandlesTextStreaming.php' => 'edb21b567ee8575f6b1ded539dbc5f805022b58b6477a5c52505a71e7495a191',
        'Gateway/OpenRouter/Concerns/ParsesTextResponses.php' => 'b3367ebe29da3fc7d8e81bcf970b4e7de28f3fd276e7136efaec90ddd5e9dc26',
        'Gateway/OpenRouter/Concerns/BuildsTextRequests.php' => '323379f22747ca5b2a8da67e5605a8c97736a09a2013904111ec7e01cef7546c',
        'Gateway/OpenRouter/Concerns/CreatesOpenRouterClient.php' => '0eb8db8712cf6fe41c50431047a3da6fdbc5b331268d63e60d9591a0b12af375',
        // Stock maps every audio attachment (Base64Audio, any StorableFile
        // Audio, an audio/* UploadedFile) to `input_audio`, routing the
        // format through audioFormat(), which the gateway overrides to be
        // tolerant; a missing mime reaches it as 'audio/mp3'.
        'Gateway/OpenRouter/Concerns/MapsAttachments.php' => 'a7c674a62ebf659cf320738033fe1b7bd30c8ccfb65bf9d394a8267f27f21689',
        // Declares the audioFormat() the gateway overrides (also used by the
        // transcription endpoint, after its pcm-to-wav conversion).
        'Gateway/OpenRouter/OpenRouterGateway.php' => '58b8aa484fbc1f33300014537610d0540f2365f6aaa70f923aef52ffeb3a2c38',
        // InspectedStepResponse::from() copies every constructor field; a
        // field added upstream would be silently dropped by the re-wrap.
        'Gateway/StepResponse.php' => 'cde94246dc683e812224858ded01f6f342becd3134f5e5796eee8f462b2a3a71',
        'Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php' => 'b0a6c3786124f9cca8898f424d8efb0fdddaccf280e3bec17aaeb96bc9735167',
        // parseServerSentEvents() is the tap point: the override delegates a
        // StreamTap's real body to it and rewrites what it yields.
        'Gateway/Concerns/ParsesServerSentEvents.php' => '6429c2393b9f9d3d1d6e6cee92d356bda84067151c3bca050635a6c06de7b649',
        // M2 additions — failover semantics the fallback chains and circuit
        // breaker ride on, and the event dispatch points metering listens to.
        'Promptable.php' => '3b57caba0d069be9843180b90cbaa85745314c1b1e077610a504b095edb1697a',
        // overloadedStatusCodes() extends this trait's list; recordStepFailure()
        // relies on withErrorHandling() wrapping ConnectionException into
        // ProviderConnectionException (a FailoverableException).
        'Gateway/Concerns/HandlesFailoverErrors.php' => '94aa857c8decc7d25520881c0daf9bfebd18be3111dc97ee63b2a592fbdb9ded',
        'Providers/Concerns/GeneratesText.php' => 'aa6a9dcd5792fb08e13611924c1447b472afe3c03d53a0d6f27807abeed4b5b6',
        'Providers/Concerns/StreamsText.php' => '0278107e076268c99162c1c12fa2896cc1957550f559790c3d4f9b7aee4a803e',
        'Models/Conversation.php' => '0e51a0f5a3a8b8cfba83fd72dfb3dc30040071f124713420c96730706e0107ff',
        // M3 addition — EncryptedConversationStore extends this and rides its
        // messageAttributes() seam; the encrypted-trace overrides reproduce
        // getLatestConversationMessages(), existingToolResultIds() and
        // storeApprovalResults() with decryption folded in — re-diff those
        // three verbatim on any change. Pinned at 1.0.0 BEFORE the store is
        // reconciled: 1.0 moved messages onto `steps`/`status`, and the
        // encrypted store's rewrite onto that schema is the follow-up part.
        'Storage/DatabaseConversationStore.php' => '6612d2b4da0e4fd5ca96a7fcbc4211359ee13b6bb48cc2ef7f5472e13bbc1de3',
        // Classified-approvals seam — ClassifiedTool derives its pause from
        // InteractsWithApprovals::needsApproval(), keys idempotency on the
        // toolCallId the loop passes to executeTool(), and ResumeDecisions/
        // ApprovalCards mirror Decision and PendingApproval shapes.
        'Gateway/TextGenerationLoop.php' => '9e22c360292422bd793d4456227b7a8bb5907c6ebbee92dbf94340100226471e',
        'Gateway/Concerns/HandlesToolApprovals.php' => '305411bae4a65dca826d71c14c3df0cbc7264ace0439c14381ab9e096c821778',
        'Concerns/InteractsWithApprovals.php' => '43aae01afae1d0662c7afaae3c8e48517b75bb717349991250c9ef5f4298b880',
        'Approvals/Decision.php' => '86911eb064ae9466677e4ac5ec270719718d8175b007d8db3d4cfdfcc15d4948',
        'Approvals/PendingApproval.php' => '7a3e4dcfe84cfa0f157ce2981515907b6b4c71915b2ce4ed383a76a2379900d3',
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
        'Re-check ReasoningOpenRouterGateway against the new stock sources (did upstream '.
        'absorb a delta? change a surface a hook leans on, per the comments above?), '.
        'reconcile, then refresh the pinned hashes in %s.',
        implode("\n  - ", $drifted),
        __FILE__,
    ));
});
