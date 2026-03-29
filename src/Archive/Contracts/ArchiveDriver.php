<?php

namespace SoftArtisan\LaravelAuditEvents\Archive\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface ArchiveDriver
{
    /**
     * Persist a collection of audit records to the archive destination.
     *
     * Must be called BEFORE the live records are deleted.
     * Returns the number of records successfully archived.
     *
     * @param  Collection<int, Model>  $records
     */
    public function archive(Collection $records): int;
}
