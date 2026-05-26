# Deploy guide — cumstaicubanii.ro

Producția rulează pe `apache.ro` (HestiaCP), serviciu Apache + nginx în față, PHP 8.3 FPM, MariaDB 11.8.

## Arhitectură pe server

```
/home/cscb/
├── web/cumstaicubanii.ro/
│   ├── public_html/                ← DocumentRoot (servit de nginx + Apache)
│   │   ├── index.html, assets/, *.png, og-image.png
│   │   ├── .htaccess               (SPA fallback la index.html, cache control)
│   │   └── api -> ../private/repo/backend/api    (symlink)
│   ├── private/repo/               ← git checkout (out of web-tree)
│   │   ├── backend/
│   │   │   ├── api/                ← servit via symlink, executat de PHP-FPM
│   │   │   ├── db.php
│   │   │   └── config.local.php    (gitignored; conține DB credentials)
│   │   └── frontend/dist/          ← build output, rsync-uit în public_html
│   └── conf/                       (HestiaCP-managed Apache/nginx vhosts)

/home/deployer/.ssh/authorized_keys ← ForceCommand=sudo /usr/local/bin/deploy-cscb.sh
/usr/local/bin/deploy-cscb.sh       ← scriptul real de deploy (logat în /var/log/deploy-cscb.log)
/etc/sudoers.d/deployer-cscb        ← deployer NOPASSWD doar pentru scriptul ăla
```

## Auto-deploy (CI/CD)

`push origin main` → GitHub Actions (`.github/workflows/deploy.yml`) → SSH la server ca user `deployer` → ForceCommand execută `/usr/local/bin/deploy-cscb.sh`.

Script-ul de deploy face:
1. `sudo -Hu cscb git pull --ff-only origin main`
2. `sudo -Hu cscb npm ci && npm run build` în `frontend/`
3. `rsync -a --delete --exclude=api --exclude=.htaccess frontend/dist/ public_html/`
4. `chown -R cscb:www-data public_html/`

**Output și loguri:** vizibile live în GitHub Actions UI; persistente în `/var/log/deploy-cscb.log`.

**Concurrency:** workflow-ul are `concurrency.group: deploy-production` cu `cancel-in-progress: false` → push-uri rapide se serializează (nu rulează 2 deploy-uri simultan).

**Model de securitate:**
- Cheia SSH a deployer-ului e folosită doar de Actions. Pe server e doar publica (în `authorized_keys`).
- `ForceCommand` în `authorized_keys` ignoră orice comandă cere clientul SSH — executa NUMAI scriptul de deploy.
- Sudoers-ul deployer-ului are `NOPASSWD` doar pentru `/usr/local/bin/deploy-cscb.sh` și nimic altceva. Niciun shell, niciun acces la DB, niciun acces de scriere în alte dirs.

## Deploy manual (cu sudo, ca user `robert`)

Util pentru emergențe (dacă Actions e jos) sau pentru rollback:

```bash
ssh robert@apache.ro -p 2293
sudo /usr/local/bin/deploy-cscb.sh
```

## Rollback la o versiune anterioară

```bash
ssh robert@apache.ro -p 2293
# 1. resetează repo-ul la SHA-ul dorit
sudo -Hu cscb git -C /home/cscb/web/cumstaicubanii.ro/private/repo reset --hard <SHA>
# 2. re-execută build + sync (același script ca la deploy normal)
sudo /usr/local/bin/deploy-cscb.sh
```

(Există și opțiunea de revert public via `git revert` push → Actions face restul.)

## Schema DB / migrări

Schema curentă: `backend/schema.sql` cu `CREATE TABLE IF NOT EXISTS`. **Nu** se rulează automat la deploy. Pentru schimbări:

```bash
ssh robert@apache.ro -p 2293
sudo mariadb -ucscb_dbuser -p<DB_PASS> cscb_db < /home/cscb/web/cumstaicubanii.ro/private/repo/backend/schema.sql
# pentru ALTER TABLE / coloane noi, scrie manual SQL-ul și execută-l
```

Pentru un sistem propriu de migrări (când vom avea schimbări frecvente), plan: `backend/migrations/NNNN_descriere.sql` numerotat, plus o tabelă `schema_migrations` care reține ce s-a aplicat.

## Setări de bază HestiaCP (one-time, deja făcute)

```bash
# user + domeniu + PHP 8.3 + Let's Encrypt + DB
sudo v-add-user cscb <pass> webmaster@cumstaicubanii.ro default "Cum Stai cu Banii"
sudo v-add-web-domain cscb cumstaicubanii.ro
sudo v-change-web-domain-backend-tpl cscb cumstaicubanii.ro PHP-8_3
sudo v-add-letsencrypt-domain cscb cumstaicubanii.ro www.cumstaicubanii.ro
sudo v-add-web-domain-ssl-force cscb cumstaicubanii.ro
sudo v-add-database cscb db dbuser <db_pass> mysql
# = creează DB `cscb_db` cu user `cscb_dbuser`
```

## Secrets GitHub Actions

`https://github.com/robert-schmidt/cumstaicubanii.ro/settings/secrets/actions`:

| Secret    | Valoare       |
|-----------|---------------|
| `SSH_HOST`| `apache.ro`   |
| `SSH_PORT`| `2293`        |
| `SSH_USER`| `deployer`    |
| `SSH_KEY` | private key ed25519 (`-----BEGIN OPENSSH PRIVATE KEY-----` … `-----END OPENSSH PRIVATE KEY-----`) |

Pentru a rotaționa cheia: generează una nouă pe server (`sudo -u deployer ssh-keygen -t ed25519 -f /home/deployer/.ssh/gh_actions_new -N ""`), actualizează `authorized_keys` cu publica, copiază privata în GitHub secret, șterge fișierul privat de pe server.

## Debugging

```bash
# logul deploy-urilor
sudo tail -50 /var/log/deploy-cscb.log

# PHP errors
sudo tail -50 /var/log/apache2/domains/cumstaicubanii.ro.error.log

# nginx access
sudo tail -50 /var/log/apache2/domains/cumstaicubanii.ro.log

# DB shell
sudo mariadb -ucscb_dbuser -p<db_pass> cscb_db
```
