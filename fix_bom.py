f = open('pages/dashboard.php', 'rb')
c = f.read()
f.close()

# Strip UTF-8 BOM if present
if c.startswith(b'\xef\xbb\xbf'):
    c = c[3:]
    print("BOM stripped")
else:
    print("No BOM found")

f = open('pages/dashboard.php', 'wb')
f.write(c)
f.close()

# Verify
f = open('pages/dashboard.php', 'rb')
first5 = f.read(5)
f.close()
print("First 5 bytes now:", repr(first5))
