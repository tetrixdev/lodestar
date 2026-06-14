<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/** A single landing page that gathers the account & integration settings. */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.index');
    }
}
