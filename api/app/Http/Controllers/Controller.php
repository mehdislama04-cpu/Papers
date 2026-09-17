<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Depuis Laravel 11, le contrôleur de base n'hérite plus de rien et n'apporte
 * plus AuthorizesRequests : $this->authorize() n'existe pas tant qu'on ne le
 * remonte pas ici. Les policies de Papers (isolation par utilisateur) étant
 * appelées explicitement dans presque tous les contrôleurs, le trait a sa
 * place au socle.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
