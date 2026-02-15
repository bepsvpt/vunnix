<?php

use App\Models\GlobalSetting;

it('returns default PRD template via static method', function () {
    $template = GlobalSetting::defaultPrdTemplate();

    expect($template)->toContain('## Problem')
        ->and($template)->toContain('## Proposed Solution')
        ->and($template)->toContain('## User Stories')
        ->and($template)->toContain('## Acceptance Criteria')
        ->and($template)->toContain('## Out of Scope')
        ->and($template)->toContain('## Technical Notes')
        ->and($template)->toContain('## Open Questions');
});
