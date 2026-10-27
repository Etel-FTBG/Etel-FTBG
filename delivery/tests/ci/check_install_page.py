"""Guards the install page (install.html): the all-in-one ETEL app is the first download, it points at apk/ኢE_Etel.apk (the file the owner uploads from the Expo build, named like the other company apps),
the per-company downloads are still there, and the in-app download rows know the 'etel' role."""
root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
html = read('install.html')
if 'href="apk/ኢE_Etel.apk"' not in html:
    bad.append('install.html must link apk/ኢE_Etel.apk')
elif html.index('apk/ኢE_Etel.apk') > html.index('apk/ኢE_Delivery.apk'):
    bad.append('the all-in-one ETEL app must come before the single-company downloads')
for f in ('ኢE_Delivery.apk', 'ኢE_Driver.apk', 'ኢE_Courier.apk', 'ኢE_Service.apk', 'ኢE_Ride.apk'):
    if 'apk/' + f not in html:
        bad.append('install.html lost the download for ' + f)
if "etel:     'ኢE_Etel.apk'" not in read('js/apk-download-badge.js'):
    bad.append("js/apk-download-badge.js lost the 'etel' role")
if bad:
    print('check_install_page: FAIL')
    for b in bad:
        print('  -', b)
    raise SystemExit(1)
print('check_install_page: ok')
