<?php

namespace SoftArtisan\LaravelAuditEvents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;

class Article extends Model
{
    use IsAuditable { getHiddenForAudit as protected getHiddenForAuditFromTrait; }

    protected $table = 'articles';

    protected $guarded = [];

    protected $casts = [
        'extra_fields' => 'array',
    ];

    public function getHiddenForAudit(): array
    {
        return array_merge($this->getHiddenForAuditFromTrait(), ['secret_token']);
    }
}
