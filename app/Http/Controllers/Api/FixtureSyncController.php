<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fixtures\QueueFixtureSyncAction;
use App\Http\Controllers\Controller;

class FixtureSyncController extends Controller
{
    public function __invoke(QueueFixtureSyncAction $action)
    {
        return response()->json(['data' => $action->execute()], 202);
    }
}
