<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class ContactsLinks extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'contacts_links';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function contacts1()
    {
        return $this->belongsTo(Contacts::class, 'contacts_1', 'id');
    }

    public function contacts2()
    {
        return $this->belongsTo(Contacts::class, 'contacts_2', 'id');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
