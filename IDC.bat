@echo off
echo Starting SSH...
where ssh
"C:\Windows\System32\OpenSSH\ssh.exe" -i id_ed25519_new ubuntu@202.8.28.198
pause
