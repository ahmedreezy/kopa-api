<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends TenantModel
{
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
