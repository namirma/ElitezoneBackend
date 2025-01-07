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
        $query = $request->input('query');

        if (Auth::check()) {
            // Check if the search query already exists for the authenticated user
            $existingSearch = Search::where('user_id', Auth::id())
                ->where('query', $query)
                ->first();

            // Save the search query only if it doesn't already exist for the authenticated user
            if (!$existingSearch) {
                Search::create([
                    Search::QUERY => $query,
                    Search::USER_ID => Auth::id(), // Set the authenticated user's ID
                ]);
            }
        } else {
            // For guest users, check if the search query already exists for any user
            $existingSearch = Search::where('query', $query)
                ->whereNull('user_id') // Check for guest searches
                ->first();

            // Save the search query only if it doesn't already exist for guest users
            if (!$existingSearch) {
                Search::create([
                    Search::QUERY => $query,
                    Search::USER_ID => null, // Set user_id to null for guest users
                ]);
            }
        }

        // Fetch search results
        // $results = Search::where('query', 'like', "%$query%")
        //     ->get()->all();


        // Fetch related searches from the searches table

        $relatedSearches = SupplierActivityCategory::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%$query%") // Match parent categories
                    ->orWhereHas('children', function ($q) use ($query) {
                        $q->where('name', 'like', "%$query%"); // Match child categories
                    });
            })
            ->with('children') // Eager load child categories
            ->get()
            ->flatMap(function ($category) use ($query) {
                // Include parent category if it matches the query
                $matches = collect();
                if (Str::contains($category->name, $query)) {
                    $matches->push($category->name);
                }

                // Include child categories that match the query
                $matches = $matches->merge(
                    $category->children->filter(function ($child) use ($query) {
                        return Str::contains($child->name, $query);
                    })->pluck('name')
                );

                return $matches;
            })
            ->unique() // Remove duplicates
            ->take(5); // Limit to 5 results


        // Fetch latest searches
        $latestSearches = Auth::check()
            ? Search::where('user_id', Auth::id())
            ->select('query') // Select only the query column
            ->distinct() // Remove duplicates
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('query')
            : Search::select('query') // Select only the query column
            ->distinct() // Remove duplicates
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('query');


        // Fetch popular searches (most searched terms by all users)
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
            // 'results' => $results,
            'related_searches' => $relatedSearches,
            'latest_searches' => $latestSearches,
            'popular_searches' => $popularSearches,
        ];
        // Return the response as JSON
        return new SearchResource($data);
    }
}
