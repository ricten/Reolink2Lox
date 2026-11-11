"""Erzeugt das Logo und die LoxBerry-Plugin-Icons im Stil von Senec2Lox / Nut2Lox / Fronius2Lox.

Aufruf (aus dem Repo-Ordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift: Arial Bold (Windows) bzw. DejaVu Sans Bold.
Benoetigt ausserdem tools/kamera_vorlage.png (freigestelltes Kamerabild aus einem Produktfoto des Herstellers; liegt nicht im Repo, die fertigen Logos schon).

Ausgabe:
  docs/logo/reolink2lox.png                     gruener, runder LoxBerry-Stil
  reolink2lox/icons/icon_{64,128,256,512}.png    dasselbe als Plugin-Icons

Links eine Reolink-Schwenk-Neige-Kamera (eigene, schematische Darstellung ohne Herstellerlogo und Schrift: Wandhalter, weisse Kuppel, dunkles Objektivfenster mit Objektiv und Infrarot-LEDs),
rechts der Miniserver (wie bei Senec2Lox), dazwischen ein Pfeil, darunter der Name "Reolink2Lox".
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
CAMERA = os.path.join(ROOT, "tools", "kamera_vorlage.png")   # freigestelltes Foto der Kamera (aus dem Produktfoto, ohne Schriftzug)
LABEL = "Reolink2Lox"
LABEL_WIDTH = 310      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie
OX, OY = -15, 29         # Verschiebung der ganzen Gruppe (Drucker, Miniserver, Name): Mittelpunkt der Gruppe (x 99..429, y 101..352) liegt im Kreismittelpunkt (256, 256)

PRINTER = (102, 104, 208, 272)             # x1, y1, x2, y2 (Kamera: Halter, Kuppel, Sockel)
MINISERVER = (270, 152, 426, 240)          # x1, y1, x2, y2 (gruenes Gehaeuse, Draufsicht)
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (214, 256)


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht), identisch zu Senec2Lox.

    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("ellipse", cx, cy, rx, ry, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))
    sh.append(("rrect", x1, y1, x2, y2, 0, green))
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def printer_shapes():
    """Reolink-Schwenk-Neige-Kamera als flaches Icon (Name der Funktion bleibt aus der Vorlage). Die Proportionen stammen aus dem Produktfoto
    (ausgemessen in dessen Bildpunkten, Kamera 747 Punkte hoch, Mitte bei x = 343,5), gezeichnet sind nur flache Flaechen ohne Verlaeufe, ohne Schrift und
    ohne Herstellerlogo: Wandhalter mit zwei Schluessellochern und Hals, breite weisse Kuppel, schwarzes Gehaeuse mit seitlichen Wuelsten,
    grosses Objektiv und zwei warmweisse Scheinwerfer. Sie sitzt direkt auf dem gruenen Kreis (kein Rahmen, keine Hintergrundflaeche)."""
    x1, y1, x2, y2 = PRINTER
    cx = (x1 + x2) / 2
    top = y1 - 3
    k = (y2 + 3 - top) / 747.0

    def X(px):
        return cx + (px - 343.5) * k

    def Y(py):
        return top + (py - 18) * k

    white = (255, 255, 255, 255)
    shade = (214, 221, 230, 255)         # flache Schattenflaeche auf Weiss
    black = (26, 28, 33, 255)
    graphite = (64, 68, 76, 255)
    sh = []
    # Wandhalter: Ohren mit Schluessellochern, Hals mit Schraube
    plate = (226, 232, 240, 255)
    sh.append(("rrect", X(232), Y(18), X(458), Y(262), 18 * k, plate))
    for kx in (270, 418):
        sh.append(("circle", X(kx), Y(78), 15 * k, graphite))
        sh.append(("rrect", X(kx) - 7 * k, Y(78), X(kx) + 7 * k, Y(106), 6 * k, graphite))
    sh.append(("rrect", X(286), Y(24), X(400), Y(250), 26 * k, white))
    sh.append(("circle", X(343), Y(172), 9 * k, shade))                                  # Schraube
    # Kuppel: breit, oben abgerundet; darunter ein Schattenband
    sh.append(("ellipse", X(343.5), Y(338), 177.5 * k, 128 * k, white))                 # oben abgerundete Haube
    sh.append(("rrect", X(166), Y(338), X(521), Y(470), 10 * k, white))
    sh.append(("rrect", X(166), Y(436), X(521), Y(470), 6 * k, shade))
    # schwarzes Gehaeuse mit seitlichen Wuelsten
    sh.append(("rrect", X(178), Y(464), X(509), Y(765), 62 * k, black))
    sh.append(("rrect", X(168), Y(538), X(214), Y(722), 24 * k, graphite))
    sh.append(("rrect", X(476), Y(538), X(514), Y(722), 24 * k, graphite))
    # Objektiv: Fassung, Ring, Glas, Glanz
    sh.append(("rrect", X(234), Y(488), X(454), Y(706), 64 * k, graphite))
    sh.append(("circle", X(347), Y(596), 76 * k, black))
    sh.append(("circle", X(347), Y(596), 56 * k, (92, 98, 110, 255)))
    sh.append(("circle", X(347), Y(596), 44 * k, black))
    sh.append(("circle", X(347), Y(596), 31 * k, (52, 78, 132, 255)))
    sh.append(("circle", X(335), Y(583), 10 * k, white))
    # zwei warmweisse Scheinwerfer unter dem Objektiv
    sh.append(("rrect", X(300), Y(702), X(396), Y(754), 24 * k, black))
    for lx in (326, 368):
        sh.append(("circle", X(lx), Y(727), 19 * k, (255, 228, 170, 255)))
        sh.append(("circle", X(lx), Y(727), 11 * k, white))
    return sh


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def render(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))

    def draw(shapes):
        for sh in shapes:
            if sh[0] == "rrect":
                _, x1, y1, x2, y2, rad, c = sh
                d.rounded_rectangle(r(x1 + OX, y1 + OY, x2 + OX, y2 + OY), radius=rad * k, fill=c)
            elif sh[0] == "circle":
                _, cx, cy, rr, c = sh
                d.ellipse(r(cx - rr + OX, cy - rr + OY, cx + rr + OX, cy + rr + OY), fill=c)
            elif sh[0] == "ellipse":
                _, cx, cy, rx, ry, c = sh
                d.ellipse(r(cx - rx + OX, cy - ry + OY, cx + rx + OX, cy + ry + OY), fill=c)
            else:
                _, x1, y1, x2, y2, w, c = sh
                d.line([((x1 + OX) * k, (y1 + OY) * k), ((x2 + OX) * k, (y2 + OY) * k)], fill=c, width=max(1, int(w * k)))

    # Kamera: freigestelltes Produktfoto (tools/kamera_vorlage.png, Schriftzug entfernt, weisser Rand), Hoehe wie der frueher gezeichnete Rahmen
    cam = Image.open(CAMERA)
    ch = (PRINTER[3] + 3 - (PRINTER[1] - 3)) * k              # Hoehe in Pixeln
    cw = cam.width * ch / cam.height
    cam = cam.resize((max(1, round(cw)), max(1, round(ch))), Image.LANCZOS)
    cx_u = (PRINTER[0] + PRINTER[2]) / 2 + OX
    ov.alpha_composite(cam, (round(cx_u * k - cam.width / 2), round((PRINTER[1] - 3 + OY) * k)))
    draw(miniserver_shapes())
    # Verbindung mit Pfeilspitze (Drucker -> Miniserver)
    ly = (LINK_Y + OY) * k
    d.line([((LINK_X[0] + OX) * k, ly), ((LINK_X[1] - 4 + OX) * k, ly)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8 + OX) * k, ly), ((LINK_X[1] - 6 + OX) * k, ly - 11 * k),
               ((LINK_X[1] - 6 + OX) * k, ly + 11 * k)], fill="white")
    # Beschriftung: Name in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, (LABEL_BASE + OY) * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "reolink2lox", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    render(512).save(os.path.join(logo_dir, "reolink2lox.png"))
    for s in (64, 128, 256, 512):
        render(s).save(os.path.join(icon_dir, "icon_%d.png" % s))
    print("Logo und Icons geschrieben")
