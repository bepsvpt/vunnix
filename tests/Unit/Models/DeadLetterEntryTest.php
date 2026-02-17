<?php

use App\Models\DeadLetterEntry;

it('has correct table name', function (): void {
    $entry = new DeadLetterEntry;
    expect($entry->getTable())->toBe('dead_letter_queue');
});

it('casts task_record to array', function (): void {
    $entry = new DeadLetterEntry;
    $casts = $entry->getCasts();
    expect($casts['task_record'])->toBe('array');
});

it('casts attempts to array', function (): void {
    $entry = new DeadLetterEntry;
    $casts = $entry->getCasts();
    expect($casts['attempts'])->toBe('array');
});

it('casts dismissed to boolean', function (): void {
    $entry = new DeadLetterEntry;
    $casts = $entry->getCasts();
    expect($casts['dismissed'])->toBe('boolean');
});

it('casts timestamps correctly', function (): void {
    $entry = new DeadLetterEntry;
    $casts = $entry->getCasts();
    expect($casts['originally_queued_at'])->toBe('datetime');
    expect($casts['dead_lettered_at'])->toBe('datetime');
    expect($casts['dismissed_at'])->toBe('datetime');
});
