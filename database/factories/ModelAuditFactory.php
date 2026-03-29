<?php

namespace SoftArtisan\LaravelAuditEvents\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

/**
 * @extends Factory<ModelAudit>
 */
class ModelAuditFactory extends Factory
{
    protected $model = ModelAudit::class;

    public function definition(): array
    {
        $fields = config('audit-events.table_fields');
        $events = config('audit-events.events', ['created', 'updated', 'deleted', 'restored']);
        $morphName = config('audit-events.table_fields.morph_prefix', 'auditable');

        return [
            $fields['event'] => $this->faker->randomElement($events),
            $fields['user_id'] => $this->faker->optional(0.8)->randomNumber(),
            $fields['url'] => $this->faker->optional(0.9)->url(),
            $fields['ip_address'] => $this->faker->optional(0.9)->ipv4(),
            $fields['user_agent'] => $this->faker->optional(0.9)->userAgent(),
            $fields['old_values'] => $this->faker->optional(0.6)->passthrough(
                ['title' => $this->faker->words(2, true), 'status' => 'draft']
            ),
            $fields['new_values'] => ['title' => $this->faker->words(3, true), 'status' => 'published'],
            $fields['context'] => $this->faker->optional(0.3)->passthrough(['source' => 'factory']),
            "{$morphName}_type" => null,
            "{$morphName}_id" => null,
        ];
    }

    /**
     * State: bind to a specific auditable model instance.
     */
    public function forModel(Model $model): static
    {
        $morphName = config('audit-events.table_fields.morph_prefix', 'auditable');

        return $this->state([
            "{$morphName}_type" => get_class($model),
            "{$morphName}_id" => $model->getKey(),
        ]);
    }

    /**
     * State: created event.
     */
    public function created(): static
    {
        $fields = config('audit-events.table_fields');

        return $this->state([
            $fields['event'] => 'created',
            $fields['old_values'] => [],
        ]);
    }

    /**
     * State: updated event.
     */
    public function updated(): static
    {
        $fields = config('audit-events.table_fields');

        return $this->state([$fields['event'] => 'updated']);
    }

    /**
     * State: deleted event.
     */
    public function deleted(): static
    {
        $fields = config('audit-events.table_fields');

        return $this->state([
            $fields['event'] => 'deleted',
            $fields['new_values'] => [],
        ]);
    }

    /**
     * State: free event (no auditable model bound).
     */
    public function free(string $event = 'custom.event'): static
    {
        $fields = config('audit-events.table_fields');
        $morphName = config('audit-events.table_fields.morph_prefix', 'auditable');

        return $this->state([
            $fields['event'] => $event,
            "{$morphName}_type" => null,
            "{$morphName}_id" => null,
        ]);
    }
}
