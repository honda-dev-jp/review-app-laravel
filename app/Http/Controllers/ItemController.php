<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function index(): View
    {
        $items = Item::with('category')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return view('items.index', compact('items'));
    }

    public function show(Item $item): View
    {
        $item->load([
            'category',
            'reviews' => function (Builder $query) {
                $query->with([
                    'user',
                    'comments' => function (Builder $query) {
                        $query->with('user')
                            ->orderBy('created_at')
                            ->orderBy('id');
                    },
                ])
                    ->orderByDesc('created_at')
                    ->orderByDesc('id');
            },
        ]);

        return view('items.show', compact('item'));
    }
}
