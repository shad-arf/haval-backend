<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

class SiteSettingsController extends Controller
{
    /**
     * Public read of the storefront's contact info and short marketing
     * copy. Unlike /admin/settings (pricing, auth-gated), these values are
     * safe to expose to every visitor since the header, footer, and
     * contact page all render them.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => SiteSettings::all()]);
    }
}
