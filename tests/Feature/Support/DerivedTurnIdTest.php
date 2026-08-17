<?php

use Saad\AiKit\Support\DerivedTurnId;

it('derives stable uuid-shaped ids that differ per suffix and never equal the turn id', function () {
    $vision = DerivedTurnId::for('turn-1', 'vision');
    $batch = DerivedTurnId::for('turn-1', 'document:0:1');

    expect($vision)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(DerivedTurnId::for('turn-1', 'vision'))->toBe($vision)
        ->and($batch)->not->toBe($vision)
        ->and(DerivedTurnId::for('turn-2', 'vision'))->not->toBe($vision);
});
