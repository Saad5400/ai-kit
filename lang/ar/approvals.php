<?php

return [

    'undo_unsupported' => 'لا يمكن التراجع عن هذا التغيير تلقائياً.',
    'undo_failed' => 'تعذر التراجع عن هذا التغيير.',

    // The tool result a bare rejection hands the model (see ResumeDecisions::rejected()).
    'rejected_result' => 'The user rejected this action on the approval card; it was NOT applied. Do not retry it. Tell the user in one short sentence, in their language, that it was not applied, and ask what they would like instead.',

    // The plain-text rendering of an approval card (ApprovalCards::text()),
    // for surfaces with no form: Telegram, SMS, plain-text mail.
    'text' => [
        'tool' => 'الأداة: :tool',
        'destructive' => '⚠️ لا يمكن التراجع عن هذا الإجراء.',
        'reason' => 'السبب: :reason',
        'yes' => 'نعم',
        'no' => 'لا',
    ],

];
