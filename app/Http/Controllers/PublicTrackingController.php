<?php

namespace App\Http\Controllers;

use App\Models\PublicTrackingToken;
use App\Services\PublicTracking;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicTrackingController extends Controller
{
    public function index()
    {
        return Inertia::render('track', ['result' => null, 'searched' => false]);
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'secret' => ['required', 'string', 'max:200']]);
        $token = PublicTrackingToken::where('token_hash', hash('sha256', PublicTracking::normalize($data['secret'])))
            ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('document', fn ($q) => $q->where('tracking_number', strtoupper(trim($data['reference'])))->where('public_tracking_enabled', true)->where('confidentiality', 'internal'))
            ->with('document.type')->first();
        $result = null;
        if ($token) {
            $document = $token->document;
            $token->update(['last_used_at' => now()]);
            $result = ['reference' => $document->tracking_number, 'category' => $document->type->name, 'status' => $document->status->publicLabel(), 'updated_at' => $document->updated_at->toIso8601String(), 'timeline' => app(PublicTracking::class)->timeline($document)];
        }

        return Inertia::render('track', ['result' => $result, 'searched' => true]);
    }
}
