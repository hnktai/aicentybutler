<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;

class NoContentController extends Controller
{
    public function __invoke()
    {
        return response()->noContent();
    }
}
