# 🔌 Panduan Akses Terminal cPanel via SSH untuk AI Agent

Dokumen ini menjelaskan **cara AI agent (Claude/dsb) mengakses terminal cPanel shared hosting secara penuh** menggunakan Python + Paramiko, lengkap dengan tutorial, requirements, dan contoh penggunaan.

> **Konteks:** Dibuat saat deploy project **SIKLAS-NB** ke cPanel `pinnhost.my.id`. Metode ini bekerja pada shared hosting yang **membatasi SSH port 22** tetapi membuka port alternatif.

---

## 📑 Daftar Isi

1. [Kenapa Metode Ini Diperlukan](#1-kenapa-metode-ini-diperlukan)
2. [Requirements](#2-requirements)
3. [Informasi yang Dibutuhkan](#3-informasi-yang-dibutuhkan)
4. [Instalasi](#4-instalasi)
5. [Menemukan Port SSH](#5-menemukan-port-ssh)
6. [Helper Script SSH](#6-helper-script-ssh-lengkap)
7. [Cara Penggunaan](#7-cara-penggunaan)
8. [Contoh Kasus Nyata](#8-contoh-kasus-nyata)
9. [Troubleshooting](#9-troubleshooting)
10. [Keamanan](#10-keamanan-penting)

---

## 1. Kenapa Metode Ini Diperlukan

### Masalah
- **cPanel Terminal (web-based)** tidak bisa diotomasi oleh AI agent — harus copy-paste manual.
- **`ssh` command biasa** butuh prompt password interaktif → tidak bisa dijalankan otomatis oleh script.
- **`sshpass`** (tool untuk pass password non-interaktif) **tidak tersedia di Windows** Git Bash.
- Banyak shared hosting **memblokir port 22** dan memakai port alternatif (mis. `6699`, `2222`).

### Solusi
Gunakan **Python + Paramiko** — library SSH murni Python yang:
- ✅ Bisa passing password langsung tanpa prompt
- ✅ Jalan di Windows, Linux, macOS
- ✅ Support SSH (jalankan perintah) **dan** SFTP (upload/download file)
- ✅ Bisa dipanggil AI agent lewat Bash/PowerShell tool

```
┌──────────────────────┐         ┌─────────────────────────────┐
│  AI Agent (lokal)    │         │  cPanel Server              │
│  ┌────────────────┐  │  SSH    │  ┌───────────────────────┐  │
│  │ Python+Paramiko│──┼─────────┼─▶│ Terminal (full akses) │  │
│  │ exec_command() │◀─┼─output──┼──│ php, composer, mysql  │  │
│  │ sftp.put()     │──┼─SFTP────┼─▶│ upload file           │  │
│  └────────────────┘  │         │  └───────────────────────┘  │
└──────────────────────┘         └─────────────────────────────┘
```

---

## 2. Requirements

| Komponen | Versi | Fungsi |
|---|---|---|
| **Python** | 3.7+ | Runtime untuk paramiko |
| **paramiko** | 3.x / 5.x | Library SSH & SFTP |
| **Akses SSH cPanel** | — | Harus **diaktifkan** di cPanel (lihat §3) |

### Dependency paramiko (otomatis terinstall)
- `bcrypt` — hashing
- `pynacl` — kriptografi
- `cryptography` — enkripsi SSH

---

## 3. Informasi yang Dibutuhkan

Kumpulkan data ini dari penyedia hosting / cPanel Anda:

| Data | Contoh | Cara Dapat |
|---|---|---|
| **Host / IP** | `195.88.211.25` atau `domain.com` | Email welcome hosting / cPanel → sidebar kanan "Shared IP Address" |
| **Port SSH** | `6699` | Tanya support / scan port (lihat §5) |
| **Username** | `pinnhost` | Username login cPanel |
| **Password** | `EzA%=...` | Password login cPanel |
| **Home dir** | `/home/pinnhost` | Biasanya `/home/<username>` |

### ⚠️ Pastikan SSH Sudah Aktif
Di sebagian hosting, SSH **harus diaktifkan dulu**:
- cPanel → cari menu **"SSH Access"** atau **"Terminal"**
- Atau minta support hosting mengaktifkan "SSH/Shell Access"

---

## 4. Instalasi

### Windows (PowerShell / Git Bash)
```bash
# Install paramiko
pip install paramiko

# Jika ada beberapa versi Python, gunakan py launcher:
py -3.10 -m pip install paramiko

# Verifikasi
py -3.10 -c "import paramiko; print('paramiko', paramiko.__version__)"
```

### Linux / macOS
```bash
pip3 install paramiko
python3 -c "import paramiko; print(paramiko.__version__)"
```

---

## 5. Menemukan Port SSH

Jika tidak tahu port SSH, scan port umum cPanel:

```python
# save as: scan_port.py
import socket

HOST = '195.88.211.25'   # ganti dengan IP/domain Anda
PORTS = [22, 6699, 2222, 2200, 2022, 22222]

for p in PORTS:
    try:
        s = socket.create_connection((HOST, p), timeout=8)
        print(f'✅ OPEN: {HOST}:{p}')
        s.close()
    except Exception:
        print(f'❌ CLOSED: {HOST}:{p}')
```

Jalankan:
```bash
py -3.10 scan_port.py
```

Port yang **OPEN** dan bukan 21/2082/2083/443/80 (itu FTP/cPanel/web) biasanya adalah **port SSH**.

---

## 6. Helper Script SSH (Lengkap)

Simpan sebagai `ssh_helper.py`:

```python
"""
SSH Helper — paramiko wrapper untuk akses terminal cPanel.
Password-based auth, non-interaktif, cocok untuk otomasi AI agent.
"""
import paramiko
import sys
import os

# === KONFIGURASI (ganti sesuai hosting Anda) ===
HOST = "195.88.211.25"
PORT = 6699
USER = "pinnhost"
PASS = "PASSWORD_ANDA"
REMOTE_HOME = "/home/pinnhost"


def connect():
    """Buka koneksi SSH."""
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, port=PORT, username=USER, password=PASS, timeout=15)
    return client


def run(client, cmd, timeout=60):
    """Jalankan perintah, return (stdout, stderr, exit_code)."""
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return out, err, code


def upload_file(client, local_path, remote_path):
    """Upload satu file via SFTP."""
    sftp = client.open_sftp()
    sftp.put(local_path, remote_path)
    sftp.close()


def upload_dir(client, local_dir, remote_dir, exclude=None):
    """Upload folder rekursif via SFTP.
    PENTING: replace('\\\\','/') agar path separator Windows jadi Linux."""
    exclude = exclude or set()
    sftp = client.open_sftp()
    for root, dirs, files in os.walk(local_dir):
        dirs[:] = [d for d in dirs if d not in exclude]
        rel = os.path.relpath(root, local_dir).replace("\\", "/")
        rroot = remote_dir if rel == "." else f"{remote_dir}/{rel}"
        try:
            sftp.stat(rroot)
        except FileNotFoundError:
            sftp.mkdir(rroot)
        for f in files:
            if f in exclude:
                continue
            sftp.put(os.path.join(root, f), f"{rroot}/{f}")
    sftp.close()


# === CLI ===
if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "test"
    c = connect()

    if cmd == "test":
        out, err, code = run(c, "echo CONNECTED; hostname; php -v | head -1")
        print(out)
    elif cmd == "run":
        out, err, code = run(c, " ".join(sys.argv[2:]))
        print(out)
        if err.strip():
            print("[ERR]", err, file=sys.stderr)
    elif cmd == "upload":
        upload_file(c, sys.argv[2], sys.argv[3])
        print("Uploaded")
    elif cmd == "uploaddir":
        exc = set(sys.argv[4].split(",")) if len(sys.argv) > 4 else set()
        upload_dir(c, sys.argv[2], sys.argv[3], exc)
        print("Uploaded dir")

    c.close()
```

---

## 7. Cara Penggunaan

### A. Tes Koneksi
```bash
py -3.10 ssh_helper.py test
```
Output:
```
CONNECTED
mten.kencang.id
PHP 8.2.31 (cli) ...
```

### B. Jalankan Perintah Apa Saja
```bash
py -3.10 ssh_helper.py run "ls -la ~/"
py -3.10 ssh_helper.py run "php artisan migrate --force"
py -3.10 ssh_helper.py run "df -h ~/"
```

### C. Upload File
```bash
py -3.10 ssh_helper.py upload "C:\local\.env" "/home/pinnhost/app/.env"
```

### D. Upload Folder
```bash
# uploaddir <lokal> <remote> <exclude1,exclude2,...>
py -3.10 ssh_helper.py uploaddir "C:\project\backend" "/home/pinnhost/app" "vendor,node_modules,.git,.env"
```

### E. Script Kustom (untuk tugas kompleks)
```python
import sys
sys.path.insert(0, r'C:\path\ke\folder')
from ssh_helper import connect, run, upload_file

c = connect()

# Chaining perintah
out, err, code = run(c, 'cd /home/pinnhost/app && composer install --no-dev 2>&1')
print(out)

out, err, code = run(c, 'cd /home/pinnhost/app && php artisan migrate --force 2>&1')
print(out)

c.close()
```

---

## 8. Contoh Kasus Nyata

### Deploy Laravel ke cPanel (yang kita lakukan)
```python
from ssh_helper import connect, run, upload_dir

c = connect()
REMOTE = '/home/pinnhost/siklas.pinnhost.my.id'

# 1. Upload source (tanpa vendor & .env)
upload_dir(c, r'C:\project\backend', REMOTE,
           exclude={'vendor', 'node_modules', '.git', '.env'})

# 2. Install dependency di server
run(c, f'cd {REMOTE} && composer install --no-dev --prefer-dist 2>&1')

# 3. Setup Laravel
run(c, f'cd {REMOTE} && php artisan key:generate --force')
run(c, f'cd {REMOTE} && php artisan migrate --force')
run(c, f'cd {REMOTE} && php artisan db:seed --force')
run(c, f'cd {REMOTE} && php artisan config:cache')

c.close()
```

### Buat Database via cPanel API (UAPI)
```python
# cPanel UAPI bisa dipanggil dari terminal!
run(c, 'uapi Mysql create_database name=user_dbname')
run(c, 'uapi Mysql create_user name=user_dbuser password=SecurePass123')
run(c, 'uapi Mysql set_privileges_on_database '
       'database=user_dbname user=user_dbuser privileges=ALL')
```

### Cek Environment Server
```python
run(c, 'php -v')                          # versi PHP default
run(c, 'ls /opt/alt/php*/usr/bin/php')    # semua versi PHP tersedia
run(c, 'composer --version')
run(c, 'df -h ~/')                        # sisa disk
run(c, 'php -m')                          # extension PHP aktif
```

---

## 9. Troubleshooting

| Masalah | Penyebab | Solusi |
|---|---|---|
| `Unable to connect to port 22` | Port 22 diblokir | Scan port (§5), pakai port alternatif |
| `Authentication failed` | User/pass salah / SSH belum aktif | Cek kredensial, aktifkan SSH Access di cPanel |
| `Socket is closed` | Terlalu banyak perintah 1 koneksi | Buat koneksi baru per batch, kurangi timeout |
| Folder ter-upload jadi `app\Http` (literal backslash) | Path separator Windows | Pakai `.replace("\\","/")` di `upload_dir` (sudah ada di helper) |
| `Class "PDO" not found` | Extension PHP belum aktif | cPanel → "Select PHP Version" → centang extension |
| Perintah `find /` timeout | Scan terlalu luas | Batasi scope: `find ~/ -maxdepth 3` |
| Output kosong tapi HTTP 500 | PHP crash sebelum log | Test bertahap: `hi.php` → autoload → boot |

### Tips Koneksi Stabil
- **Jangan** jalankan puluhan perintah dalam satu koneksi → buka-tutup koneksi per batch.
- Gunakan `timeout` pendek (15–30s) untuk perintah cepat, panjang (300–600s) untuk `composer install`.
- Untuk perintah lama (composer), jalankan **background** atau naikkan timeout.

---

## 10. Keamanan (PENTING)

### ⚠️ Password dalam Plaintext
Helper script menyimpan password dalam file `.py`. Ini **berisiko**:

**Praktik aman:**
1. **Jangan commit** file berisi password ke git:
   ```gitignore
   ssh_helper.py
   *_deploy.py
   .env
   ```
2. **Pakai environment variable** daripada hardcode:
   ```python
   import os
   PASS = os.environ.get('CPANEL_PASS')  # set: export CPANEL_PASS="..."
   ```
3. **Lebih baik: SSH Key** daripada password:
   ```python
   client.connect(HOST, port=PORT, username=USER,
                  key_filename='/path/to/private_key')
   ```
4. **Ganti password** setelah selesai jika sempat ter-share (mis. di chat AI).
5. **Hapus file helper** setelah deploy selesai jika mengandung kredensial.

### Rekomendasi Setup SSH Key (Paling Aman)
```bash
# 1. Generate key di lokal
ssh-keygen -t ed25519 -f ~/.ssh/cpanel_key

# 2. Upload public key ke cPanel:
#    cPanel → SSH Access → Manage SSH Keys → Import
#    (paste isi ~/.ssh/cpanel_key.pub)

# 3. Authorize key tersebut di cPanel

# 4. Connect tanpa password:
#    client.connect(HOST, port=PORT, username=USER,
#                   key_filename=os.path.expanduser('~/.ssh/cpanel_key'))
```

---

## 📌 Ringkasan Cepat

```bash
# 1. Install
pip install paramiko

# 2. Scan port (jika belum tahu)
py -3.10 scan_port.py

# 3. Edit ssh_helper.py (isi HOST, PORT, USER, PASS)

# 4. Tes koneksi
py -3.10 ssh_helper.py test

# 5. Jalankan perintah
py -3.10 ssh_helper.py run "php artisan migrate --force"

# 6. Upload project
py -3.10 ssh_helper.py uploaddir "C:\app" "/home/user/app" "vendor,.git,.env"
```

---

> **Dibuat untuk project SIKLAS-NB** — Sistem Klasifikasi Bantuan Sosial Desa Pangalasiang.
> Metode ini memungkinkan AI agent melakukan deployment penuh ke cPanel shared hosting secara otomatis.
