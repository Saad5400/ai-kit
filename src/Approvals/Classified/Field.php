<?php

namespace Saad\AiKit\Approvals\Classified;

use InvalidArgumentException;

/**
 * One argument of an approval card, described well enough for the client to
 * render the right control without inspecting the value (owner decision #4:
 * "editable form for payload writes, prefilled from the tool schema").
 *
 * A tool declares the fields it cares about in
 * {@see ClassifiedTool::fields()}; {@see ApprovalCards} fills in the rest by
 * inference and stamps each field with the pending call's current value.
 *
 * `editable` is derived from the widget and then narrowed by the card: a
 * one-click (destructive) card renders every field {@see FieldWidget::Readonly}.
 * Nothing here is a client-side hint — {@see ApprovalCards::guardEdits()}
 * enforces the same flags on the way back in.
 */
final class Field
{
    /**
     * @param  list<array{value: mixed, label: string}>|null  $options
     */
    private function __construct(
        public readonly string $name,
        public readonly FieldWidget $widget,
        public readonly bool $editable,
        public readonly ?string $label,
        public readonly ?array $options,
        public readonly ?string $placeholder,
    ) {}

    /**
     * @param  array<int|string, mixed>|null  $options  a list of scalars, a value => label map, or {value, label} rows
     */
    public static function make(
        string $name,
        FieldWidget $widget = FieldWidget::Text,
        ?bool $editable = null,
        ?string $label = null,
        ?array $options = null,
        ?string $placeholder = null,
    ): self {
        return new self(
            name: $name,
            widget: $widget,
            // A widget that takes no input is never editable, whatever the
            // caller passed; otherwise the caller may still lock it down.
            editable: $widget->acceptsInput() && ($editable ?? true),
            label: $label,
            options: $options === null ? null : self::normalizeOptions($options),
            placeholder: $placeholder,
        );
    }

    public static function hidden(string $name): self
    {
        return self::make($name, FieldWidget::Hidden);
    }

    public static function readonly(string $name, ?string $label = null): self
    {
        return self::make($name, FieldWidget::Readonly, label: $label);
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    public static function select(string $name, array $options, ?string $label = null): self
    {
        return self::make($name, FieldWidget::Select, label: $label, options: $options);
    }

    /**
     * Read a tool's `fields()` entry, which may be a full Field, a bare
     * widget, or a partial spec array — so declaring one field's widget
     * costs one line and does not force the whole object.
     */
    public static function fromSpec(string $name, mixed $spec): self
    {
        if ($spec instanceof self) {
            return $spec->name === $name ? $spec : $spec->renamed($name);
        }

        if ($spec instanceof FieldWidget) {
            return self::make($name, $spec);
        }

        if (is_string($spec)) {
            return self::make($name, self::widget($name, $spec));
        }

        if (! is_array($spec)) {
            throw new InvalidArgumentException("Unsupported field spec for argument [{$name}].");
        }

        /** @var array<string, mixed> $spec */
        $widget = $spec['widget'] ?? FieldWidget::Text;

        return self::make(
            name: $name,
            widget: $widget instanceof FieldWidget ? $widget : self::widget($name, (string) $widget),
            editable: isset($spec['editable']) ? (bool) $spec['editable'] : null,
            label: isset($spec['label']) ? (string) $spec['label'] : null,
            options: is_array($spec['options'] ?? null) ? $spec['options'] : null,
            placeholder: isset($spec['placeholder']) ? (string) $spec['placeholder'] : null,
        );
    }

    /**
     * The inference the client used to do badly: the widget a value's own
     * PHP type implies. Argument names that read as identifiers
     * (`id`, `*_id`) are never editable — they address the record the write
     * lands on, so letting the user retype one repoints the write.
     */
    public static function infer(string $name, mixed $value): self
    {
        if ($name === 'id' || str_ends_with($name, '_id')) {
            return self::readonly($name);
        }

        return self::make($name, match (true) {
            is_bool($value) => FieldWidget::Boolean,
            is_int($value), is_float($value) => FieldWidget::Number,
            // Structured arguments have no scalar control to render; a tool
            // that wants one editable declares its own field spec.
            is_array($value), is_object($value) => FieldWidget::Readonly,
            is_string($value) && (str_contains($value, "\n") || mb_strlen($value) > 120) => FieldWidget::Textarea,
            default => FieldWidget::Text,
        });
    }

    /** The same field with every input locked — a one-click card's rendering. */
    public function locked(): self
    {
        return new self(
            name: $this->name,
            widget: $this->widget === FieldWidget::Hidden ? FieldWidget::Hidden : FieldWidget::Readonly,
            editable: false,
            label: $this->label,
            options: $this->options,
            placeholder: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $value = null): array
    {
        return [
            'name' => $this->name,
            'widget' => $this->widget->value,
            'editable' => $this->editable,
            'label' => $this->label,
            'options' => $this->options,
            'placeholder' => $this->placeholder,
            'value' => $value,
        ];
    }

    /**
     * Coerce an edited value back to the type the widget promises, so an
     * HTML control's string does not reach a tool that had an int.
     */
    public function cast(mixed $value, mixed $original): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->widget) {
            FieldWidget::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            FieldWidget::Number => self::castNumber($value, $original),
            default => $value,
        };
    }

    private function renamed(string $name): self
    {
        return new self($name, $this->widget, $this->editable, $this->label, $this->options, $this->placeholder);
    }

    private static function castNumber(mixed $value, mixed $original): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        // An int argument stays an int; a float one stays a float even when
        // the client sent "3".
        if (is_float($original) || (is_string($value) && str_contains($value, '.'))) {
            return (float) $value;
        }

        return (int) $value;
    }

    private static function widget(string $name, string $value): FieldWidget
    {
        return FieldWidget::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown field widget [{$value}] for argument [{$name}].");
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return list<array{value: mixed, label: string}>
     */
    private static function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $normalized[] = [
                    'value' => $option['value'],
                    'label' => (string) ($option['label'] ?? $option['value']),
                ];

                continue;
            }

            // A value => label map keys the option; a plain list labels itself.
            $value = is_string($key) ? $key : $option;

            $normalized[] = ['value' => $value, 'label' => (string) $option];
        }

        return $normalized;
    }
}
