<?php

namespace Saad\AiKit\Bench;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One realistic multi-turn story the assistant must get right. The app
 * seeds its world, names the acting user, scripts the turns and attaches
 * graders; the runner does the rest.
 */
final class Scenario
{
    /**
     * @param  string  $name  unique slug, e.g. 'structure.create-course-three-sections'
     * @param  string  $title  human title (may be Arabic)
     * @param  list<Turn>  $turns
     * @param  list<Grader>  $graders  scenario-level graders (see all turns)
     * @param  list<string>  $tags  e.g. ['prefilled', 'structure', 'ar']
     * @param  (Closure(): mixed)|null  $seed  seeds the world before the first turn; its return is the context handed to graders via {@see ScenarioRun::$context}
     * @param  (Closure(mixed): Authenticatable)|null  $actor  resolves the acting user from the context
     * @param  (Closure(mixed): void)|null  $teardown
     * @param  string|null  $expectedLanguage  'ar'|'en'|null
     * @param  bool  $screenshot  hint for an app-side browser runner
     * @param  string|null  $description  why this scenario exists / what real usage it mirrors
     */
    public function __construct(
        public string $name,
        public string $title,
        public array $turns,
        public array $graders = [],
        public array $tags = [],
        public ?Closure $seed = null,
        public ?Closure $actor = null,
        public ?Closure $teardown = null,
        public ?string $expectedLanguage = null,
        public ?float $maxCostUsd = null,
        public ?int $maxWallMs = null,
        public bool $screenshot = false,
        public ?string $description = null,
    ) {}

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'tags' => $this->tags,
            'turns' => count($this->turns),
            'expectedLanguage' => $this->expectedLanguage,
            'maxCostUsd' => $this->maxCostUsd,
            'maxWallMs' => $this->maxWallMs,
            'screenshot' => $this->screenshot,
        ];
    }
}
