<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'institution',
        'exam',
        'year',
        'url',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'question_source_id');
    }
}
