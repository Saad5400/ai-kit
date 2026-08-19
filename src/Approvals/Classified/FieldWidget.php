<?php

namespace Saad\AiKit\Approvals\Classified;

/**
 * How ONE approval-card argument should be rendered by the client. The
 * server picks the widget — either from the tool's own
 * {@see ClassifiedTool::fields()} spec or by inference from the pending
 * value — so the client stops guessing input types from raw JSON, which is
 * what turned every confirm form into a row of editable text boxes.
 *
 * The two non-input cases carry the security half of owner decision #4:
 * `Hidden` and `Readonly` fields are NOT editable, and an edit decision that
 * tries to change one is corrected server-side
 * ({@see ApprovalCards::guardEdits()}) rather than trusted.
 */
enum FieldWidget: string
{
    /** Travels with the call but is never shown (internal keys, tokens). */
    case Hidden = 'hidden';

    /** Shown as a label + value, never as an input (ids, resolved records). */
    case Readonly = 'readonly';

    /** Single-line free text. */
    case Text = 'text';

    /** Numeric input; {@see ApprovalCards::guardEdits()} casts it back to int/float. */
    case Number = 'number';

    /** Checkbox / switch; cast back to bool. */
    case Boolean = 'boolean';

    /** One of {@see Field::$options}. */
    case Select = 'select';

    /** Multi-line free text. */
    case Textarea = 'textarea';

    /** Long-form markdown body — a monospace editor by default. */
    case Markdown = 'markdown';

    /** Source or structured text — a monospace editor by default. */
    case Code = 'code';

    /**
     * Whether this widget can accept user input at all. Editability is a
     * property of the widget first: an app cannot mark a `Readonly` field
     * editable by accident.
     */
    public function acceptsInput(): bool
    {
        return $this !== self::Hidden && $this !== self::Readonly;
    }
}
