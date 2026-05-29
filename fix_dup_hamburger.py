import re
f = open('pages/dashboard.php', 'rb')
c = f.read().decode('utf-8')
f.close()

# Remove the duplicate floating hamburger on the right
c, n = re.subn(r'\s*<button class="sb-hamburger" id="sb-toggle"[^>]*>&#9776;</button>\s*(?=\n.*</body>)', '', c, count=1)
print(f"Duplicate hamburger removed: {n}")

f = open('pages/dashboard.php', 'wb')
f.write(c.encode('utf-8'))
f.close()
