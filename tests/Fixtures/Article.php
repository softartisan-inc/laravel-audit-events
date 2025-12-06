<?php

namespace SoftArtisan\LaravelModelAudits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelModelAudits\Concerns\IsAuditable;

class Article extends Model
{
    use IsAuditable { getHiddenForAudit as protected getHiddenForAuditFromTrait; }

    protected $table = 'articles';

    protected $guarded = [];

    // Ajoute un champ à masquer pour les tests, sans redéclarer la propriété du trait
    public function getHiddenForAudit(): array
    {
        return array_merge($this->getHiddenForAuditFromTrait(), ['secret_token']);
    }
}
