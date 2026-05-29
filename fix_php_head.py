import re
f = open('pages/dashboard.php', 'rb')
c = f.read().decode('utf-8')
f.close()

# Remove the misplaced theme.css link that ended up before <?php
c = c.replace('<meta charset="UTF-8">\n  <link rel="stylesheet" href="../css/theme.css">\n<meta', '<meta charset="UTF-8">\n  <link rel="stylesheet" href="../css/theme.css">\n  <meta')

# The real issue: link was inserted before <?php - check if <?php is still first
if not c.startswith('<?php'):
    # Find and move theme link to correct position inside <head>
    c = c.replace('\n  <link rel="stylesheet" href="../css/theme.css">', '')
    c = c.replace('<meta name="viewport"', '<link rel="stylesheet" href="../css/theme.css">\n  <meta name="viewport"')
    print("Moved theme.css link to correct position")

# Verify <?php is first
print("Starts with php:", c[:5])
print("Line 1:", c.splitlines()[0])
print("Line 2:", c.splitlines()[1])

f = open('pages/dashboard.php', 'wb')
f.write(c.encode('utf-8'))
f.close()
print("Done")
