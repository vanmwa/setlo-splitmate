# Deploying Setlo on Google Cloud (free tier)

Setlo runs on one **Compute Engine e2-micro** VM (part of Google Cloud's Always Free tier) with Apache, PHP and MariaDB — the same stack as XAMPP, on Linux. Phones and tablets open it over HTTPS in the browser (and can install it as a PWA).

**You need:**
- A Google Cloud account with billing enabled. The free tier still requires a card on file.
- The [gcloud CLI](https://cloud.google.com/sdk/docs/install), or use Cloud Shell in the console.
- A Gemini API key from https://aistudio.google.com/apikey.

> Free-tier limits change occasionally. Check https://cloud.google.com/free/docs/free-cloud-features#compute before you start, and set the budget alert in step 9.

---

## 1. Create the VM

Only `us-central1`, `us-west1` and `us-east1` qualify for the free e2-micro.

```sh
gcloud compute instances create setlo \
  --zone=us-central1-a --machine-type=e2-micro \
  --image-family=debian-12 --image-project=debian-cloud \
  --boot-disk-size=30GB --boot-disk-type=pd-standard \
  --tags=web

gcloud compute firewall-rules create setlo-web --allow=tcp:80,tcp:443 --target-tags=web
```

Make the VM's external IP static so it survives a stop/start. Replace `EXTERNAL_IP` with the address the previous command printed:

```sh
gcloud compute addresses create setlo-ip --region=us-central1 --addresses=EXTERNAL_IP
```

## 2. Install the stack

```sh
gcloud compute ssh setlo --zone=us-central1-a
```

On the VM:

```sh
# 1 GB of RAM is tight, so add swap
sudo fallocate -l 1G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-mysql php-curl php-gd php-mbstring mariadb-server \
                    certbot python3-certbot-apache
sudo a2enmod rewrite && sudo systemctl restart apache2
```

Use **MariaDB, not MySQL**: `database/migrate.php` uses `ADD COLUMN IF NOT EXISTS`, which is MariaDB-only syntax. XAMPP ships MariaDB too.

`mod_rewrite` is what serves every page without `.php` in the URL (the root `.htaccess`) and blocks directory listing — XAMPP has it enabled by default, but a fresh Debian Apache install doesn't, so `a2enmod rewrite` above is required.

## 3. Tune for 1 GB RAM and phone photos

```sh
# MariaDB: small buffers
sudo tee /etc/mysql/mariadb.conf.d/99-setlo.cnf <<'EOF'
[mysqld]
innodb_buffer_pool_size = 64M
performance_schema = OFF
max_connections = 30
EOF

# PHP: allow 8 MB photos (Debian's default is 2 MB), and give the Gemini call time to finish
sudo tee "$(ls -d /etc/php/*/apache2/conf.d)/99-setlo.ini" <<'EOF'
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 60
expose_php = Off
EOF

# Apache: cap workers so they fit in memory
sudo sed -i 's/MaxRequestWorkers.*/MaxRequestWorkers 10/' /etc/apache2/mods-available/mpm_prefork.conf

sudo systemctl restart mariadb
```

## 4. Database user

Pick a strong password and use it again in step 6.

```sh
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS setlo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'setlo'@'localhost' IDENTIFIED BY 'CHANGE_ME';
  GRANT ALL ON setlo.* TO 'setlo'@'localhost';"
```

## 5. Upload the code

On your Windows PC, from the project folder:

1. Rebuild the CSS with `tools\build-css.cmd`. The Tailwind binary is Windows-only, so the built `assets/css/app.css` ships as-is.
2. Package and copy the code:

```powershell
tar -czf ..\setlo.tgz --exclude=tools --exclude=uploads/receipts --exclude=uploads/qr --exclude=uploads/proofs .
gcloud compute scp ..\setlo.tgz setlo:~ --zone=us-central1-a
```

Then on the VM:

```sh
sudo rm -f /var/www/html/index.html
sudo tar -xzf ~/setlo.tgz -C /var/www/html
sudo chown -R www-data:www-data /var/www/html/uploads
```

## 6. Apache site and secrets

Secrets live in the vhost file, never in `config/config.php`. `config.php` reads them with `getenv()` and falls back to the XAMPP defaults.

```sh
sudo tee /etc/apache2/sites-available/setlo.conf <<'EOF'
<VirtualHost *:80>
    ServerName YOURNAME.duckdns.org
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
    SetEnv APP_BASE_URL ""
    SetEnv DB_NAME setlo
    SetEnv DB_USER setlo
    SetEnv DB_PASS CHANGE_ME
    SetEnv GEMINI_API_KEY YOUR_GEMINI_KEY
    SetEnv GOOGLE_CLIENT_ID YOUR_CLIENT_ID.apps.googleusercontent.com
</VirtualHost>
EOF
sudo chmod 640 /etc/apache2/sites-available/setlo.conf
sudo a2dissite 000-default && sudo a2ensite setlo && sudo systemctl reload apache2
```

`AllowOverride All` matters: the `.htaccess` files in `config/`, `includes/`, `database/` and `uploads/` block direct web access to those folders.

## 7. Create the tables

Command-line PHP does not see Apache's `SetEnv`, so pass the DB credentials inline:

```sh
cd /var/www/html
sudo -u www-data env DB_NAME=setlo DB_USER=setlo DB_PASS=CHANGE_ME php database/setup.php     # schema + demo data (drops existing tables!)
sudo -u www-data env DB_NAME=setlo DB_USER=setlo DB_PASS=CHANGE_ME php database/migrate.php
```

`setup.php` creates demo accounts with the password `password123` and an admin with `admin12345`. On a public server, change these after the defense, or skip `setup.php` and import `database/schema.sql` only.

## 8. Free domain + HTTPS

HTTPS is required: the PWA service worker, and a camera that works reliably in mobile browsers, only run on a secure origin.

1. Sign in at https://www.duckdns.org, create `YOURNAME`, and set its IP to the VM's static IP.
2. On the VM, run:
   ```sh
   sudo certbot --apache -d YOURNAME.duckdns.org --redirect -m you@example.com --agree-tos -n
   ```
   Certbot sets up auto-renewal with a systemd timer.

Open `https://YOURNAME.duckdns.org` on a phone, sign in, and scan a receipt.

## 9. Keep it free

- **Billing → Budgets & alerts:** create a budget of $1 with email alerts.
- Keep exactly one e2-micro in a free region, on a standard disk of 30 GB or less.
- Outbound traffic is free up to 1 GB/month (to most regions). Receipt photos go *to* the server, which counts as inbound and is free.
- Gemini API free tier: rate and daily request limits apply, which is plenty for a class demo. On the free tier, Google may use submitted content to improve its products.

## Google sign-in (optional)

1. Open https://console.cloud.google.com/apis/credentials, create a project if asked, then **Configure consent screen** → External → enter the app name and your email.
2. **Create credentials → OAuth client ID → Web application**.
3. Under **Authorized JavaScript origins**, add every address people open Setlo from: `http://localhost` for XAMPP, and `https://YOURNAME.duckdns.org` for the server. No redirect URIs are needed.
4. Put the **Client ID** in the vhost (`SetEnv GOOGLE_CLIENT_ID …`, step 6). On XAMPP, put it in `config/config.php` as the default value of `GOOGLE_CLIENT_ID`.

Google only proves who the user is; Setlo verifies the token on the server (`includes/google.php`). A first-time Google user gets a new account with a random password. They keep signing in with Google, or the app manager can reset the password in **Admin → Users**.

## Updating later

Repeat step 5. The tar excludes `uploads/`, so users' photos are kept. Then run `migrate.php` as in step 7. Do **not** run `setup.php` again, because it wipes the data.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Receipt scanning isn't set up" banner | `GEMINI_API_KEY` is missing from the vhost (or from `config/local.php` on a local copy), or `php-curl` isn't installed. Run `sudo systemctl reload apache2` after edits. |
| "Continue with Google" says it isn't set up | `GOOGLE_CLIENT_ID` is missing from the vhost. |
| Google popup shows "origin_mismatch", or the button doesn't appear | Add the exact site address under **Authorized JavaScript origins** of the OAuth client. |
| "Photo is too large" / upload fails at about 2 MB | The PHP ini from step 3 is not loaded. Check with `php --ini` and the Apache `phpinfo()`. |
| "The scanning service rejected the server's API key" | Regenerate the key in AI Studio and update the vhost. |
| Redirects go to a wrong path | `APP_BASE_URL` must be `""` when the app is served from the domain root. |
| Server-side errors | `sudo tail -f /var/log/apache2/error.log`. Gemini failures are logged with the prefix `[setlo]`. |
