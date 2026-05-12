# Pipeline de importação Revalida

## Instalar dependências

```bash
cd revalida-api
python3 -m venv .venv
source .venv/bin/activate
pip install -r tools/requirements.txt
```

## Rodar fluxo

Fluxo CLI para lotes autorais estilo Revalida:

```bash
php artisan revalida:generate --amount=200 --area=clinica
php artisan revalida:review --run=<run_id>
php artisan revalida:import --run=<run_id>
```

Se quiser gerar com Gemini no mesmo passo:

```bash
php artisan revalida:generate --amount=200 --area=clinica --with-ai
```

Fluxo completo:

```bash
python tools/run_web_pipeline.py --full
```

Observação:

- por padrão, o crawl agora considera apenas fontes com `exam=Revalida`
- por padrão, o pipeline mantém apenas materiais da `1ª fase` do Revalida, isto é, `objetiva` e `discursiva`
- materiais da `2ª fase`, como `habilidades clínicas`, ficam de fora do fluxo padrão
- para incluir todas as fontes configuradas, use `python tools/run_web_pipeline.py --full --exam all`
- para incluir também a 2ª fase do Revalida, use `python tools/run_web_pipeline.py --full --phase all`
- em jobs automatizados, você também pode sobrescrever com `QUESTIONS_EXAM_SCOPE=all`
- a fase também pode ser sobrescrita com `QUESTIONS_PHASE_SCOPE=all`

Fluxo automático por Artisan:

```bash
php artisan questions:sync-web
```

Script pronto para cron:

```bash
bash tools/run_questions_pipeline.sh
```

Daemon contínuo em background:

```bash
bash tools/run_questions_pipeline_daemon.sh
```

Para acelerar a primeira execução:

```bash
QUESTIONS_LIMIT_SOURCES=1 QUESTIONS_GENERATE_LIMIT=1 bash tools/run_questions_pipeline.sh
```

Gerar questões automaticamente com IA:

```bash
python tools/run_web_pipeline.py --generate
```

Para gerar com Gemini, configure `GEMINI_API_KEY` e opcionalmente `GEMINI_MODEL`.

Importar no banco:

```bash
php artisan questions:import-json storage/imports/questions.json
```

Pipeline manual, passo a passo:

```bash
python tools/crawl_pdf_links.py
python tools/download_pdfs.py
python tools/parse_pdfs.py
python tools/make_ai_batches.py
python tools/auto_generate_questions.py
```

Depois:

1. revise os prompts em `storage/imports/json/ai_batches/`
2. gere o JSON com IA
3. valide o lote com `python tools/validate_questions.py storage/imports/questions.json`
4. revise manualmente os arquivos `flagged` e `rejected`
5. importe o arquivo `approved`
6. rode `php artisan questions:import-json storage/imports/validation/questions.approved.json`

Fluxo alternativo usando o banco como fonte de verdade:

```bash
php artisan questions:export-for-rewrite --status=draft --out=storage/imports/questions.for-rewrite.json
```

Piloto conservador, excluindo temas sensíveis e priorizando `official_based`:

```bash
php artisan questions:export-for-rewrite \
  --status=draft \
  --only-origin=official_based \
  --exclude-temas="vacina,gestante,antibiotico,rastreamento,urgencia,emergencia" \
  --exclude-areas="GO" \
  --limit=200 \
  --out=storage/imports/questions.for-rewrite.sample200.json
```

Para gerar prompts em lote para a IA reescrever as alternativas:

```bash
python3 tools/make_rewrite_batches.py storage/imports/questions.for-rewrite.json --mode alternatives
```

Depois de receber o JSON reescrito, aplique no banco:

```bash
php artisan questions:apply-rewrites-json storage/imports/questions.for-rewrite.json --set-status=reviewed
```

Observação:

- o importador deduplica por `source_hash`
- o JSON pode incluir `question_source` ou `source`
- o campo `reference` é preservado e usado como metadado de origem
- o crawler aceita páginas HTML e PDFs diretos
- o agendamento diário roda às 02:00 se o servidor executar `php artisan schedule:run` a cada minuto
- o daemon contínuo roda em loop com `QUESTIONS_SYNC_INTERVAL_SECONDS=86400` por padrão
- você pode limitar o crawl e a geração com `QUESTIONS_LIMIT_SOURCES` e `QUESTIONS_GENERATE_LIMIT`
- o validador separa o lote em `approved`, `flagged`, `rejected` e `validation_report`
