<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteWidgetTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static int $invocations = 0;

    public function name(): string
    {
        return 'DeleteWidget';
    }

    public function description(): string
    {
        return 'Deletes a widget. Destructive.';
    }

    public function handle(Request $request): string
    {
        static::$invocations++;

        return 'deleted widget '.$request['id'];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('destructive');
    }
}
