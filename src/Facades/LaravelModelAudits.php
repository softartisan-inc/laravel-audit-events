<?php

namespace SoftArtisan\LaravelModelAudits\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SoftArtisan\LaravelModelAudits\LaravelModelAudits
 */
class LaravelModelAudits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SoftArtisan\LaravelModelAudits\LaravelModelAudits::class;
    }
}
