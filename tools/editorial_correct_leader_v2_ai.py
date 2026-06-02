from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from docx import Document


BASE_DIR = Path(r"E:\Carte\BB\++++carti scrise de bebe\CELE 63 de calitati ale liderului")
TOOLS_DIR = Path(r"D:\Teste cursor\HTML Editor\Word Editor\tools")
SOURCE_DOCX = BASE_DIR / "pentru tiparire - actualizat cu articole web.docx"
MERGE_SCRIPT = TOOLS_DIR / "merge_leader_articles.py"
OUT_DOCX = BASE_DIR / "Final Corectat V2.docx"
REPORT_TXT = BASE_DIR / "raport-corectura-editoriala-v2-ai.txt"
CACHE_JSONL = TOOLS_DIR / "editorial_v2_ai_cache.jsonl"

API_URL = "https://api.openai.com/v1/responses"
MODELS_BY_PREFERENCE = [
    "gpt-5.4",
    "gpt-5.4-mini",
    "gpt-4.1",
    "gpt-4o",
]


SYSTEM_PROMPT = """
Esti corector editorial profesionist pentru limba romana.

Vei corecta fragmente dintr-o carte despre leadership. Lucreaza cu fidelitate maxima fata de stilul autorului:
- corecteaza gramatica, ortografia, diacriticele, punctuatia, acordurile, topica greoaie, logica frazei si exprimarile nenaturale;
- pastreaza intentia, tonul si vocabularul autorului cat mai mult posibil;
- nu rescrie creativ si nu moderniza textul daca fraza are deja sens;
- adauga cuvinte doar cand lipsesc gramatical sau logic;
- schimba un cuvant numai cand cel original suna gresit, fortat, confuz sau nenatural;
- nu rezuma, nu extinde idei, nu inventa exemple si nu elimina informatie;
- pastreaza nume, titluri, citate, cifre, ISBN, ani, marcaje de articol si sensul exact;
- pastreaza fiecare paragraf separat: primesti N paragrafe si returnezi exact N paragrafe, cu aceleasi id-uri.

Calibrare dupa corectura dorita de autor:
Original: "Asa cum doctorii trebuie sa descalceasca firele incurcate ale bolii, sa stie ce intrebari sa puna pacientilor, sa recunoasca indiciile fizice subtile si sa identifice testele care ar putea duce, in cele din urma, la diagnosticul perfect - tot asa si tu trebuie sa descalcești modul in care poti obtine rezultatele cele mai bune..."
Corect: "Asa cum doctorii trebuie sa descalceasca firele incurcate ale bolii, sa stie ce intrebari sa le puna pacientilor, sa recunoasca indiciile fizice subtile si sa identifice testele care ar putea duce, in cele din urma, la diagnosticul corect - tot asa si tu trebuie sa descalcesti modul in care poti obtine cele mai bune rezultate..."

Original: "Daca vrei sa fii o persoana care sa-i conduca pe altii si sa indrume o organizatie..."
Corect: "Daca vrei sa fii o persoana capabila sa-i conduca pe altii si sa indrume o organizatie..."

Returneaza numai JSON valid, fara markdown:
{"paragraphs":[{"id":123,"text":"text corectat"}]}
""".strip()


@dataclass
class ParagraphItem:
    idx: int
    text: str


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def normalize_old_romanian_diacritics(text: str) -> str:
    return text.translate(str.maketrans({"ş": "ș", "Ş": "Ș", "ţ": "ț", "Ţ": "Ț"}))


def compact_text(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def ensure_source_docx() -> None:
    if SOURCE_DOCX.is_file():
        return
    if not MERGE_SCRIPT.is_file():
        raise FileNotFoundError(f"Lipseste sursa {SOURCE_DOCX} si nu gasesc {MERGE_SCRIPT}")
    subprocess.run([sys.executable, str(MERGE_SCRIPT)], check=True)
    if not SOURCE_DOCX.is_file():
        raise FileNotFoundError(f"Scriptul de merge nu a creat {SOURCE_DOCX}")


def is_editable_paragraph(text: str) -> bool:
    t = compact_text(text)
    if not t:
        return False
    if re.fullmatch(r"-\s*\d+\s*-", t):
        return False
    if re.fullmatch(r"\d+\.?", t):
        return False
    if len(t) <= 2:
        return False
    return True


def collect_items(doc: Document) -> list[ParagraphItem]:
    items: list[ParagraphItem] = []
    for idx, par in enumerate(doc.paragraphs):
        text = normalize_old_romanian_diacritics(par.text)
        if is_editable_paragraph(text):
            items.append(ParagraphItem(idx=idx, text=text))
    return items


def make_batches(items: list[ParagraphItem], max_chars: int = 5200, max_items: int = 18) -> list[list[ParagraphItem]]:
    batches: list[list[ParagraphItem]] = []
    cur: list[ParagraphItem] = []
    chars = 0
    for item in items:
        need = len(item.text) + 80
        if cur and (chars + need > max_chars or len(cur) >= max_items):
            batches.append(cur)
            cur = []
            chars = 0
        cur.append(item)
        chars += need
    if cur:
        batches.append(cur)
    return batches


def read_cache() -> dict[str, dict[int, str]]:
    cache: dict[str, dict[int, str]] = {}
    if not CACHE_JSONL.is_file():
        return cache
    with CACHE_JSONL.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
                cache[rec["batch_hash"]] = {int(k): v for k, v in rec["texts"].items()}
            except Exception:
                continue
    return cache


def append_cache(batch_hash: str, texts: dict[int, str], model: str) -> None:
    rec = {
        "batch_hash": batch_hash,
        "model": model,
        "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "texts": {str(k): v for k, v in texts.items()},
    }
    with CACHE_JSONL.open("a", encoding="utf-8") as f:
        f.write(json.dumps(rec, ensure_ascii=False) + "\n")


def batch_hash(batch: list[ParagraphItem]) -> str:
    payload = [{"id": it.idx, "text": it.text} for it in batch]
    return sha256_text(json.dumps(payload, ensure_ascii=False, sort_keys=True))


def extract_response_text(data: dict[str, Any]) -> str:
    if "output_text" in data:
        return str(data["output_text"])
    parts: list[str] = []
    for item in data.get("output", []):
        for content in item.get("content", []):
            if content.get("type") in {"output_text", "text"} and "text" in content:
                parts.append(str(content["text"]))
    return "\n".join(parts)


def call_openai(batch: list[ParagraphItem], model: str, timeout: int = 180) -> dict[int, str]:
    key = os.environ.get("OPENAI_API_KEY", "").strip()
    if not key:
        raise RuntimeError("OPENAI_API_KEY nu este setat")

    payload = {
        "model": model,
        "input": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": json.dumps(
                    {
                        "task": "Corecteaza editorial aceste paragrafe si returneaza exact JSON-ul cerut.",
                        "paragraphs": [{"id": it.idx, "text": it.text} for it in batch],
                    },
                    ensure_ascii=False,
                ),
            },
        ],
        "text": {"format": {"type": "json_object"}},
        "temperature": 0.1,
    }
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        API_URL,
        data=body,
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        data = json.loads(resp.read().decode("utf-8"))

    text = extract_response_text(data).strip()
    parsed = json.loads(text)
    returned = parsed.get("paragraphs")
    if not isinstance(returned, list):
        raise ValueError("Raspuns fara lista paragraphs")
    out: dict[int, str] = {}
    expected = {it.idx for it in batch}
    for row in returned:
        idx = int(row["id"])
        if idx not in expected:
            raise ValueError(f"ID neasteptat in raspuns: {idx}")
        out[idx] = str(row.get("text", ""))
    if set(out) != expected:
        missing = sorted(expected - set(out))
        raise ValueError(f"Lipsesc paragrafe din raspuns: {missing[:10]}")
    return out


def corrected_text_is_suspicious(old: str, new: str) -> bool:
    old_c = compact_text(old)
    new_c = compact_text(new)
    if not new_c and old_c:
        return True
    if "```" in new_c or new_c.startswith("{") or new_c.startswith("["):
        return True
    if len(old_c) > 80:
        if len(new_c) < len(old_c) * 0.45:
            return True
        if len(new_c) > len(old_c) * 1.75:
            return True
    return False


def choose_model(explicit_model: str | None) -> str:
    if explicit_model:
        return explicit_model
    env_model = os.environ.get("OPENAI_MODEL", "").strip()
    if env_model:
        return env_model
    return MODELS_BY_PREFERENCE[0]


def set_paragraph_text(paragraph, text: str) -> None:
    # Pastreaza stilul paragrafului si proprietatile primului run; evita refacerea structurii DOCX.
    if paragraph.runs:
        paragraph.runs[0].text = text
        for run in paragraph.runs[1:]:
            run.text = ""
    else:
        paragraph.add_run(text)


def write_report(
    source: Path,
    out: Path,
    model: str,
    items: list[ParagraphItem],
    corrected: dict[int, str],
    changed: list[tuple[int, str, str]],
    limit: int | None,
) -> None:
    lines = [
        "Corectura editoriala V2 AI",
        f"Sursa: {source}",
        f"Rezultat: {out}",
        f"Model: {model}",
        f"Paragrafe eligibile: {len(items)}",
        f"Paragrafe corectate in aceasta rulare: {len(corrected)}",
        f"Paragrafe schimbate: {len(changed)}",
    ]
    if limit:
        lines.append(f"LIMITA TEST: {limit} paragrafe")
    lines.extend(["", "Exemple de schimbari:"])
    for idx, old, new in changed[:120]:
        lines.append("")
        lines.append(f"Paragraf DOCX {idx}")
        lines.append(f"INAINTE: {old}")
        lines.append(f"DUPA:    {new}")
    REPORT_TXT.write_text("\n".join(lines), encoding="utf-8")


def run(args: argparse.Namespace) -> None:
    ensure_source_docx()
    doc = Document(SOURCE_DOCX)
    items = collect_items(doc)
    if args.limit:
        items = items[: args.limit]
    batches = make_batches(items, max_chars=args.max_chars, max_items=args.max_items)
    cache = read_cache()
    model = choose_model(args.model)

    corrected: dict[int, str] = {}
    for n, batch in enumerate(batches, start=1):
        bh = batch_hash(batch)
        if not args.no_cache and bh in cache:
            corrected.update(cache[bh])
            print(f"[{n}/{len(batches)}] cache {len(batch)} paragrafe")
            continue

        print(f"[{n}/{len(batches)}] OpenAI {model}: {len(batch)} paragrafe")
        last_error: Exception | None = None
        for attempt in range(1, args.retries + 1):
            try:
                out = call_openai(batch, model=model, timeout=args.timeout)
                for item in batch:
                    new = normalize_old_romanian_diacritics(out[item.idx])
                    if corrected_text_is_suspicious(item.text, new):
                        raise ValueError(f"Corectura suspecta la paragraful {item.idx}")
                    out[item.idx] = new
                corrected.update(out)
                if not args.no_cache:
                    append_cache(bh, out, model)
                break
            except urllib.error.HTTPError as e:
                detail = e.read().decode("utf-8", "replace")
                last_error = RuntimeError(f"HTTP {e.code}: {detail[:500]}")
                if e.code in {401, 403, 404}:
                    raise last_error
            except Exception as e:
                last_error = e
            wait = min(8 * attempt, 45)
            print(f"  incercare {attempt} esuata: {last_error}; astept {wait}s")
            time.sleep(wait)
        else:
            raise RuntimeError(f"Nu pot corecta batch-ul {n}: {last_error}")

    changed: list[tuple[int, str, str]] = []
    for item in items:
        new = corrected.get(item.idx)
        if new is None:
            continue
        old = normalize_old_romanian_diacritics(doc.paragraphs[item.idx].text)
        if compact_text(old) != compact_text(new):
            changed.append((item.idx, old, new))
        if not args.dry_run:
            set_paragraph_text(doc.paragraphs[item.idx], new)

    if not args.dry_run:
        doc.save(OUT_DOCX)
    write_report(SOURCE_DOCX, OUT_DOCX, model, items, corrected, changed, args.limit)
    print(f"source={SOURCE_DOCX}")
    print(f"out={OUT_DOCX}")
    print(f"report={REPORT_TXT}")
    print(f"eligible={len(items)} corrected={len(corrected)} changed={len(changed)}")
    if args.dry_run:
        print("DRY_RUN: documentul DOCX nu a fost scris")


def main() -> None:
    parser = argparse.ArgumentParser(description="Corectura editoriala AI V2 pentru cartea de leadership.")
    parser.add_argument("--model", default=None, help="Model OpenAI; implicit OPENAI_MODEL sau gpt-5.4")
    parser.add_argument("--limit", type=int, default=None, help="Proceseaza doar primele N paragrafe eligibile.")
    parser.add_argument("--max-chars", type=int, default=5200)
    parser.add_argument("--max-items", type=int, default=18)
    parser.add_argument("--timeout", type=int, default=180)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--no-cache", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    run(args)


if __name__ == "__main__":
    main()
