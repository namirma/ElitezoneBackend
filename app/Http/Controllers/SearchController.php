<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResource;
use App\Models\Search;
use App\Models\SupplierActivityCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public function search(SearchRequest $request)
    {
        $query = $request->input('query', ''); // Default to an empty string if no query is provided

        // If the query is empty, fetch popular searches directly
        if (empty($query)) {
            $popularSearches = Search::select('query')
                ->whereIn('query', function ($q) {
                    $q->select('name')
                        ->from('supplier_activity_categories')
                        ->union(
                            SupplierActivityCategory::select('name')
                                ->whereHas('children')
                                ->getQuery()
                        );
                })
                ->groupBy('query')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(5)
                ->pluck('query');

            return new SearchResource([
                'related_searches' => [],
                'latest_searches' => [],
                'popular_searches' => $popularSearches,
            ]);
        }

        // Save the search query for logged-in users or guests
        if (Auth::check()) {
            // Check if the search query already exists for the authenticated user
            $existingSearch = Search::where('user_id', Auth::id())
                ->where('query', $query)
                ->first();

            if (!$existingSearch) {
                Search::create([
                    Search::QUERY => $query,
                    Search::USER_ID => Auth::id(),
                ]);
            }
        } else {
            // For guest users
            $existingSearch = Search::where('query', $query)
                ->whereNull('user_id')
                ->first();

            if (!$existingSearch) {
                Search::create([
                    Search::QUERY => $query,
                    Search::USER_ID => null,
                ]);
            }
        }

        // Fetch related searches
        $relatedSearches = SupplierActivityCategory::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%$query%")
                    ->orWhereHas('children', function ($q) use ($query) {
                        $q->where('name', 'like', "%$query%");
                    });
            })
            ->with('children')
            ->get()
            ->flatMap(function ($category) use ($query) {
                $matches = collect();
                if (Str::contains($category->name, $query)) {
                    $matches->push($category->name);
                }

                $matches = $matches->merge(
                    $category->children->filter(function ($child) use ($query) {
                        return Str::contains($child->name, $query);
                    })->pluck('name')
                );

                return $matches;
            })
            ->unique()
            ->take(5);

        // Fetch latest searches
        $latestSearches = Auth::check()
            ? Search::where('user_id', Auth::id())
                ->select('query')
                ->distinct()
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->pluck('query')
            : Search::select('query')
                ->distinct()
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->pluck('query');

        // Fetch popular searches
        $popularSearches = Search::select('query')
            ->whereIn('query', function ($q) {
                $q->select('name')
                    ->from('supplier_activity_categories')
                    ->union(
                        SupplierActivityCategory::select('name')
                            ->whereHas('children')
                            ->getQuery()
                    );
            })
            ->groupBy('query')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(5)
            ->pluck('query');

        $data = [
            'related_searches' => $relatedSearches,
            'latest_searches' => $latestSearches,
            'popular_searches' => $popularSearches,
        ];

        return new SearchResource($data);
    }
}
