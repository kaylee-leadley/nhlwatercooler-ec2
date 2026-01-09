#!/usr/bin/env python3
import re
import sys
from pathlib import Path
from datetime import datetime

def patch_file(path: Path) -> None:
    css = path.read_text(encoding="utf-8")

    original = css

    # ------------------------------------------------------------
    # 1) Fix selector that doesn't match your <article class="player-card adv-player-card ...">
    #    ".player-card .adv-player-card" means "adv-player-card inside player-card"
    #    You want the same element, so ".player-card.adv-player-card"
    # ------------------------------------------------------------
    css = re.sub(
        r'(\.player-card)\s+(\.adv-player-card\b)',
        r'\1\2',
        css
    )

    # ------------------------------------------------------------
    # 2) Add article selectors to each team override block header
    #    Finds rule headers containing "body.theme-team-XXX" up to the "{"
    #    and injects:
    #      article.team-XXX,
    #      article.player-card.adv-player-card.team-XXX
    #
    #    Safe: won't duplicate if already present.
    # ------------------------------------------------------------
    header_re = re.compile(
        r'(?ms)^(?P<header>[^{}]*\bbody\.theme-team-(?P<code>[a-z0-9]+)\b[^{}]*?)\{'
    )

    def add_article_selectors(m: re.Match) -> str:
        header = m.group("header")
        code = m.group("code")

        want1 = f"article.team-{code}"
        want2 = f"article.player-card.adv-player-card.team-{code}"

        # If either is already present, don't add duplicates.
        if want1 in header or want2 in header:
            return m.group(0)

        header_stripped = header.rstrip()

        injected = (
            f"{header_stripped},\n"
            f"{want1},\n"
            f"{want2} "
            "{"
        )
        return injected

    css = header_re.sub(add_article_selectors, css)

    if css == original:
        print(f"[NO-OP] No changes needed: {path}")
        return

    # Backup
    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    bak = path.with_suffix(path.suffix + f".bak.{ts}")
    bak.write_text(original, encoding="utf-8")

    # Write patched file
    path.write_text(css, encoding="utf-8")

    print(f"[OK] Patched: {path}")
    print(f"[OK] Backup : {bak}")

def main():
    if len(sys.argv) != 2:
        print("Usage: patch_color_scheme_adv_cards.py /path/to/color-scheme.css", file=sys.stderr)
        sys.exit(2)

    p = Path(sys.argv[1]).expanduser().resolve()
    if not p.is_file():
        print(f"ERROR: file not found: {p}", file=sys.stderr)
        sys.exit(1)

    patch_file(p)

if __name__ == "__main__":
    main()
