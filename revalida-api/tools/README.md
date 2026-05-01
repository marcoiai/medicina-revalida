# Pipeline de importação Revalida

## Instalar dependências

```bash
cd revalida-api
python3 -m venv .venv
source .venv/bin/activate
pip install -r tools/requirements.txt
```

## Rodar fluxo

Fluxo completo:

```bash
python tools/run_web_pipeline.py --full
```

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

Observação:

- o importador deduplica por `source_hash`
- o JSON pode incluir `question_source` ou `source`
- o campo `reference` é preservado e usado como metadado de origem
- o crawler aceita páginas HTML e PDFs diretos
- o agendamento diário roda às 02:00 se o servidor executar `php artisan schedule:run` a cada minuto
- o daemon contínuo roda em loop com `QUESTIONS_SYNC_INTERVAL_SECONDS=86400` por padrão
- você pode limitar o crawl e a geração com `QUESTIONS_LIMIT_SOURCES` e `QUESTIONS_GENERATE_LIMIT`
- o validador separa o lote em `approved`, `flagged`, `rejected` e `validation_report`
