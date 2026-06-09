from __future__ import annotations

import argparse
import hashlib
import html as html_lib
import json
import os
import re
import shutil
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from openai_key import get_openai_api_key


TOOLS_DIR = Path(__file__).resolve().parent
SUGGESTED_ROOT = Path(r"E:\Carte\BB\17 - Site Leadership\Principal\en")
API_URL = "https://api.openai.com/v1/responses"
CACHE_JSONL = TOOLS_DIR / "corecteaza_website_en_cache.jsonl"
REPORT_TXT = TOOLS_DIR / "raport-corecteaza-website-en.txt"
PROMPT_VERSION = "website-en-v1.3-visible-context"

START_MARKER = "<!-- SASA-1 -->"
END_MARKER = "<!-- SASA-2 -->"

MODELS_BY_PREFERENCE = [
    "gpt-5.4",
    "gpt-5.5",
    "gpt-5.4-mini",
    "gpt-4.1",
    "gpt-4o",
]

SYSTEM_PROMPT = """
You are a professional English editor for a leadership website translated from Romanian.

### CORE DIRECTIVES
- Correct the logic, style, grammar, spelling, structure, and punctuation of the English text.
- Preserve the author's original style, tone, and intention as much as possible.
- Add phrasing or words ONLY if they are grammatically wrong or strictly necessary for coherence.

### INPUT HANDLING
- You receive HTML fragments where tags, comments, entities, and attributes are replaced by tokens like __HTML_KEEP_000001__.
- You also receive visible_text (plain readable text) to use as context for cross-boundary grammar correction.
- Preserve every token exactly, in order, with the same spelling. Tokens are NOT text.
- Correct visible text between tokens (including words in span/a/b/i/strong) and inside links, but NEVER touch the hidden href/URL.
- If a tag-wrapped word is a leftover (e.g., Romanian "acas"), remove its visible text entirely instead of replacing it with filler (e.g., "However, __HTML_KEEP_000001____HTML_KEEP_000002__ one day").
- Fix Google Translate artifacts and malformed English words based on context.

### OUTPUT CONSTRAINTS
- Provide detailed explanations of what is wrong and why.
- EXCEPTION: If the text exceeds one page, output the entire corrected text WITHOUT separate explanations.
- Never add markdown or text outside the JSON structure.
- Return only valid JSON with this exact shape:
{"items":[{"id":1,"html":"corrected protected html","explanations":"detailed corrections or empty if >1 page"}]}
""".strip()

PROTECT_RE = re.compile(
    r"(?is)<!--.*?-->|<[^>]+>|&(?:[A-Za-z][A-Za-z0-9]+|#[0-9]+|#x[0-9A-Fa-f]+);"
)
TOKEN_RE = re.compile(r"__HTML_KEEP_\d{6}__")
BLOCK_RE = re.compile(r"(?is)<(p|h[1-6]|li|blockquote)\b[^>]*>.*?</\1>")
TAG_RE = re.compile(r"(?is)<!--.*?-->|<[^>]+>")
EMPTY_SPAN_RE = re.compile(r"(?is)<span\b[^>]*>\s*</span>")


@dataclass
class HtmlUnit:
    id: int
    file_path: Path
    start: int
    end: int
    original_html: str
    protected_html: str
    token_order: list[str]
    token_map: dict[str, str]
    visible_text: str
    cache_key: str


@dataclass
class ApiResult:
    html_by_id: dict[int, str]
    usage: dict[str, int]


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def compact_text(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def visible_text(fragment: str) -> str:
    text = TAG_RE.sub(" ", fragment)
    text = html_lib.unescape(text)
    return compact_text(text)


def choose_model(explicit_model: str | None) -> str:
    if explicit_model:
        return explicit_model
    env_model = os.environ.get("OPENAI_MODEL", "").strip()
    if env_model:
        return env_model
    return MODELS_BY_PREFERENCE[0]


def detect_encoding(raw: bytes) -> str:
    if raw.startswith(b"\xef\xbb\xbf"):
        return "utf-8-sig"
    for enc in ("utf-8", "cp1252", "latin-1"):
        try:
            raw.decode(enc)
            return enc
        except UnicodeDecodeError:
            continue
    return "utf-8"


def read_html(path: Path) -> tuple[str, str]:
    raw = path.read_bytes()
    enc = detect_encoding(raw)
    return raw.decode(enc), enc


def write_html(path: Path, text: str, encoding: str) -> None:
    path.write_text(text, encoding=encoding, newline="")


def find_marker_pairs(text: str) -> list[tuple[int, int]]:
    pairs: list[tuple[int, int]] = []
    pos = 0
    while True:
        start = text.find(START_MARKER, pos)
        if start < 0:
            break
        region_start = start + len(START_MARKER)
        end = text.find(END_MARKER, region_start)
        if end < 0:
            break
        pairs.append((region_start, end))
        pos = end + len(END_MARKER)
    return pairs


def protect_html(fragment: str) -> tuple[str, list[str], dict[str, str]]:
    token_order: list[str] = []
    token_map: dict[str, str] = {}

    def repl(match: re.Match[str]) -> str:
        token = f"__HTML_KEEP_{len(token_order):06d}__"
        token_order.append(token)
        token_map[token] = match.group(0)
        return token

    return PROTECT_RE.sub(repl, fragment), token_order, token_map


def restore_html(protected: str, token_order: list[str], token_map: dict[str, str]) -> str:
    found = TOKEN_RE.findall(protected)
    if found != token_order:
        raise ValueError(
            "Modelul a modificat token-urile HTML. "
            f"Asteptat={token_order[:8]} gasit={found[:8]}"
        )
    out = protected
    for token in token_order:
        out = out.replace(token, token_map[token])
    return out


def cleanup_restored_html(fragment: str) -> str:
    fragment = EMPTY_SPAN_RE.sub("", fragment)
    fragment = re.sub(r" {2,}", " ", fragment)
    return fragment


def unit_cache_key(protected_html: str, model: str, temperature: float | None) -> str:
    payload = {
        "prompt_version": PROMPT_VERSION,
        "model": model,
        "temperature": temperature,
        "html": protected_html,
    }
    return sha256_text(json.dumps(payload, ensure_ascii=False, sort_keys=True))


def read_cache() -> dict[str, str]:
    cache: dict[str, str] = {}
    if not CACHE_JSONL.is_file():
        return cache
    with CACHE_JSONL.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
                cache[str(rec["cache_key"])] = str(rec["html"])
            except Exception:
                continue
    return cache


def append_cache(cache_key: str, corrected_html: str, model: str) -> None:
    rec = {
        "cache_key": cache_key,
        "model": model,
        "prompt_version": PROMPT_VERSION,
        "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "html": corrected_html,
    }
    with CACHE_JSONL.open("a", encoding="utf-8") as f:
        f.write(json.dumps(rec, ensure_ascii=False) + "\n")


def extract_response_text(data: dict[str, Any]) -> str:
    if "output_text" in data:
        return str(data["output_text"])
    parts: list[str] = []
    for item in data.get("output", []):
        for content in item.get("content", []):
            if content.get("type") in {"output_text", "text"} and "text" in content:
                parts.append(str(content["text"]))
    return "\n".join(parts)


def extract_usage(data: dict[str, Any]) -> dict[str, int]:
    usage = data.get("usage") or {}
    input_tokens = int(usage.get("input_tokens") or usage.get("prompt_tokens") or 0)
    output_tokens = int(usage.get("output_tokens") or usage.get("completion_tokens") or 0)
    total_tokens = int(usage.get("total_tokens") or (input_tokens + output_tokens))
    return {
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "total_tokens": total_tokens,
    }


def call_openai(units: list[HtmlUnit], model: str, temperature: float | None, timeout: int) -> ApiResult:
    key = get_openai_api_key()
    payload: dict[str, Any] = {
        "model": model,
        "input": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": json.dumps(
                    {
                        "task": "Correct these protected HTML fragments. Preserve all __HTML_KEEP_000000__ tokens exactly and return JSON only.",
                        "items": [
                            {"id": unit.id, "visible_text": unit.visible_text, "html": unit.protected_html}
                            for unit in units
                        ],
                    },
                    ensure_ascii=False,
                ),
            },
        ],
        "text": {"format": {"type": "json_object"}},
    }
    if temperature is not None:
        payload["temperature"] = temperature

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
    returned = parsed.get("items")
    if not isinstance(returned, list):
        raise ValueError("Raspuns fara lista items")

    expected = {unit.id for unit in units}
    html_by_id: dict[int, str] = {}
    for row in returned:
        item_id = int(row["id"])
        if item_id not in expected:
            raise ValueError(f"ID neasteptat in raspuns: {item_id}")
        html_by_id[item_id] = str(row.get("html", ""))
    if set(html_by_id) != expected:
        missing = sorted(expected - set(html_by_id))
        raise ValueError(f"Lipsesc item-uri din raspuns: {missing[:10]}")
    return ApiResult(html_by_id=html_by_id, usage=extract_usage(data))


def corrected_html_is_suspicious(old_html: str, new_html: str) -> bool:
    old_visible = visible_text(old_html)
    new_visible = visible_text(new_html)
    if old_visible and not new_visible:
        return True
    if "```" in new_html:
        return True
    if len(old_visible) > 80:
        if len(new_visible) < len(old_visible) * 0.45:
            return True
        if len(new_visible) > len(old_visible) * 1.90:
            return True
    return False


def collect_units_for_file(path: Path, text: str, model: str, temperature: float | None, next_id: int) -> list[HtmlUnit]:
    units: list[HtmlUnit] = []
    for region_start, region_end in find_marker_pairs(text):
        region = text[region_start:region_end]
        for match in BLOCK_RE.finditer(region):
            original = match.group(0)
            text_visible = visible_text(original)
            if not text_visible:
                continue
            protected, token_order, token_map = protect_html(original)
            units.append(
                HtmlUnit(
                    id=next_id + len(units),
                    file_path=path,
                    start=region_start + match.start(),
                    end=region_start + match.end(),
                    original_html=original,
                    protected_html=protected,
                    token_order=token_order,
                    token_map=token_map,
                    visible_text=text_visible,
                    cache_key=unit_cache_key(protected, model, temperature),
                )
            )
    return units


def make_batches(units: list[HtmlUnit], max_chars: int, max_items: int) -> list[list[HtmlUnit]]:
    batches: list[list[HtmlUnit]] = []
    current: list[HtmlUnit] = []
    chars = 0
    for unit in units:
        need = len(unit.protected_html) + 120
        if current and (chars + need > max_chars or len(current) >= max_items):
            batches.append(current)
            current = []
            chars = 0
        current.append(unit)
        chars += need
    if current:
        batches.append(current)
    return batches


def make_backup(path: Path) -> Path:
    backup_dir = path.parent / "_backup_corecteaza_website_en"
    backup_dir.mkdir(exist_ok=True)
    stamp = time.strftime("%Y%m%d_%H%M%S")
    backup = backup_dir / f"{path.name}.{stamp}.bak"
    suffix = 1
    while backup.exists():
        backup = backup_dir / f"{path.name}.{stamp}.{suffix}.bak"
        suffix += 1
    shutil.copy2(path, backup)
    return backup


def ensure_backup(path: Path, backups: dict[Path, Path]) -> Path:
    resolved = path.resolve()
    if resolved not in backups:
        backups[resolved] = make_backup(path)
    return backups[resolved]


def apply_replacements(text: str, replacements: list[tuple[int, int, str]]) -> str:
    out = text
    for start, end, new_html in sorted(replacements, key=lambda item: item[0], reverse=True):
        out = out[:start] + new_html + out[end:]
    return out


def list_target_files(args: argparse.Namespace) -> list[Path]:
    if args.file:
        return [Path(args.file)]
    if args.all:
        if not args.root:
            raise ValueError("Pentru toate fisierele, pune folderul cu --root.")
        root = Path(args.root)
        return sorted(root.glob("*.html"))
    raise ValueError("Alege un fisier cu --file sau un folder cu --all --root.")


def build_gui_args() -> argparse.Namespace | None:
    try:
        import tkinter as tk
        from tkinter import filedialog, messagebox
    except Exception as exc:
        print(f"Nu pot porni fereastra grafica: {exc}")
        return None

    result: list[argparse.Namespace] = []
    root = tk.Tk()
    root.title("Corecteaza website EN")
    root.resizable(False, False)

    mode_var = tk.StringVar(value="file")
    path_var = tk.StringVar(value="")

    frm = tk.Frame(root, padx=18, pady=16)
    frm.grid(row=0, column=0, sticky="nsew")

    title = tk.Label(frm, text="Corectura engleza cu API", font=("Segoe UI", 12, "bold"))
    title.grid(row=0, column=0, columnspan=3, sticky="w", pady=(0, 12))

    rb_file = tk.Radiobutton(
        frm,
        text="Vrei sa corectezi o anumita pagina?",
        variable=mode_var,
        value="file",
        command=lambda: path_var.set(""),
    )
    rb_file.grid(row=1, column=0, columnspan=3, sticky="w")

    rb_all = tk.Radiobutton(
        frm,
        text="Vrei sa corectezi toate fisierele?",
        variable=mode_var,
        value="folder",
        command=lambda: path_var.set(""),
    )
    rb_all.grid(row=2, column=0, columnspan=3, sticky="w", pady=(0, 10))

    tk.Label(frm, text="Locatie:").grid(row=3, column=0, sticky="w")
    entry = tk.Entry(frm, textvariable=path_var, width=78)
    entry.grid(row=4, column=0, columnspan=2, sticky="ew", pady=(3, 10))

    def browse() -> None:
        if mode_var.get() == "file":
            initialdir = str(SUGGESTED_ROOT) if SUGGESTED_ROOT.is_dir() else str(Path.cwd())
            selected = filedialog.askopenfilename(
                title="Alege pagina HTML in engleza",
                initialdir=initialdir,
                filetypes=[("HTML", "*.html;*.htm"), ("Toate fisierele", "*.*")],
            )
        else:
            initialdir = str(SUGGESTED_ROOT) if SUGGESTED_ROOT.is_dir() else str(Path.cwd())
            selected = filedialog.askdirectory(
                title="Alege folderul cu fisiere HTML",
                initialdir=initialdir,
            )
        if selected:
            path_var.set(selected)

    tk.Button(frm, text="Alege...", command=browse, width=12).grid(row=4, column=2, padx=(8, 0), sticky="ew")

    note = tk.Label(
        frm,
        text="Vor fi corectate doar textele dintre <!-- SASA-1 --> si <!-- SASA-2 -->. Se face backup inainte de scriere.",
        wraplength=620,
        justify="left",
        fg="#444",
    )
    note.grid(row=5, column=0, columnspan=3, sticky="w", pady=(0, 12))

    def start() -> None:
        location = path_var.get().strip().strip('"')
        if not location:
            messagebox.showerror("Lipseste locatia", "Pune locatia fisierului sau a folderului.")
            return
        if mode_var.get() == "file":
            file_path = Path(location)
            if not file_path.is_file():
                messagebox.showerror("Fisier inexistent", f"Nu gasesc fisierul:\n{file_path}")
                return
            ns = argparse.Namespace(
                root=str(file_path.parent),
                file=str(file_path),
                all=False,
                model=None,
                temperature=0.1,
                max_chars=5200,
                max_items=12,
                timeout=180,
                retries=3,
                limit_files=None,
                limit_units=None,
                no_cache=False,
                dry_run=False,
                verbose=False,
                _gui=True,
            )
        else:
            folder_path = Path(location)
            if not folder_path.is_dir():
                messagebox.showerror("Folder inexistent", f"Nu gasesc folderul:\n{folder_path}")
                return
            ns = argparse.Namespace(
                root=str(folder_path),
                file=None,
                all=True,
                model=None,
                temperature=0.1,
                max_chars=5200,
                max_items=12,
                timeout=180,
                retries=3,
                limit_files=None,
                limit_units=None,
                no_cache=False,
                dry_run=False,
                verbose=False,
                _gui=True,
            )
        result.append(ns)
        root.destroy()

    buttons = tk.Frame(frm)
    buttons.grid(row=6, column=0, columnspan=3, sticky="e")
    tk.Button(buttons, text="Renunta", command=root.destroy, width=12).grid(row=0, column=0, padx=(0, 8))
    tk.Button(buttons, text="Porneste corectura", command=start, width=18).grid(row=0, column=1)

    entry.focus_set()
    root.mainloop()
    return result[0] if result else None


def show_gui_done(args: argparse.Namespace, error: Exception | None = None) -> None:
    if not getattr(args, "_gui", False):
        return
    try:
        import tkinter as tk
        from tkinter import messagebox
    except Exception:
        return
    root = tk.Tk()
    root.withdraw()
    if error:
        messagebox.showerror("Corectura oprita", str(error))
    else:
        messagebox.showinfo("Gata", f"Corectura s-a terminat.\nRaport:\n{REPORT_TXT}")
    root.destroy()


def process_files(args: argparse.Namespace) -> None:
    model = choose_model(args.model)
    if args.temperature is not None and args.temperature < 0:
        args.temperature = None

    paths = list_target_files(args)
    if args.limit_files:
        paths = paths[: args.limit_files]
    paths = [path for path in paths if path.is_file()]
    if not paths:
        raise FileNotFoundError("Nu am gasit niciun fisier HTML de procesat.")

    cache = read_cache()
    reports: list[str] = []
    total_usage = {"input_tokens": 0, "output_tokens": 0, "total_tokens": 0}
    total_units = 0
    total_changed = 0
    total_api_batches = 0
    total_cached = 0
    backups: dict[Path, Path] = {}

    for path in paths:
        text, encoding = read_html(path)
        if START_MARKER not in text or END_MARKER not in text:
            if args.verbose:
                print(f"skip fara markere: {path}")
            continue

        units = collect_units_for_file(path, text, model, args.temperature, next_id=1)
        if args.limit_units:
            units = units[: args.limit_units]
        total_units += len(units)
        if not units:
            reports.append(f"{path} | unitati=0 | schimbari=0")
            continue
        backup = None
        if not args.dry_run:
            backup = ensure_backup(path, backups)
            if args.verbose:
                print(f"backup preventiv: {backup}")

        corrected_protected: dict[int, str] = {}
        pending: list[HtmlUnit] = []
        for unit in units:
            if not args.no_cache and unit.cache_key in cache:
                corrected_protected[unit.id] = cache[unit.cache_key]
                total_cached += 1
            else:
                pending.append(unit)

        batches = make_batches(pending, args.max_chars, args.max_items)
        for batch_index, batch in enumerate(batches, start=1):
            print(f"{path.name}: OpenAI {model} batch {batch_index}/{len(batches)} ({len(batch)} fragmente)")
            last_error: Exception | None = None
            for attempt in range(1, args.retries + 1):
                try:
                    result = call_openai(batch, model=model, temperature=args.temperature, timeout=args.timeout)
                    total_api_batches += 1
                    for key in total_usage:
                        total_usage[key] += result.usage.get(key, 0)
                    for unit in batch:
                        corrected = result.html_by_id[unit.id]
                        cleanup_restored_html(restore_html(corrected, unit.token_order, unit.token_map))
                        corrected_protected[unit.id] = corrected
                        if not args.no_cache:
                            append_cache(unit.cache_key, corrected, model)
                    break
                except urllib.error.HTTPError as e:
                    detail = e.read().decode("utf-8", "replace")
                    last_error = RuntimeError(f"HTTP {e.code}: {detail[:700]}")
                    if e.code in {401, 403, 404}:
                        raise last_error
                    if e.code == 429 and "insufficient_quota" in detail:
                        raise RuntimeError("OpenAI API key-ul este valid, dar proiectul nu are quota/credit disponibil.")
                except Exception as e:
                    last_error = e
                wait = min(8 * attempt, 45)
                print(f"  incercare {attempt} esuata: {last_error}; astept {wait}s")
                time.sleep(wait)
            else:
                raise RuntimeError(f"Nu pot corecta batch-ul pentru {path}: {last_error}")

        replacements: list[tuple[int, int, str]] = []
        changed_examples: list[tuple[str, str]] = []
        for unit in units:
            protected = corrected_protected.get(unit.id)
            if protected is None:
                continue
            new_html = cleanup_restored_html(restore_html(protected, unit.token_order, unit.token_map))
            if corrected_html_is_suspicious(unit.original_html, new_html):
                raise ValueError(f"Corectura suspecta in {path}, fragment id={unit.id}")
            if unit.original_html != new_html:
                replacements.append((unit.start, unit.end, new_html))
                changed_examples.append((visible_text(unit.original_html), visible_text(new_html)))

        if replacements:
            new_text = apply_replacements(text, replacements)
            if not args.dry_run:
                write_html(path, new_text, encoding)
            total_changed += len(replacements)
            reports.append(
                f"{path} | unitati={len(units)} | schimbari={len(replacements)} | "
                f"backup={backup if backup else 'dry-run'}"
            )
            print(f"schimbat: {path} ({len(replacements)} fragmente)")
            if backup:
                print(f"backup: {backup}")
            for before, after in changed_examples[:5]:
                print("  INAINTE:", before[:240])
                print("  DUPA:   ", after[:240])
        else:
            reports.append(f"{path} | unitati={len(units)} | schimbari=0")
            print(f"fara schimbari: {path}")

    summary = [
        "Corectare website EN",
        f"Data: {time.strftime('%Y-%m-%d %H:%M:%S')}",
        f"Root: {Path(args.root) if args.root else '(fisier individual)'}",
        f"Model: {model}",
        f"Temperature: {args.temperature if args.temperature is not None else 'default'}",
        f"Dry-run: {args.dry_run}",
        f"Fisiere analizate: {len(paths)}",
        f"Fragmente eligibile: {total_units}",
        f"Fragmente schimbate: {total_changed}",
        f"Fragmente din cache: {total_cached}",
        f"Batch-uri API: {total_api_batches}",
        f"Input tokens: {total_usage['input_tokens']}",
        f"Output tokens: {total_usage['output_tokens']}",
        f"Total tokens: {total_usage['total_tokens']}",
        "",
        "Fisiere:",
        *reports,
    ]
    REPORT_TXT.write_text("\n".join(summary), encoding="utf-8-sig")
    print(f"raport: {REPORT_TXT}")
    if args.dry_run:
        print("DRY_RUN: nu am scris fisiere si nu am facut backup.")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Corecteaza textele englezesti dintre <!-- SASA-1 --> si <!-- SASA-2 -->, pastrand linkurile si tagurile HTML."
    )
    parser.add_argument("--root", default=None, help="Folderul cu HTML-uri EN.")
    parser.add_argument("--file", default=None, help="Proceseaza un singur HTML.")
    parser.add_argument("--all", action="store_true", help="Proceseaza toate HTML-urile din --root care au marker-ele SASA.")
    parser.add_argument("--model", default=None, help="Model OpenAI; implicit OPENAI_MODEL sau gpt-5.4.")
    parser.add_argument("--temperature", type=float, default=0.1, help="Foloseste -1 pentru default fara temperature.")
    parser.add_argument("--max-chars", type=int, default=5200)
    parser.add_argument("--max-items", type=int, default=12)
    parser.add_argument("--timeout", type=int, default=180)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--limit-files", type=int, default=None)
    parser.add_argument("--limit-units", type=int, default=None)
    parser.add_argument("--no-cache", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    return parser


def main() -> None:
    parser = build_parser()
    if len(sys.argv) == 1:
        args = build_gui_args()
        if args is None:
            return
    else:
        args = parser.parse_args()
        if not args.file and not args.all:
            parser.error("Alege un fisier cu --file sau un folder cu --all --root.")
        if args.all and not args.root:
            parser.error("Pentru toate fisierele, pune folderul cu --root.")

    try:
        process_files(args)
    except Exception as exc:
        show_gui_done(args, exc)
        raise
    show_gui_done(args)


if __name__ == "__main__":
    main()
