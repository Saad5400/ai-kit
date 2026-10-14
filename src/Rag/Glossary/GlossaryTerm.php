<?php

namespace Saad\AiKit\Rag\Glossary;

/**
 * One concept and the ways people write it — «محاضرة» / «لكتشر» / `lecture`,
 * `string` / «سترنق» / «النصوص». Every form is equivalent for matching: a
 * query containing ANY of them is expanded with the others. `term` is the
 * preferred spelling, the one a whole-query rewrite substitutes first.
 *
 * Forms are written as authored (they are shown to the user as "also
 * searched: …"); matching folds them with the kit normaliser. A form may be
 * several words (`past exams`).
 */
final class GlossaryTerm
{
    /**
     * @param  list<string>  $alternatives
     * @param  string|null  $source  where the term came from (e.g. `course:12`, `platform`), for diagnostics
     */
    public function __construct(
        public readonly string $term,
        public readonly array $alternatives = [],
        public readonly ?string $source = null,
    ) {}

    /**
     * The preferred spelling first, then the alternatives, de-duplicated and
     * without blanks.
     *
     * @return list<string>
     */
    public function forms(): array
    {
        $forms = [];

        foreach ([$this->term, ...$this->alternatives] as $form) {
            $form = trim((string) $form);

            if ($form !== '' && ! in_array($form, $forms, true)) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * @param  array{term: string, alternatives?: list<string>, source?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['term'], array_values($data['alternatives'] ?? []), $data['source'] ?? null);
    }
}
