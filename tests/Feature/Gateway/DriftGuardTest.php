<?php

use Composer\InstalledVersions;
use Laravel\Ai\Ai;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Pins the vendor sources ReasoningOpenRouterGateway hooks into. It copies
 * no vendor logic any more; it rewrites stream chunks before stock's loop
 * reads them and overrides a few seams. When laravel/ai changes any of
 * these files, this fails on purpose: upstream may have absorbed one of our
 * deltas (drop it), or changed a surface a hook leans on (re-check the hook).
 * After reconciling, refresh the hash here. Recorded against laravel/ai v1.2.2
 * (re-pinned 2026-10-15 from v1.0.0 after reading every changed file's diff:
 * upstream added a Retry-After retry inside withErrorHandling() — the gateway
 * now opts out of it while its own client retry is on, see
 * retryAfterSeconds() — and OpenRouter reasoning-details replay, which the
 * tap and the inspected re-wrap carry through; the rest touches surfaces the
 * kit does not hook).
 *
 * composer.json requires `~1.2.2` — the release these pins were verified
 * against — so no consuming app resolves onto an unreviewed minor; moving to
 * 1.3 is a kit release that re-runs this reconciliation. Within 1.2.x the
 * pins are enforced: a patch release changing these files is exactly the
 * alarm this test exists to raise. (Versions below the rebase version skip,
 * for a local install that predates the constraint.)
 */
const DRIFT_GUARD_REBASED_ON = '1.2.2';

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
        'Gateway/OpenRouter/Concerns/HandlesTextStreaming.php' => 'dfc5460431f71d2f87f1bed16c64a67a8c88dba9298897771919de60c1ed5dd7',
        'Gateway/OpenRouter/Concerns/ParsesTextResponses.php' => 'a0e0eac548e9eb5a218b5aabb4de6689d43bdfeb63a989ed06150265e4c8ea7d',
        'Gateway/OpenRouter/Concerns/BuildsTextRequests.php' => '323379f22747ca5b2a8da67e5605a8c97736a09a2013904111ec7e01cef7546c',
        'Gateway/OpenRouter/Concerns/CreatesOpenRouterClient.php' => '0eb8db8712cf6fe41c50431047a3da6fdbc5b331268d63e60d9591a0b12af375',
        // Stock maps every audio attachment (Base64Audio, any StorableFile
        // Audio, an audio/* UploadedFile) to `input_audio`, routing the
        // format through audioFormat(), which the gateway overrides to be
        // tolerant; a missing mime reaches it as 'audio/mp3'.
        'Gateway/OpenRouter/Concerns/MapsAttachments.php' => 'a7c674a62ebf659cf320738033fe1b7bd30c8ccfb65bf9d394a8267f27f21689',
        // 1.2: assistant messages that carry tool calls replay the step's
        // reasoning_details (replayBlocks) — which the tap must leave intact.
        'Gateway/OpenRouter/Concerns/MapsMessages.php' => '9e9d729ca2fc6687a6772366bb901e33c2be5246d0e846ba76cc2954414f34c6',
        // Declares the audioFormat() the gateway overrides (also used by the
        // transcription endpoint, after its pcm-to-wav conversion).
        'Gateway/OpenRouter/OpenRouterGateway.php' => '97dc203361348974ee7f3bd77d31014972049e3d5262cdfe60e252536f1b8ba1',
        // InspectedStepResponse::from() copies every constructor field; a
        // field added upstream would be silently dropped by the re-wrap.
        'Gateway/StepResponse.php' => 'cde94246dc683e812224858ded01f6f342becd3134f5e5796eee8f462b2a3a71',
        'Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php' => 'b0a6c3786124f9cca8898f424d8efb0fdddaccf280e3bec17aaeb96bc9735167',
        // parseServerSentEvents() is the tap point: the override delegates a
        // StreamTap's real body to it and rewrites what it yields.
        'Gateway/Concerns/ParsesServerSentEvents.php' => '6429c2393b9f9d3d1d6e6cee92d356bda84067151c3bca050635a6c06de7b649',
        // M2 additions — failover semantics the fallback chains and circuit
        // breaker ride on, and the event dispatch points metering listens to.
        'Promptable.php' => '258e8ccc0cf8b79539954355250b5baacf699929ac13a5d14049b0b2d19d1d76',
        // overloadedStatusCodes() extends this trait's list; recordStepFailure()
        // relies on withErrorHandling() wrapping ConnectionException into
        // ProviderConnectionException (a FailoverableException).
        // 1.2: withErrorHandling() also retries once after a short Retry-After;
        // retryAfterSeconds() is overridden to keep the kit's single policy.
        'Gateway/Concerns/HandlesFailoverErrors.php' => '8dbe9e7371310a4c99e3c39c71400ed547adfe1ba88df94c21dd6ad7fdb10164',
        'Providers/Concerns/GeneratesText.php' => 'fa5c2f0a57a05ab904cb165936dcf0887fbde4be14ece0f80808e5dba3677fc1',
        'Providers/Concerns/StreamsText.php' => '0278107e076268c99162c1c12fa2896cc1957550f559790c3d4f9b7aee4a803e',
        'Models/Conversation.php' => '0e51a0f5a3a8b8cfba83fd72dfb3dc30040071f124713420c96730706e0107ff',
        // M3 addition — EncryptedConversationStore extends this: it rides the
        // messageAttributes() / decoded() / userMessageFrom() seams and
        // reproduces resumePausedRow(), forgetReplayBlocks(),
        // storeApprovalResults() and paginateConversationMessages() with
        // sealing folded in. The per-method pins below say WHICH of those
        // moved; re-diff each flagged body against the kit's copy.
        'Storage/DatabaseConversationStore.php' => 'b7dcb9131430932665c10c918d12e9b2e15f6873815be32ac25af72924f8a231',
        // Classified-approvals seam — ClassifiedTool derives its pause from
        // InteractsWithApprovals::needsApproval(), keys idempotency on the
        // toolCallId the loop passes to executeTool(), and ResumeDecisions/
        // ApprovalCards mirror Decision and PendingApproval shapes.
        'Gateway/TextGenerationLoop.php' => 'b77ec93e010dd93965a4e8e2c48bf6dbbc03f880fbe158838434fc6480ea17cf',
        'Gateway/Concerns/HandlesToolApprovals.php' => '305411bae4a65dca826d71c14c3df0cbc7264ace0439c14381ab9e096c821778',
        'Concerns/InteractsWithApprovals.php' => '43aae01afae1d0662c7afaae3c8e48517b75bb717349991250c9ef5f4298b880',
        'Approvals/Decision.php' => '86911eb064ae9466677e4ac5ec270719718d8175b007d8db3d4cfdfcc15d4948',
        'Approvals/PendingApproval.php' => '7a3e4dcfe84cfa0f157ce2981515907b6b4c71915b2ce4ed383a76a2379900d3',
        // The 1.0 row shape paginateConversationMessages() hands out.
        'Storage/StoredMessage.php' => 'bd444fa99a8a5946e41591a5705e3c287649af8e7d29a4379eff274a7ffcbfb4',
        // Failed / stopped turns (Streaming\InterruptedTurns, TurnRunner's
        // stop, the mapper's pull past an in-stream Error). They lean on:
        // RememberConversation's catch storing the run context's
        // recordedResponse() with the exception (and only when the failure
        // propagates through its generator — hence the throw-in on stop);
        // RunContext dispatching StepFailed BEFORE the throw leaves the loop,
        // so seal() can recordStep() the interrupted step first; the loop
        // (pinned above) throwing StreamErrorException on the pull after an
        // Error event; StreamableAgentResponse handing out the very generator
        // the vendor is suspended in (so Generator::throw() lands there).
        'Middleware/RememberConversation.php' => '2c2e97b72eb41e7513a67f145b2cd43a54c39df8328a4b7a1bd4f437011af47f',
        'Gateway/RunContext.php' => 'e975204751087ce71d355bf220a292b3ae8ec15f181cf2eb555d19e0c9015925',
        'Responses/StreamableAgentResponse.php' => '632b7c1d1cb7f3d15b5266807eda1bcbd235bd547137e18e9f03c2d662ffaecf',
        'Events/StepFailed.php' => '65d74309ad7f81c02e02c15dd067f02f854dc3a7c2da7c5087146ae88f62025c',
        'Events/AgentFailed.php' => '80f4026f63ba6570b9832d245e2f81ae2dea3bca5a716da0656ff08c908dccf7',
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

it('vendor conversation-store methods the encrypted store mirrors or rides are unchanged', function () {
    $installed = ltrim((string) InstalledVersions::getPrettyVersion('laravel/ai'), 'v');

    if (version_compare($installed, DRIFT_GUARD_REBASED_ON, '<')) {
        $this->markTestSkipped('Drift guard pins laravel/ai v'.DRIFT_GUARD_REBASED_ON.' sources.');
    }

    // Mirrored with sealing folded in — a change here means re-copying the
    // body into EncryptedConversationStore.
    $mirrored = [
        'resumePausedRow' => '2a64233069c2b3e14b4bfcb79a3bdbc25a225e4dc09d1d21daea34cc18dcf745',
        'forgetReplayBlocks' => 'f48ab6b68d83fa6b0d052c6d65348e95c6745ab36081c2d09a980d199b1eadc7',
        'storeApprovalResults' => 'cb9a7c03f0efcb7415a3f01a6420df277ae4399fd306ce0b5316227b7d40b04a',
        'paginateConversationMessages' => '2ecd0368a3749b7721217f1298b91054d17c1d50790ef729951d1b203b6056f8',
    ];

    // Seams the store relies on: every JSON read goes through decoded(),
    // every insert through messageAttributes(), the user row's text and
    // attachments through userMessageFrom(), and nothing else UPDATEs rows.
    $ridden = [
        'decoded' => 'beadba41000f0b99b142cbb763533d0a313bd604ab1dacd53c96e07fcb8183ae',
        'decodedSteps' => '92173c95273bcc19e217d6ff0a3dc9b031d4b3c06fa28fc23919091e6e0bbfdc',
        'userMessageFrom' => '45100df4eed390e85ec5a14f0b3ee2eea838ecd74b8e0db22dd9d60eeeade12f',
        'messageAttributes' => 'b3eb739c6f3356a4f088249a5eb88a10bd95b7a61a50bc64105a2d94282512d8',
        'storeUserMessage' => 'af62099410d8b819d8f2be47a6b066f2520930aef56b44f4db5dc2828df010b5',
        'storeAssistantMessage' => '7b413955db06a189b02256f8e2e2143f7af9784e08614e936174d27b3c8bc677',
        'getLatestConversationMessages' => '513a0b3ff3f5e73701019075019e49a10988cbb9a0793c1e5702c782841aae7e',
        'pendingApprovalsFor' => '6ab488874fbccba7df85cb450d6e9abe78d56672046c638112e310e6f173e83e',
    ];

    $drifted = [];

    foreach ([...$mirrored, ...$ridden] as $method => $expected) {
        $reflection = new ReflectionMethod(DatabaseConversationStore::class, $method);
        $source = implode('', array_slice(
            file((string) $reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        if (hash('sha256', $source) !== $expected) {
            $drifted[] = (isset($mirrored[$method]) ? 'mirrored ' : 'seam ').$method.'()';
        }
    }

    // A new vendor method that writes rows would bypass the store's sealing.
    $updates = substr_count((string) file_get_contents((string) (new ReflectionClass(DatabaseConversationStore::class))->getFileName()), '->update(');

    expect($drifted)->toBe([], "DatabaseConversationStore drifted:\n  - ".implode("\n  - ", $drifted)."\n\nReconcile EncryptedConversationStore, then refresh the hashes in ".__FILE__)
        ->and($updates)->toBe(4, 'DatabaseConversationStore gained or lost an UPDATE (3 on messages + touchConversation) — make sure EncryptedConversationStore seals it.');
});
