<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class PageController extends Controller
{
    public function welcome() {
        return Inertia::render('welcome');
    }
    public function home() {

        $username = 'Haydaru';
        return Inertia::render('home', ['username' => $username]);
    }

    public function support()
    {
        return Inertia::render('support');
    }

    public function pricing()
    {
        return Inertia::render('pricing');
    }
    public function myReport(){
        return Inertia::render('my-report');
    }
}
