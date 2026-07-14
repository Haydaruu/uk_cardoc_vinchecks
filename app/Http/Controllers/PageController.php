<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function home() {

        $username = 'Haydaru';
        return Inertia::render('home', ['username' => $username]);
    }
}
