<?php

return [
    /*
    | Roles that must confirm stock and product changes with their own PIN
    | (sent as the X-Staff-Pin header). Add Storekeeper once every till runs
    | a version that asks for the PIN, e.g. STAFF_PIN_ROLES=Manager,Storekeeper.
    */
    'roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('STAFF_PIN_ROLES', 'Manager'))))),

    // Wrong PIN tries allowed per user per minute.
    'max_attempts' => 5,
];
