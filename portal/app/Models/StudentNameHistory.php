<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentNameHistory extends Model
{
    protected $table = 'student_name_history';

    protected $fillable = ['student_profile_id', 'name_change_request_id', 'previous_name', 'new_name', 'reason', 'approved_by', 'effective_date'];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }
}
