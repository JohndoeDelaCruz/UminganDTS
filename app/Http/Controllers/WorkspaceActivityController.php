<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceActivity;
use Illuminate\Http\Request;

class WorkspaceActivityController extends Controller
{
    public function __invoke(Request $request, WorkspaceActivity $activity)
    {
        return response()->json($activity->counts($request->user()));
    }
}
