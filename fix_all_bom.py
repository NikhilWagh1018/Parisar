import os
files = ['pages/profile.php','pages/segment.php','pages/report.php','pages/form.php']
for path in files:
    if os.path.exists(path):
        with open(path,'rb') as f:
            data = f.read()
        if data.startswith(b'\xef\xbb\xbf'):
            with open(path,'wb') as f:
                f.write(data[3:])
            print(f"BOM stripped: {path}")
        else:
            print(f"Clean: {path}")
