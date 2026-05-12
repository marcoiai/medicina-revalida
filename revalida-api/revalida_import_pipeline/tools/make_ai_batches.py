import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEXT_MANIFEST = ROOT / "storage" / "imports" / "text_manifest.json"
BATCH_DIR = ROOT / "storage" / "imports" / "json" / "ai_batches"

PROMPT_TEMPLATE = """
Você é um médico professor criando questões ORIGINAIS para Revalida/Residência.
Use o texto-base abaixo apenas como referência de tema, linguagem e estilo de prova. NÃO copie enunciados.
Gere questões inéditas, tecnicamente corretas e com gabarito único defensável.

OBJETIVO DE QUALIDADE:
- Cada questão deve ter apenas 1 alternativa correta, sem ambiguidade.
- As 4 alternativas erradas devem ser claramente erradas ou menos adequadas.
- Não misture conceitos de fontes conflitantes.
- Se houver chance razoável de 2 alternativas parecerem corretas, descarte a questão e gere outra.

DEFINIÇÃO DE DIFICULDADE:
- Fácil: exige reconhecimento direto de conceito clássico.
- Média: exige interpretação clínica ou aplicação objetiva de regra, em 1 ou 2 passos.
- Difícil: exige integração de múltiplos dados, comparação fina ou decisão menos imediata.
- Não rotule como "Média" uma questão baseada em exceção obscura, detalhe controverso ou pegadinha semântica.

REGRAS DE REDAÇÃO:
- O enunciado deve ser claro, suficiente e tecnicamente preciso.
- As 5 alternativas devem ser frases completas e relativamente equilibradas em tamanho.
- Evite alternativas curtas demais, telegráficas ou obviamente descartáveis.
- Prefira alternativas mais desenvolvidas, com contexto clínico ou conduta explícita.
- Sempre que possível, cada alternativa deve trazer o núcleo decisório completo: diagnóstico, conduta, critério, indicação, contraindicação, tempo, via, dose ou justificativa relevante.
- As alternativas devem ser homogêneas entre si: todas devem representar diagnóstico, ou todas conduta, ou todas classificação, e não uma mistura desses formatos.
- Evite opções compostas apenas por rótulos secos quando for possível escrever uma alternativa mais informativa.
- Como regra prática, prefira alternativas com redação em 1 frase curta a 2 frases curtas, e não apenas 1 a 3 palavras.
- Só use alternativas curtas do tipo "Classe III", "2º grau superficial" ou nome isolado de doença quando a própria natureza da questão exigir nomenclatura/classificação padronizada.
- A alternativa correta não pode ser muito mais longa ou específica que as demais.
- Evite duas alternativas quase idênticas.
- Evite pistas formais de prova mal feita.

EVITE:
- "todas as anteriores"
- "nenhuma das anteriores"
- combinações do tipo "I e III"
- negações desnecessárias ("EXCETO", "NÃO") quando puder formular positivamente
- termos absolutos vagos como "sempre" e "nunca", salvo quando forem tecnicamente indispensáveis

SE USAR TEMA DE VACINAS, CONTRAINDICAÇÕES, PRECAUÇÕES OU SITUAÇÕES ESPECIAIS:
- Diferencie claramente contraindicação absoluta, precaução, adiamento temporário e situação que exige avaliação individualizada.
- Não transforme precaução em contraindicação.
- Não transforme situação especial em proibição.
- Não misture DTP, DTPa e dTpa sem explicitar.

COMENTÁRIO OBRIGATÓRIO:
- Explique por que a correta está correta.
- Explique brevemente por que cada uma das outras 4 está errada ou é menos adequada.
- O comentário deve permitir auditoria do gabarito, não apenas repetir o enunciado.

ANTES DE FINALIZAR CADA QUESTÃO, FAÇA ESTA CHECAGEM INTERNA:
1. Há apenas 1 alternativa correta?
2. Alguma errada ficou parcialmente verdadeira ou defensável?
3. O nível de dificuldade está coerente?
4. As alternativas estão equilibradas em tamanho e especificidade?
5. O comentário refuta explicitamente as outras 4?
Se qualquer resposta for "não", regenere a questão antes de responder.

Retorne APENAS JSON válido, no formato:

[
  {{
    "area": "Clínica Médica | Pediatria | GO | Cirurgia | Preventiva/SUS",
    "tema": "...",
    "dificuldade": "Fácil | Média | Difícil",
    "enunciado": "...",
    "alternativas": {{
      "A": "...",
      "B": "...",
      "C": "...",
      "D": "...",
      "E": "..."
    }},
    "gabarito": "A|B|C|D|E",
    "comentario": "Explique por que a alternativa correta é correta e comente as armadilhas.",
    "origin": "official_based",
    "status": "draft",
    "reference": "{reference}"
  }}
]

TEXTO-BASE:
{text}
"""

def main():
    manifest = json.loads(TEXT_MANIFEST.read_text(encoding="utf-8"))
    BATCH_DIR.mkdir(parents=True, exist_ok=True)

    for item in manifest:
        text_path = ROOT / item["text_path"]
        text = text_path.read_text(encoding="utf-8", errors="ignore")

        max_chars = 12000
        chunks = [text[i:i+max_chars] for i in range(0, min(len(text), max_chars * 8), max_chars)]

        reference = f"{item.get('institution','')} - {item.get('exam','')} - {item.get('page_url','')}"
        for idx, chunk in enumerate(chunks, 1):
            prompt = PROMPT_TEMPLATE.format(reference=reference, text=chunk)
            out = BATCH_DIR / f"{text_path.stem}_batch_{idx:02d}.prompt.txt"
            out.write_text(prompt, encoding="utf-8")
            print(f"[prompt] {out}")

    print("\nOK: prompts criados em storage/imports/json/ai_batches/")
    print("Cole cada prompt na IA, revise o JSON retornado e junte em storage/imports/questions.json")

if __name__ == "__main__":
    main()
