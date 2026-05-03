<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class QuestionController extends Controller
{
    private const AI_COMMENT_PREFIX = 'gabarito definitivo oficial do inep:';

    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 2000);
        $perPage = max(1, min($perPage, 2000));

        /** @var LengthAwarePaginator $questions */
        $questions = $this->scopedQuestions($request)
            ->with('source')
            ->orderBy('id')
            ->paginate($perPage);

        $questions->getCollection()->transform(function ($q) {
            return $this->formatQuestion($q);
        });

        return response()->json($questions);
    }

    public function summary(Request $request)
    {
        return response()->json([
            'total_questions' => $this->scopedQuestions($request)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'area' => 'required|string|max:100',
            'tema' => 'required|string|max:150',
            'dificuldade' => 'nullable|string|max:50',
            'question_type' => 'nullable|in:multiple_choice,discursive',
            'study_category' => 'nullable|in:revalida,estudo_geral',
            'enunciado' => 'required|string',
            'alternativas.A' => 'required|string',
            'alternativas.B' => 'required|string',
            'alternativas.C' => 'required|string',
            'alternativas.D' => 'required|string',
            'alternativas.E' => 'required|string',
            'gabarito' => 'required|in:A,B,C,D,E',
            'comentario' => 'nullable|string',
            'comment_source' => 'nullable|in:official,ai',
            'official_answer' => 'nullable|string',
        ]);

        $q = Question::create([
            'area' => $data['area'],
            'tema' => $data['tema'],
            'dificuldade' => $data['dificuldade'] ?? 'Média',
            'question_type' => $data['question_type'] ?? 'multiple_choice',
            'study_category' => $data['study_category'] ?? 'revalida',
            'enunciado' => $data['enunciado'],
            'alternativa_a' => $data['alternativas']['A'],
            'alternativa_b' => $data['alternativas']['B'],
            'alternativa_c' => $data['alternativas']['C'],
            'alternativa_d' => $data['alternativas']['D'],
            'alternativa_e' => $data['alternativas']['E'],
            'gabarito' => $data['gabarito'],
            'comentario' => $data['comentario'] ?? null,
            'comment_source' => $data['comment_source'] ?? null,
            'official_answer' => $data['official_answer'] ?? null,
        ]);

        return response()->json($this->formatQuestion($q), 201);
    }

    private function formatQuestion(Question $q): array
    {
        $commentSource = $this->resolveCommentSource($q);

        return [
            'id' => $q->id,
            'area' => $q->area,
            'tema' => $q->tema,
            'dificuldade' => $q->dificuldade,
            'question_type' => $q->question_type ?: 'multiple_choice',
            'study_category' => $q->study_category ?: 'revalida',
            'enunciado' => $q->enunciado,
            'alternativas' => [
                'A' => $q->alternativa_a,
                'B' => $q->alternativa_b,
                'C' => $q->alternativa_c,
                'D' => $q->alternativa_d,
                'E' => $q->alternativa_e,
            ],
            'gabarito' => $q->gabarito,
            'comentario' => $q->comentario,
            'comment_source' => $commentSource,
            'comment_generated_by_ai' => $commentSource === 'ai',
            'official_answer' => $q->official_answer,
            'origin' => $q->origin,
            'status' => $q->status,
            'reference' => $q->reference,
            'tags' => $q->tags,
            'question_source_id' => $q->question_source_id,
            'source' => $q->source ? [
                'id' => $q->source->id,
                'name' => $q->source->name,
                'institution' => $q->source->institution,
                'exam' => $q->source->exam,
                'year' => $q->source->year,
                'url' => $q->source->url,
            ] : null,
        ];
    }

    private function scopedQuestions(Request $request): Builder
    {
        $category = $this->normalizeStudyCategory(
            (string) $request->query('category', (string) $request->query('scope', 'revalida'))
        );
        $commentSource = $this->normalizeCommentSource((string) $request->query('comment_source', ''));
        $phase = strtolower(trim((string) $request->query('phase', 'first')));
        $query = Question::query();

        if ($category === 'all') {
            $this->applyCommentSourceFilter($query, $commentSource);

            return $query;
        }

        if ($category === 'revalida') {
            $query->where(function (Builder $revalidaQuery) {
                $revalidaQuery
                    ->where('study_category', 'revalida')
                    ->orWhereNull('study_category')
                    ->orWhereRaw("TRIM(COALESCE(study_category, '')) = ''");
            });
        } else {
            $query->where('study_category', $category);
        }

        $this->applyCommentSourceFilter($query, $commentSource);

        if ($category === 'revalida' && $phase === 'first') {
            $query->where(function (Builder $phaseQuery) {
                $phaseQuery
                    ->whereHas('source', function (Builder $sourceQuery) {
                        $this->applyFirstPhaseSourceFilter($sourceQuery);
                    })
                    ->orWhere(function (Builder $referenceQuery) {
                        $referenceQuery
                            ->where(function (Builder $matchQuery) {
                                $matchQuery
                                    ->whereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', ['%objetiva%'])
                                    ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', ['%discursiva%']);
                            })
                            ->whereRaw('LOWER(COALESCE(reference, \'\')) NOT LIKE ?', ['%habilidades_clinicas%'])
                            ->whereRaw('LOWER(COALESCE(reference, \'\')) NOT LIKE ?', ['%habilidades clínicas%']);
                    })
                    ->orWhere(function (Builder $fallbackQuery) {
                        $fallbackQuery
                            ->whereNull('question_source_id')
                            ->whereRaw('TRIM(COALESCE(reference, \'\')) = \'\'');
                    });
            });
        }

        return $query;
    }

    private function normalizeCommentSource(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'ai' => 'ai',
            'official' => 'official',
            'not_ai', 'nao_ai', 'sem_ia', 'human', 'non_ai' => 'not_ai',
            default => null,
        };
    }

    private function applyCommentSourceFilter(Builder $query, ?string $commentSource): void
    {
        if ($commentSource === null) {
            return;
        }

        if ($commentSource === 'not_ai') {
            $query->where(function (Builder $commentQuery) {
                $commentQuery
                    ->whereNull('comment_source')
                    ->orWhereRaw("LOWER(COALESCE(comment_source, '')) != 'ai'");
            });

            return;
        }

        $query->where('comment_source', $commentSource);
    }

    private function resolveCommentSource(Question $question): ?string
    {
        $stored = strtolower(trim((string) ($question->comment_source ?? '')));
        if (in_array($stored, ['official', 'ai'], true)) {
            return $stored;
        }

        if (($question->question_type ?? 'multiple_choice') === 'discursive' && !empty($question->official_answer)) {
            return 'official';
        }

        $comment = strtolower(trim((string) ($question->comentario ?? '')));
        if (
            ($question->question_type ?? 'multiple_choice') === 'multiple_choice'
            && ($question->origin ?? '') === 'official_verbatim'
            && $comment !== ''
            && !str_starts_with($comment, self::AI_COMMENT_PREFIX)
        ) {
            return 'ai';
        }

        return null;
    }

    private function normalizeStudyCategory(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'all' => 'all',
            'estudo_geral', 'estudogeral', 'geral', 'general' => 'estudo_geral',
            default => 'revalida',
        };
    }

    private function applyRevalidaSourceFilter(Builder $query): void
    {
        $query->where(function (Builder $matchQuery) {
            $matchQuery
                ->whereRaw('LOWER(COALESCE(exam, \'\')) LIKE ?', ['%revalida%'])
                ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', ['%revalida%'])
                ->orWhereRaw('LOWER(COALESCE(url, \'\')) LIKE ?', ['%revalida%'])
                ->orWhereRaw('LOWER(COALESCE(file_path, \'\')) LIKE ?', ['%revalida%']);
        });
    }

    private function applyFirstPhaseSourceFilter(Builder $query): void
    {
        $query
            ->where(function (Builder $matchQuery) {
                $matchQuery
                    ->whereRaw('LOWER(COALESCE(url, \'\')) LIKE ?', ['%objetiva%'])
                    ->orWhereRaw('LOWER(COALESCE(url, \'\')) LIKE ?', ['%discursiva%'])
                    ->orWhereRaw('LOWER(COALESCE(file_path, \'\')) LIKE ?', ['%objetiva%'])
                    ->orWhereRaw('LOWER(COALESCE(file_path, \'\')) LIKE ?', ['%discursiva%'])
                    ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', ['%objetiva%'])
                    ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', ['%discursiva%']);
            })
            ->whereRaw('LOWER(COALESCE(url, \'\')) NOT LIKE ?', ['%habilidades_clinicas%'])
            ->whereRaw('LOWER(COALESCE(file_path, \'\')) NOT LIKE ?', ['%habilidades_clinicas%'])
            ->whereRaw('LOWER(COALESCE(name, \'\')) NOT LIKE ?', ['%habilidades_clinicas%']);
    }
}
