#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import argparse
import json
import os
from pathlib import Path
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup
from slugify import slugify

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCES_PATH = ROOT / "tools" / "sources.json"
OUT_PATH = ROOT / "storage" / "imports" / "pdf_links.json"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RevalidaStudyCrawler/0.2; educational-public-docs)"
}

ALLOW_WORDS = [
    "prova",
    "provas",
    "gabarito",
    "gabaritos",
    "questao",
    "questão",
    "questoes",
    "questões",
    "caderno",
    "caderno de questões",
    "caderno de questoes",
    "padrão de resposta",
    "padrao de resposta",
    "resposta esperada",
    "respostas esperadas",
]

BLOCK_WORDS = [
    "resultado",
    "resultados",
    "aprovado",
    "aprovados",
    "classificado",
    "classificados",
    "convocação",
    "convocacao",
    "inscrição",
    "inscricao",
    "edital",
    "editais",
    "recurso",
    "recursos",
    "homologação",
    "homologacao",
    "matrícula",
    "matricula",
    "cronograma",
    "comunicado",
    "retificação",
    "retificacao",
    "ensalamento",
    "locais de prova",
    "lista",
    "relação",
    "relacao",
]


def is_pdf_url(url: str) -> bool:
    path = urlparse(url).path.lower()
    return path.endswith(".pdf") or ".pdf?" in url.lower()


def is_allowed_pdf(text: str, href: str) -> bool:
    label = f"{text} {href}".lower()

    has_allowed_word = any(word in label for word in ALLOW_WORDS)
    has_blocked_word = any(word in label for word in BLOCK_WORDS)

    return has_allowed_word and not has_blocked_word


def make_filename(text: str, href: str) -> str:
    filename = Path(urlparse(href).path).name

    if not filename or not filename.lower().endswith(".pdf"):
        filename = f"{slugify(text or 'documento')[:90]}.pdf"

    return filename


def matches_revalida_phase(source: dict, text: str, href: str, filename: str, phase: str) -> bool:
    phase = (phase or "first").strip().lower()
    if phase == "all":
        return True

    exam = str(source.get("exam", "")).strip().lower()
    if "revalida" not in exam:
        return True

    label = f"{text} {href} {filename}".lower()
    is_first_phase = "objetiva" in label or "discursiva" in label
    is_second_phase = (
        "habilidades_clinicas" in label
        or "habilidades clinicas" in label
        or "padrao esperado de procedimentos" in label
        or "padrão esperado de procedimentos" in label
        or ("pep" in label and "discursiva" not in label)
    )

    if phase == "first":
        return is_first_phase

    if phase == "second":
        return is_second_phase

    return True


def append_pdf(found, source, page_url, pdf_url, link_text, filename):
    found.append({
        "source_name": source.get("name"),
        "institution": source.get("institution"),
        "exam": source.get("exam"),
        "year": source.get("year"),
        "page_url": page_url,
        "pdf_url": pdf_url,
        "link_text": link_text,
        "filename": filename,
        "source_type": "direct_pdf" if is_pdf_url(page_url) else "html_page",
    })


def main():
    parser = argparse.ArgumentParser(description="Descobre links de PDFs públicos nas fontes configuradas")
    parser.add_argument(
        "--sources",
        default=os.getenv("QUESTIONS_SOURCES_FILE", str(DEFAULT_SOURCES_PATH)),
        help="Arquivo JSON com as fontes de crawl. Padrão: tools/sources.json",
    )
    parser.add_argument("--limit-sources", type=int, default=0, help="Limita a quantidade de fontes processadas")
    parser.add_argument(
        "--exam",
        default="Revalida",
        help="Filtra as fontes por exame. Use 'all' para não filtrar.",
    )
    parser.add_argument(
        "--phase",
        default="first",
        help="Filtra a fase do Revalida: first, second ou all.",
    )
    args = parser.parse_args()

    sources_path = Path(args.sources)
    sources = json.loads(sources_path.read_text(encoding="utf-8"))
    exam_filter = (args.exam or "").strip().lower()
    if exam_filter and exam_filter != "all":
        sources = [
            source for source in sources
            if exam_filter in str(source.get("exam", "")).strip().lower()
        ]
    if args.limit_sources and args.limit_sources > 0:
        sources = sources[: args.limit_sources]

    found = []
    total_sources = len(sources)

    for index, source in enumerate(sources, start=1):
        url = source["url"]
        print(f"[crawl {index}/{total_sources}] {source['name']} -> {url}")

        if is_pdf_url(url):
            filename = make_filename(source.get("name", "documento"), url)
            if not matches_revalida_phase(source, source.get("name", ""), url, filename, args.phase):
                print("  PDF direto ignorado pelo filtro de fase")
                continue
            append_pdf(found, source, url, url, source.get("name", ""), filename)
            print("  PDF direto registrado")
            continue

        try:
            res = requests.get(url, headers=HEADERS, timeout=30)
            res.raise_for_status()
        except Exception as e:
            print(f"  erro ao acessar página: {e}")
            continue

        soup = BeautifulSoup(res.text, "html.parser")
        page_count = 0

        for a in soup.find_all("a", href=True):
            href = urljoin(url, a["href"])
            text = " ".join(a.get_text(" ", strip=True).split())

            if not is_pdf_url(href):
                continue

            if not is_allowed_pdf(text, href):
                continue

            filename = make_filename(text, href)
            if not matches_revalida_phase(source, text, href, filename, args.phase):
                continue
            append_pdf(found, source, url, href, text, filename)

            page_count += 1

        print(f"  PDFs úteis encontrados: {page_count}")

    unique = {}
    for item in found:
        unique[item["pdf_url"]] = item

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(
        json.dumps(list(unique.values()), ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    print()
    print(f"OK: {len(unique)} PDF(s) úteis encontrados")
    print(f"Salvo em: {OUT_PATH}")


if __name__ == "__main__":
    main()
