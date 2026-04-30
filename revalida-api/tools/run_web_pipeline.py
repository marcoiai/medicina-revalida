#!/usr/bin/env python3
import argparse
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"


def run_step(command, *, env=None):
    print(f"\n==> {' '.join(command)}")
    subprocess.run(command, cwd=ROOT, env=env, check=True)


def main():
    parser = argparse.ArgumentParser(description="Executa o pipeline web de importação do Revalida")
    parser.add_argument("--crawl", action="store_true", help="Executa apenas a etapa de descoberta de links")
    parser.add_argument("--download", action="store_true", help="Executa apenas a etapa de download")
    parser.add_argument("--parse", action="store_true", help="Executa apenas a etapa de parsing")
    parser.add_argument("--batch", action="store_true", help="Executa apenas a etapa de montagem de prompts")
    parser.add_argument("--generate", action="store_true", help="Executa a geração automática via Gemini")
    parser.add_argument("--limit-sources", type=int, default=0, help="Limita a quantidade de fontes no crawl")
    parser.add_argument("--generate-limit", type=int, default=0, help="Limita a quantidade de prompts processados na geração")
    parser.add_argument("--import-json", action="store_true", help="Importa storage/imports/questions.json após gerar")
    parser.add_argument("--skip-ai", action="store_true", help="Pula a geração automática e só cria prompts")
    parser.add_argument("--full", action="store_true", help="Executa crawl, download, parse, batch e geração automática")
    args = parser.parse_args()

    any_step = args.full or args.crawl or args.download or args.parse or args.batch or args.generate or args.import_json
    run_all = args.full or not any_step

    steps = []
    if run_all or args.crawl:
        crawl_command = ["python3", str(TOOLS / "crawl_pdf_links.py")]
        if args.limit_sources and args.limit_sources > 0:
            crawl_command.extend(["--limit-sources", str(args.limit_sources)])
        steps.append(crawl_command)
    if run_all or args.download:
        steps.append(["python3", str(TOOLS / "download_pdfs.py")])
    if run_all or args.parse:
        steps.append(["python3", str(TOOLS / "parse_pdfs.py")])
    if run_all or args.batch:
        steps.append(["python3", str(TOOLS / "make_ai_batches.py")])

    if run_all and not args.skip_ai:
        generate_command = ["python3", str(TOOLS / "auto_generate_questions.py")]
        if args.generate_limit and args.generate_limit > 0:
            generate_command.extend(["--limit", str(args.generate_limit)])
        steps.append(generate_command)
    elif args.generate:
        generate_command = ["python3", str(TOOLS / "auto_generate_questions.py")]
        if args.generate_limit and args.generate_limit > 0:
            generate_command.extend(["--limit", str(args.generate_limit)])
        steps.append(generate_command)

    for command in steps:
        run_step(command, env=os.environ.copy())

    if args.import_json or args.full:
        run_step(["php", "artisan", "questions:import-json"], env=os.environ.copy())


if __name__ == "__main__":
    main()
