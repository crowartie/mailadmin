#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Чертёж DXF → PDF для просмотрщика вложений (обращение №64).

Первая страница — пространство модели целиком, дальше — листы (Layout1, «Лист1»…), где что-то нарисовано.
Фон белый; цвет 7 AutoCAD (белые линии на чёрном экране) становится чёрным, остальные цвета — как в файле.
Файл читается «с починкой» (ezdxf.recover): чертежи из разных программ часто слегка повреждены.
DWG сюда приходит уже переведённым в DXF (LibreDWG dwg2dxf, см. OfficePdf).

Рисует векторный SVG-модуль ezdxf (в 9 раз быстрее matplotlib на сложных чертежах), страницы собирает
в один PDF rsvg-convert. Страница — по пропорциям чертежа: длинная сторона A1 (841 мм), короткая — не меньше A4.

Запуск: cad2pdf.py <вход.dxf> <выход.pdf>
"""
import math
import os
import subprocess
import sys
import tempfile

import ezdxf
from ezdxf import bbox, recover
from ezdxf.addons.drawing import Frontend, RenderContext, layout, svg
from ezdxf.addons.drawing.config import BackgroundPolicy, ColorPolicy, Configuration, LineweightPolicy
from ezdxf.entities import Text
from ezdxf.enums import TextEntityAlignment
from ezdxf.math import Vec3

MAX_ENTITIES = 400000      # больше — рисовать минутами; такой чертёж проще скачать
LONG_MM = 841              # A1
SHORT_MIN_MM = 210         # A4
SKIPPED = [0]
FALLBACK = [0]              # многострочные надписи, нарисованные простым текстом


# MTEXT: точка привязки 1…9 → выравнивание однострочного TEXT.
MTEXT_ALIGN = {
    1: TextEntityAlignment.TOP_LEFT, 2: TextEntityAlignment.TOP_CENTER, 3: TextEntityAlignment.TOP_RIGHT,
    4: TextEntityAlignment.MIDDLE_LEFT, 5: TextEntityAlignment.MIDDLE_CENTER, 6: TextEntityAlignment.MIDDLE_RIGHT,
    7: TextEntityAlignment.BOTTOM_LEFT, 8: TextEntityAlignment.BOTTOM_CENTER, 9: TextEntityAlignment.BOTTOM_RIGHT,
}


def mtext_as_text(m):
    """Многострочный текст → простые строки TEXT в той же точке, того же размера, с тем же поворотом.
    Запасной путь: вёрстка MTEXT в ezdxf 1.1 падает на части надписей размеров из DWG («not enough space»)."""
    h = m.dxf.get('char_height', 2.5) or 2.5
    lines = [x for x in m.plain_text(split=True)] or ['']
    d = Vec3(m.dxf.get('text_direction', Vec3(1, 0, 0)))
    angle = math.degrees(math.atan2(d.y, d.x)) if d.magnitude > 1e-9 else 0.0
    down = Vec3(-d.y, d.x, 0).normalize() * -1 if d.magnitude > 1e-9 else Vec3(0, -1, 0)
    step = h * 1.667 * (m.dxf.get('line_spacing_factor', 1.0) or 1.0)
    ap = m.dxf.get('attachment_point', 1)
    # Строки распределяем от точки привязки: сверху вниз (1–3), вокруг середины (4–6), снизу вверх (7–9).
    shift0 = 0 if ap <= 3 else (-(len(lines) - 1) * step / 2 if ap <= 6 else -(len(lines) - 1) * step)
    out = []
    for i, line in enumerate(lines):
        t = Text.new(dxfattribs={'text': line, 'height': h, 'rotation': angle, 'layer': m.dxf.layer,
                                 'style': m.dxf.get('style', 'Standard'), 'color': m.dxf.get('color', 256)}, doc=m.doc)
        t.set_placement(Vec3(m.dxf.insert) + down * (shift0 + i * step), align=MTEXT_ALIGN.get(ap, TextEntityAlignment.TOP_LEFT))
        out.append(t)
    return out


class TolerantFrontend(Frontend):
    """Один «кривой» объект не должен ронять весь чертёж (в ezdxf 1.1 ломается, например, ломаная из одной
    точки). Такой объект пропускаем, остальное рисуем. Многострочный текст, который не верстается, рисуем
    простым текстом."""

    def draw_entity(self, entity, properties):
        try:
            super().draw_entity(entity, properties)
        except Exception:  # noqa: BLE001 — сбой одного объекта, не всего чертежа
            if entity.dxftype() == 'MTEXT':
                try:
                    for t in mtext_as_text(entity):
                        super().draw_entity(t, properties)
                    FALLBACK[0] += 1
                    return
                except Exception:  # noqa: BLE001
                    pass
            SKIPPED[0] += 1


def rebuild_dimensions(doc):
    """Размеры без нарисованного блока — достроить. AutoCAD хранит вид каждого размера (линии, стрелки,
    текст «10' (3.05m)») в анонимном блоке; LibreDWG при переводе DWG → DXF эти блоки не переносит,
    и размеры выходили без текста. ezdxf умеет нарисовать размер заново по его параметрам."""
    n = 0
    for lay in [doc.modelspace()] + [doc.layouts.get(x) for x in doc.layout_names_in_taborder() if x != 'Model']:
        for dim in lay.query('DIMENSION'):
            try:
                if dim.get_geometry_block() is not None:
                    continue
                dim.override().render()
                n += 1
            except Exception:  # noqa: BLE001 — не вышло с одним размером, остальные рисуем
                SKIPPED[0] += 1
    return n


def page_svg(doc, lay, cfg):
    ents = list(lay)
    if not ents:
        return None
    box = bbox.extents(ents, fast=True)
    if not box.has_data:
        return None
    w, h = max(box.size.x, 1e-6), max(box.size.y, 1e-6)
    if w >= h:
        pw, ph = LONG_MM, max(SHORT_MIN_MM, LONG_MM * h / w)
    else:
        pw, ph = max(SHORT_MIN_MM, LONG_MM * w / h), LONG_MM
    be = svg.SVGBackend()
    ctx = RenderContext(doc)
    ctx.set_current_layout(lay)
    TolerantFrontend(ctx, be, config=cfg).draw_layout(lay, finalize=True)
    page = layout.Page(round(pw), round(ph), layout.Units.mm, margins=layout.Margins.all(8))
    return be.get_string(page, settings=layout.Settings(fit_page=True))


def main(src, out):
    try:
        doc, _auditor = recover.readfile(src)
    except IOError:
        print('не читается файл', file=sys.stderr)
        return 2
    except ezdxf.DXFStructureError as e:
        print('повреждённый DXF: %s' % e, file=sys.stderr)
        return 3
    if len(doc.entitydb) > MAX_ENTITIES:
        print('слишком большой чертёж: %d объектов' % len(doc.entitydb), file=sys.stderr)
        return 4
    rebuilt = rebuild_dimensions(doc)
    cfg = Configuration(
        background_policy=BackgroundPolicy.WHITE,
        color_policy=ColorPolicy.COLOR_SWAP_BW,
        lineweight_policy=LineweightPolicy.RELATIVE,
    )
    layouts = [doc.modelspace()] + [doc.layouts.get(n) for n in doc.layout_names_in_taborder() if n != 'Model']
    with tempfile.TemporaryDirectory(dir=os.path.dirname(os.path.abspath(out))) as tmp:
        pages = []
        for i, lay in enumerate(layouts):
            try:
                s = page_svg(doc, lay, cfg)
            except Exception as e:  # noqa: BLE001 — лист, который не рисуется (пустой видовой экран и т. п.), пропускаем
                print('лист %s пропущен: %s' % (lay.name, str(e)[:120]), file=sys.stderr)
                continue
            if s is None:
                continue
            p = os.path.join(tmp, 'p%03d.svg' % i)
            with open(p, 'w', encoding='utf-8') as f:
                f.write(s)
            pages.append(p)
        if not pages:
            print('в чертеже нечего рисовать', file=sys.stderr)
            return 5
        r = subprocess.run(['rsvg-convert', '-f', 'pdf', '-o', out] + pages, capture_output=True, text=True, timeout=180)
        if r.returncode != 0 or not os.path.exists(out):
            print('rsvg-convert: %s' % (r.stderr or r.stdout)[:300], file=sys.stderr)
            return 6
    if rebuilt:
        print('достроено размеров: %d' % rebuilt, file=sys.stderr)
    if FALLBACK[0]:
        print('надписей простым текстом: %d' % FALLBACK[0], file=sys.stderr)
    if SKIPPED[0]:
        print('пропущено объектов: %d' % SKIPPED[0], file=sys.stderr)
    return 0


if __name__ == '__main__':
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        sys.exit(1)
    sys.exit(main(sys.argv[1], sys.argv[2]))
