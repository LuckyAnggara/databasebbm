<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetailPembelian extends Model
{
    use HasFactory;
    use SoftDeletes;
 
    protected $table = 'detail_pembelian';
    protected $guarded = [
        'id',
    ];
    public $primaryKey = 'id';
    public $timestamps = true;
    protected $dates = ['deleted_at'];
}
