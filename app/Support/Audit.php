<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Append an entry to the activity log for the current user and request. */
    public static function log(string $action, Model $subject, array $properties = []): ActivityLog
    {
        $log = $subject->morphMany(ActivityLog::class, 'subject')->make([
            'user_id' => auth()->id(),
            'action' => $action,
            'properties' => $properties,
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
        ]);
        $log->save();

        return $log;
    }
}
