<?php

use Composer\InstalledVersions;
use Laravel\Ai\Ai;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Pins the vendor sources ReasoningOpenRouterGateway copies from or wraps.
 * When laravel/ai changes any of them, this fails on purpose: upstream may
 * have absorbed one of our fixes (drop the delta), changed the surface we
 * patch (re-diff processTextStream against the new stock body), or shipped
 * behavior our copy now misses (port it). After reconciling, refresh the
 * hash here. Recorded against laravel/ai v1.0.0.
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
        'Gateway/OpenRouter/Concerns/HandlesTextStreaming.php' => 'edb21b567ee8575f6b1ded539dbc5f805022b58b6477a5c52505a71e7495a191',
        'Gateway/OpenRouter/Concerns/ParsesTextResponses.php' => 'b3367ebe29da3fc7d8e81bcf970b4e7de28f3fd276e7136efaec90ddd5e9dc26',
        'Gateway/OpenRouter/Concerns/BuildsTextRequests.php' => '323379f22747ca5b2a8da67e5605a8c97736a09a2013904111ec7e01cef7546c',
        'Gateway/OpenRouter/Concerns/CreatesOpenRouterClient.php' => '0eb8db8712cf6fe41c50431047a3da6fdbc5b331268d63e60d9591a0b12af375',
        // The gateway's mapAttachments() override delegates every non-audio
        // attachment back to this trait one at a time, and leans on its throw
        // for unsupported types. Upstream added the audio case in 1.0; the
        // override is kept until the gateway diet retires it (its format
        // inference tolerates mimes stock's audioFormat() throws on).
        'Gateway/OpenRouter/Concerns/MapsAttachments.php' => 'a7c674a62ebf659cf320738033fe1b7bd30c8ccfb65bf9d394a8267f27f21689',
        'Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php' => 'b0a6c3786124f9cca8898f424d8efb0fdddaccf280e3bec17aaeb96bc9735167',
        'Gateway/Concerns/ParsesServerSentEvents.php' => '6429c2393b9f9d3d1d6e6cee92d356bda84067151c3bca050635a6c06de7b649',
        // M2 additions — failover semantics the fallback chains and circuit
        // breaker ride on, and the event dispatch points metering listens to.
        'Promptable.php' => '3b57caba0d069be9843180b90cbaa85745314c1b1e077610a504b095edb1697a',
        'Gateway/Concerns/HandlesFailoverErrors.php' => '94aa857c8decc7d25520881c0daf9bfebd18be3111dc97ee63b2a592fbdb9ded',
        'Providers/Concerns/GeneratesText.php' => 'aa6a9dcd5792fb08e13611924c1447b472afe3c03d53a0d6f27807abeed4b5b6',
        'Providers/Concerns/StreamsText.php' => '0278107e076268c99162c1c12fa2896cc1957550f559790c3d4f9b7aee4a803e',
        'Models/Conversation.php' => '0e51a0f5a3a8b8cfba83fd72dfb3dc30040071f124713420c96730706e0107ff',
        // M3 addition — EncryptedConversationStore extends this: it rides the
        // messageAttributes() / decoded() / userMessageFrom() seams and
        // reproduces resumePausedRow(), forgetReplayBlocks(),
        // storeApprovalResults() and paginateConversationMessages() with
        // sealing folded in. The per-method pins below say WHICH of those
        // moved; re-diff each flagged body against the kit's copy.
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
        // The 1.0 row shape paginateConversationMessages() hands out.
        'Storage/StoredMessage.php' => 'bd444fa99a8a5946e41591a5705e3c287649af8e7d29a4379eff274a7ffcbfb4',
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
