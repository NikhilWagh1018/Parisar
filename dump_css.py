import os
css_files = ['dashboard.css','profile.css','segment.css','results.css','report.css','form.css']
for f in css_files:
    path = f'css/{f}'
    if os.path.exists(path):
        fc = open(path,'rb').read().decode('utf-8')
        with open(f'dump_{f}.txt','wb') as out:
            out.write(fc.encode('utf-8'))
        print(f"OK: {f} ({len(fc)} chars)")
    else:
        print(f"MISSING: {f}")
