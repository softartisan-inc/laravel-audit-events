<?php

namespace SoftArtisan\LaravelModelAudits\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModelAudit extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [];

    protected $casts = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('model-audits.table_name');

        $fields = config('model-audits.table_fields');
        $this->primaryKey = $fields['id'] ?? 'audit_id';

        $this->fillable = [
            $fields['event'],
            $fields['user_id'],
            $fields['url'],
            $fields['ip_address'],
            $fields['user_agent'],
            $fields['old_values'],
            $fields['new_values'],
        ];

        $this->casts = [
            $fields['old_values'] => 'array',
            $fields['new_values'] => 'array',
        ];
    }

    /**
     * Define a polymorphic relationship.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
