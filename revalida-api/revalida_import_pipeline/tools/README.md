# Pipeline de importação Revalida

## Instalar dependências

```bash
cd revalida-api
python3 -m venv .venv
source .venv/bin/activate
pip install -r tools/requirements.txt
```

## Rodar fluxo

```bash
python tools/crawl_pdf_links.py
python tools/download_pdfs.py
python tools/parse_pdfs.py
python tools/make_ai_batches.py
```

Depois:

1. abra os prompts em `storage/imports/json/ai_batches/`
2. gere JSON com IA
3. revise manualmente
4. salve tudo em `storage/imports/questions.json`
5. rode no Laravel: `php artisan questions:import-json`
