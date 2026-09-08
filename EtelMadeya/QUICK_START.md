# Etel Madeya - Quick Start Guide
## የኢትል ማደያ - ፈጣን የመጀመሪያ መመሪያ

---

## ⚡ 5-Minute Setup

### 1. Import Database (2 minutes)
```bash
# Open phpMyAdmin: http://localhost/phpmyadmin
# Create database: Etel-Madeya
# Import: database.sql
```

### 2. Configure (1 minute)
Edit `includes/config.php`:
```php
define('DB_USER', 'root');              // Your MySQL username
define('DB_PASS', '');                  // Your MySQL password
define('SITE_URL', 'http://localhost/Etel-Madeya/madeya-gas-queue');
```

### 3. Test (2 minutes)
- **Driver Interface**: http://localhost/Etel-Madeya/madeya-gas-queue/
- **Controller Panel**: http://localhost/Etel-Madeya/madeya-gas-queue/controller.php
  - Login: `madeya` / `CHANGE_ME`
- **Admin Panel**: http://localhost/Etel-Madeya/madeya-gas-queue/admin/
  - Login: `admin` / `CHANGE_ME`

---

## 🎯 First Steps

### As Admin:
1. Login to admin panel
2. Change default password
3. Add/configure controllers
4. Configure vehicle types
5. Update settings

### As Controller:
1. Login to controller panel
2. Change default password
3. Add vehicles to queue
4. Serve vehicles
5. Monitor queue

### As Driver:
1. Visit driver interface
2. Register vehicle with plate number
3. Check position anytime
4. Get notified when turn is near

---

## 📋 Default Data

### Vehicle Types:
- 🛺 Bajaj (ባጃጅ)
- 🚕 Taxi (ታክሲ)
- 🚗 Private Car (የግል መኪና)
- 🚐 Minibus (ሚኒባስ)
- 🚚 Truck (ጭነት)
- 🏍️ Motorcycle (ሞተርሳይክል)

### Default Accounts:
| Role | Username | Password |
|------|----------|----------|
| Controller | madeya | CHANGE_ME |
| Admin | admin | CHANGE_ME |

⚠️ **Change these immediately!**

---

## 🔥 Common Tasks

### Register a Vehicle:
1. Go to driver interface
2. Click "Register in Queue"
3. Enter plate number (e.g., AA-3-12345)
4. Select vehicle type
5. Submit

### Check Position:
1. Go to driver interface
2. Click "Check Position"
3. Enter plate number
4. View position and status

### Serve a Vehicle (Controller):
1. Login to controller panel
2. Find vehicle in queue
3. Click "Serve" button
4. Enter fuel amount (optional)
5. Confirm

### Add Controller (Admin):
1. Login to admin panel
2. Go to "Controllers" tab
3. Click "Add Controller"
4. Fill in details
5. Save

---

## 🐛 Quick Fixes

### Can't connect to database?
```php
// Check config.php has correct credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Blank page?
```bash
# Check PHP error log
# Windows: C:\xampp\apache\logs\error.log
# Linux: /var/log/apache2/error.log
```

### Login not working?
```sql
-- Reset admin password to 'CHANGE_ME'
UPDATE admin_users 
SET password = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5GyYIxIvoK5jO' 
WHERE username = 'admin';
```

### API errors?
1. Check browser console (F12)
2. Verify SITE_URL in config.php
3. Test API directly: `http://localhost/.../index.php?route=api&action=get_vehicle_types`

---

## 📚 Full Documentation

For complete installation and configuration:
- **Installation Guide**: INSTALLATION.md
- **Full Documentation**: README-STANDALONE.md

---

## 🎉 You're Ready!

Your Etel Madeya Gas Queue System is now running!

**Next Steps:**
1. Change all default passwords
2. Upload your logo to `assets/img/etel-logo.png`
3. Configure your station details
4. Add your controllers
5. Start managing queues!

---

**Need Help?**
- 📧 Email: info@etelmadeya.com
- 📞 Phone: +251-911-000-000

**May your queues be fair and your fuel flow smoothly!**
**ወረፋዎችዎ ፍትሃዊ እና ነዳጅዎ በጥሩ ሁኔታ ይፍሰስ!**
