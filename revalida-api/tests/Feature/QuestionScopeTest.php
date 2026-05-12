<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionSource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_sources');

        Schema::create('question_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('exam')->nullable();
            $table->integer('year')->nullable();
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_source_id')->nullable()->constrained('question_sources')->nullOnDelete();
            $table->string('source_hash')->nullable();
            $table->string('area');
            $table->string('tema');
            $table->string('dificuldade')->default('Média');
            $table->string('question_type')->default('multiple_choice');
            $table->string('study_category')->default('revalida');
            $table->text('enunciado');
            $table->text('alternativa_a');
            $table->text('alternativa_b');
            $table->text('alternativa_c');
            $table->text('alternativa_d');
            $table->text('alternativa_e');
            $table->string('gabarito', 1);
            $table->text('comentario')->nullable();
            $table->string('origin')->default('original');
            $table->string('status')->default('draft');
            $table->json('tags')->nullable();
            $table->text('reference')->nullable();
            $table->timestamps();
        });
    }

    public function test_questions_endpoint_defaults_to_revalida_first_phase(): void
    {
        $objectiveSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Objetiva',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_PV_objetiva_regular.pdf',
            'file_path' => 'storage/imports/pdfs/inep-revalida-2024_1_PV_objetiva_regular.pdf',
        ]);

        $discursiveSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Discursiva',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_PV_discursiva_regular.pdf',
            'file_path' => 'storage/imports/pdfs/inep-revalida-2024_1_PV_discursiva_regular.pdf',
        ]);

        $skillsSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Habilidades Clínicas',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_prova_habilidades_clinicas.pdf',
            'file_path' => 'storage/imports/pdfs/inep-revalida-2024_1_prova_habilidades_clinicas.pdf',
        ]);

        $enareSource = QuestionSource::create([
            'name' => 'ENARE 2024',
            'institution' => 'EBSERH',
            'exam' => 'ENARE',
            'url' => 'https://gov.br/enare/2024',
        ]);

        $this->makeQuestion($objectiveSource->id, null);
        $this->makeQuestion($discursiveSource->id, null);
        $this->makeQuestion($skillsSource->id, 'INEP | Revalida | https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_prova_habilidades_clinicas.pdf');
        $this->makeQuestion($enareSource->id, 'EBSERH | ENARE | https://gov.br/enare/2024', 'estudo_geral');

        $response = $this->getJson('/api/questions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('total', 2);
    }

    public function test_questions_endpoint_can_return_all_revalida_phases_when_requested(): void
    {
        $objectiveSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Objetiva',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_PV_objetiva_regular.pdf',
            'file_path' => 'storage/imports/pdfs/inep-revalida-2024_1_PV_objetiva_regular.pdf',
        ]);

        $skillsSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Habilidades Clínicas',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_prova_habilidades_clinicas.pdf',
            'file_path' => 'storage/imports/pdfs/inep-revalida-2024_1_prova_habilidades_clinicas.pdf',
        ]);

        $this->makeQuestion($objectiveSource->id, null);
        $this->makeQuestion($skillsSource->id, 'INEP | Revalida | https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_prova_habilidades_clinicas.pdf');

        $response = $this->getJson('/api/questions/summary?scope=revalida&phase=all');

        $response->assertOk();
        $response->assertJsonPath('total_questions', 2);
    }

    public function test_questions_endpoint_can_return_estudo_geral_when_requested(): void
    {
        $revalidaSource = QuestionSource::create([
            'name' => 'Revalida INEP 2024 - Objetiva',
            'institution' => 'INEP',
            'exam' => 'Revalida',
            'url' => 'https://download.inep.gov.br/revalida/provas_e_gabaritos/2024_1_PV_objetiva_regular.pdf',
        ]);

        $generalSource = QuestionSource::create([
            'name' => 'FUVEST 2025',
            'institution' => 'USP',
            'exam' => 'Residência Médica',
            'url' => 'https://www.fuvest.br/residencia-medica-provas-e-gabarito/',
        ]);

        $this->makeQuestion($revalidaSource->id, null, 'revalida');
        $this->makeQuestion($generalSource->id, 'FUVEST / USP - Residência Médica', 'estudo_geral');

        $response = $this->getJson('/api/questions/summary?category=estudo_geral');

        $response->assertOk();
        $response->assertJsonPath('total_questions', 1);
    }

    private function makeQuestion(?int $sourceId, ?string $reference, string $studyCategory = 'revalida'): Question
    {
        return Question::create([
            'question_source_id' => $sourceId,
            'source_hash' => uniqid('hash_', true),
            'area' => 'Clínica Médica',
            'tema' => 'Tema teste',
            'dificuldade' => 'Média',
            'question_type' => 'multiple_choice',
            'study_category' => $studyCategory,
            'enunciado' => 'Paciente com quadro clínico. Qual é a melhor conduta?',
            'alternativa_a' => 'Alternativa A completa.',
            'alternativa_b' => 'Alternativa B completa.',
            'alternativa_c' => 'Alternativa C completa.',
            'alternativa_d' => 'Alternativa D completa.',
            'alternativa_e' => 'Alternativa E completa.',
            'gabarito' => 'A',
            'comentario' => 'Comentário de teste suficientemente detalhado.',
            'origin' => 'official_based',
            'status' => 'draft',
            'reference' => $reference,
        ]);
    }
}
