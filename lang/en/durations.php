<?php

return [
    'value_invalid' => 'The value must be a whole number between 1 and :max for this unit.',
    'value_forbidden' => 'A lifetime duration has no value.',
    'category_unit' => 'Trial and test periods can only use days, weeks or months.',
    'name_taken' => 'Another active duration already uses this name.',
    'key_taken' => 'This key is already used by another duration.',
    'key_invalid' => 'The key may only contain lowercase letters, digits and underscores, and must start with a letter.',
    'key_immutable' => 'The key of an existing duration cannot be changed.',
    'immutable_system' => 'This is a protected system duration: its category, unit and value cannot be changed.',
    'immutable_referenced' => 'Plans already use this duration, so its category, unit and value cannot be changed. Create a new duration instead.',
    'version_conflict' => 'Another administrator changed this duration. Reload and try again.',
    'in_use' => 'Active plans use this duration. Confirm to archive it; existing plans and subscriptions keep working.',
    'plan_duration_unavailable' => 'The selected duration does not exist or is not available for new plans.',
    'plan_duration_locked' => 'This plan already has subscriptions, so its duration cannot change. Create a new plan for the new duration.',
    'plan_duration_required' => 'Choose a duration from the catalog.',
];
