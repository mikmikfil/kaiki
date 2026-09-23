"""Add motion to the deck build.cjs writes: transitions, build-ins, drifting waves.

    python docs/deck/motion/animate.py [in.pptx] [out.pptx]

pptxgenjs writes neither transitions nor animations, so they are added here as
OOXML, by name:

- every slide fades in from the one before (the cover and the close slower);
- shapes named `anim-<NN>-…` rise and fade in, grouped by NN, one group after
  another, starting on their own as the slide opens — nothing waits for a click;
- pictures named `wave-left-…` / `wave-right-…` drift sideways and back for as
  long as the slide is up, the back layer slower and the other way, as the
  waves do on the panel.

Only string insertion before `</p:sld>`; nothing already in the slide is
rewritten, so the package stays exactly as pptxgenjs made it otherwise.
"""
from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).parent
SRC = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE / "build" / "Kaiki-parousiasi.pptx"
DST = Path(sys.argv[2]) if len(sys.argv) > 2 else SRC

SHAPE = re.compile(r'<p:(sp|pic)>\s*<p:nv(?:Sp|Pic)Pr>\s*<p:cNvPr id="(\d+)" name="([^"]+)"')

STEP_MS = 260      # between groups
FIRST_MS = 250     # before the first group
EFFECT_MS = 650


class Ids:
    def __init__(self) -> None:
        self.n = 2

    def next(self) -> int:
        self.n += 1
        return self.n


def entrance(ids: Ids, spid: str, delay: int) -> str:
    """Fade in while rising a little: PowerPoint's «Ascend», preset 42."""
    outer, show, fade, rise_x, rise_y = (ids.next() for _ in range(5))
    tgt = f'<p:tgtEl><p:spTgt spid="{spid}"/></p:tgtEl>'
    return (
        f'<p:par><p:cTn id="{outer}" presetID="42" presetClass="entr" presetSubtype="0" fill="hold" nodeType="withEffect">'
        f'<p:stCondLst><p:cond delay="{delay}"/></p:stCondLst><p:childTnLst>'
        f'<p:set><p:cBhvr><p:cTn id="{show}" dur="1" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn>{tgt}'
        f'<p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst></p:cBhvr><p:to><p:strVal val="visible"/></p:to></p:set>'
        f'<p:animEffect transition="in" filter="fade"><p:cBhvr><p:cTn id="{fade}" dur="{EFFECT_MS}"/>{tgt}</p:cBhvr></p:animEffect>'
        f'<p:anim calcmode="lin" valueType="num"><p:cBhvr><p:cTn id="{rise_x}" dur="{EFFECT_MS}" fill="hold"/>{tgt}'
        f'<p:attrNameLst><p:attrName>ppt_x</p:attrName></p:attrNameLst></p:cBhvr><p:tavLst>'
        f'<p:tav tm="0"><p:val><p:strVal val="#ppt_x"/></p:val></p:tav><p:tav tm="100000"><p:val><p:strVal val="#ppt_x"/></p:val></p:tav></p:tavLst></p:anim>'
        f'<p:anim calcmode="lin" valueType="num"><p:cBhvr><p:cTn id="{rise_y}" dur="{EFFECT_MS}" decel="100000" fill="hold"/>{tgt}'
        f'<p:attrNameLst><p:attrName>ppt_y</p:attrName></p:attrNameLst></p:cBhvr><p:tavLst>'
        f'<p:tav tm="0"><p:val><p:strVal val="#ppt_y+0.04"/></p:val></p:tav><p:tav tm="100000"><p:val><p:strVal val="#ppt_y"/></p:val></p:tav></p:tavLst></p:anim>'
        f'</p:childTnLst></p:cTn></p:par>'
    )


def drift(ids: Ids, spid: str, direction: str, back: bool) -> str:
    """A slow sideways motion path, reversing and repeating while the slide is up."""
    outer, motion = ids.next(), ids.next()
    dx = (0.18 if back else 0.28) * (-1 if direction == "left" else 1)
    dur = 26000 if back else 18000
    return (
        f'<p:par><p:cTn id="{outer}" presetID="0" presetClass="path" presetSubtype="0" repeatCount="indefinite" autoRev="1" fill="hold" nodeType="withEffect">'
        f'<p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>'
        f'<p:animMotion origin="layout" path="M 0 0 L {dx:.3f} 0 E" pathEditMode="relative" ptsTypes="">'
        f'<p:cBhvr><p:cTn id="{motion}" dur="{dur}" accel="30000" decel="30000" fill="hold"/><p:tgtEl><p:spTgt spid="{spid}"/></p:tgtEl>'
        f'<p:attrNameLst><p:attrName>ppt_x</p:attrName><p:attrName>ppt_y</p:attrName></p:attrNameLst></p:cBhvr>'
        f'<p:rCtr x="0" y="0"/></p:animMotion></p:childTnLst></p:cTn></p:par>'
    )


def timing(xml: str) -> str:
    shapes = SHAPE.findall(xml)
    ids = Ids()
    effects: list[str] = []

    for _kind, spid, name in shapes:
        m = re.match(r"wave-(left|right)-(back|front)", name)
        if m:
            effects.append(drift(ids, spid, m.group(1), m.group(2) == "back"))

    groups: dict[int, list[str]] = {}
    for _kind, spid, name in shapes:
        m = re.match(r"anim-(\d+)-", name)
        if m:
            groups.setdefault(int(m.group(1)), []).append(spid)
    for i, order in enumerate(sorted(groups)):
        for spid in groups[order]:
            effects.append(entrance(ids, spid, FIRST_MS + i * STEP_MS))

    if not effects:
        return ""
    return (
        '<p:timing><p:tnLst><p:par><p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"><p:childTnLst>'
        '<p:seq concurrent="1" nextAc="seek"><p:cTn id="2" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'
        f'<p:par><p:cTn id="{ids.next()}" fill="hold"><p:stCondLst><p:cond delay="indefinite"/>'
        '<p:cond evt="onBegin" delay="0"><p:tn val="2"/></p:cond></p:stCondLst><p:childTnLst>'
        f'<p:par><p:cTn id="{ids.next()}" fill="hold"><p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>'
        + "".join(effects)
        + '</p:childTnLst></p:cTn></p:par></p:childTnLst></p:cTn></p:par>'
        '</p:childTnLst></p:cTn><p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:prevCondLst>'
        '<p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl></p:cond></p:nextCondLst></p:seq>'
        '</p:childTnLst></p:cTn></p:par></p:tnLst></p:timing>'
    )


def transition(index: int, last: int) -> str:
    slow = index in (1, last)
    return f'<p:transition spd="{"slow" if slow else "med"}"><p:fade/></p:transition>'


def main() -> None:
    with zipfile.ZipFile(SRC) as zin:
        items = [(i, zin.read(i.filename)) for i in zin.infolist()]

    slides = sorted(
        (int(re.search(r"slide(\d+)\.xml$", i.filename).group(1)) for i, _ in items
         if re.fullmatch(r"ppt/slides/slide\d+\.xml", i.filename)),
    )
    last = slides[-1]

    out_items = []
    for info, data in items:
        m = re.fullmatch(r"ppt/slides/slide(\d+)\.xml", info.filename)
        if m:
            xml = data.decode("utf-8")
            if "<p:transition" not in xml and "<p:timing" not in xml:
                xml = xml.replace("</p:sld>", transition(int(m.group(1)), last) + timing(xml) + "</p:sld>", 1)
            data = xml.encode("utf-8")
        out_items.append((info, data))

    tmp = DST.with_suffix(".tmp.pptx")
    with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as zout:
        for info, data in out_items:
            zout.writestr(info, data)
    tmp.replace(DST)
    print("animated", DST, "slides:", len(slides))


if __name__ == "__main__":
    main()
