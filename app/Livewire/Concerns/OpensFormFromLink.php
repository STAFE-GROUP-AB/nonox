<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Lets a register page open its form from a link, as the spotlight does:
 * `?ny=1` opens an empty form, `?redigera=<id>` the record's. Only for
 * those who may change the register; others just see the list.
 */
trait OpensFormFromLink
{
    /**
     * @param  class-string<Model>  $model
     * @param  string  $ability  the policy ability that allows changing the register
     */
    protected function openFormFromLink(string $model, string $ability = 'create'): void
    {
        if (! Gate::allows($ability, [$model, $this->company()])) {
            return;
        }

        if (request()->boolean('ny')) {
            $this->create();

            return;
        }

        $id = request()->integer('redigera');

        if ($id > 0 && $model::query()->whereKey($id)->exists()) {
            $this->edit($id);
        }
    }
}
