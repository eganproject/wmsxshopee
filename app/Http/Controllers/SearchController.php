<?php

namespace App\Http\Controllers;

use App\Support\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Pencarian global untuk palet Ctrl+K: hasil per grup dalam JSON. */
    public function __invoke(Request $request): JsonResponse
    {
        $term = mb_substr(trim($request->string('q')->toString()), 0, 100);

        return response()->json([
            'query' => $term,
            'groups' => (new GlobalSearch($request->user(), $term))->results(),
        ]);
    }
}
