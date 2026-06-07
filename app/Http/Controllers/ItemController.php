<?php

namespace App\Http\Controllers;

use App\Models\Item;

class ItemController extends Controller
{
    public function index()
    {
        $items = Item::with('category')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return view('items.index', compact('items'));
    }

    public function show(Item $item)
    {
        $item->load([
            'category',
            'reviews' => function ($query) {
                $query->with([
                    'user',
                    'comments' => function ($query) {
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
