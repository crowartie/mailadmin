#!/bin/bash
# Инструменты просмотра вложений (обращение №64). Идемпотентно, запускается из update.sh (root).
#  - libheif (heif-convert + модуль libde265): фото с iPhone HEIC → JPEG;
#  - libtiff-tools (tiff2pdf): сканы TIFF → PDF;
#  - python3-ezdxf + librsvg2-bin (rsvg-convert): чертежи DXF → PDF (resources/tools/cad2pdf.py);
#  - LibreDWG (dwg2dxf) — чертежи DWG → DXF. В Ubuntu 24.04 пакета нет: собираем выпуск с GitHub,
#    архив сверяем по контрольной сумме. ODA File Converter не ставим: его сайт закрыт для России.
set -e
PKGS="libheif-examples libheif-plugin-libde265 libtiff-tools python3-ezdxf python3-matplotlib librsvg2-bin poppler-utils"
missing=""
for p in $PKGS; do dpkg -s "$p" >/dev/null 2>&1 || missing="$missing $p"; done
if [ -n "$missing" ]; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q $missing >/dev/null
  echo "    просмотр вложений: установлены$missing"
fi

LDWG_VER=0.14
LDWG_SHA=62ebb73b984f865960f20ed26619ea5f8789d5e3fd088fa40a2598384da81275
if ! /usr/local/bin/dwg2dxf --version 2>/dev/null | grep -q "$LDWG_VER"; then
  BUILD="build-essential pkg-config"
  need=""
  for p in $BUILD; do dpkg -s "$p" >/dev/null 2>&1 || need="$need $p"; done
  [ -n "$need" ] && DEBIAN_FRONTEND=noninteractive apt-get install -y -q $need >/dev/null
  W=$(mktemp -d)
  trap 'rm -rf "$W"' EXIT
  curl -sSL --max-time 300 -o "$W/l.tar.xz" "https://github.com/LibreDWG/libredwg/releases/download/$LDWG_VER/libredwg-$LDWG_VER.tar.xz"
  echo "$LDWG_SHA  $W/l.tar.xz" | sha256sum -c --quiet - || { echo "LibreDWG: контрольная сумма не сошлась — не ставлю"; exit 1; }
  tar xf "$W/l.tar.xz" -C "$W"
  cd "$W/libredwg-$LDWG_VER"
  ./configure --prefix=/usr/local --disable-bindings --disable-python --disable-werror -q >/dev/null 2>&1
  make -j"$(nproc)" >/dev/null 2>&1
  make install >/dev/null 2>&1
  ldconfig
  echo "    просмотр вложений: LibreDWG $LDWG_VER собрана ($(/usr/local/bin/dwg2dxf --version 2>&1 | head -1))"
fi
