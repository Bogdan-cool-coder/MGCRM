<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Contacts extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'contacts';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'contacts_buy_dob' => 'date',
        ];
    }

    /**
     * Link to company contact via contacts_links (person → company)
     * Custom relation added for BOMI calls report
     */
    public function companyLink()
    {
        return $this->hasOne(ContactsLinks::class, 'contacts_2', 'id');
    }
}
