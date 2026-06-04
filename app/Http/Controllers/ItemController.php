<?php

namespace App\Http\Controllers;

use App\Models\Item;

class ItemController extends Controller
{
    public function index()
    {
        $items = Item::with('category')
            ->orderBy('id', 'desc')
            ->paginate(10);

        return view('items.index', compact('items'));
    }

    public function show(Item $item)
    {
        $item->load('category');

        return view('items.show', compact('item'));
    }
}
