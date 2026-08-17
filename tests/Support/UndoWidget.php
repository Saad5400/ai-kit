<?php

namespace Saad\AiKit\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class UndoWidget extends Model
{
    /** @var list<int> ids whose deleting hook fired */
    public static array $deleted = [];

    protected $table = 'undo_widgets';

    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(function (self $widget): void {
            self::$deleted[] = (int) $widget->getKey();
        });
    }
}
