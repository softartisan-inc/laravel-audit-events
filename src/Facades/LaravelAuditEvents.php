<?php

namespace SoftArtisan\LaravelAuditEvents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SoftArtisan\LaravelAuditEvents\LaravelAuditEvents
 */
class LaravelAuditEvents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SoftArtisan\LaravelAuditEvents\LaravelAuditEvents::class;
    }
}
