"""Builds small fake zip files for tests/ui_apk_check.js:  python3 tests/apk_fixtures.py <folder>
good.apk (v2 block + v1 files, arm64 + armv7), unsigned.apk, bundle.aab, cut.apk (cut off), page.apk (a web page), arm32only.apk"""
import os, struct, sys, zlib

def zipfile_bytes(files, sig_block=False):
    out = b''; cd = b''
    for name, data in files:
        nb = name.encode(); crc = zlib.crc32(data) & 0xffffffff; off = len(out)
        out += struct.pack('<IHHHHHIIIHH', 0x04034b50, 20, 0, 0, 0, 0, crc, len(data), len(data), len(nb), 0) + nb + data
        cd += struct.pack('<IHHHHHHIIIHHHHHII', 0x02014b50, 20, 20, 0, 0, 0, 0, crc, len(data), len(data), len(nb), 0, 0, 0, 0, 0, off) + nb
    if sig_block:
        body = b'\x00' * 16 + struct.pack('<Q', 40) + b'APK Sig Block 42'
        out += struct.pack('<Q', 40) + body[16:]   # size + pairs(none) + size + magic (shape only; the page checks the magic)
        out = out[:len(out) - 24] + struct.pack('<Q', 24) + struct.pack('<Q', 24) + b'APK Sig Block 42'
    cd_off = len(out)
    return out + cd + struct.pack('<IHHHHIIH', 0x06054b50, 0, 0, len(files), len(files), len(cd), cd_off, 0)

base = [('AndroidManifest.xml', b'x' * 200), ('classes.dex', b'd' * 5000), ('resources.arsc', b'r' * 300)]
libs = [('lib/arm64-v8a/libapp.so', b's' * 900), ('lib/armeabi-v7a/libapp.so', b's' * 900)]
v1 = [('META-INF/CERT.SF', b'sf'), ('META-INF/CERT.RSA', b'rsa')]
d = sys.argv[1]; os.makedirs(d, exist_ok=True)
w = lambda n, b: open(os.path.join(d, n), 'wb').write(b)
good = zipfile_bytes(base + libs + v1, sig_block=True)
w('good.apk', good)
w('unsigned.apk', zipfile_bytes(base + libs))
w('bundle.aab', zipfile_bytes([('BundleConfig.pb', b'b'), ('base/manifest/AndroidManifest.xml', b'm'), ('base/dex/classes.dex', b'd')]))
w('cut.apk', good[:int(len(good) * 0.8)])
w('page.apk', b'<!DOCTYPE html><html><body>403 Forbidden</body></html>')
w('arm32only.apk', zipfile_bytes(base + [libs[1]] + v1))
