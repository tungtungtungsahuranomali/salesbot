# WuzAPI — WhatsApp Gateway API

**Server:** `http://202.8.28.198:3000`
**Admin Token:** `admin123`

---

## Daftar Isi
1. [Akses Web Dashboard](#1-akses-web-dashboard)
2. [Buat User & Scan QR](#2-buat-user--scan-qr)
3. [Kirim Pesan](#3-kirim-pesan)
4. [Terima Pesan & Location (Webhook)](#4-terima-pesan--location-webhook)
5. [Manajemen Session](#5-manajemen-session)
6. [API Endpoints Lengkap](#6-api-endpoints-lengkap)
7. [Cheat Sheet CURL](#7-cheat-sheet-curl)

---

## 1. Akses Web Dashboard

Buka browser: **http://202.8.28.198:3000**

Login pakai **Admin Token** → `admin123`

Dari dashboard bisa:
- Buat user baru
- Connect/scan QR
- Lihat status session
- Logout

---

## 2. Buat User & Scan QR

### Via Web Dashboard
1. Buka `http://202.8.28.198:3000`
2. Login dengan `admin123`
3. Klik **Add User** → isi nama & token (token bebas, simpan untuk API calls)
4. Klik **Connect** → muncul QR code
5. Buka WhatsApp > Linked Devices > Link a Device
6. Scan QR — status berubah jadi **Connected**

### Via API
```bash
# Buat user baru
curl -X POST http://202.8.28.198:3000/admin/users \
  -H "Authorization: admin123" \
  -H "Content-Type: application/json" \
  -d '{"name":"bot1","token":"token_bot1"}'

# List semua user
curl http://202.8.28.198:3000/admin/users \
  -H "Authorization: admin123"

# Connect session (muncul QR)
curl -X POST http://202.8.28.198:3000/session/connect \
  -H "Authorization: token_bot1"

# Ambil QR code (base64 PNG)
curl http://202.8.28.198:3000/session/qr \
  -H "Authorization: token_bot1"

# Cek status session
curl http://202.8.28.198:3000/session/status \
  -H "Authorization: token_bot1"
```

**Response session status:**
```json
{
  "status": "Connected",
  "LoggedIn": true,
  "phone": "6281xxxxxxxx"
}
```

---

## 3. Kirim Pesan

Gunakan token user (bukan admin token) untuk semua endpoint chat.

### Text Message
```bash
curl -X POST http://202.8.28.198:3000/chat/send/text \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "text":"Halo, ini pesan dari API"
  }'
```

> **Format JID:** `62xxx@s.whatsapp.net` (no HP tanpa `+`, pakai kode negara)

### Image
```bash
curl -X POST http://202.8.28.198:3000/chat/send/image \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "image":"https://example.com/photo.jpg",
    "caption":"Foto dari API"
  }'
```

### Document
```bash
curl -X POST http://202.8.28.198:3000/chat/send/document \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "document":"https://example.com/file.pdf",
    "filename":"laporan.pdf"
  }'
```

### Video
```bash
curl -X POST http://202.8.28.198:3000/chat/send/video \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "video":"https://example.com/video.mp4",
    "caption":"Video"
  }'
```

### Audio
```bash
curl -X POST http://202.8.28.198:3000/chat/send/audio \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "audio":"https://example.com/audio.ogg"
  }'
```

### Sticker
```bash
curl -X POST http://202.8.28.198:3000/chat/send/sticker \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "sticker":"https://example.com/sticker.webp"
  }'
```

### Location
```bash
curl -X POST http://202.8.28.198:3000/chat/send/location \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "latitude":-0.026330,
    "longitude":109.342509,
    "name":"Pontianak"
  }'
```

### Contact
```bash
curl -X POST http://202.8.28.198:3000/chat/send/contact \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "contact":"6281xxxxxxx"
  }'
```

### Template Message (Tombol)
```bash
curl -X POST http://202.8.28.198:3000/chat/send/template \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "jid":"6281xxxxxxx@s.whatsapp.net",
    "template":"Apakah kamu suka?",
    "buttons":["👍 Ya","👎 Tidak"]
  }'
```

### Group Management
```bash
# List grup
curl http://202.8.28.198:3000/group/list \
  -H "Authorization: token_bot1"

# Buat grup baru
curl -X POST http://202.8.28.198:3000/group/create \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "subject":"Grup Baru",
    "participants":["6281xxxxxxx@s.whatsapp.net"]
  }'

# Info grup
curl "http://202.8.28.198:3000/group/info?jid=xxxx-group@g.us" \
  -H "Authorization: token_bot1"
```

---

## 4. Terima Pesan & Location (Webhook)

WuzAPI bisa kirim notifikasi ke server kamu setiap ada pesan **masuk** (text, location, image, dll).

### 4.1 Setup Webhook

Set webhook URL via dashboard atau API:

```bash
curl -X POST http://202.8.28.198:3000/webhook \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{
    "url":"https://serverkamu.com/webhook-wa",
    "events":["Message"]
  }'
```

**Available events:**
| Event | Deskripsi |
|-------|-----------|
| `Message` | Semua pesan masuk (text, location, image, video, audio, document, dll) |
| `ReadReceipt` | Centang biru / notifikasi dibaca |
| `HistorySync` | Sinkronisasi histori chat |
| `ChatPresence` | Status online/typing |

### 4.2 Format Webhook — Text Message

Saat ada pesan text masuk, server kamu akan menerima POST:

```json
{
  "event": {
    "Info": {
      "Id": "ABGGk09....",
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "FromMe": false,
      "PushName": "Nama Kontak",
      "Timestamp": 1712345678,
      "MessageType": "Conversation"
    },
    "Message": {
      "Conversation": "Halo, ini pesan masuk"
    }
  }
}
```

### 4.3 Format Webhook — Location (Share Loc)

Saat seseorang **share location real-time** atau kirim lokasi:

```json
{
  "event": {
    "Info": {
      "Id": "ABGGk09....",
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "FromMe": false,
      "PushName": "Nama Kontak",
      "Timestamp": 1712345678,
      "MessageType": "LocationMessage"
    },
    "Message": {
      "LocationMessage": {
        "degreesLatitude": -0.026330,
        "degreesLongitude": 109.342509,
        "degreesAccuracy": 10.0,
        "comment": "Lokasi saya disini",
        "name": "Rumah",
        "jpegThumbnail": "/9j/4AAQ..."
      }
    }
  }
}
```

**Field location yang diterima:**
| Field | Tipe | Keterangan |
|-------|------|------------|
| `degreesLatitude` | float | Latitude |
| `degreesLongitude` | float | Longitude |
| `degreesAccuracy` | float | Akurasi (meter) |
| `comment` | string | Pesan/keterangan |
| `name` | string | Nama lokasi (jika ada) |
| `jpegThumbnail` | string | Thumbnail base64 (jika ada) |

> **Catatan:** Live location juga masuk sebagai `LocationMessage`, bedanya ada field `degreesAccuracy` untuk akurasi.

### 4.4 Format Webhook — Image

```json
{
  "event": {
    "Info": {
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "MessageType": "ImageMessage"
    },
    "Message": {
      "ImageMessage": {
        "url": "https://mmg.whatsapp.net/...",
        "caption": "Foto ini",
        "mimetype": "image/jpeg",
        "fileLength": 123456
      }
    }
  }
}
```

### 4.5 Format Webhook — Video

```json
{
  "event": {
    "Info": {
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "MessageType": "VideoMessage"
    },
    "Message": {
      "VideoMessage": {
        "url": "https://mmg.whatsapp.net/...",
        "caption": "Video",
        "mimetype": "video/mp4",
        "seconds": 30,
        "fileLength": 123456
      }
    }
  }
}
```

### 4.6 Format Webhook — Audio

```json
{
  "event": {
    "Info": {
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "MessageType": "AudioMessage"
    },
    "Message": {
      "AudioMessage": {
        "url": "https://mmg.whatsapp.net/...",
        "mimetype": "audio/ogg; codecs=opus",
        "seconds": 15,
        "fileLength": 12345
      }
    }
  }
}
```

### 4.7 Format Webhook — Document

```json
{
  "event": {
    "Info": {
      "RemoteJid": "6281xxxxxxx@s.whatsapp.net",
      "MessageType": "DocumentMessage"
    },
    "Message": {
      "DocumentMessage": {
        "url": "https://mmg.whatsapp.net/...",
        "mimetype": "application/pdf",
        "title": "laporan.pdf",
        "fileLength": 54321,
        "pageCount": 5
      }
    }
  }
}
```

---

## 5. Manajemen Session

```bash
# Status koneksi
curl http://202.8.28.198:3000/session/status \
  -H "Authorization: token_bot1"

# Disconnect (tetap login, reconnect cepat)
curl -X POST http://202.8.28.198:3000/session/disconnect \
  -H "Authorization: token_bot1"

# Logout (hapus session, harus scan QR lagi)
curl -X POST http://202.8.28.198:3000/session/logout \
  -H "Authorization: token_bot1"

# Hapus user
curl -X DELETE http://202.8.28.198:3000/admin/users/1 \
  -H "Authorization: admin123"

# Cek kontak
curl http://202.8.28.198:3000/user/contacts \
  -H "Authorization: token_bot1"

# Cek apakah nomor terdaftar di WhatsApp
curl -X POST http://202.8.28.198:3000/user/check \
  -H "Authorization: token_bot1" \
  -H "Content-Type: application/json" \
  -d '{"phones":["6281xxxxxxx"]}'
```

---

## 6. API Endpoints Lengkap

### 🔐 Admin (pakai `admin123`)
| Method | Path | Fungsi |
|--------|------|--------|
| `GET` | `/admin/users` | List semua user |
| `POST` | `/admin/users` | Buat user baru |
| `DELETE` | `/admin/users/{id}` | Hapus user |

### 🔄 Session (pakai token user)
| Method | Path | Fungsi |
|--------|------|--------|
| `POST` | `/session/connect` | Connect WhatsApp |
| `POST` | `/session/disconnect` | Disconnect (simpan session) |
| `POST` | `/session/logout` | Logout (hapus session) |
| `GET` | `/session/status` | Cek status |
| `GET` | `/session/qr` | Ambil QR code |

### 📨 Kirim Pesan (pakai token user)
| Method | Path | Fungsi |
|--------|------|--------|
| `POST` | `/chat/send/text` | Kirim text |
| `POST` | `/chat/send/image` | Kirim image |
| `POST` | `/chat/send/video` | Kirim video |
| `POST` | `/chat/send/audio` | Kirim audio |
| `POST` | `/chat/send/document` | Kirim document |
| `POST` | `/chat/send/sticker` | Kirim sticker |
| `POST` | `/chat/send/template` | Kirim template (tombol) |
| `POST` | `/chat/send/location` | Kirim lokasi |
| `POST` | `/chat/send/contact` | Kirim kontak |

### 📥 Webhook (pakai token user)
| Method | Path | Fungsi |
|--------|------|--------|
| `POST` | `/webhook` | Set webhook URL |
| `GET` | `/webhook` | Lihat webhook config |

### 👤 User Info (pakai token user)
| Method | Path | Fungsi |
|--------|------|--------|
| `POST` | `/user/info` | Info user |
| `POST` | `/user/check` | Cek nomor WA |
| `GET` | `/user/contacts` | Daftar kontak |
| `GET` | `/user/avatar` | Foto profil |

### 👥 Group (pakai token user)
| Method | Path | Fungsi |
|--------|------|--------|
| `GET` | `/group/list` | List grup |
| `POST` | `/group/create` | Buat grup |
| `GET` | `/group/info` | Info grup |
| `GET` | `/group/invitelink` | Link undangan |

---

## 7. Cheat Sheet CURL

```bash
# VARIABEL
BASE="http://202.8.28.198:3000"
ADMIN="admin123"
TOKEN="token_bot1"
JID="6281xxxxxxx@s.whatsapp.net"

# ADMIN - Buat user
curl -X POST "$BASE/admin/users" \
  -H "Authorization: $ADMIN" \
  -H "Content-Type: application/json" \
  -d '{"name":"bot1","token":"token_bot1"}'

# Connect & scan QR
curl -X POST "$BASE/session/connect" -H "Authorization: $TOKEN"

# Cek status
curl "$BASE/session/status" -H "Authorization: $TOKEN"

# Kirim text
curl -X POST "$BASE/chat/send/text" \
  -H "Authorization: $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"jid\":\"$JID\",\"text\":\"Halo dari API\"}"

# Kirim lokasi
curl -X POST "$BASE/chat/send/location" \
  -H "Authorization: $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"jid\":\"$JID\",\"latitude\":-0.026330,\"longitude\":109.342509}"

# Set webhook
curl -X POST "$BASE/webhook" \
  -H "Authorization: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://serverkamu.com/webhook","events":["Message"]}'

# Logout
curl -X POST "$BASE/session/logout" -H "Authorization: $TOKEN"
```

---

## Informasi Server

| Item | Value |
|------|-------|
| IP | `202.8.28.198` |
| Port WuzAPI | `3000` |
| Admin Token | `admin123` |
| Dashboard | http://202.8.28.198:3000 |
| Service | systemd: `wuzapi` |
| Binary | `/opt/wuzapi/wuzapi` |
| Data | `/opt/wuzapi/data` |
| Restart | `sudo systemctl restart wuzapi` |
| Log | `sudo journalctl -u wuzapi.service -n 50 --no-pager` |

### SSH Connection
```powershell
& "C:\Windows\System32\OpenSSH\ssh.exe" -i id_ed25519_new ubuntu@202.8.28.198
```

### Docker (sudah dimatikan)
```bash
sudo systemctl stop docker
sudo systemctl disable docker
```
