# Changelog

Releases are git tags on `main`. Earlier history is recorded per milestone in
[`docs/PLAN.md`](docs/PLAN.md); this file starts at 0.11.0 and is the log from here on.

## 0.11.0

- **`ApprovalCards::text(PendingApproval $approval, string $locale): string`** — the same
  approval card `card()` builds, rendered as plain text: title, the tool's human label, a
  warning line when the call is destructive, `• Label: value` per visible argument, the
  tool's preview lines, then the reason. Arabic and English copy under
  `ai-kit::approvals.text.*`; `$locale` switches the whole rendering (the kit's copy and the
  app copy the tool resolves) and restores the previous locale afterwards. No HTML and no
  Markdown — escaping belongs to the transport that adds markup.

  *Why:* **Telegram needs a text card.** R6 puts the teacher lane's writes behind the same
  classified approval primitive the web uses, and a Telegram message has no form to render —
  it gets a text preview plus an inline ✅/❌ keyboard. The trust-bearing fields still have to
  come from the tool instance rather than from model output, so the rendering belongs next to
  `card()` in the kit, where uqucc's bot can reuse it, instead of being written twice app-side.

  Additive: nothing else changed, no config, no migration.
