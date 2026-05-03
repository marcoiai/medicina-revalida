# Auto geração de questões com IA

## Instalar

Dentro de `revalida-api`:

```bash
source .venv/bin/activate
pip install -r tools/requirements-ai.txt
```

## Configurar chave

Por padrão, o fluxo usa OpenAI.

```bash
export OPENAI_API_KEY="sua_chave_aqui"
```

Opcional:

```bash
export QUESTION_AI_PROVIDER="openai"
export OPENAI_MODEL="gpt-5-nano"
```

Ou rode sem variável e passe o provedor por comando:

```bash
python tools/auto_generate_questions.py --provider openai
```

## Rodar teste com 1 prompt

```bash
python tools/auto_generate_questions.py --limit 1
```

## Rodar todos

```bash
python tools/auto_generate_questions.py
```

## Validar o lote antes de importar

```bash
python tools/validate_questions.py storage/imports/questions.json
```

Auditoria semântica opcional com o mesmo provedor:

```bash
python tools/validate_questions.py storage/imports/questions.json --llm
```

O script agora faz retry automático em falhas transitórias como `503 UNAVAILABLE` e picos de demanda do modelo.

Se quiser ajustar o comportamento:

```bash
export MAX_CONCURRENT_BATCHES=3
export AI_RETRY_ATTEMPTS=5
export AI_RETRY_INITIAL_DELAY_SECONDS=2
export AI_RETRY_MAX_DELAY_SECONDS=30
```

Saída:

```txt
storage/imports/questions.json
storage/imports/questions.rejected.json
storage/imports/validation/questions.approved.json
storage/imports/validation/questions.flagged.json
storage/imports/validation/questions.rejected.json
storage/imports/validation/questions.validation_report.json
```

Importar no Laravel:

```bash
php artisan questions:import-json storage/imports/validation/questions.approved.json
```

Atalho completo:

```bash
python tools/run_web_pipeline.py --full --import-json
```
