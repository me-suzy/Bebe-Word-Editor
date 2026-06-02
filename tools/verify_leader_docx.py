from __future__ import annotations

import argparse
import re
from collections import Counter
from pathlib import Path

from docx import Document


DEFAULT_DOCX = Path(
    r"E:\Carte\BB\++++carti scrise de bebe\CELE 63 de calitati ale liderului\Final Corectat V1.docx"
)


def is_marker(text: str) -> bool:
    stripped = text.strip()
    return (
        len(stripped) >= 3
        and stripped.startswith("-")
        and stripped.endswith("-")
        and stripped[1:-1].isdigit()
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Structural verification for the corrected leader DOCX.")
    parser.add_argument("--docx", type=Path, default=DEFAULT_DOCX)
    args = parser.parse_args()

    doc = Document(args.docx)
    paragraphs = [paragraph.text for paragraph in doc.paragraphs]
    full_text = "\n".join(paragraphs)
    markers = [int(text.strip()[1:-1]) for text in paragraphs if is_marker(text)]
    marker_counts = Counter(markers)

    old_diacritics = "\u015f\u015e\u0163\u0162"  # old Romanian comma-below replacements: s/t with cedilla
    old_diacritic_count = sum(full_text.count(ch) for ch in old_diacritics)
    word_count = sum(len(re.findall(r"\w+", paragraph, re.UNICODE)) for paragraph in paragraphs)

    print(f"docx={args.docx}")
    print(f"exists={args.docx.exists()}")
    print(f"bytes={args.docx.stat().st_size if args.docx.exists() else 0}")
    print(f"paragraphs={len(paragraphs)}")
    print(f"nonempty_paragraphs={sum(1 for paragraph in paragraphs if paragraph.strip())}")
    print(f"rough_word_count={word_count}")
    print(f"markers={len(markers)}")
    print(f"missing_markers={[number for number in range(1, 64) if marker_counts[number] == 0]}")
    print(f"duplicate_markers={[number for number, count in marker_counts.items() if count > 1]}")
    print(f"old_diacritics_count={old_diacritic_count}")

    checks = {
        "Daca -> Daca with breve": "Dac\u0103 ai v\u0103zut filmul",
        "instructiuni -> instructiuni with Romanian chars": "instruc\u021biuni clare",
        "in ce lor -> in ce loc": "\u00een ce loc",
        "transferata -> transferata with breve": "transferat\u0103 imediat",
        "supravietuit -> supravietuit with comma-below": "supravie\u021buit",
        "quoted imagine": "acea \u201eimagine\u201d puternic\u0103",
        "leader agreement": "unul dintre cei mai str\u0103luci\u021bi lideri",
    }
    for label, needle in checks.items():
        print(f"check:{label}={'FOUND' if needle in full_text else 'MISSING'}")


if __name__ == "__main__":
    main()
