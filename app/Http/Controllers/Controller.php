<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Gives every controller $this->authorize(), so a policy check is one line.
     * Laravel 11 and later leave this out of the skeleton by default.
     */
    use AuthorizesRequests;
}
