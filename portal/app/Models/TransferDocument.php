<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferDocument extends Model
{
    protected $fillable = ['transfer_evaluation_id', 'path', 'original_name'];
}
