<?php

namespace SoftArtisan\LaravelModelAudits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use SoftArtisan\LaravelModelAudits\Concerns\IsAuditable;

class SoftArticle extends Model
{
    use IsAuditable, SoftDeletes { IsAuditable::getHiddenForAudit as protected getHiddenForAuditFromTrait; }

    protected $table = 'soft_articles';

    protected $guarded = [];

    // Ajoute un champ à masquer pour les tests, sans redéclarer la propriété du trait
    public function getHiddenForAudit(): array
    {
        return array_merge($this->getHiddenForAuditFromTrait(), ['secret_token']);
    }
}
