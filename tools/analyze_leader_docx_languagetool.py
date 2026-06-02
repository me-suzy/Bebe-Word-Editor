from __future__ import annotations

import argparse
import json
import sys
import time
from collections import Counter, defaultdict
from pathlib import Path

from docx import Document


DEFAULT_DOCX = Path(
    r"E:\Carte\BB\++++carti scrise de bebe\CELE 63 de calitati ale liderului\Final Corectat V1.docx"
)
DEFAULT_REPORT = Path(
    r"E:\Carte\BB\++++carti scrise de bebe\CELE 63 de calitati ale liderului\analiza-dupa-corectura-v1.json"
)
DEPS_DIR = Path(__file__).resolve().parent / ".pydeps_languagetool"


def is_marker(text: str) -> bool:
    stripped = text.strip()
    return (
        len(stripped) >= 3
        and stripped.startswith("-")
        and stripped.endswith("-")
        and stripped[1:-1].isdigit()
    )


def load_languagetool():
    if not DEPS_DIR.exists():
        raise RuntimeError(
            f"Missing dependency folder: {DEPS_DIR}. "
            "Install with: python -m pip install --target tools/.pydeps_languagetool language-tool-python"
        )

    sys.path.insert(0, str(DEPS_DIR))
    import language_tool_python  # type: ignore
    import language_tool_python.download_lt as download_lt  # type: ignore

    # The local PC currently has Java 8. LanguageTool 5.9 works here, while
    # newer LanguageTool packages require Java 17.
    download_lt.confirm_java_compatibility = lambda version_name="5.9": None
    return language_tool_python


def main() -> None:
    parser = argparse.ArgumentParser(description="Analyze a Romanian DOCX with local LanguageTool.")
    parser.add_argument("--docx", type=Path, default=DEFAULT_DOCX)
    parser.add_argument("--out", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--language", default="ro-RO")
    parser.add_argument("--lt-version", default="5.9")
    args = parser.parse_args()

    language_tool_python = load_languagetool()
    doc = Document(args.docx)
    items = [
        (idx, paragraph.text)
        for idx, paragraph in enumerate(doc.paragraphs)
        if paragraph.text.strip() and not is_marker(paragraph.text) and len(paragraph.text.strip()) >= 4
    ]

    tool = language_tool_python.LanguageTool(args.language, language_tool_download_version=args.lt_version)
    rules = Counter()
    categories = Counter()
    samples = defaultdict(list)
    all_matches = []
    started = time.time()

    try:
        for n, (paragraph_index, text) in enumerate(items, 1):
            matches = tool.check(text)
            for match in matches:
                rules[match.rule_id] += 1
                categories[str(match.category)] += 1
                found = text[match.offset : match.offset + match.error_length]
                record = {
                    "paragraph": paragraph_index,
                    "rule_id": match.rule_id,
                    "category": str(match.category),
                    "offset": match.offset,
                    "length": match.error_length,
                    "found": found,
                    "replacements": match.replacements[:8],
                    "message": match.message,
                    "context": text[max(0, match.offset - 80) : min(len(text), match.offset + match.error_length + 80)],
                }
                all_matches.append(record)
                if len(samples[match.rule_id]) < 8:
                    samples[match.rule_id].append(record)

            if n % 200 == 0:
                print(f"checked={n} matches={len(all_matches)} elapsed_sec={time.time() - started:.1f}")
    finally:
        tool.close()

    payload = {
        "docx": str(args.docx),
        "paragraphs_checked": len(items),
        "matches_total": len(all_matches),
        "categories": categories.most_common(),
        "rules": rules.most_common(),
        "samples": samples,
        "all_matches": all_matches,
    }
    args.out.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Report saved: {args.out}")
    print(f"matches_total={len(all_matches)}")
    print(f"top_rules={rules.most_common(20)}")


if __name__ == "__main__":
    main()
