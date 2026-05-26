# Cum stai cu banii? — datorii vs asset-uri

Aplicație web anonimă unde introduci datoriile și asset-urile tale și afli unde te plasezi față de ceilalți utilizatori — pe medii, percentile, distribuții pe județ / domeniu ocupațional / persoane în întreținere și corelația dintre optimism financiar și net worth real.

🌐 **Live**: [cumstaicubanii.ro](https://cumstaicubanii.ro)
📦 **Repo**: [github.com/robert-schmidt/cumstaicubanii.ro](https://github.com/robert-schmidt/cumstaicubanii.ro)

## Stack

- **Frontend**: React 19 · Vite 6 · Tailwind 4 · Recharts · framer-motion · react-router
- **Backend**: PHP 8.3 simplu (REST JSON), fără framework, PDO cu prepared statements
- **DB**: MariaDB 11.8 cu InnoDB / utf8mb4
- **Containerizare**: docker compose pentru development
- **Auth**: nici una. UUID anonim în `localStorage`, plus un cod de sesiune scurt (8 chars) generat server-side pentru re-login pe alt dispozitiv
- **Tracking, cookies, analytics**: nimic, niciodată

## Structură

```
docker-compose.yml             # orchestrare dev: db + backend + frontend
backend/
  Dockerfile                   # PHP 8.3 + pdo_mysql + mariadb-client
  db.php                       # PDO + whitelist constante + helpers
  schema.sql                   # tabele MariaDB (rulat automat la initdb)
  seed.sql                     # ~50 submit-uri demo (rulat automat la initdb)
  seed_generator.php           # regenerează seed.sql
  config.local.php.example     # template pentru config prod (gitignored real)
  api/
    meta.php                   # GET — whitelist-uri tipuri / județe / domenii
    submit.php                 # POST — primește un submit, returnează session_id
    stats.php                  # GET — statistici agregate, filtre opționale
  index.php                    # landing simplu pentru :8000 dev
frontend/
  index.html                   # SPA shell + OG/Twitter meta tags
  vite.config.js
  src/
    main.jsx, App.jsx, index.css
    pages/FormPage.jsx
    pages/DashboardPage.jsx
    lib/{api,identity,format}.js
  public/
    favicon.svg + PNG variants
    og-image.png (1200×630, share preview)
```

## Rulare locală (docker compose)

```bash
docker compose up --build
```

Deschide [http://localhost:5173](http://localhost:5173). Trei servicii pornesc:

| Serviciu  | Imagine                  | Expus pe host | Note |
|-----------|--------------------------|---------------|------|
| `db`      | `mariadb:11.8`           | doar intern   | volum persistent `db_data`; schema + seed se aplică automat la prima inițializare |
| `backend` | `php:8.3-cli-alpine` + `pdo_mysql` | doar intern | server dev pe `0.0.0.0:8000`; bind mount pe `./backend` |
| `frontend`| `node:22-alpine`         | `:5173`       | Vite cu HMR; `npm install` rulează la primul start |

Comenzi utile:

```bash
docker compose down                       # oprire (păstrează datele)
docker compose down -v                    # oprire + șterge volumele (reset DB)
docker compose logs -f backend            # log-uri PHP
docker compose exec db mariadb -udatorii -pdatorii_secret -D datorii   # shell SQL
docker compose exec backend php /app/seed_generator.php > backend/seed.sql   # regen seed
```

> ⚠️ Parolele din `docker-compose.yml` (`MARIADB_ROOT_PASSWORD`, `MARIADB_PASSWORD`) sunt valori dev. **Nu** le folosi în producție.

### Fără Docker

Ai nevoie de MariaDB/MySQL local. Creează DB-ul, importă schema + seed, apoi rulează PHP-ul cu env vars:

```bash
mysql -uroot -e "CREATE DATABASE datorii CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER 'datorii'@'localhost' IDENTIFIED BY 'datorii_secret';
                  GRANT ALL ON datorii.* TO 'datorii'@'localhost';"
mysql -udatorii -pdatorii_secret -D datorii < backend/schema.sql
mysql -udatorii -pdatorii_secret -D datorii < backend/seed.sql

DB_HOST=127.0.0.1 DB_USER=datorii DB_PASS=datorii_secret \
  php -S 127.0.0.1:8000 -t backend

cd frontend && npm install && npm run dev
```

## API

### `GET /api/meta.php`
Returnează whitelist-urile (tipuri datorii/asset, județe, domenii, sexe).

### `POST /api/submit.php`
```jsonc
{
  "uuid": "abc-123-…",                // client-generated, [0-9a-f-]{16,64}
  "optimist": true,
  "judet": "Cluj",                    // opțional, whitelist
  "varsta": 32,                       // opțional, 14-110
  "sex": "M",                         // opțional, M/F/X
  "persoane_intretinere": 1,          // opțional, 0-20
  "domeniu": "IT & Software",         // opțional, whitelist + text liber la "Altele"
  "entries": [
    { "kind": "datorie", "type": "credit personal", "amount": 15000 },
    { "kind": "asset",   "type": "depozite bancare", "amount": 50000 }
  ]
}
```
Returnează `{ ok, submission_id, session_id }`. `session_id` e cod de 8 chars `[a-z2-9]` (alfabet fără caractere ambigue), unic per submission, folosit pentru re-login.

### `GET /api/stats.php`
Query params (toate opționale):
- `sid` — cod de sesiune 8 chars (prioritar la lookup user)
- `uuid` — fallback dacă nu există sid
- `judet`, `sex`, `age_group` (`14-24`, `25-34`, ..., `65+`) — filtrează `population` și `breakdown`

Răspuns:
- `population` — counts + medii + mediane (integer RON) pe populația filtrată
- `breakdown.{datorii,asset}` — agregate pe tip
- `by_judet` — distribuție globală pe județ
- `by_domeniu` — distribuție globală pe domeniu
- `by_persoane_intretinere` — distribuție pe bucket-uri 0/1/2/3/4+
- `optimism.{optimist,pesimist}` — counts + medii + mediane net worth
- `user` — secțiunea personală: net worth, percentile, distribuții pe tip (apare doar dacă `sid` sau `uuid` găsesc un submit)
- `meta` — whitelist-uri (idem ca `meta.php`)

## Cod de sesiune (8 caractere)

La fiecare submit, backend-ul generează un cod scurt `[a-z2-9]{8}` (ex. `upbah96y`) — alfabetul exclude `0/1/o/l/i` ca să fie ușor de dictat la telefon. Frontend-ul îl salvează în `localStorage` și-l afișează ca un buton flotant în colțul stânga-jos (click → copy în clipboard).

Pe pagina formularului există un panou colapsabil "Ai un cod de sesiune?" — paste + Enter → fetch validare → dacă există, navighează direct la dashboard. Util pentru re-login de pe alt dispozitiv sau după ce ai șters localStorage-ul.

## Configurare producție

În producție, parolele de DB **nu** vin din `docker-compose.yml` sau env vars Apache (ar fi expuse). Backend-ul caută `backend/config.local.php` (gitignored) și-l încarcă dacă există — valorile de acolo prevalează peste env vars.

```bash
cp backend/config.local.php.example backend/config.local.php
# editează valorile, restrânge permisiunile:
chmod 640 backend/config.local.php
```

Vezi secțiunea **[Deploy](#deploy-hestiacp--apache--mariadb)** mai jos pentru pașii completi.

## Deploy (HestiaCP + Apache + MariaDB)

Aplicația rulează pe `cumstaicubanii.ro` cu HestiaCP. Vezi `DEPLOY.md` pentru pașii detaliați (creare cont, SSL Let's Encrypt, DB MariaDB, deploy key pentru `git pull`, build & symlinks).

## Licență

© cumstaicubanii.ro. Reproducerea fără acordul explicit sau menționarea în clar a sursei este interzisă.
