<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    protected $fillable = [
        'question_source_id',
        'source_hash',
        'area',
        'tema',
        'dificuldade',
        'question_type',
        'study_category',
        'enunciado',
        'alternativa_a',
        'alternativa_b',
        'alternativa_c',
        'alternativa_d',
        'alternativa_e',
        'gabarito',
        'comentario',
        'comment_source',
        'official_answer',
        'origin',
        'status',
        'reference',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'question_source_id' => 'integer',
            'tags' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(QuestionSource::class, 'question_source_id');
    }
}
