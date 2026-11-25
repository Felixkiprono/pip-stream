# Rebuilds the PipStream logo SVGs: python3 docs/brand/build.py docs/brand/svg  (needs fonttools and Inter Display)
import os, sys
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
OUT = sys.argv[1]; os.makedirs(OUT, exist_ok=True)

BLUE_D, BLUE, TEAL, GOLD_L, GOLD_D, INK, NAVY = '#1d4ed8', '#2f6bff', '#14d6a4', '#ffd25a', '#f5a300', '#0d1424', '#070c17'

def defs(p=''):
    return f'''<defs>
<linearGradient id="{p}bg" x1="0" y1="64" x2="64" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="{BLUE_D}"/><stop offset=".55" stop-color="{BLUE}"/><stop offset="1" stop-color="{TEAL}"/></linearGradient>
<linearGradient id="{p}fg" x1="16" y1="54" x2="54" y2="10" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="{BLUE}"/><stop offset="1" stop-color="{TEAL}"/></linearGradient>
<linearGradient id="{p}gold" x1="0" y1="41" x2="0" y2="53" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="{GOLD_L}"/><stop offset="1" stop-color="{GOLD_D}"/></linearGradient>
</defs>'''

def glyph(fill, coin):
    # the P: a candlestick stem (wicks + body) with a round bowl; the gold coin is the "pip"
    return (f'<path d="M23.5 9.5v8M23.5 46v8.5" stroke="{fill}" stroke-width="3.5" stroke-linecap="round"/>'
            f'<rect x="18" y="16.5" width="11" height="31" rx="3" fill="{fill}"/>'
            f'<path d="M27 20.5h8.5a8.75 8.75 0 0 1 0 17.5H27" stroke="{fill}" stroke-width="7.5" fill="none"/>'
            f'<circle cx="46.5" cy="47" r="5.5" fill="{coin}"/>')

def svg(vb, body, w=None, h=None):
    size = f' width="{w}" height="{h}"' if w else ''
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}"{size}>{body}</svg>\n'

def mark(kind='color', p='ps'):
    if kind == 'color':   # gradient tile, white P, gold pip
        return defs(p) + f'<rect width="64" height="64" rx="16" fill="url(#{p}bg)"/>' + glyph('#fff', f'url(#{p}gold)')
    if kind == 'night':   # navy tile, gradient P
        return defs(p) + f'<rect width="64" height="64" rx="16" fill="{NAVY}"/>' + glyph(f'url(#{p}fg)', f'url(#{p}gold)')
    if kind == 'glyph':   # no tile, brand-gradient P (for light backgrounds)
        return defs(p) + glyph(f'url(#{p}fg)', f'url(#{p}gold)')
    if kind == 'mono':    # one colour, currentColor
        return glyph('currentColor', 'currentColor')

files = {}
files['mark.svg'] = svg('0 0 64 64', mark('color'))
files['mark-night.svg'] = svg('0 0 64 64', mark('night'))
files['mark-glyph.svg'] = svg('0 0 64 64', mark('glyph'))
files['mark-mono.svg'] = svg('0 0 64 64', mark('mono'))

# favicon: same mark; tile corners slightly tighter
files['favicon.svg'] = files['mark.svg']

# maskable / app icon (full bleed, glyph inside the 80% safe zone)
def fullbleed(scale, round_=False, p='fb'):
    off = 32 - 32*scale
    return svg('0 0 64 64', defs(p) + f'<rect width="64" height="64" fill="url(#{p}bg)"/>'
               + f'<g transform="translate({off:.3f} {off:.3f}) scale({scale})">' + glyph('#fff', f'url(#{p}gold)') + '</g>')
files['maskable.svg'] = fullbleed(0.72)
files['apple-touch.svg'] = fullbleed(0.80)
# Telegram bot avatar: Telegram crops a circle, so the glyph sits inside it with room
files['bot-avatar.svg'] = fullbleed(0.66)

# wordmark from Inter Display Bold, as paths (no font needed)
f = TTFont('/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf')
gs, cmap, upm = f.getGlyphSet(), f.getBestCmap(), f['head'].unitsPerEm
def word(text, x0, baseline, size, tracking=-0.02):
    s = size/upm; x = 0; paths = []
    for ch in text:
        g = cmap[ord(ch)]
        pen = SVGPathPen(gs)
        gs[g].draw(TransformPen(pen, (s, 0, 0, -s, x0 + x*s, baseline)))
        paths.append(pen.getCommands())
        x += gs[g].width + tracking*upm
    return ' '.join(paths), (x - tracking*upm)*s
wpath, wwidth = word('PipStream', 0, 0, 100)
# cap height of Inter ~ 0.727em
cap = 72.7
def lockup(ink, kind='color', p='lk'):
    # mark 64 tall; wordmark cap height = 26 units, vertically centred
    size = 26/cap*100; s = size/100
    bx = 64 + 14; base = 32 + 13
    w = bx + wwidth*s
    body = (mark(kind, p) + f'<path transform="translate({bx} {base}) scale({s:.5f})" d="{wpath}" fill="{ink}"/>')
    return svg(f'0 0 {w:.1f} 64', body)
files['logo.svg'] = lockup(INK)
files['logo-dark.svg'] = lockup('#ffffff', p='lkd')
files['logo-mono.svg'] = lockup('currentColor', 'mono')
files['wordmark.svg'] = svg(f'0 -76 {wwidth:.1f} 96', f'<path d="{wpath}" fill="{INK}"/>')
# stacked lockup for social / splash
def stacked(ink, bg=None, p='st'):
    W, H = 420, 300
    s = 0.62
    body = ''
    if bg: body += f'<rect width="{W}" height="{H}" fill="{bg}"/>'
    body += f'<g transform="translate({W/2-64} 40) scale(2)">' + mark('color', p) + '</g>'
    body += f'<path transform="translate({(W - wwidth*s)/2:.1f} 236) scale({s})" d="{wpath}" fill="{ink}"/>'
    return svg(f'0 0 {W} {H}', body)
files['logo-stacked.svg'] = stacked(INK)
files['logo-stacked-dark.svg'] = stacked('#ffffff', NAVY, 'std')
for n, c in files.items():
    open(os.path.join(OUT, n), 'w').write(c)

print('\n'.join(files))
