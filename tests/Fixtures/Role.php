<?php

namespace SoftArtisan\LaravelAuditEvents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;
use SoftArtisan\LaravelAuditEvents\Concerns\TracksRelationChanges;

class Role extends Model
{
    use IsAuditable, TracksRelationChanges;

    protected $table = 'roles';

    protected $guarded = [];
}
