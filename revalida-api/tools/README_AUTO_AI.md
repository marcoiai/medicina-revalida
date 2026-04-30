# Auto geração de questões com Gemini

## Instalar

Dentro de `revalida-api`:

```bash
source .venv/bin/activate
pip install -r tools/requirements-ai.txt
```

## Configurar chave

```bash
export GEMINI_API_KEY="sua_chave_aqui"
```

Opcional:

```bash
export GEMINI_MODEL="gemini-2.5-flash"
```

## Rodar teste com 1 prompt

```bash
python tools/auto_generate_questions.py --limit 1
```

## Rodar todos

```bash
python tools/auto_generate_questions.py
```

O script agora faz retry automático em falhas transitórias como `503 UNAVAILABLE` e picos de demanda do modelo.

Se quiser ajustar o comportamento:

```bash
export MAX_CONCURRENT_BATCHES=3
export GEMINI_RETRY_ATTEMPTS=5
export GEMINI_RETRY_INITIAL_DELAY_SECONDS=2
export GEMINI_RETRY_MAX_DELAY_SECONDS=30
```

Saída:

```txt
storage/imports/questions.json
storage/imports/questions.rejected.json
```

Importar no Laravel:

```bash
php artisan questions:import-json storage/imports/questions.json
```

Atalho completo:

```bash
python tools/run_web_pipeline.py --full --import-json
```
