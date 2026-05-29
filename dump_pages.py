import os
pages = ['dashboard.php','segment.php','results.php','report.php','profile.php']
for p in pages:
    path = f'pages/{p}'
    if os.path.exists(path):
        f = open(path, 'rb')
        c = f.read().decode('utf-8')
        f.close()
        with open(f'dump_{p}.txt', 'wb') as out:
            out.write(c.encode('utf-8'))
        print(f"OK: {p} ({len(c)} chars)")
    else:
        print(f"MISSING: {p}")
