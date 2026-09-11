<?php

return [

    'undo_unsupported' => 'This change cannot be undone automatically.',
    'undo_failed' => 'Reverting this change failed.',

    // The tool result a bare rejection hands the model (see ResumeDecisions::rejected()).
    'rejected_result' => 'The user rejected this action on the approval card; it was NOT applied. Do not retry it. Tell the user in one short sentence, in their language, that it was not applied, and ask what they would like instead.',

    // The plain-text rendering of an approval card (ApprovalCards::text()),
    // for surfaces with no form: Telegram, SMS, plain-text mail.
    'text' => [
        'tool' => 'Tool: :tool',
        'destructive' => '⚠️ This action cannot be undone.',
        'reason' => 'Reason: :reason',
        'yes' => 'Yes',
        'no' => 'No',
    ],

];
