<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImmagineProdotto extends Model
{
    protected $table = 'immagini_prodotto';
    public $timestamps = false;
    protected $fillable = ['id_prodotto', 'url', 'ordine', 'principale'];
}
