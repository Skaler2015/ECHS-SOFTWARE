# 🚀 Noble Health Software — Setup & Auto-Deploy Guide (Hindi)

Yeh software **RGHS** aur **ECHS** dono ke liye hai, jo aapke subdomain
**noble.subhashkaler.com** par chalega.

---

## भाग 1 — पहली बार Hosting पर Setup (एक ही बार करना है)

### Step 1: Subdomain banayein
Apni hosting (cPanel / Hostinger) me subdomain banayein:
- Subdomain: `noble`
- Domain: `subhashkaler.com`
- Ban jayega: **noble.subhashkaler.com**
- Iska folder note kar lein (aam taur par `/noble/` ya `/public_html/noble/`)

### Step 2: MySQL Database banayein
cPanel me **MySQL Databases** me jaakar:
1. Ek naya database banayein (jaise `noble_health`)
2. Ek user banayein aur password set karein
3. User ko us database se **All Privileges** ke saath jodein
4. Yeh 3 cheezein note karein: **DB Name, DB User, DB Password**

### Step 3: Files upload karein
Do tarike hain — **A (aasan, auto)** ya **B (manual)**:

**Tarika A — Auto (recommended):** Bhaag 2 (neeche) follow karein. Ek baar
set karne ke baad har change apne aap upload hoga.

**Tarika B — Manual:** Is repo ka ZIP download karke, saari files subdomain
ke folder me upload kar dein.

### Step 4: Database details bharein
Server par `config/secrets.php` file banayein (`config/secrets.sample.php`
ki copy banakar) aur usme Step 2 wali DB details bhar dein.

> ⚠️ `secrets.php` kabhi git me nahi jaati aur auto-deploy ise kabhi
> overwrite nahi karta — aapka password safe rehta hai.

### Step 5: Install chalayein
Browser me kholein: **https://noble.subhashkaler.com/install/**
- Admin username, naam aur password bharein
- **Install Now** dabayein
- ✅ Tables ban jayenge aur aapka login ready ho jayega

### Step 6: Security
Setup ke baad **`/install/` folder delete** kar dein (hosting file manager se).

### Step 7: Login
**https://noble.subhashkaler.com/** kholein aur login karein. 🎉

---

## भाग 2 — Auto-Deploy (GitHub → Portal, apne aap publish)

Ek baar yeh set karne ke baad, jab bhi yahan (GitHub) par code change
hoga, wo **apne aap** aapki hosting par upload ho jayega.

### GitHub me 3 Secrets daalein
GitHub repo kholein → **Settings** → **Secrets and variables** →
**Actions** → **New repository secret**. Yeh banायें:

| Secret Name      | Value (aapki hosting se)                          |
|------------------|---------------------------------------------------|
| `FTP_SERVER`     | FTP host, jaise `ftp.subhashkaler.com` ya IP      |
| `FTP_USERNAME`   | Aapka FTP username                                |
| `FTP_PASSWORD`   | Aapka FTP password                                |
| `FTP_REMOTE_DIR` | Subdomain folder, jaise `/noble/` (optional)      |

> FTP details cPanel ke **FTP Accounts** section me milti hain. Agar
> aapko nahi pata, hosting support se `FTP host, username, password` maang
> lein.

### Ab kaise chalega?
- Jaise hi kisi change ka commit is branch par push hoga, GitHub apne aap
  files hosting par bhej dega (1-2 minute me live).
- Aap **Actions** tab me dekh sakte hain ki deploy chal raha hai ya ho gaya.
- Manually deploy karne ke liye: **Actions** tab → **Deploy to Hosting**
  → **Run workflow**.

### Pehli baar test
Secrets daalne ke baad, GitHub → **Actions** → **Deploy to Hosting** →
**Run workflow** dabayein. Green tick aane ka matlab deploy safal. ✅

---

## अक्सर पूछे जाने वाले सवाल

**Q. Database password auto-deploy me delete to nahi hoga?**
Nahi. `config/secrets.php` git aur deploy dono se bahar rakhi gayi hai.

**Q. Data (patients, bills) deploy se delete hoga?**
Nahi. Data database me hai, files me nahi. Deploy sirf code update karta hai.

**Q. Naya feature chahiye to?**
Yahan (GitHub / Claude) bata dein — change ho kar apne aap live ho jayega.

---

Banaya gaya: **Subhash Kaler** ke liye · Noble Health Software v1.0
