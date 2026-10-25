<?php

use Illuminate\Support\Facades\Broadcast;

// Channel is private; only authenticated ERP users may receive invalidation events.
// If branch-level isolation is required, extend this callback with the project's branch policy.
Broadcast::channel('erp.sync', function ($user): bool {
    return $user !== null;
});
