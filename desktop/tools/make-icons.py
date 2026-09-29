# -*- coding: utf-8 -*-
"""Иконки приложения «Почта» для Windows из иконки веб-почты (public/icon-512.png).

  build/icon.ico            — установщик, ярлыки, exe (16…256 px)
  assets/icon.png           — окно и уведомления (256 px)
  assets/tray.png           — значок в трее (32 px, Windows сама уменьшит до 16 при 100 %)
  assets/tray-unread.png    — он же с красной точкой: есть непрочитанные
  assets/badges/badge-N.png — кружок с числом поверх значка на панели задач (1…9, «9+»)

Запуск: python desktop/tools/make-icons.py (нужен Pillow). Результат коммитится — сборке Python не нужен.
"""
import os
from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SRC = os.path.join(os.path.dirname(ROOT), 'public', 'icon-512.png')
RED = (214, 48, 39, 255)
WHITE = (255, 255, 255, 255)


def font(size):
    for f in ('C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'):
        if os.path.exists(f):
            return ImageFont.truetype(f, size)
    return ImageFont.load_default()


def main():
    src = Image.open(SRC).convert('RGBA')
    os.makedirs(os.path.join(ROOT, 'build'), exist_ok=True)
    os.makedirs(os.path.join(ROOT, 'assets', 'badges'), exist_ok=True)

    src.save(os.path.join(ROOT, 'build', 'icon.ico'), sizes=[(16, 16), (20, 20), (24, 24), (32, 32), (40, 40), (48, 48), (64, 64), (128, 128), (256, 256)])
    src.resize((256, 256), Image.LANCZOS).save(os.path.join(ROOT, 'assets', 'icon.png'), optimize=True)

    tray = src.resize((32, 32), Image.LANCZOS)
    tray.save(os.path.join(ROOT, 'assets', 'tray.png'), optimize=True)
    dot = tray.copy()
    d = ImageDraw.Draw(dot)
    d.ellipse((19, 0, 31, 12), fill=RED, outline=WHITE, width=2)
    dot.save(os.path.join(ROOT, 'assets', 'tray-unread.png'), optimize=True)

    # Кружок рисуем крупно и уменьшаем — так края ровные; Windows показывает его 16×16 в углу значка.
    for label in [str(i) for i in range(1, 10)] + ['9+']:
        big = Image.new('RGBA', (64, 64), (0, 0, 0, 0))
        d = ImageDraw.Draw(big)
        d.ellipse((1, 1, 63, 63), fill=RED, outline=WHITE, width=4)
        f = font(40 if len(label) == 1 else 30)
        box = d.textbbox((0, 0), label, font=f)
        w, h = box[2] - box[0], box[3] - box[1]
        d.text(((64 - w) / 2 - box[0], (64 - h) / 2 - box[1] - 1), label, font=f, fill=WHITE)
        name = 'badge-9plus.png' if label == '9+' else f'badge-{label}.png'
        big.resize((32, 32), Image.LANCZOS).save(os.path.join(ROOT, 'assets', 'badges', name), optimize=True)
    print('ok')


if __name__ == '__main__':
    main()
