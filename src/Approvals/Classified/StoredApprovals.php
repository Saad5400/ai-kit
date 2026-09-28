<?php

namespace Saad\AiKit\Approvals\Classified;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;

/**
 * Read seam for a conversation's STILL-PENDING approvals — what a client
 * needs to repaint its approval / question cards after a page reload, before
 * any resume request exists.
 *
 * A thin wrapper over laravel/ai 1.0's
 * `ResolvesPendingApprovals::pendingApprovalsFor()` on the bound store: the
 * newest turn, when it is `paused`, contributes each stored call that carries
 * an `approval_reason` and no result yet. The EncryptedConversationStore
 * decrypts `steps` on that path, so encrypted and pre-encryption rows read
 * the same way. Only the newest turn counts: a pause the user walked away
 * from (they sent a new message instead) is settled as denied by the next
 * run, so it has no card to repaint.
 *
 * Calling the store directly is equivalent; this class stays so existing
 * call sites keep their shape (a Collection). A store that cannot resolve
 * approvals yields none.
 *
 * Feed the result to {@see ApprovalCards::cards()} for the wire payloads.
 */
class StoredApprovals
{
    /**
     * @param  string|null  $connection  @deprecated ignored — the bound store owns its connection
     */
    public function __construct(protected ?string $connection = null) {}

    /**
     * @return Collection<int, PendingApproval>
     */
    public function pending(string $conversationId): Collection
    {
        $store = app(ConversationStore::class);

        return $store instanceof ResolvesPendingApprovals
            ? collect($store->pendingApprovalsFor($conversationId))->values()
            : collect();
    }
}
