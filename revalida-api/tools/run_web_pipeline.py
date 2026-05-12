#!/usr/bin/env python3
import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

from env_loader import load_dotenv_file

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"
PYTHON_BIN = os.getenv("QUESTIONS_PYTHON_BIN") or sys.executable or "python3"
QUESTIONS_OUTPUT_PATH = ROOT / "storage" / "imports" / "questions.json"

load_dotenv_file(ROOT)


def run_step(command, *, env=None):
    print(f"\n==> {' '.join(command)}", flush=True)
    subprocess.run(command, cwd=ROOT, env=env, check=True)


def run_optional_step(command, *, env=None, failure_message: str):
    print(f"\n==> {' '.join(command)}", flush=True)
    try:
        subprocess.run(command, cwd=ROOT, env=env, check=True)
    except subprocess.CalledProcessError as exc:
        print(f"\nWARN: {failure_message}: {exc}", flush=True)


def load_questions_count() -> int:
    if not QUESTIONS_OUTPUT_PATH.exists():
        return 0

    try:
        items = json.loads(QUESTIONS_OUTPUT_PATH.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return 0

    return len(items) if isinstance(items, list) else 0


def main():
    parser = argparse.ArgumentParser(description="Executa o pipeline web de importação do Revalida")
    parser.add_argument("--crawl", action="store_true", help="Executa apenas a etapa de descoberta de links")
    parser.add_argument("--download", action="store_true", help="Executa apenas a etapa de download")
    parser.add_argument("--parse", action="store_true", help="Executa apenas a etapa de parsing")
    parser.add_argument("--extract-official", action="store_true", help="Extrai questões oficiais verbatim da prova objetiva")
    parser.add_argument("--batch", action="store_true", help="Executa apenas a etapa de montagem de prompts")
    parser.add_argument("--generate", action="store_true", help="Executa a geração automática via Gemini")
    parser.add_argument("--limit-sources", type=int, default=0, help="Limita a quantidade de fontes no crawl")
    parser.add_argument("--generate-limit", type=int, default=0, help="Limita a quantidade de prompts processados na geração")
    parser.add_argument("--import-json", action="store_true", help="Importa storage/imports/questions.json após gerar")
    parser.add_argument("--skip-ai", action="store_true", help="Pula apenas o fallback via IA; se houver prova oficial extraída, ela ainda poderá ser importada")
    parser.add_argument("--full", action="store_true", help="Executa crawl, download, parse, extração oficial e, se necessário, fallback via IA")
    parser.add_argument(
        "--exam",
        default=os.getenv("QUESTIONS_EXAM_SCOPE", "Revalida"),
        help="Filtra as fontes do crawl por exame. Use 'all' para incluir tudo.",
    )
    parser.add_argument(
        "--phase",
        default=os.getenv("QUESTIONS_PHASE_SCOPE", "first"),
        help="Filtra a fase do Revalida no crawl. Use 'all' para incluir tudo.",
    )
    args = parser.parse_args()

    any_step = (
        args.full
        or args.crawl
        or args.download
        or args.parse
        or args.extract_official
        or args.batch
        or args.generate
        or args.import_json
    )
    run_all = args.full or not any_step

    if run_all or args.crawl:
        crawl_command = [PYTHON_BIN, "-u", str(TOOLS / "crawl_pdf_links.py")]
        if args.exam:
            crawl_command.extend(["--exam", args.exam])
        if args.phase:
            crawl_command.extend(["--phase", args.phase])
        if args.limit_sources and args.limit_sources > 0:
            crawl_command.extend(["--limit-sources", str(args.limit_sources)])
        run_step(crawl_command, env=os.environ.copy())
    if run_all or args.download:
        run_step([PYTHON_BIN, "-u", str(TOOLS / "download_pdfs.py")], env=os.environ.copy())
    if run_all or args.parse:
        run_step([PYTHON_BIN, "-u", str(TOOLS / "parse_pdfs.py")], env=os.environ.copy())

    official_questions_count = 0
    if run_all or args.extract_official:
        run_step([PYTHON_BIN, "-u", str(TOOLS / "extract_official_questions.py")], env=os.environ.copy())
        official_questions_count = load_questions_count()

    if run_all:
        if official_questions_count > 0:
            if not args.skip_ai:
                enrich_command = [PYTHON_BIN, "-u", str(TOOLS / "enrich_official_comments.py")]
                if args.generate_limit and args.generate_limit > 0:
                    enrich_command.extend(["--limit", str(args.generate_limit)])
                run_optional_step(
                    enrich_command,
                    env=os.environ.copy(),
                    failure_message="enriquecimento opcional de comentários via IA falhou; seguindo com o material oficial bruto",
                )
            print(
                f"\nOK: {official_questions_count} questão(ões) oficiais extraída(s). "
                "Fallback via IA ignorado nesta rodada.",
                flush=True,
            )
        else:
            batch_env = os.environ.copy()
            if not args.skip_ai:
                batch_env["QUESTIONS_EXPECTS_AUTO_GENERATION"] = "1"
            run_step([PYTHON_BIN, "-u", str(TOOLS / "make_ai_batches.py")], env=batch_env)

            if not args.skip_ai:
                generate_command = [PYTHON_BIN, "-u", str(TOOLS / "auto_generate_questions.py")]
                if args.generate_limit and args.generate_limit > 0:
                    generate_command.extend(["--limit", str(args.generate_limit)])
                run_step(generate_command, env=os.environ.copy())
    else:
        if args.batch:
            batch_env = os.environ.copy()
            run_step([PYTHON_BIN, "-u", str(TOOLS / "make_ai_batches.py")], env=batch_env)

        if args.generate:
            generate_command = [PYTHON_BIN, "-u", str(TOOLS / "auto_generate_questions.py")]
            if args.generate_limit and args.generate_limit > 0:
                generate_command.extend(["--limit", str(args.generate_limit)])
            run_step(generate_command, env=os.environ.copy())

    if run_all:
        if official_questions_count > 0 or not args.skip_ai:
            run_step(["php", "artisan", "questions:import-json", "--category=revalida"], env=os.environ.copy())
        else:
            print(
                "\nImportação pulada: nenhuma questão oficial foi extraída e o fallback via IA está desativado.",
                flush=True,
            )
    elif args.import_json:
        run_step(["php", "artisan", "questions:import-json", "--category=revalida"], env=os.environ.copy())


if __name__ == "__main__":
    main()
